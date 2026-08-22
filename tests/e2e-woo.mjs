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
	const res = spawnSync( `npx @wordpress/env run cli ${ cmd }`, {
		encoding: 'utf8',
		cwd: CWD,
		shell: true,
		input,
	} );
	return { ok: 0 === res.status, out: String( res.stdout ?? '' ), err: String( res.stderr ?? '' ) };
}

function wpCli( cmd, input ) {
	const res = runCli( cmd, input );
	if ( ! res.ok ) {
		throw new Error( `wp-cli failed: ${ cmd }\n${ res.err }` );
	}
}

function wpCliInt( cmd, input ) {
	const res = runCli( cmd, input );
	const matches = [ ...res.out.matchAll( /\b(\d+)\b/g ) ];
	return matches.length ? parseInt( matches[ matches.length - 1 ][ 1 ], 10 ) : 0;
}

const CONTAINER_PLUGIN = '/var/www/html/wp-content/plugins/wpmcp-dev/tests';
function setLicense( active ) {
	const file = join( CWD, 'tests', '_license_fixture.php' );
	writeFileSync(
		file,
		`<?php\n${ active
			? `update_option( 'wpmcp_license', array( 'status' => 'active', 'expires_at' => 4102444800, 'plan' => 'pro' ) );`
			: `delete_option( 'wpmcp_license' );` }\n`
	);
	wpCli( `wp eval-file ${ CONTAINER_PLUGIN }/_license_fixture.php` );
	rmSync( file, { force: true } );
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

let idCounter = 5000;
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

async function toolNames() {
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

const PRO_TOOLS = [ 'list-products', 'get-product', 'update-product', 'list-orders', 'get-order', 'update-order' ];
const createdProductIds = [];
let createdOrderId = 0;

// 0. Ensure no license.
setLicense( false );

// 1. Unlicensed: pro tools invisible, free teaser visible.
let names = await toolNames();
check( 'gate: pro woo tools absent from tools/list', PRO_TOOLS.every( ( n ) => ! names.includes( n ) ), names.filter( ( n ) => n.startsWith( 'woo' ) || PRO_TOOLS.includes( n ) ).join( ',' ) );
check( 'gate: woo-status teaser present', names.includes( 'woo-status' ) );

const gatedRpc = await toolCall( 'list-products', {} );
check( 'gate: calling pro tool -> unknown_tool', gatedRpc?.error?.code === -32602 && /Unknown tool/.test( gatedRpc?.error?.message ?? '' ), JSON.stringify( gatedRpc?.error ?? {} ).slice( 0, 100 ) );

const st = resultOf( await toolCall( 'woo-status' ) );
check( 'woo-status detects woocommerce', st?.woocommerce === true && typeof st?.version === 'string', JSON.stringify( st ?? {} ).slice( 0, 120 ) );
check( 'woo-status reports counts', Number.isInteger( st?.product_count ) && Number.isInteger( st?.order_count ), JSON.stringify( { p: st?.product_count, o: st?.order_count } ) );
check( 'woo-status pro_active false', st?.pro_active === false );

// 2. Activate license (option injection; mirrors server-side activation payload).
setLicense( true );

names = await toolNames();
check( 'licensed: all 6 pro woo tools registered', PRO_TOOLS.every( ( n ) => names.includes( n ) ), PRO_TOOLS.filter( ( n ) => ! names.includes( n ) ).join( ',' ) );

const st2 = resultOf( await toolCall( 'woo-status' ) );
check( 'licensed: pro_active true', st2?.pro_active === true );

// 3. Products.
const p1 = wpCliInt( 'wp wc product create --user=admin --name="E2E Widget" --regular_price=19.99 --type=simple --porcelain' );
const p2 = wpCliInt( 'wp wc product create --user=admin --name="E2E Gadget" --regular_price=49.50 --type=simple --porcelain' );
createdProductIds.push( p1, p2 );
check( 'fixtures: products created', Number.isInteger( p1 ) && Number.isInteger( p2 ), `${ p1 }, ${ p2 }` );

const listed = resultOf( await toolCall( 'list-products', { search: 'E2E Widget' } ) );
check( 'list-products search finds fixture', ( listed?.products ?? [] ).some( ( p ) => p.id === p1 && parseFloat( p.price ) === 19.99 ), JSON.stringify( listed ?? {} ).slice( 0, 160 ) );

const got = resultOf( await toolCall( 'get-product', { id: p1 } ) );
check( 'get-product pricing fields', got?.id === p1 && got?.regular_price === '19.99' && Array.isArray( got.categories ), JSON.stringify( got ?? {} ).slice( 0, 140 ) );

const upd = resultOf( await toolCall( 'update-product', { id: p1, regular_price: '24.99', stock_quantity: 7, status: 'publish' } ) );
check( 'update-product applies', upd?.ok === true && upd.updated.includes( 'regular_price' ) && upd.updated.includes( 'stock_quantity' ), JSON.stringify( upd ?? {} ) );

const after = resultOf( await toolCall( 'get-product', { id: p1 } ) );
check( 'update persisted', parseFloat( after?.price ) === 24.99 && after.stock_quantity === 7 && after.manage_stock === true, JSON.stringify( { price: after?.price, stock: after?.stock_quantity } ) );

const badUpd = resultOf( await toolCall( 'update-product', { id: 99999999, regular_price: '1' } ) );
check( 'update missing product -> error', badUpd?.error === 'product_not_found_or_forbidden' );

// 4. Rollback product change via ledger.
const changes = resultOf( await toolCall( 'list-changes', { domain: 'woocommerce', per_page: 10 } ) );
const prodChange = ( changes?.changes ?? [] ).find( ( c ) => c.action === 'update-product' && c.target_id === p1 );
if ( prodChange ) {
	const rolled = resultOf( await toolCall( 'rollback-change', { id: prodChange.id, confirm: true } ) );
	check( 'rollback-change product', rolled?.rolled_back === true, JSON.stringify( rolled ?? {} ).slice( 0, 120 ) );
	const reverted = resultOf( await toolCall( 'get-product', { id: p1 } ) );
	check( 'product reverted to 19.99', parseFloat( reverted?.price ) === 19.99, String( reverted?.price ) );
} else {
	check( 'found product change to roll back', false );
}

// 5. Orders.
function makeOrder() {
	const file = join( CWD, 'tests', '_order_fixture.php' );
	writeFileSync(
		file,
		`<?php\n` +
		`$order = wc_create_order( array( 'status' => 'processing', 'customer_id' => 1 ) );\n` +
		`$order->set_billing_first_name( 'E2E' );\n` +
		`$order->set_billing_last_name( 'Tester' );\n` +
		`$order->set_billing_email( 'e2e@example.test' );\n` +
		`$order->calculate_totals();\n` +
		`echo $order->get_id();\n`
	);
	const id = wpCliInt( `wp eval-file ${ CONTAINER_PLUGIN }/_order_fixture.php` );
	rmSync( file, { force: true } );
	return id;
}
createdOrderId = makeOrder();
check( 'fixture: order created', createdOrderId > 0, String( createdOrderId ) );

const orders = resultOf( await toolCall( 'list-orders', { status: 'processing' } ) );
const orderRow = ( orders?.orders ?? [] ).find( ( o ) => o.customer === 'E2E Tester' );
createdOrderId = orderRow?.id ?? 0;
check( 'list-orders finds fixture', Number.isInteger( createdOrderId ) && createdOrderId > 0, JSON.stringify( orders ?? {} ).slice( 0, 140 ) );

if ( createdOrderId ) {
	const ord = resultOf( await toolCall( 'get-order', { id: createdOrderId } ) );
	check( 'get-order detail', ord?.id === createdOrderId && ord.status === 'processing' && Array.isArray( ord.line_items ), ord?.status );

	const ordUpd = resultOf( await toolCall( 'update-order', { id: createdOrderId, status: 'completed', customer_note: 'E2E note' } ) );
	check( 'update-order status', ordUpd?.ok === true && ordUpd.status === 'completed', JSON.stringify( ordUpd ?? {} ) );

	const ordChanges = resultOf( await toolCall( 'list-changes', { domain: 'woocommerce', per_page: 10 } ) );
	const ordChange = ( ordChanges?.changes ?? [] ).find( ( c ) => c.action === 'update-order' && c.target_id === createdOrderId );
	if ( ordChange ) {
		const ordRoll = resultOf( await toolCall( 'rollback-change', { id: ordChange.id, confirm: true } ) );
		check( 'rollback-change order', ordRoll?.rolled_back === true, JSON.stringify( ordRoll ?? {} ).slice( 0, 120 ) );
		const ordAfter = resultOf( await toolCall( 'get-order', { id: createdOrderId } ) );
		check( 'order status reverted to processing', ordAfter?.status === 'processing', ordAfter?.status );
	} else {
		check( 'found order change to roll back', false );
	}
}

// 6. Deactivate license -> pro tools vanish again.
setLicense( false );
names = await toolNames();
check( 'gate closes after license removal', PRO_TOOLS.every( ( n ) => ! names.includes( n ) ) );

// Cleanup.
for ( const pid of createdProductIds ) {
	if ( pid ) wpCli( `wp wc product delete ${ pid } --user=admin --force` );
}
if ( createdOrderId ) {
	runCli( `wp wc shop_order delete ${ createdOrderId } --user=admin --force` );
}

console.log( `\n${ failures } failure(s)` );
process.exit( failures > 0 ? 1 : 0 );

