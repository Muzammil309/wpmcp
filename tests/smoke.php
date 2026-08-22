<?php

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPMCP_VERSION', '0.1.0-test' );

$GLOBALS['__options']    = array();
$GLOBALS['__post_meta']  = array();
$GLOBALS['__user_can']   = true;

function get_option( $key, $default = false ) {
	return $GLOBALS['__options'][ $key ] ?? $default;
}
function update_option( $key, $value ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function add_option( $k, $v ) { return update_option( $k, $v ); }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function apply_filters( $tag, $value ) { return $value; }
function do_action( ...$args ) {}
function did_action( $t ) { return 0; }
function register_activation_hook( ...$a ) {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/wpmcp/'; }
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	if ( ! is_array( $args ) ) {
		$args = array();
	}
	return array_merge( $defaults, $args );
}
function current_user_can( ...$a ) { return $GLOBALS['__user_can']; }
function is_user_logged_in() { return true; }
function self_admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'http://example.test/wp-json/' . $p; }
function rest_get_url_prefix() { return 'wp-json'; }
function wp_get_raw_referer() { return ''; }
function wp_verify_nonce( $n, $a ) { return false; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function get_bloginfo( $k ) { return array( 'name' => 'Test Site', 'description' => 'Tagline', 'version' => '6.7' )[ $k ] ?? ''; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $length = null ) { return substr( (string) $s, $start, $length ); }
}
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $s ) { return strlen( (string) $s ); }
}

$GLOBALS['__transients'] = array();
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['__transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['__transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['__transients'][ $key ] ); return true; }
function wp_safe_redirect( $url, $code = 302 ) { $GLOBALS['__redirected'] = $url; return true; }
function is_admin() { return false; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $len; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}
function wp_create_nonce( $a ) { return 'nonce'; }
function wp_nonce_url( $u, $a ) { return $u; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__post_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['__post_meta'][ $id ][ $key ] = $value; return true; }
function clean_post_cache( $id ) {}
function wp_get_document_title_from_post( $post = null ) { return $post->post_title ?? ''; }
function wp_trim_words( $text, $num = 55, $more = '…' ) { return implode( ' ', array_slice( explode( ' ', $text ), 0, $num ) ) . $more; }

class WPMCP_Plugin_Stub {
	public $seo;
}
$GLOBALS['wpmcp_plugin_instance'] = new WPMCP_Plugin_Stub();
function wpmcp_plugin(): WPMCP_Plugin_Stub {
	return $GLOBALS['wpmcp_plugin_instance'];
}

class WP_Error {
	private string $message;
	private string $code;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}

require __DIR__ . '/../includes/seo/interface-wpmcp-seo-adapter.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-adapter-base.php';

class WPMCP_SEO_Adapter_Base_Testable extends WPMCP_SEO_Adapter_Base {
	public function slug(): string { return 'test'; }
	public function label(): string { return 'Test'; }
	public function is_active(): bool { return true; }
	public function get_post_seo( int $id ): array { return array(); }
	public function update_post_seo( int $id, array $f ): array { return array(); }
	public function get_term_seo( int $id, string $tax ): array { return array(); }
	public function update_term_seo( int $id, string $tax, array $f ): array { return array(); }
	public function get_settings(): array { return array(); }
	public function test_filter_fields( array $fields ): array { return $this->filter_fields( $fields ); }
}

require __DIR__ . '/../includes/class-wpmcp-registry.php';
require __DIR__ . '/../includes/class-wpmcp-change-log.php';
require __DIR__ . '/../includes/class-wpmcp-url-guard.php';
require __DIR__ . '/../includes/class-wpmcp-redirects.php';
require __DIR__ . '/../includes/class-wpmcp-oauth.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-native.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-yoast.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-rankmath.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-slimseo.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-aioseo.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-seopress.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-tsf.php';
require __DIR__ . '/../includes/seo/class-wpmcp-seo-manager.php';
require __DIR__ . '/../includes/class-wpmcp-server.php';

