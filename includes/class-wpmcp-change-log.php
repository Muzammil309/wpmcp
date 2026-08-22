<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Change_Log {

	const TABLE = 'wpmcp_change_log';
	const CAP   = 2000;

	public static function install(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			domain VARCHAR(32) NOT NULL,
			action VARCHAR(64) NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_label VARCHAR(191) NOT NULL DEFAULT '',
			summary TEXT NOT NULL,
			before_image LONGTEXT NULL,
			reversible TINYINT(1) NOT NULL DEFAULT 0,
			rolled_back TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY domain_idx (domain),
			KEY created_idx (created_at)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function record( string $domain, string $action, int $target_id, string $target_label, string $summary, $before_image = null, bool $reversible = false ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->insert(
			$table,
			array(
				'user_id'       => get_current_user_id(),
				'created_at'    => current_time( 'mysql', true ),
				'domain'        => $domain,
				'action'        => $action,
				'target_id'     => $target_id,
				'target_label'  => mb_substr( $target_label, 0, 190 ),
				'summary'       => $summary,
				'before_image'  => null !== $before_image ? wp_json_encode( $before_image ) : null,
				'reversible'    => $reversible ? 1 : 0,
				'rolled_back'   => 0,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
		);
		$this->prune();
		return (int) $wpdb->insert_id;
	}

	private function prune(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > self::CAP ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id ASC LIMIT %d ) AS old )",
					$count - self::CAP
				)
			);
		}
	}

	public function list_changes( int $per_page = 20, int $page = 1 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$per_page = min( 100, max( 1, $per_page ) );
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	private function hydrate( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'user_id'     => (int) $row['user_id'],
			'created_at'  => $row['created_at'],
			'domain'      => $row['domain'],
			'action'      => $row['action'],
			'target_id'   => (int) $row['target_id'],
			'target'      => $row['target_label'],
			'summary'     => $row['summary'],
			'reversible'  => (bool) $row['reversible'],
			'rolled_back' => (bool) $row['rolled_back'],
		);
	}

	public function get_change( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$change              = $this->hydrate( $row );
		$change['before_image'] = json_decode( (string) $row['before_image'], true );
		return $change;
	}

	public function mark_rolled_back( int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->update( $table, array( 'rolled_back' => 1 ), array( 'id' => $id ) );
	}
}
