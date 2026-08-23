import { request } from 'node:http';

const BASE = process.env.WPMCP_BASE || 'http://localhost:8888';
const USER = process.env.WPMCP_USER || 'admin';
const PASS = process.env.WPMCP_PASS;
const ENDPOINT = new URL( '/wp-json/wpmcp/v1/mcp', BASE );

if ( ! PASS ) {
	console.error( 'Set WPMCP_PASS (application password).' );
	process.exit( 2 );
}

let failures = 0;

function check( label, ok, extra = '' ) {
	console.log( `${ ok ? '[PASS]' : '[FAIL]' } ${ label }${ extra ? ' :: ' + extra : '' }` );
	if ( ! ok ) failures++;
}

function safeParse( raw ) {
	try {
		return JSON.parse( raw );
	} catch {
		return null;
	}
}

let idCounter = 1000;
async function rpc( method, params = undefined ) {
	const body = { jsonrpc: '2.0', id: ++idCounter, method };
	if ( params !== undefined ) body.params = params;
	const payload = JSON.stringify( body );
	const res = await new Promise( ( resolve, reject ) => {
		const req = request(
			ENDPOINT,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Content-Length': Buffer.byteLength( payload ),
					Authorization: 'Basic ' + Buffer.from( `${ USER }:${ PASS }` ).toString( 'base64' ),
				},
				agent: false,
			},
			( res ) => {
				let data = '';
				res.on( 'data', ( chunk ) => ( data += chunk ) );
				res.on( 'end', () => resolve( safeParse( data ) ) );
			}
		);
		req.on( 'error', () => resolve( null ) );
		req.setTimeout( 60000, () => req.destroy() );
		req.write( payload );
		req.end();
	} );
	return res;
}

async function toolCall( name, args = {} ) {
	return rpc( 'tools/call', { name, arguments: args } );
}

function resultOf( response ) {
	if ( ! response?.result ) return null;
	try {
		return JSON.parse( response.result.content?.[ 0 ]?.text ?? 'null' );
	} catch {
		return null;
	}
}

const createdIds = [];
async function makePost( title, content ) {
	const out = resultOf( await toolCall( 'create-post', { title, content, status: 'publish' } ) );
	if ( Number.isInteger( out?.id ) ) createdIds.push( out.id );
	return out;
}

// 1. Catalog.
const catalog = resultOf( await toolCall( 'list-blocks', { search: 'paragraph' } ) );
check( 'list-blocks finds core/paragraph', ( catalog?.blocks ?? [] ).some( ( b ) => b.name === 'core/paragraph' ), JSON.stringify( catalog ?? {} ).slice( 0, 120 ) );

const schema = resultOf( await toolCall( 'get-block-schema', { name: 'core/heading' } ) );
check( 'get-block-schema heading has content attr', schema?.attributes?.content !== undefined && ! schema?.error, Object.keys( schema?.attributes ?? {} ).join( ',' ) );

const missingSchema = resultOf( await toolCall( 'get-block-schema', { name: 'nope/block' } ) );
check( 'get-block-schema unknown -> unknown_block', missingSchema?.error === 'unknown_block' );

// 2. Flat post round-trip.
const flat = await makePost(
	'E2E Blocks Flat',
	'<!-- wp:heading --><h2>First Heading</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>Alpha paragraph.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Beta paragraph.</p><!-- /wp:paragraph -->'
);
check( 'create-post block content', Number.isInteger( flat?.id ), JSON.stringify( flat ?? {} ) );
const postId = flat.id;

const tree = resultOf( await toolCall( 'get-post-blocks', { post_id: postId } ) );
check( 'get-post-blocks is_gutenberg', tree?.is_gutenberg === true );
check( 'get-post-blocks 3 blocks with paths', tree?.blocks?.length === 3 && JSON.stringify( tree.blocks[ 2 ].path ) === '[2]', JSON.stringify( tree?.blocks?.map( ( b ) => [ b.path, b.name ] ) ?? [] ) );

// 3. Insert.
const insAppend = resultOf( await toolCall( 'insert-block', {
	post_id: postId,
	name: 'core/heading',
	attrs: { level: 2 },
	content_html: '<h2>Appended Heading</h2>',
} ) );
check( 'insert-block append ok', insAppend?.ok === true && insAppend.block_summary.length === 4, JSON.stringify( insAppend ?? {} ).slice( 0, 140 ) );

const insAt = resultOf( await toolCall( 'insert-block', {
	post_id: postId,
	name: 'core/quote',
	content_html: '<blockquote>Cited words</blockquote>',
	path: [ 1 ],
} ) );
check( 'insert-block at path ok', insAt?.ok === true && insAt.block_summary[ 1 ] === 'core/quote', JSON.stringify( insAt ?? {} ).slice( 0, 140 ) );

