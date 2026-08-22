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

let idCounter = 2000;
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
		req.on( 'error', reject );
		req.setTimeout( 20000, () => req.destroy( new Error( 'timeout' ) ) );
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

// 1. Status + catalog.
const status = resultOf( await toolCall( 'elementor-status' ) );
check( 'elementor-status version', typeof status?.version === 'string', status?.version );
check( 'elementor-status widgets registered', ( status?.widget_count ?? 0 ) > 0, String( status?.widget_count ) );

const widgets = resultOf( await toolCall( 'list-elementor-widgets', { search: 'heading' } ) );
check( 'list-elementor-widgets finds heading', ( widgets?.widgets ?? [] ).some( ( w ) => w.name === 'heading' ), JSON.stringify( widgets ?? {} ).slice( 0, 120 ) );

const wSchema = resultOf( await toolCall( 'get-widget-schema', { widget_type: 'heading' } ) );
check( 'get-widget-schema heading controls', wSchema?.controls?.title !== undefined && ! wSchema?.error, Object.keys( wSchema?.controls ?? {} ).slice( 0, 8 ).join( ',' ) );

// 2. build-page composite.
const built = resultOf( await toolCall( 'build-page', {
	title: 'E2E Elementor Page',
	structure: [
		{
			settings: { flex_direction: 'column' },
			widgets: [
				{ type: 'heading', settings: { title: 'E2E Hero Title', header_size: 'h1' } },
				{ type: 'text-editor', settings: { editor: '<p>E2E body copy.</p>' } },
			],
		},
		{
			widgets: [ { type: 'button', settings: { text: 'E2E CTA' } } ],
		},
	],
	status: 'publish',
} ) );
check( 'build-page returns ids', Number.isInteger( built?.post_id ) && Array.isArray( built?.containers ) && built.containers.length === 2, JSON.stringify( built ?? {} ).slice( 0, 200 ) );
check( 'build-page saved', built?.saved === true );
check( 'build-page widget ids', built?.containers?.[ 0 ]?.widgets?.length === 2 && built.containers[ 0 ].widgets.every( ( w ) => typeof w.widget_id === 'string' ), JSON.stringify( built?.containers ?? [] ).slice( 0, 160 ) );

const pageId = built.post_id;
const heroContainer = built.containers[ 0 ].container_id;
const heroHeading = built.containers[ 0 ].widgets[ 0 ].widget_id;
const ctaContainer = built.containers[ 1 ].container_id;

const emptyBuild = resultOf( await toolCall( 'build-page', { title: 'x', structure: [] } ) );
check( 'build-page empty structure -> structure_required', emptyBuild?.error === 'structure_required' );

// 3. Structure read-back.
let structure = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
check( 'structure: built with elementor', structure?.is_built_with_elementor === true );
check( 'structure: 2 root containers', structure?.elements?.length === 2, JSON.stringify( structure?.elements?.map( ( e ) => [ e.id, e.elType ] ) ?? [] ) );
check( 'structure: heading widget nested', structure?.elements?.[ 0 ]?.children?.some( ( c ) => c.widgetType === 'heading' && c.id === heroHeading ) );

// 4. add-container nested + add-widget into it.
const inner = resultOf( await toolCall( 'add-container', { post_id: pageId, parent_id: heroContainer, settings: { flex_direction: 'row' } } ) );
check( 'add-container nested returns id', typeof inner?.container_id === 'string', JSON.stringify( inner ?? {} ).slice( 0, 120 ) );
const innerId = inner.container_id;

const nestedWidget = resultOf( await toolCall( 'add-widget', {
	post_id: pageId,
	widget_type: 'heading',
	settings: { title: 'Nested Heading' },
	container_id: innerId,
} ) );
check( 'add-widget into nested container', typeof nestedWidget?.widget_id === 'string', JSON.stringify( nestedWidget ?? {} ).slice( 0, 120 ) );

const unknownWidget = resultOf( await toolCall( 'add-widget', { post_id: pageId, widget_type: 'definitely-not-a-widget' } ) );
check( 'add-widget unknown -> unknown_widget', unknownWidget?.error === 'unknown_widget' );

const badParent = resultOf( await toolCall( 'add-widget', { post_id: pageId, widget_type: 'heading', container_id: heroHeading } ) );
check( 'add-widget into widget -> wpmcp_bad_parent', badParent?.error === 'wpmcp_bad_parent', JSON.stringify( badParent ?? {} ).slice( 0, 120 ) );

