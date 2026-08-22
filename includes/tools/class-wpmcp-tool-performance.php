<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Performance {

	private WPMCP_Registry $registry;

	public function __construct( WPMCP_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'analyze-performance',
			array(
				'title'       => 'Analyze Performance',
				'description' => 'Read-only server and WordPress performance audit: PHP config, database size, autoloaded-options weight, post revisions, cron backlog, object cache, OPcache, plugin count. Scored 0-100 with A-F grade and ranked recommendations.',
				'category'    => 'performance',
				'capability'  => 'manage_options',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => array( $this, 'analyze' ),
			)
		);
	}

	public function analyze( array $args ): array {
		global $wpdb;
		$findings = array();
		$score    = 100;

		$php_ini = ini_get_all();
		$memory  = (string) ( $php_ini['memory_limit']['local_value'] ?? ini_get( 'memory_limit' ) );
		$max_exec = (int) ( $php_ini['max_execution_time']['local_value'] ?? ini_get( 'max_execution_time' ) );

		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'php_version', 'message' => sprintf( 'PHP %s is below 8.1; upgrade for speed + security.', PHP_VERSION ) );
			$score -= 10;
		}
		if ( $this->to_bytes( $memory ) < 268435456 ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'php_memory', 'message' => sprintf( 'WP_MEMORY_LIMIT is %s; 256M+ recommended.', $memory ) );
			$score -= 3;
		}

		$db_size      = 0;
		$table_count  = 0;
		$row          = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) AS size, COUNT(*) AS tables FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			),
			ARRAY_A
		);
		if ( $row ) {
			$db_size     = (int) $row['size'];
			$table_count = (int) $row['tables'];
		}

		$autoload = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on')"
		, ARRAY_A );
		$autoload_kb = (int) $autoload['bytes'] / 1024;
		if ( $autoload_kb > 800 ) {
			$findings[] = array( 'severity' => 'high', 'check' => 'autoloaded_options', 'message' => sprintf( '%d autoloaded options totalling %.0f KB load on every request. Audit with: SELECT option_name, LENGTH(option_value) FROM wp_options WHERE autoload IN (%s) ORDER BY 2 DESC LIMIT 20;', (int) $autoload['cnt'], $autoload_kb, "'yes','on'" ) );
			$score -= 15;
		} elseif ( $autoload_kb > 400 ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'autoloaded_options', 'message' => sprintf( 'Autoloaded options are %.0f KB (aim < 400).', $autoload_kb ) );
			$score -= 7;
		}

		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		if ( $revisions > 500 ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'revisions', 'message' => sprintf( '%d post revisions bloat the posts table. Consider WP_POST_REVISIONS limit.', $revisions ) );
			$score -= 5;
		}

		$cron_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout%' AND option_value < UNIX_TIMESTAMP()" );
		$cron_batch = wp_next_scheduled( 'wp_version_check' );
		if ( false === $cron_batch ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'cron', 'message' => 'WP-Cron core events unscheduled; cron may be broken.' );
			$score -= 5;
		}

		$expired_transients = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' AND option_name NOT LIKE '\_transient\_timeout%'" );

		$ext_cache   = wp_using_ext_object_cache();
		if ( ! $ext_cache ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'object_cache', 'message' => 'No persistent object cache. Install Redis/Memcached integration for multi-second wins on busy sites.' );
			$score -= 8;
		}

		$opcache = function_exists( 'opcache_get_status' );
		if ( ! $opcache ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'opcache', 'message' => 'PHP OPcache not detected.' );
			$score -= 4;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( count( $active_plugins ) > 30 ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'plugin_count', 'message' => sprintf( '%d active plugins; each adds load. Deactivate what you do not use.', count( $active_plugins ) ) );
			$score -= 4;
		}

		$pingbacks = get_option( 'default_ping_status' ) === 'open';
		if ( $pingbacks ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'xmlrpc_pingback', 'message' => 'Pingbacks open by default; close unless needed (abuse vector).' );
			$score -= 2;
		}

		return array(
			'score'    => max( 0, min( 100, $score ) ),
			'grade'    => $this->grade( max( 0, $score ) ),
			'server'   => array(
				'php_version'         => PHP_VERSION,
				'memory_limit'        => $memory,
				'max_execution_time'  => $max_exec,
				'opcache'             => $opcache,
				'object_cache'        => $ext_cache ? 'persistent' : 'none',
			),
			'database' => array(
				'size_mb'            => round( $db_size / 1048576, 1 ),
				'tables'             => $table_count,
				'autoloaded_options' => array(
					'count'   => (int) $autoload['cnt'],
					'bytes'   => (int) $autoload['bytes'],
				),
				'post_revisions'     => $revisions,
				'expired_cron_hooks_sample' => $cron_count,
				'transients_approx'  => $expired_transients,
			),
			'plugins'  => array( 'active' => count( $active_plugins ) ),
			'wordpress'=> array(
				'version'     => get_bloginfo( 'version' ),
				'debug_mode'  => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'cron_ok'     => false !== $cron_batch,
			),
			'findings' => $findings,
		);
	}

	private function to_bytes( string $value ): int {
		$value = trim( $value );
		$unit  = strtolower( substr( $value, -1 ) );
		$num   = (int) $value;
		return match ( $unit ) {
			'g'     => $num * 1073741824,
			'm'     => $num * 1048576,
			'k'     => $num * 1024,
			default => (int) $value,
		};
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