const afterInsert = resultOf( await toolCall( 'get-post-blocks', { post_id: postId } ) );
check( 'tree reflects inserts', afterInsert?.blocks?.length === 5 && afterInsert.blocks[ 1 ].name === 'core/quote' && afterInsert.blocks[ 4 ].name === 'core/heading', JSON.stringify( afterInsert?.blocks?.map( ( b ) => b.name ) ?? [] ) );

// 4. Update attrs + html.
const upd = resultOf( await toolCall( 'update-block-attrs', {
	post_id: postId,
	path: [ 4 ],
	attrs: { level: 3 },
	content_html: '<h3>Appended Heading H3</h3>',
} ) );
check( 'update-block-attrs ok', upd?.ok === true, JSON.stringify( upd ?? {} ).slice( 0, 120 ) );
const afterUpd = resultOf( await toolCall( 'get-post-blocks', { post_id: postId, include_html: true } ) );
const h = afterUpd?.blocks?.find( ( b ) => b.name === 'core/heading' && ( b.html ?? '' ).includes( 'H3' ) );
check( 'attrs persisted', h?.attrs?.level === 3, JSON.stringify( h ?? {} ).slice( 0, 160 ) );
check( 'html persisted', ( h?.html ?? '' ).includes( 'H3' ), h?.html );

// 5. Move.
const moved = resultOf( await toolCall( 'move-block', { post_id: postId, path: [ 4 ], to_path: [ 0 ] } ) );
check( 'move-block to front', moved?.ok === true, JSON.stringify( moved ?? {} ).slice( 0, 140 ) );
const afterMove = resultOf( await toolCall( 'get-post-blocks', { post_id: postId } ) );
check( 'moved block is first', afterMove?.blocks?.[ 0 ]?.name === 'core/heading', afterMove?.blocks?.[ 0 ]?.name );

const badMove = resultOf( await toolCall( 'move-block', { post_id: postId, path: [ 9 ], to_path: [ 0 ] } ) );
check( 'move bad source -> invalid_source_path', badMove?.error === 'invalid_source_path' );

// 6. Nested blocks.
const nested = await makePost(
	'E2E Blocks Nested',
	'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inner one.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
);
const nestedTree = resultOf( await toolCall( 'get-post-blocks', { post_id: nested.id } ) );
const group = nestedTree?.blocks?.[ 0 ];
check( 'nested: group parsed', group?.name === 'core/group' && group?.has_inner === true, JSON.stringify( group ?? {} ).slice( 0, 160 ) );
check( 'nested: inner path [0,0]', group?.innerBlocks?.[ 0 ]?.path?.join() === '0,0' );

const insInner = resultOf( await toolCall( 'insert-block', {
	post_id: nested.id,
	name: 'core/paragraph',
	content_html: '<p>Inner two.</p>',
	path: [ 0, 1 ],
} ) );
check( 'nested: insert into group', insInner?.ok === true, JSON.stringify( insInner ?? {} ).slice( 0, 140 ) );

const noOp = resultOf( await toolCall( 'move-block', { post_id: nested.id, path: [ 0 ], to_path: [ 0, 0 ] } ) );
check( 'nested: move into own subtree refused', noOp?.error === 'no_op_subtree', JSON.stringify( noOp ?? {} ) );

// 7. Error paths.
const unknownIns = resultOf( await toolCall( 'insert-block', { post_id: postId, name: 'made/up-block' } ) );
check( 'insert unknown block -> unknown_block', unknownIns?.error === 'unknown_block' );

const badPath = resultOf( await toolCall( 'update-block-attrs', { post_id: postId, path: [ 99 ], attrs: { x: 1 } } ) );
check( 'update invalid path -> invalid_path', badPath?.error === 'invalid_path' );

// 8. Confirm gate.
const rmNoConfirm = await toolCall( 'remove-block', { post_id: postId, path: [ 0 ] } );
check( 'remove without confirm gated', resultOf( rmNoConfirm )?.error === 'confirm_required', rmNoConfirm?.result?.content?.[ 0 ]?.text?.slice( 0, 80 ) );

const rm = resultOf( await toolCall( 'remove-block', { post_id: postId, path: [ 0 ], confirm: true } ) );
check( 'remove with confirm ok', rm?.ok === true && rm.block_summary.length === 4, JSON.stringify( rm ?? {} ).slice( 0, 140 ) );

// 9. Change log integration.
const changes = resultOf( await toolCall( 'list-changes', { per_page: 20 } ) );
const blockActions = new Set( ( changes?.changes ?? [] ).filter( ( c ) => c.domain === 'blocks' ).map( ( c ) => c.action ) );
for ( const expected of [ 'insert-block', 'update-block-attrs', 'move-block', 'remove-block' ] ) {
	check( `change log has ${ expected }`, blockActions.has( expected ), [ ...blockActions ].join( ',' ) );
}

// Cleanup.
for ( const id of createdIds ) {
	await toolCall( 'delete-post', { id, confirm: true } );
}

console.log( `\n${ failures } failure(s)` );
process.exit( failures > 0 ? 1 : 0 );