// 5. update-element merge.
const upd = resultOf( await toolCall( 'update-element', { post_id: pageId, element_id: heroHeading, settings: { title: 'Updated E2E Title' } } ) );
check( 'update-element ok', upd?.updated === true, JSON.stringify( upd ?? {} ).slice( 0, 120 ) );
structure = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
const heroNode = structure?.elements?.[ 0 ]?.children?.find( ( c ) => c.id === heroHeading );
check( 'update reflected in structure', heroNode?.settings?.title === 'Updated E2E Title', heroNode?.settings?.title );

const updMissing = resultOf( await toolCall( 'update-element', { post_id: pageId, element_id: 'nope123', settings: { title: 'x' } } ) );
check( 'update missing -> wpmcp_element_missing', updMissing?.error === 'wpmcp_element_missing' );

// 6. duplicate-element.
const dup = resultOf( await toolCall( 'duplicate-element', { post_id: pageId, element_id: heroContainer } ) );
check( 'duplicate-element new id', typeof dup?.new_element_id === 'string' && dup.new_element_id !== heroContainer, JSON.stringify( dup ?? {} ).slice( 0, 120 ) );
structure = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
check( 'duplicate: root count 3', structure?.elements?.length === 3, String( structure?.elements?.length ) );
check( 'duplicate: clone placed after original', structure.elements[ 1 ].id === dup.new_element_id );
const dupNode = structure.elements[ 1 ];
check( 'duplicate: subtree cloned', ( dupNode?.children ?? [] ).length === 3, String( dupNode?.children?.length ) );

// 7. move-element.
const mv = resultOf( await toolCall( 'move-element', { post_id: pageId, element_id: dup.new_element_id, index: 0 } ) );
check( 'move-element to index 0', mv?.moved === true, JSON.stringify( mv ?? {} ).slice( 0, 120 ) );
structure = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
check( 'moved clone is first root', structure?.elements?.[ 0 ]?.id === dup.new_element_id );

const mvSubtree = resultOf( await toolCall( 'move-element', { post_id: pageId, element_id: heroContainer, parent_id: innerId } ) );
check( 'move into own subtree -> wpmcp_no_op', mvSubtree?.error === 'wpmcp_no_op', JSON.stringify( mvSubtree ?? {} ).slice( 0, 120 ) );

const mvMissing = resultOf( await toolCall( 'move-element', { post_id: pageId, element_id: 'nope123', index: 0 } ) );
check( 'move missing -> wpmcp_element_missing', mvMissing?.error === 'wpmcp_element_missing' );

// 8. remove-element confirm gate.
const rmNoConfirm = await toolCall( 'remove-element', { post_id: pageId, element_id: dup.new_element_id } );
check( 'remove without confirm gated', resultOf( rmNoConfirm )?.error === 'confirm_required', rmNoConfirm?.result?.content?.[ 0 ]?.text?.slice( 0, 80 ) );

const rm = resultOf( await toolCall( 'remove-element', { post_id: pageId, element_id: dup.new_element_id, confirm: true } ) );
check( 'remove with confirm ok', rm?.removed === true, JSON.stringify( rm ?? {} ).slice( 0, 120 ) );
structure = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
check( 'remove reflected', structure?.elements?.length === 2, String( structure?.elements?.length ) );

// 9. clear-page confirm gate.
const clearNoConfirm = await toolCall( 'clear-page', { post_id: pageId } );
check( 'clear without confirm gated', resultOf( clearNoConfirm )?.error === 'confirm_required' );

const cleared = resultOf( await toolCall( 'clear-page', { post_id: pageId, confirm: true } ) );
check( 'clear with confirm ok', cleared?.cleared === true, JSON.stringify( cleared ?? {} ).slice( 0, 120 ) );
structure = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
check( 'cleared structure empty', ( structure?.elements ?? [] ).length === 0 );

// 10. Change log integration.
const changes = resultOf( await toolCall( 'list-changes', { per_page: 30 } ) );
const elActions = new Set( ( changes?.changes ?? [] ).filter( ( c ) => c.domain === 'elementor' ).map( ( c ) => c.action ) );
for ( const expected of [ 'build-page', 'add-container', 'add-widget', 'update-element', 'duplicate-element', 'move-element', 'remove-element', 'clear-page' ] ) {
	check( `change log has ${ expected }`, elActions.has( expected ), [ ...elActions ].join( ',' ) );
}

// Cleanup.
const cleanup = resultOf( await toolCall( 'delete-post', { id: pageId, confirm: true } ) );
check( 'cleanup delete-post', cleanup?.deleted === true );

console.log( `\n${ failures } failure(s)` );
process.exit( failures > 0 ? 1 : 0 );