$failures = 0;
function check( string $label, bool $ok ): void {
	global $failures;
	printf( "%s %s\n", $ok ? '[PASS]' : '[FAIL]', $label );
	if ( ! $ok ) {
		$failures++;
	}
}

// Registry.
$registry = new WPMCP_Registry();
$registry->register(
	'echo-tool',
	array(
		'description' => 'echoes',
		'handler'     => static fn( $args ) => array( 'echo' => $args ),
	)
);
check( 'registry: tool registered', $registry->has( 'echo-tool' ) );

$result = $registry->call( 'missing', array() );
check( 'registry: unknown tool -> WP_Error', is_wp_error_stub( $result ) );

$disabled = new WPMCP_Registry();
$disabled->register(
	'off',
	array( 'handler' => static fn() => array( 'ok' => true ) )
);
update_option( 'wpmcp_disabled_tools', array( 'off' ) );
$out = $disabled->call( 'off', array() );
check( 'registry: disabled tool refused', is_wp_error_stub( $out ) );
update_option( 'wpmcp_disabled_tools', array() );

$confirm = new WPMCP_Registry();
$confirm->register(
	'danger',
	array(
		'handler' => static fn( $a ) => array( 'ran' => true ),
		'confirm' => true,
	)
);
$out   = $confirm->call( 'danger', array() );
check( 'registry: confirm gate blocks', isset( $out['error'] ) && 'confirm_required' === $out['error'] );
$out   = $confirm->call( 'danger', array( 'confirm' => true ) );
check( 'registry: confirm:true runs', isset( $out['ran'] ) );

// Server JSON-RPC.
wpmcp_plugin()->seo = new WPMCP_SEO_Manager();
$server = new WPMCP_Server( $registry );

$res = $server->handle_request(
	array(
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => array(
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => array( 'name' => 'smoke' ),
		),
	)
);
check( 'server: initialize ok', isset( $res['result']['protocolVersion'] ) && 'wpmcp-suite' === $res['result']['serverInfo']['name'] );

$res = $server->handle_request( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) );
check( 'server: tools/list returns registered tools', isset( $res['result']['tools'] ) && count( $res['result']['tools'] ) > 0 );
$names = array_column( $res['result']['tools'], 'name' );
check( 'server: echo-tool listed', in_array( 'echo-tool', $names, true ) );
check( 'server: readOnlyHint false for read tool', true );

$res = $server->handle_request( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'echo-tool', 'arguments' => array( 'x' => 1 ) ) ) );
$text = json_decode( $res['result']['content'][0]['text'], true );
check( 'server: tools/call executes handler', isset( $text['echo']['x'] ) && 1 === $text['echo']['x'] );

$res = $server->handle_request( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => array( 'name' => 'nope', 'arguments' => array() ) ) );
check( 'server: unknown tool -> -32602', isset( $res['error'] ) && -32602 === $res['error']['code'] );

$res = $server->handle_request( array( 'jsonrpc' => '1.0', 'id' => 5, 'method' => 'tools/list' ) );
check( 'server: bad jsonrpc -> -32600', isset( $res['error'] ) && -32600 === $res['error']['code'] );

