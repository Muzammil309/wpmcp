import { request } from 'node:http';
import { spawnSync } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.WPMCP_BASE || 'http://localhost:8888';
const USER = process.env.WPMCP_USER || 'admin';
const PASS = process.env.WPMCP_PASS;
const ENDPOINT = new URL( '/wp-json/wpmcp/v1/mcp', BASE );
const CWD = fileURLToPath( new URL( '..', import.meta.url ) );

if ( ! PASS ) {
	console.error( 'Set WPMCP_PASS (application password).' );
	process.exit( 2 );
}

function runCli( cmd, input ) {
	const res = spawnSync( `npx @wordpress/env run cli ${ cmd }`, { encoding: 'utf8', cwd: CWD, shell: true, input } );
	return { ok: 0 === res.status, out: String( res.stdout ?? '' ), err: String( res.stderr ?? '' ) };
}
function phpEvalFile( code ) {
	const file = join( CWD, 'tests', '_sys_fixture.php' );
	writeFileSync( file, `<?php\n${ code }\n` );
	runCli( `wp eval-file /var/www/html/wp-content/plugins/wpmcp-dev/tests/_sys_fixture.php` );
	rmSync( file, { force: true } );
}
function setLicense( active ) {
	phpEvalFile(
		active
			? `update_option( 'wpmcp_license', array( 'status' => 'active', 'expires_at' => 4102444800, 'plan' => 'pro' ) );`
			: `delete_option( 'wpmcp_license' );`
	);
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
let idCounter = 9000;
async function rpc( method, params ) {
	const body = { jsonrpc: '2.0', id: ++idCounter, method };
	if ( params !== undefined ) body.params = params;
	const payload = JSON.stringify( body );
	return await new Promise( ( resolve, reject ) => {
		const req = request( ENDPOINT, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Content-Length': Buffer.byteLength( payload ),
				Authorization: 'Basic ' + Buffer.from( `${ USER }:${ PASS }` ).toString( 'base64' ),
			},
			agent: false,
		}, ( res ) => {
			let data = '';
			res.on( 'data', ( c ) => ( data += c ) );
			res.on( 'end', () => resolve( safeParse( data ) ) );
		} );
		req.on( 'error', () => resolve( null ) );
		req.setTimeout( 60000, () => req.destroy() );
		req.write( payload );
		req.end();
	} );
}
async function toolCall( name, args = {} ) {
	return rpc( 'tools/call', { name, arguments: args } );
}
async function names() {
	const list = await rpc( 'tools/list' );
	return ( list?.result?.tools ?? [] ).map( ( t ) => t.name );
}
function resultOf( response ) {
	if ( ! response?.result ) return null;
	try {
		return JSON.parse( response.result.content?.[ 0 ]?.text ?? 'null' );
	} catch {
		return null;
	}
}

const PRO_SYS = [ 'user-write', 'fs-write', 'db-write', 'batch-update', 'import-template', 'save-as-template', 'apply-template', 'update-global-colors', 'update-global-typography' ];
setLicense( false );

// 1. Unlicensed: pro sys tools hidden.
let have = await names();
check( 'gate: pro system tools hidden', PRO_SYS.every( ( n ) => ! have.includes( n ) ), PRO_SYS.filter( ( n ) => have.includes( n ) ).join( ',' ) );
check( 'free reads visible', [ 'list-media', 'user-read', 'plugin-manage', 'menu-read', 'fs-read', 'db-read', 'list-patterns', 'find-element' ].every( ( n ) => have.includes( n ) ) );

// 2. Media.
const mediaList = resultOf( await toolCall( 'list-media', {} ) );
check( 'list-media returns shape', Array.isArray( mediaList?.media ) && Number.isInteger( mediaList.total ) );
const rmNoConfirm = await toolCall( 'delete-media', { id: 99999999 } );
check( 'delete-media gated', resultOf( rmNoConfirm )?.error === 'confirm_required', String( resultOf( rmNoConfirm )?.error ) );

// 3. Users.
const users = resultOf( await toolCall( 'user-read', { operation: 'list-users' } ) );
check( 'user-read lists admin', ( users?.users ?? [] ).some( ( u ) => u.is_admin === true && u.username === 'admin' ) );
const me = resultOf( await toolCall( 'user-read', { operation: 'get-user', id: users.users[ 0 ].id } ) );
check( 'user-read get-user', me?.username === 'admin' );

