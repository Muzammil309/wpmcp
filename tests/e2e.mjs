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

function callRpc( body, auth = true ) {
	return new Promise( ( resolve, reject ) => {
		const payload = JSON.stringify( body );
		const headers = {
			'Content-Type': 'application/json',
			'Content-Length': Buffer.byteLength( payload ),
		};
		if ( auth ) {
			headers.Authorization = 'Basic ' + Buffer.from( `${ USER }:${ PASS }` ).toString( 'base64' );
		}
		const req = request(
			ENDPOINT,
			{ method: 'POST', headers, agent: false },
			( res ) => {
				let data = '';
				res.on( 'data', ( chunk ) => ( data += chunk ) );
				res.on( 'end', () => resolve( { status: res.statusCode, json: safeParse( data ) } ) );
			}
		);
		req.on( 'error', () => resolve( null ) );
		req.setTimeout( 60000, () => req.destroy() );
		req.write( payload );
		req.end();
	} );
}

function safeParse( raw ) {
	try {
		return JSON.parse( raw );
	} catch {
		return null;
	}
}

let idCounter = 0;
async function rpc( method, params = undefined ) {
	const body = { jsonrpc: '2.0', id: ++idCounter, method };
	if ( params !== undefined ) body.params = params;
	const res = await callRpc( body );
	return res.json?.[ 0 ] ?? res.json;
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

// 1. Unauthenticated request must be rejected.
const anon = await callRpc( { jsonrpc: '2.0', id: 900, method: 'tools/list' }, false );
check( 'auth: anonymous rejected', anon.status === 401 || anon.status === 403, `status ${ anon.status }` );

// 2. Initialize handshake.
const init = await rpc( 'initialize', {
	protocolVersion: '2025-06-18',
	capabilities: {},
	clientInfo: { name: 'wpmcp-e2e', version: '0.0.1' },
} );
check( 'initialize: server info', init?.result?.serverInfo?.name === 'wpmcp-suite', JSON.stringify( init?.result?.serverInfo ?? {} ) );

await rpc( 'notifications/initialized' );

// 3. tools/list.
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
function wpCli( cmd ) {
	execSync( `npx @wordpress/env run cli ${ cmd }`, { stdio: 'ignore', cwd: fileURLToPath( new URL( '..', import.meta.url ) ) } );
}
wpCli( 'wp option update wpmcp_compact_mode 0' );

const list = await rpc( 'tools/list' );
const names = ( list?.result?.tools ?? [] ).map( ( t ) => t.name );
for ( const expected of [ 'list-posts', 'create-post', 'seo-read', 'seo-write', 'audit-page-seo', 'generate-meta-tags', 'generate-schema-markup', 'redirect-write', 'rollback-change', 'get-settings', 'sideload-image' ] ) {
	check( `tools/list has ${ expected }`, names.includes( expected ) );
}
check( 'tools/list count >= 20', names.length >= 20, String( names.length ) );

// 4. Content round-trip.
const created = resultOf( await toolCall( 'create-post', {
	title: 'E2E Test Post',
	content: '<h1>E2E Heading</h1><p>This is a paragraph about search engine optimization and keyword research for the e2e keyword test.</p><img src="x.png">',
	status: 'publish',
} ) );
check( 'create-post returns id', Number.isInteger( created?.id ), JSON.stringify( created ?? {} ) );
const postId = created.id;

const got = resultOf( await toolCall( 'get-post', { id: postId } ) );
check( 'get-post reads back title', got?.title === 'E2E Test Post' );

const listed = resultOf( await toolCall( 'list-posts', { search: 'E2E Test Post' } ) );
check( 'list-posts finds created post', ( listed?.posts ?? [] ).some( ( p ) => p.id === postId ) );

// 5. SEO layer.
const status = resultOf( await toolCall( 'seo-read', { operation: 'get-status' } ) );
check( 'seo-read get-status has adapter', typeof status?.active === 'string', status?.active );

const seoBefore = resultOf( await toolCall( 'seo-read', { operation: 'get-post-seo', post_id: postId } ) );
check( 'seo-read get-post-seo no error', ! seoBefore?.error, JSON.stringify( seoBefore ?? {} ).slice( 0, 120 ) );

const written = resultOf( await toolCall( 'seo-write', {
	operation: 'update-post-seo',
	post_id: postId,
	fields: { title: 'Optimized E2E Title', description: 'A meta description under one hundred fifty five characters for the e2e test.', noindex: false },
} ) );
check( 'seo-write updates fields', Array.isArray( written?.updated ) && written.updated.includes( 'title' ), JSON.stringify( written ?? {} ) );

const seoAfter = resultOf( await toolCall( 'seo-read', { operation: 'get-post-seo', post_id: postId } ) );
check( 'seo read-after-write works', seoAfter?.title === 'Optimized E2E Title', seoAfter?.title );

// 6. Audit + generators.
const audit = resultOf( await toolCall( 'audit-page-seo', { post_id: postId, target_keyword: 'keyword' } ) );
check( 'audit-page-seo scored', typeof audit?.score === 'number' && audit.score >= 0 && audit.score <= 100, String( audit?.score ) );
check( 'audit-page-seo findings array', Array.isArray( audit?.findings ) );

const keywords = resultOf( await toolCall( 'extract-keywords-from-content', { post_id: postId } ) );
check( 'extract-keywords returns unigrams', keywords?.unigrams && Object.keys( keywords.unigrams ).length > 0 );

const metaDry = resultOf( await toolCall( 'generate-meta-tags', { post_id: postId, target_keyword: 'optimization' } ) );
check( 'generate-meta-tags dry-run', metaDry?.applied === false && metaDry.title.length <= 60, metaDry?.title );

const schemaDry = resultOf( await toolCall( 'generate-schema-markup', { post_id: postId, type: 'Article' } ) );
check( 'generate-schema-markup dry-run', schemaDry?.applied === false && schemaDry.json_ld['@type'] === 'Article' );

const schemaApply = resultOf( await toolCall( 'generate-schema-markup', { post_id: postId, type: 'FAQPage', faqs: [ { question: 'Q?', answer: 'A.' } ], apply: true } ) );
check( 'generate-schema-markup apply', schemaApply?.applied === true );

// 7. Redirects.
const stamp = Date.now().toString( 36 );
const fromPath = `/e2e-old-${ stamp }/`;
const toPath = `/e2e-new-${ stamp }/`;
const redirectAdd = resultOf( await toolCall( 'redirect-write', { operation: 'add', from: fromPath, to: toPath, code: 301 } ) );
check( 'redirect add ok', redirectAdd?.ok === true, JSON.stringify( redirectAdd ?? {} ) );
const redirectList = resultOf( await toolCall( 'redirect-read' ) );
check( 'redirect-read lists entry', ( redirectList?.redirects ?? [] ).some( ( r ) => r.from === `/e2e-old-${ stamp }` ) );

// 8. History + rollback.
const changes = resultOf( await toolCall( 'list-changes', { per_page: 10 } ) );
check( 'list-changes recorded entries', ( changes?.changes ?? [] ).length > 0 );
const seoChange = ( changes.changes ?? [] ).find( ( c ) => c.action === 'update-post-seo' && c.target_id === postId );
if ( seoChange ) {
	const rolled = resultOf( await toolCall( 'rollback-change', { id: seoChange.id, confirm: true } ) );
	check( 'rollback-change restores SEO', rolled?.rolled_back === true, JSON.stringify( rolled ?? {} ) );
	const reverted = resultOf( await toolCall( 'seo-read', { operation: 'get-post-seo', post_id: postId } ) );
	check( 'SEO title reverted after rollback', reverted?.title !== 'Optimized E2E Title', reverted?.title );
} else {
	check( 'found seo change to roll back', false );
}

// 8b. Phase 2: snapshot + scanners.
const snapshot = resultOf( await toolCall( 'get-page-snapshot', { post_id: postId } ) );
check( 'snapshot: outline present', Array.isArray( snapshot?.structure?.outline ) );
check( 'snapshot: word count > 0', ( snapshot?.structure?.word_count ?? 0 ) > 0, String( snapshot?.structure?.word_count ) );
check( 'snapshot: seo plugin label', typeof snapshot?.seo?.plugin === 'string', snapshot?.seo?.plugin );
check( 'snapshot: warnings array', Array.isArray( snapshot?.warnings ) );

const perf = resultOf( await toolCall( 'analyze-performance' ) );
check( 'analyze-performance scored', typeof perf?.score === 'number' && perf.score >= 0 && perf.score <= 100, String( perf?.score ) );
check( 'analyze-performance db size', typeof perf?.database?.size_mb === 'number' );

const security = resultOf( await toolCall( 'scan-security', { scan_uploads_php: false } ) );
check( 'scan-security scored', typeof security?.score === 'number' && security.score >= 0 && security.score <= 100, String( security?.score ) );
check( 'scan-security hardening block', typeof security?.hardening === 'object' );

// 8c. Compact tool mode.
const compactOff = resultOf( await toolCall( 'call-tool', { name: 'list-tools', arguments: {} } ) );
check( 'compact: meta tool refused when off', compactOff?.error === 'wpmcp_compact_off', JSON.stringify( compactOff ?? {} ).slice( 0, 80 ) );

wpCli( 'wp option update wpmcp_compact_mode 1' );
const compactList = await rpc( 'tools/list' );
const compactNames = ( compactList?.result?.tools ?? [] ).map( ( t ) => t.name );
check( 'compact: exactly 3 meta tools listed', compactNames.length === 3 && compactNames.includes( 'call-tool' ), compactNames.join( ',' ) );
const viaMeta = resultOf( await toolCall( 'call-tool', { name: 'seo-read', arguments: { operation: 'get-status' } } ) );
check( 'compact: call-tool executes real tool', typeof viaMeta?.active === 'string' || !viaMeta?.error, JSON.stringify( viaMeta ?? {} ).slice( 0, 100 ) );
const metaCatalog = resultOf( await toolCall( 'list-tools', { category: 'seo' } ) );
check( 'compact: catalog filters by category', ( metaCatalog?.tools ?? [] ).length >= 2 && metaCatalog.tools.every( ( t ) => t.category === 'seo' ) );
wpCli( 'wp option update wpmcp_compact_mode 0' );
const normalList = await rpc( 'tools/list' );
check( 'compact: full list restored', ( normalList?.result?.tools ?? [] ).length > 20 );

// 9. Protocol errors.
const badMethod = await rpc( 'no/such/method' );
check( 'unknown method -> -32601', badMethod?.error?.code === -32601 );

const cleanup = resultOf( await toolCall( 'delete-post', { id: postId, confirm: true } ) );
check( 'delete-post trashes', cleanup?.deleted === true );

console.log( `\n${ failures } failure(s)` );
process.exit( failures > 0 ? 1 : 0 );
