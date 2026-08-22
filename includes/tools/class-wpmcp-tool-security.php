<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Security {

	private WPMCP_Registry $registry;

	public function __construct( WPMCP_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'scan-security',
			array(
				'title'       => 'Scan Security',
				'description' => 'Read-only security audit: hardening checks (file editing, debug output, admin username, XML-RPC, version disclosure, HTTPS, security headers), outdated plugins/themes/core vs wordpress.org data, and a bounded scan for PHP files inside uploads. Scored 0-100 with A-F grade. No file contents are returned.',
				'category'    => 'performance',
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'scan_uploads_php' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Count .php files under wp-content/uploads (should be zero on healthy sites)' ),
					),
				),
				'handler'     => array( $this, 'scan' ),
			)
		);
	}

	public function scan( array $args ): array {
		$findings = array();
		$score    = 100;

		if ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'file_edit', 'message' => 'Plugin/theme file editing is enabled in wp-admin. Set DISALLOW_FILE_EDIT to true in wp-config.php.' );
			$score -= 8;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! is_multisite() ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'debug_output', 'message' => 'WP_DEBUG is on; ensure it is off in production (or that display_errors stays off).' );
			$score -= 3;
		}

		$admin = get_user_by( 'login', 'admin' );
		if ( $admin && user_can( $admin, 'manage_options' ) ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'admin_username', 'message' => 'A user named "admin" exists - the most-brute-forced username. Rename it or use a strong password + 2FA.' );
			$score -= 6;
		}

		if ( has_filter( 'xmlrpc_enabled', '__return_true' ) || apply_filters( 'xmlrpc_enabled', true ) ) {
			$xmlrpc_file = ABSPATH . 'xmlrpc.php';
			if ( file_exists( $xmlrpc_file ) ) {
				$findings[] = array( 'severity' => 'low', 'check' => 'xmlrpc', 'message' => 'XML-RPC enabled. Disable (add_filter xmlrpc_enabled __return_false) unless a client needs it - common brute-force vector.' );
				$score -= 4;
			}
		}

		if ( ! has_filter( 'the_generator', '__return_empty_string' ) ) {
			$findings[] = array( 'severity' => 'info', 'check' => 'version_disclosure', 'message' => 'WP version published in meta generator tag. Remove via the_generator filter to slow targeted attacks.' );
			$score -= 2;
		}

		if ( ! is_ssl() ) {
			$findings[] = array( 'severity' => 'high', 'check' => 'https', 'message' => 'Site is not served over HTTPS; logins and content travel unencrypted.' );
			$score -= 20;
		}

		$headers = $this->front_page_headers();
		foreach ( array( 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy' ) as $header ) {
			if ( empty( $headers[ strtolower( $header ) ] ) ) {
				$findings[] = array( 'severity' => 'low', 'check' => 'security_header', 'message' => sprintf( '%s header missing. Add it at the web-server level.', $header ) );
				$score -= 3;
			}
		}

		$outdated = $this->outdated_software();
		if ( $outdated['core'] ) {
			$findings[] = array( 'severity' => 'high', 'check' => 'core_update', 'message' => sprintf( 'WordPress core is out of date (%s -> %s). Update immediately.', get_bloginfo( 'version' ), $outdated['core_latest'] ) );
			$score -= 15;
		}
		if ( $outdated['plugins'] > 0 ) {
			$names = implode( ', ', array_slice( $outdated['plugin_names'], 0, 5 ) );
			$findings[] = array( 'severity' => 'high', 'check' => 'plugin_updates', 'message' => sprintf( '%d active plugin(s) have updates: %s.', $outdated['plugins'], $names ) );
			$score -= min( 15, $outdated['plugins'] * 5 );
		}
		if ( $outdated['themes'] > 0 ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'theme_updates', 'message' => sprintf( '%d theme(s) have updates.', $outdated['themes'] ) );
			$score -= min( 8, $outdated['themes'] * 4 );
		}

		$uploads_php = array();
		if ( ! empty( $args['scan_uploads_php'] ) ) {
			$uploads_php = $this->php_in_uploads();
			if ( count( $uploads_php ) > 0 ) {
				$findings[] = array( 'severity' => 'high', 'check' => 'php_in_uploads', 'message' => sprintf( '%d PHP file(s) found under wp-content/uploads - executable code must not live there. Review: %s%s', count( $uploads_php ), implode( ', ', array_slice( $uploads_php, 0, 3 ) ), count( $uploads_php ) > 3 ? '…' : '' ) );
				$score -= min( 20, count( $uploads_php ) * 10 );
			}
		}

		return array(
			'score'     => max( 0, min( 100, $score ) ),
			'grade'     => $this->grade( max( 0, $score ) ),
			'https'     => is_ssl(),
			'hardening' => array(
				'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'debug_mode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'admin_username_exists' => (bool) $admin,
				'xmlrpc_enabled'     => apply_filters( 'xmlrpc_enabled', true ),
			),
			'updates'   => $outdated,
			'uploads_php_files' => array_slice( $uploads_php, 0, 10 ),
			'headers'   => $headers,
			'findings'  => $findings,
		);
	}

	private function front_page_headers(): array {
		$headers = array();
		$response = wp_safe_remote_head( home_url( '/' ), array( 'timeout' => 6 ) );
		if ( ! is_wp_error( $response ) ) {
			$headers = array_change_key_case( (array) wp_remote_retrieve_headers( $response ), CASE_LOWER );
		}
		return $headers;
	}

	private function outdated_software(): array {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$core_outdated = false;
		$core_latest   = '';
		try {
			foreach ( (array) get_core_updates( false ) as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response && ! empty( $update->version ) ) {
					$core_outdated = true;
					$core_latest   = $update->version;
				}
			}
		} catch ( Throwable $e ) {
			$core_outdated = false;
			$core_latest   = '';
		}

		$plugin_updates = get_site_transient( 'update_plugins' );
		$active         = (array) get_option( 'active_plugins', array() );
		$stale_plugins  = array();
		foreach ( $active as $plugin_file ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$slug = dirname( $plugin_file );
			foreach ( (array) ( $plugin_updates->response ?? array() ) as $updated_file => $info ) {
				if ( $updated_file === $plugin_file || $slug === dirname( $updated_file ) ) {
					$stale_plugins[] = ( '' !== $data['Name'] ? $data['Name'] : $plugin_file );
				}
			}
		}

		$theme_updates = get_site_transient( 'update_themes' );
		$stale_themes  = 0;
		$active_theme  = wp_get_theme();
		foreach ( array( $active_theme->get_stylesheet(), $active_theme->get_template() ) as $stylesheet ) {
			if ( isset( $theme_updates->response[ $stylesheet ] ) ) {
				$stale_themes++;
			}
		}

		return array(
			'core'         => $core_outdated,
			'core_latest'  => $core_latest,
			'plugins'      => count( array_unique( $stale_plugins ) ),
			'plugin_names' => array_values( array_unique( $stale_plugins ) ),
			'themes'       => $stale_themes,
		);
	}

	private function php_in_uploads(): array {
		$found    = array();
		$base     = wp_get_upload_dir()['basedir'] ?? '';
		if ( '' === $base || ! is_dir( $base ) ) {
			return $found;
		}
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			$limit = new LimitIterator( $iterator, 0, 20000 );
			foreach ( $limit as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					$found[] = str_replace( $base, '', $file->getPathname() );
					if ( count( $found ) >= 50 ) {
						break;
					}
				}
			}
		} catch ( Throwable $e ) {
			return $found;
		}
		return $found;
	}

	private function grade( int $score ): string {
		return match ( true ) {
			$score >= 90 => 'A',
			$score >= 80 => 'B',
			$score >= 70 => 'C',
			$score >= 60 => 'D',
			default      => 'F',
		};
	}
}