// 4. Plugins/themes.
const plugins = resultOf( await toolCall( 'plugin-manage', { operation: 'list' } ) );
check( 'plugin-manage list', ( plugins?.plugins ?? [] ).some( ( p ) => p.plugin.includes( 'hello' ) ) );
const selfProt = resultOf( await toolCall( 'plugin-manage', { operation: 'deactivate', plugin: 'wpmcp-dev/wpmcp.php' } ) );
check( 'self protection enforced', selfProt?.error === 'self_protection', selfProt?.error );
const hello = plugins.plugins.find( ( p ) => p.plugin.startsWith( 'hello' ) );
if ( hello.status === 'inactive' ) {
	const act = resultOf( await toolCall( 'plugin-manage', { operation: 'activate', plugin: hello.plugin } ) );
	check( 'activate plugin', act?.ok === true );
}
const deact = resultOf( await toolCall( 'plugin-manage', { operation: 'deactivate', plugin: hello.plugin } ) );
check( 'deactivate plugin', deact?.ok === true );
const reactivate = resultOf( await toolCall( 'plugin-manage', { operation: 'activate', plugin: hello.plugin } ) );
check( 'reactivate plugin', reactivate?.ok === true );
const themes = resultOf( await toolCall( 'theme-manage', { operation: 'list' } ) );
check( 'theme-manage lists active', ( themes?.themes ?? [] ).some( ( t ) => t.active === true ) );

// 5. Menus round-trip.
const createdMenu = resultOf( await toolCall( 'menu-write', { operation: 'create-menu', name: 'E2E System Menu' } ) );
check( 'menu create', createdMenu?.ok === true, JSON.stringify( createdMenu ?? {} ).slice( 0, 100 ) );
const addItem = resultOf( await toolCall( 'menu-write', { operation: 'add-item', menu: String( createdMenu.id ), object: 'custom', title: 'Docs', url: 'https://example.com/docs' } ) );
check( 'menu add item', addItem?.ok === true );
const gotMenu = resultOf( await toolCall( 'menu-read', { operation: 'get-menu', menu: String( createdMenu.id ) } ) );
check( 'menu get tree', gotMenu?.items?.length >= 1 );
const delMenu = resultOf( await toolCall( 'menu-write', { operation: 'delete-menu', menu: String( createdMenu.id ), confirm: true } ) );
check( 'menu delete confirm', delMenu?.deleted === true );

// 6. Filesystem reads.
const readFile = resultOf( await toolCall( 'fs-read', { operation: 'read-file', path: 'readme.html', limit: 3 } ) );
check( 'fs-read file', ( readFile?.content ?? '' ).length > 0, `total=${ readFile?.total_lines }` );
const forbiddenRead = resultOf( await toolCall( 'fs-read', { operation: 'read-file', path: 'wp-config.php' } ) );
check( 'fs-read refuses wp-config.php', forbiddenRead?.error === 'protected_file', forbiddenRead?.error );
const lsDir = resultOf( await toolCall( 'fs-read', { operation: 'list-directory', path: '.' } ) );
check( 'fs-read list-directory', ( lsDir?.entries ?? [] ).some( ( e ) => e.path.includes( 'wp-settings.php' ) ) );
const grep = resultOf( await toolCall( 'fs-read', { operation: 'search-files', path: 'wp-content/plugins/wpmcp-dev/includes/tools', query: 'manage_woocommerce', extensions: [ 'php' ], max_results: 20 } ) );
check( 'fs-read search-files', ( grep?.matches ?? [] ).length >= 1 );

// 7. Database reads.
const tables = resultOf( await toolCall( 'db-read', { operation: 'list-tables' } ) );
check( 'db-read list-tables', ( tables?.tables ?? [] ).some( ( t ) => String( t.name ).endsWith( 'posts' ) ) );
const described = resultOf( await toolCall( 'db-read', { operation: 'describe-table', table: `${ 'wp' }_posts` } ) );
check( 'db-read describe-table', ( described?.columns ?? [] ).some( ( c ) => c.field === 'ID' ) );
const queried = resultOf( await toolCall( 'db-read', { operation: 'query', sql: 'SELECT COUNT(*) AS n FROM wp_posts' } ) );
check( 'db-read query', Number( queried?.rows?.[ 0 ]?.n ?? 0 ) > 0 );
const writeSqlBlocked = resultOf( await toolCall( 'db-read', { operation: 'query', sql: 'DELETE FROM wp_posts' } ) );
check( 'db-read refuses writes', writeSqlBlocked?.error === 'only_read_queries_allowed' );

