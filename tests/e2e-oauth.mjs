import { request } from 'node:http';
import { createHash, randomBytes } from 'node:crypto';

const BASE = process.env.WPMCP_BASE || 'http://localhost:8888';
const USER = 'admin';
const BASIC_PASS = process.env.WPMCP_PASS;

if ( ! BASIC_PASS ) {
	console.error( 'Set WPMCP_PASS.' );
	process.exit( 2 );
}

let failures = 0;
function check( label, ok, extra = '' ) {
	console.log( `${ ok ? '[PASS]' : '[FAIL]' } ${ label }${ extra ? ' :: ' + extra : '' }` );
	if ( ! ok ) failures++;
}

function http( method, path, body, headers = {} ) {
	return new Promise( ( resolve, reject ) => {
		const payload = body !== undefined ? JSON.stringify( body ) : null;
		const opts = {
			host: new URL( BASE ).hostname,
			port: new URL( BASE ).port || 80,
			path,
			method,
			headers: { ...headers },
		};
		if ( payload ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.headers[ 'Content-Length' ] = Buffer.byteLength( payload );
		}
		const req = request( { ...opts, agent: false }, ( res ) => {
			let data = '';
			res.on( 'data', ( c ) => ( data += c ) );
			res.on( 'end', () => resolve( { status: res.statusCode, location: res.headers.location, json: safeParse( data ), raw: data } ) );
		} );
		req.on( 'error', () => resolve( null ) );
		req.setTimeout( 30000, () => req.destroy() );
		if ( payload ) req.write( payload );
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

// 1. Discovery documents.
const serverMeta = await http( 'GET', '/.well-known/oauth-authorization-server' );
check( 'discovery: authorization-server metadata', serverMeta.status === 200 && serverMeta.json?.issuer === BASE + '/', JSON.stringify( serverMeta.json ?? {} ).slice( 0, 140 ) );
check( 'discovery: S256 supported', serverMeta.json?.code_challenge_methods_supported?.includes( 'S256' ) );

const resourceMeta = await http( 'GET', '/.well-known/oauth-protected-resource' );
check( 'discovery: protected-resource metadata', resourceMeta.status === 200 && Array.isArray( resourceMeta.json?.authorization_servers ) );

// 2. Dynamic client registration.
const reg = await http( 'POST', '/wp-json/wpmcp/v1/oauth/register', {
	client_name: 'E2E OAuth Client',
	redirect_uris: [ 'http://localhost:9221/callback' ],
} );
check( 'register: 201 + client_id', reg.status === 201 && typeof reg.json?.client_id === 'string', JSON.stringify( reg.json ?? {} ).slice( 0, 120 ) );
const clientId = reg.json.client_id;

const badReg = await http( 'POST', '/wp-json/wpmcp/v1/oauth/register', { client_name: 'x', redirect_uris: [ 'http://evil.example/cb' ] } );
check( 'register: non-local http rejected', badReg.status === 400 );

// 3. Authorization code â€” minted server-side because the consent screen needs a browser session.
const verifier = randomBytes( 48 ).toString( 'base64url' );
const challenge = createHash( 'sha256' ).update( verifier ).digest( 'base64url' );
const state = randomBytes( 8 ).toString( 'hex' );
const code = 'e2e_' + randomBytes( 24 ).toString( 'hex' );
import { execSync } from 'node:child_process';
const mintPhp = `<?php
\$code = '${ code }';
set_transient( WPMCP_OAuth::CODE_PREFIX . hash( 'sha256', \$code ), array(
	'user_id' => 1,
	'client_id' => '${ clientId }',
	'redirect_uri' => 'http://localhost:9221/callback',
	'challenge' => '${ challenge }',
	'created_at' => time(),
), 600 );
echo 'ok';`;
execSync( 'npx @wordpress/env run cli sh -c "cat > /var/www/html/wp-content/plugins/wpmcp-dev/mint-code.php"' , { input: mintPhp, stdio: [ 'pipe', 'ignore', 'ignore' ] } );
execSync( 'npx @wordpress/env run cli wp eval-file /var/www/html/wp-content/plugins/wpmcp-dev/mint-code.php', { stdio: 'ignore' } );

// 4. Token exchange with PKCE.
const tokenRes = await http( 'POST', '/wp-json/wpmcp/v1/oauth/token', {
	grant_type: 'authorization_code',
	code,
	client_id: clientId,
	redirect_uri: 'http://localhost:9221/callback',
	code_verifier: verifier,
} );
check( 'token: exchange ok', tokenRes.status === 200 && typeof tokenRes.json?.access_token === 'string', JSON.stringify( tokenRes.json ?? {} ).slice( 0, 100 ) );
const accessToken = tokenRes.json.access_token;
const refreshToken = tokenRes.json.refresh_token;
check( 'token: expiry is 3600', tokenRes.json.expires_in === 3600 );

// 5. Code is single-use.
const replay = await http( 'POST', '/wp-json/wpmcp/v1/oauth/token', {
	grant_type: 'authorization_code', code, client_id: clientId,
	redirect_uri: 'http://localhost:9221/callback', code_verifier: verifier,
} );
check( 'token: code replay rejected', replay.status === 400 );

// 6. Bearer auth works against the MCP endpoint.
const bearerList = await http( 'POST', '/wp-json/wpmcp/v1/mcp', { jsonrpc: '2.0', id: 1, method: 'tools/list' }, { Authorization: `Bearer ${ accessToken }` } );
check( 'bearer: tools/list authorized', bearerList.status === 200 && Array.isArray( bearerList.json?.result?.tools ), `status ${ bearerList.status }` );

const bearerCall = await http( 'POST', '/wp-json/wpmcp/v1/mcp', { jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'seo-read', arguments: { operation: 'get-status' } } }, { Authorization: `Bearer ${ accessToken }` } );
check( 'bearer: tools/call executes', bearerCall.status === 200 && !!bearerCall.json?.result );

// 7. Bad PKCE verifier rejected.
const code2 = 'e2e2_' + randomBytes( 24 ).toString( 'hex' );
const challenge2 = createHash( 'sha256' ).update( 'wrong-verifier-value-wrong-verifier-value' ).digest( 'base64url' );
execSync( `npx @wordpress/env run cli wp eval "set_transient(WPMCP_OAuth::CODE_PREFIX.hash('sha256','${code2}'),array('user_id'=>1,'client_id'=>'${clientId}','redirect_uri'=>'http://localhost:9221/callback','challenge'=>'${challenge2}','created_at'=>time()),600);"`, { stdio: 'ignore' } );
const badPkce = await http( 'POST', '/wp-json/wpmcp/v1/oauth/token', {
	grant_type: 'authorization_code', code: code2, client_id: clientId,
	redirect_uri: 'http://localhost:9221/callback', code_verifier: 'totally-invalid-verifier-totally-invalid',
} );
check( 'token: wrong PKCE verifier rejected', badPkce.status === 400 );

// 8. Refresh grant rotates tokens.
const refreshRes = await http( 'POST', '/wp-json/wpmcp/v1/oauth/token', { grant_type: 'refresh_token', refresh_token: refreshToken } );
check( 'refresh: rotation ok', refreshRes.status === 200 && refreshRes.json?.access_token && refreshRes.json.refresh_token !== refreshToken );
const oldRefresh = await http( 'POST', '/wp-json/wpmcp/v1/oauth/token', { grant_type: 'refresh_token', refresh_token: refreshToken } );
check( 'refresh: old token invalidated', oldRefresh.status === 400 );

// 9. Revoke kills access.
await http( 'POST', '/wp-json/wpmcp/v1/oauth/revoke', { token: refreshRes.json.access_token } );
const afterRevoke = await http( 'POST', '/wp-json/wpmcp/v1/mcp', { jsonrpc: '2.0', id: 3, method: 'tools/list' }, { Authorization: `Bearer ${ refreshRes.json.access_token }` } );
check( 'revoke: bearer rejected after revoke', afterRevoke.status === 401 || !afterRevoke.json?.result, `status ${ afterRevoke.status }` );

console.log( `\n${ failures } failure(s)` );
process.exit( failures > 0 ? 1 : 0 );