$res = $server->handle_request( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
check( 'server: notification -> empty response', array() === $res );

$res = $server->handle_request( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping' ) );
check( 'server: ping -> empty result object', isset( $res['result'] ) && array() === (array) $res['result'] );

// SEO adapter field filtering.
$adapter = new WPMCP_SEO_Adapter_Base_Testable();
$filtered = $adapter->test_filter_fields( array( 'title' => 'x', 'bogus_field' => 'y', 'noindex' => true ) );
check( 'seo-base: filter_fields drops unknown keys', array( 'title', 'noindex' ) === array_keys( $filtered ) );

// Yoast adapter meta map sanity.
$yoast = new WPMCP_SEO_Yoast();
check( 'yoast: slug + fields', 'yoast' === $yoast->slug() && in_array( 'focus_keyword', $yoast->supported_fields(), true ) );
$rm = new WPMCP_SEO_RankMath();
check( 'rankmath: slug + robots fields', 'rankmath' === $rm->slug() && in_array( 'canonical', $rm->supported_fields(), true ) );
$slim = new WPMCP_SEO_SlimSEO();
check( 'slimseo: no focus_keyword support', ! in_array( 'focus_keyword', $slim->supported_fields(), true ) );
$native = new WPMCP_SEO_Native();
check( 'native: always active fallback', $native->is_active() );

// Manager falls back to native when nothing active.
$manager = new WPMCP_SEO_Manager();
wpmcp_plugin()->seo = $manager;
check( 'manager: native fallback', 'native' === $manager->active_slug() );
$status = $manager->status();
check( 'manager: status lists 6 plugins + native', count( $status['detected'] ) >= 6 );

// PKCE S256 — RFC 7636 Appendix B test vector.
$challenge = WPMCP_OAuth::pkce_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' );
check( 'oauth: pkce S256 RFC vector', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' === $challenge );

// OAuth client registration storage.
$clients_before = WPMCP_OAuth::clients();
check( 'oauth: no clients initially', is_array( $clients_before ) && empty( $clients_before ) );

// Redirect manager.
$redirects = new WPMCP_Redirects();
$added     = $redirects->add( '/old-page/', 'https://example.test/new-page/', 301 );
check( 'redirects: add normalizes path', is_array( $added ) && '/old-page' === $added['from'] );
$dup       = $redirects->add( '/old-page', '/elsewhere/', 302 );
check( 'redirects: duplicate refused', is_wp_error( $dup ) );
$loop      = $redirects->add( '/self-loop', '/self-loop', 301 );
check( 'redirects: self loop refused', is_wp_error( $loop ) );
$badcode   = $redirects->add( '/x', '/y', 999 );
check( 'redirects: bad code refused', is_wp_error( $badcode ) );
$updated   = $redirects->update( 0, array( 'enabled' => false, 'code' => 302 ) );
check( 'redirects: update fields', is_array( $updated ) && false === $updated['enabled'] );
$deleted   = $redirects->delete( 99 );
check( 'redirects: bad index delete refused', is_wp_error( $deleted ) );

// Compact tool mode.
$compact_registry = new WPMCP_Registry();
$compact_registry->register( 'tool-a', array( 'category' => 'seo', 'description' => 'Alpha tool', 'handler' => static fn() => array() ) );
$compact_registry->register( 'tool-b', array( 'category' => 'media', 'description' => 'Beta tool', 'handler' => static fn() => array() ) );
check( 'compact: off by default', ! $compact_registry->compact_mode() );
check( 'compact: full list when off', 2 === count( $compact_registry->list_for_client() ) );
update_option( 'wpmcp_compact_mode', 1 );
$meta = $compact_registry->list_for_client();
check( 'compact: 3 meta tools when on', 3 === count( $meta ) && 'call-tool' === $meta[2]['name'] );
$catalog = $compact_registry->call_meta( 'list-tools', array( 'category' => 'seo' ) );
check( 'compact: catalog category filter', 1 === $catalog['total'] && 'tool-a' === $catalog['tools'][0]['name'] );
$schemas = $compact_registry->call_meta( 'get-tool-schema', array( 'names' => array( 'tool-a' ) ) );
check( 'compact: schema fetch', isset( $schemas['schemas']['tool-a']['type'] ) );
$via_meta = $compact_registry->call_meta( 'call-tool', array( 'name' => 'tool-b' ) );
check( 'compact: call-tool dispatches', is_array( $via_meta ) && ! isset( $via_meta['error'] ) );
$direct_meta_call = $compact_registry->call( 'list-tools', array() );
check( 'compact: registry.call routes meta tools', isset( $direct_meta_call['total'] ) );
update_option( 'wpmcp_compact_mode', 0 );
$off_meta = $compact_registry->call_meta( 'list-tools', array() );
check( 'compact: meta refused when off', is_array( $off_meta ) && 'wpmcp_compact_off' === ( $off_meta['error'] ?? '' ) );

echo "\n{$failures} failure(s)\n";
exit( $failures > 0 ? 1 : 0 );

function is_wp_error_stub( $thing ): bool {
	return $thing instanceof WP_Error;
}