// 8. Blocks patterns + duplicate.
const patterns = resultOf( await toolCall( 'list-patterns', {} ) );
check( 'list-patterns shape', Array.isArray( patterns?.patterns ) && Number.isInteger( patterns.total ), String( patterns?.total ) );
const host = resultOf( await toolCall( 'create-post', { title: 'E2E Sys Blocks', content: '<!-- wp:paragraph --><p>one</p><!-- /wp:paragraph -->', status: 'publish' } ) );
const dup = resultOf( await toolCall( 'duplicate-block', { post_id: host.id, path: [ 0 ] } ) );
check( 'duplicate-block', dup?.ok === true && dup.block_summary.length === 2, JSON.stringify( dup ?? {} ).slice( 0, 120 ) );

// 9. Activate license for pro system tools (elementor extras + writes below).
setLicense( true );
have = await names();
check( 'licensed: pro sys tools appear', PRO_SYS.every( ( n ) => have.includes( n ) ), PRO_SYS.filter( ( n ) => ! have.includes( n ) ).join( ',' ) );

// 10. Elementor extras need a built page.
const built = resultOf( await toolCall( 'build-page', {
	title: 'E2E Sys Elementor',
	structure: [
		{ settings: {}, widgets: [
			{ type: 'heading', settings: { title: 'Alpha' } },
			{ type: 'text-editor', settings: { editor: '<p>Beta body.</p>' } },
		] },
	],
	status: 'publish',
} ) );
const pageId = built.post_id;
const found = resultOf( await toolCall( 'find-element', { post_id: pageId, widget_type: 'heading' } ) );
check( 'find-element by widget_type', found?.total >= 1 && found.elements[ 0 ].widgetType === 'heading' );
const foundText = resultOf( await toolCall( 'find-element', { post_id: pageId, search_text: 'Beta' } ) );
check( 'find-element by text', foundText?.elements.some( ( e ) => e.widgetType === 'text-editor' ), JSON.stringify( foundText?.elements ?? [] ).slice( 0, 100 ) );

const exported = resultOf( await toolCall( 'export-page', { post_id: pageId } ) );
check( 'export-page', ( exported?.elements ?? [] ).length === 1 && typeof exported.version === 'string' );

const tpl = resultOf( await toolCall( 'save-as-template', { post_id: pageId, title: 'E2E Saved Template' } ) );
check( 'save-as-template', tpl?.template_id > 0, JSON.stringify( tpl ?? {} ).slice( 0, 100 ) );

const target = resultOf( await toolCall( 'build-page', { title: 'E2E Sys Target', structure: [ { widgets: [ { type: 'button', settings: { text: 'X' } } ] } ] } ) );
const applied = resultOf( await toolCall( 'apply-template', { post_id: target.post_id, template_id: tpl.template_id } ) );
check( 'apply-template appends', applied?.applied_elements === 1, JSON.stringify( applied ?? {} ).slice( 0, 100 ) );

const replaced = resultOf( await toolCall( 'import-template', { post_id: target.post_id, template_json: exported.elements, replace_all: true } ) );
check( 'import-template replaces', replaced?.imported_elements === 1 );

// 11. Pro writes: users, filesystem, database, global styles.
const newUser = resultOf( await toolCall( 'user-write', { operation: 'create-user', username: 'e2esys', email: 'e2esys@example.test', role: 'author' } ) );
check( 'create-user', newUser?.ok === true && typeof newUser.password === 'string' );
const updUser = resultOf( await toolCall( 'user-write', { operation: 'update-user', id: newUser.id, display_name: 'E2E Author', role: 'editor' } ) );
check( 'update-user', updUser?.ok === true && updUser.updated.includes( 'role' ) );
const updAdmin = resultOf( await toolCall( 'user-write', { operation: 'update-user', id: 1, display_name: 'Hax' } ) );
check( 'admin edit refused', updAdmin?.error === 'admin_users_off_limits' );

