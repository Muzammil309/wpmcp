<?php
/**
 * Plugin Name:       WP MCP Suite
 * Plugin URI:        https://github.com/yourname/wpmcp
 * Description:       WordPress MCP server for AI agents. SEO-first: unified SEO read/write across Yoast, Rank Math and Slim SEO, on-page SEO audits, meta tag and JSON-LD schema generation, plus content, media, settings and Elementor tools over MCP. Pro adds WooCommerce store management.
 * Version:           0.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpmcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMCP_VERSION', '0.7.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_URL', plugin_dir_url( __FILE__ ) );

require_once WPMCP_DIR . 'includes/class-wpmcp-registry.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-change-log.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-auth.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-server.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-rest.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-url-guard.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-redirects.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-oauth.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-updater.php';
require_once WPMCP_DIR . 'includes/class-wpmcp-license.php';
require_once WPMCP_DIR . 'includes/elementor/class-wpmcp-el-document.php';

function wpmcp_is_pro(): bool {
	return WPMCP_License::is_pro();
}

require_once WPMCP_DIR . 'includes/seo/interface-wpmcp-seo-adapter.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-adapter-base.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-native.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-yoast.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-rankmath.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-slimseo.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-aioseo.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-seopress.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-tsf.php';
require_once WPMCP_DIR . 'includes/seo/class-wpmcp-seo-manager.php';

require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-content.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-media.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-settings.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-seo.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-audit.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-history.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-redirects.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-snapshot.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-performance.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-security.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-blocks.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-elementor.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-elementor-extra.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-woo.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-users.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-plugins.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-menus.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-fs.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-db.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-cli.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-acf.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-a11y.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-forms.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-metabox.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-themes.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-memory.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-brandkits.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-export.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-comments.php';
require_once WPMCP_DIR . 'includes/tools/class-wpmcp-tool-revisions.php';

require_once WPMCP_DIR . 'admin/class-wpmcp-admin.php';

final class WPMCP_Plugin {

	private static ?WPMCP_Plugin $instance = null;
	public WPMCP_Registry $registry;
	public WPMCP_Change_Log $change_log;
	public WPMCP_SEO_Manager $seo;

	public static function instance(): WPMCP_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->registry   = new WPMCP_Registry();
		$this->change_log = new WPMCP_Change_Log();
		$this->seo        = new WPMCP_SEO_Manager();

		add_action( 'init', array( $this, 'register_tools' ), 5 );
		add_action( 'rest_api_init', array( new WPMCP_REST(), 'register_routes' ) );
		add_action( 'admin_menu', array( new WPMCP_Admin(), 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( new WPMCP_Admin(), 'enqueue_assets' ) );
		register_activation_hook( WPMCP_FILE, array( WPMCP_Change_Log::class, 'install' ) );
	}

	public function register_tools(): void {
		$redirects = new WPMCP_Redirects();
		add_action( 'template_redirect', array( $redirects, 'maybe_redirect' ), 1 );

		$tools = array(
			new WPMCP_Tool_Content( $this->registry, $this->change_log ),
			new WPMCP_Tool_Media( $this->registry, $this->change_log ),
			new WPMCP_Tool_Settings( $this->registry, $this->change_log ),
			new WPMCP_Tool_SEO( $this->registry, $this->change_log, $this->seo ),
			new WPMCP_Tool_Audit( $this->registry, $this->seo ),
			new WPMCP_Tool_History( $this->registry, $this->change_log ),
			new WPMCP_Tool_Redirects( $this->registry, $this->change_log, $redirects ),
			new WPMCP_Tool_Snapshot( $this->registry, $this->seo ),
			new WPMCP_Tool_Performance( $this->registry ),
			new WPMCP_Tool_Security( $this->registry ),
			new WPMCP_Tool_Blocks( $this->registry, $this->change_log ),
			new WPMCP_Tool_Elementor( $this->registry, $this->change_log ),
			new WPMCP_Tool_Elementor_Extra( $this->registry, $this->change_log ),
			new WPMCP_Tool_Woo( $this->registry, $this->change_log ),
			new WPMCP_Tool_Users( $this->registry, $this->change_log ),
			new WPMCP_Tool_Plugins( $this->registry, $this->change_log ),
			new WPMCP_Tool_Menus( $this->registry, $this->change_log ),
			new WPMCP_Tool_FS( $this->registry, $this->change_log ),
			new WPMCP_Tool_DB( $this->registry, $this->change_log ),
			new WPMCP_Tool_CLI( $this->registry, $this->change_log ),
			new WPMCP_Tool_ACF( $this->registry, $this->change_log ),
			new WPMCP_Tool_A11y( $this->registry, $this->change_log ),
			new WPMCP_Tool_Forms( $this->registry, $this->change_log ),
			new WPMCP_Tool_Metabox( $this->registry, $this->change_log ),
			new WPMCP_Tool_Themes( $this->registry, $this->change_log ),
			new WPMCP_Tool_Memory( $this->registry, $this->change_log ),
			new WPMCP_Tool_BrandKits( $this->registry, $this->change_log ),
			new WPMCP_Tool_Export( $this->registry, $this->change_log ),
			new WPMCP_Tool_Comments( $this->registry, $this->change_log ),
			new WPMCP_Tool_Revisions( $this->registry, $this->change_log ),
		);
		foreach ( $tools as $tool ) {
			$tool->register();
		}
		do_action( 'wpmcp_register_tools', $this->registry );
	}
}

WPMCP_Plugin::instance();

function wpmcp_plugin(): WPMCP_Plugin {
	return WPMCP_Plugin::instance();
}