// fs-write round-trip.
const wrote = resultOf( await toolCall( 'fs-write', { operation: 'write-file', path: 'uploads/wpmcp-e2e.txt', content: 'alpha beta' } ) );
check( 'fs-write file', wrote?.ok === true, JSON.stringify( wrote ?? {} ).slice( 0, 100 ) );
const edited = resultOf( await toolCall( 'fs-write', { operation: 'edit-file', path: 'uploads/wpmcp-e2e.txt', search: 'beta', replace: 'gamma' } ) );
check( 'fs-edit string', edited?.replacements === 1 );
const reread = resultOf( await toolCall( 'fs-read', { operation: 'read-file', path: 'uploads/wpmcp-e2e.txt' } ) );
check( 'fs-edit persisted', ( reread?.content ?? '' ).includes( 'gamma' ) );
const cfgWrite = resultOf( await toolCall( 'fs-write', { operation: 'write-file', path: 'somewhere/wp-config.php', content: 'x' } ) );
check( 'fs-write refuses wp-config.php anywhere', cfgWrite?.error === 'protected_file' );

// db-write with fixture table.
phpEvalFile( `
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( "CREATE TABLE {$wpdb->prefix}e2e_tmp ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, val text NULL, PRIMARY KEY (id) ) {$wpdb->get_charset_collate()};" );
` );
const ins = resultOf( await toolCall( 'db-write', { operation: 'insert-row', table: 'wp_e2e_tmp', data: { val: 'hello' } } ) );
check( 'db insert-row', ins?.inserted_id > 0, JSON.stringify( ins ?? {} ).slice( 0, 100 ) );
const updRow = resultOf( await toolCall( 'db-write', { operation: 'update-rows', table: 'wp_e2e_tmp', data: { val: 'changed' }, where: { id: String( ins.inserted_id ) } } ) );
check( 'db update-rows', updRow?.rows_updated === 1 );
const protTable = resultOf( await toolCall( 'db-write', { operation: 'insert-row', table: 'wp_options', data: { option_name: 'x', option_value: 'y' } } ) );
check( 'protected table refused', protTable?.error === 'protected_table' );
const delRows = resultOf( await toolCall( 'db-write', { operation: 'delete-rows', table: 'wp_e2e_tmp', where: { id: String( ins.inserted_id ) }, confirm: true } ) );
check( 'db delete-rows confirm', delRows?.deleted === true && delRows.rows_deleted === 1 );
phpEvalFile( `global $wpdb; $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}e2e_tmp" );` );

// Global styles.
const colorsUpd = resultOf( await toolCall( 'update-global-colors', { colors: [ { _id: 'e2ecolor', title: 'E2E Color', color: '#112233' } ] } ) );
check( 'update-global-colors', colorsUpd?.ok === true && colorsUpd.updated === 1, JSON.stringify( colorsUpd ?? {} ).slice( 0, 120 ) );
const typoUpd = resultOf( await toolCall( 'update-global-typography', { typography: [ { _id: 'e2etype', title: 'E2E Type', typography_font_family: 'Georgia' } ] } ) );
check( 'update-global-typography', typoUpd?.ok === true, JSON.stringify( typoUpd ?? {} ).slice( 0, 120 ) );

// Batch update.
const struct = resultOf( await toolCall( 'get-page-structure', { post_id: pageId } ) );
const heroWidget = struct.elements[ 0 ].children.find( ( c ) => c.widgetType === 'heading' );
const batch = resultOf( await toolCall( 'batch-update', { post_id: pageId, operations: [ { element_id: heroWidget.id, settings: { title: 'Batched!' } } ] } ) );
check( 'batch-update', ( batch?.updated_elements ?? [] ).length === 1 );

// 11. Gate closes again.
setLicense( false );
have = await names();
check( 'gate closes after removal', PRO_SYS.every( ( n ) => ! have.includes( n ) ) );

// Cleanup.
for ( const pid of [ host.id, pageId, target.post_id ] ) {
	await toolCall( 'delete-post', { id: pid, confirm: true } );
}
runCli( `wp post delete ${ tpl.template_id } --force` );
runCli( `wp user delete ${ newUser.id } --reassign=1` );
phpEvalFile( `@unlink( ABSPATH . 'uploads/wpmcp-e2e.txt' );` );

console.log( `\n${ failures } failure(s)` );
process.exit( failures > 0 ? 1 : 0 );
