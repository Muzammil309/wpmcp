<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_DB {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	private const PROTECTED_TABLES = array( 'users', 'usermeta', 'options' );

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		global $wpdb;

		$this->registry->register(
			'db-read',
			array(
				'title'       => 'Database Read',
				'description' => 'Read-only database access. Operations: list-tables (sizes), describe-table (columns/keys), query (SELECT/SHOW/DESCRIBE/EXPLAIN only, results capped).',
				'category'    => 'database',
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list-tables', 'describe-table', 'query' ), 'required' => true ),
						'table'     => array( 'type' => 'string', 'description' => 'Full table name, e.g. wp_posts' ),
						'sql'       => array( 'type' => 'string', 'description' => 'query: read-only SQL' ),
						'limit'     => array( 'type' => 'integer', 'default' => 100, 'maximum' => 1000, 'description' => 'query: row cap' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'db-write',
			array(
				'title'       => 'Database Write',
				'description' => 'Parameterized row writes. Operations: insert-row, update-rows (equality WHERE required), delete-rows (confirm:true). Users/options tables are protected. Every write snapshots a before-image for rollback reference.',
				'category'    => 'database',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'insert-row', 'update-rows', 'delete-rows' ), 'required' => true ),
						'table'     => array( 'type' => 'string', 'required' => true ),
						'data'      => array( 'type' => 'object', 'description' => 'Column => value' ),
						'where'     => array( 'type' => 'object', 'description' => 'Equality WHERE: column => value (non-empty)' ),
						'confirm'   => array( 'type' => 'boolean', 'description' => 'delete-rows only' ),
					),
					'required'   => array( 'operation', 'table' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function guard_table( string $table ): ?string {
		global $wpdb;
		$prefix = $wpdb->prefix;
		if ( '' === $table || 0 !== strpos( $table, $prefix ) && ! in_array( $table, array( $wpdb->users, $wpdb->usermeta ), true ) ) {
			return 'unknown_table';
		}
		$short = substr( $table, strlen( $prefix ) );
		if ( in_array( $short, self::PROTECTED_TABLES, true ) ) {
			return 'protected_table';
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return 'invalid_table_name';
		}
		return null;
	}

	private static function is_read_query( string $sql ): bool {
		$trimmed = ltrim( preg_replace( '/^\s*(\/\*.*?\*\/)?\s*/s', '', $sql ) );
		return 1 === preg_match( '/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $trimmed );
	}

	public function read( array $args ): array {
		global $wpdb;
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'list-tables' === $operation ) {
			$tables = $wpdb->get_col( 'SHOW TABLES' );
			$out    = array();
			foreach ( $tables as $table ) {
				$status = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows,
						ROUND( ( DATA_LENGTH + INDEX_LENGTH ) / 1024 / 1024, 2 ) AS size_mb
						FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
						DB_NAME,
						$table
					),
					ARRAY_A
				);
				if ( $status ) {
					$out[] = $status;
				}
			}
			return array( 'total' => count( $out ), 'tables' => $out );
		}

		$table = (string) ( $args['table'] ?? '' );

		if ( 'describe-table' === $operation ) {
			$error = $this->guard_table( $table );
			if ( null !== $error ) {
				return array( 'error' => $error );
			}
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A ); // phpcs:ignore
			if ( null === $columns ) {
				return array( 'error' => 'describe_failed', 'message' => $wpdb->last_error );
			}
			return array(
				'table'   => $table,
				'columns' => array_map(
					static fn( $c ) => array(
						'field' => $c['Field'],
						'type'  => $c['Type'],
						'null'  => $c['Null'],
						'key'   => $c['Key'],
						'default' => $c['Default'],
					),
					(array) $columns
				),
			);
		}

		if ( 'query' === $operation ) {
			$sql = (string) ( $args['sql'] ?? '' );
			if ( '' === trim( $sql ) ) {
				return array( 'error' => 'sql_required' );
			}
			if ( ! self::is_read_query( $sql ) ) {
				return array( 'error' => 'only_read_queries_allowed' );
			}
			$limit = min( 1000, max( 1, (int) ( $args['limit'] ?? 100 ) ) );
			$rows  = $wpdb->get_results( $sql . ' LIMIT ' . $limit, ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( null === $rows && '' !== $wpdb->last_error ) {
				return array( 'error' => 'query_failed', 'message' => $wpdb->last_error );
			}
			return array(
				'row_count' => count( (array) $rows ),
				'rows'      => array_slice( (array) $rows, 0, $limit ),
				'capped_at' => $limit,
			);
		}

		return array( 'error' => 'unknown_operation' );
	}

	public function write( array $args ): array {
		global $wpdb;
		$operation = (string) ( $args['operation'] ?? '' );
		$table     = (string) ( $args['table'] ?? '' );

		$error = $this->guard_table( $table );
		if ( null !== $error ) {
			return array( 'error' => $error );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'forbidden' );
		}
		$data  = isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : array();
		$where = isset( $args['where'] ) && is_array( $args['where'] ) ? array_filter( $args['where'], static fn( $v ) => null !== $v ) : array();

		switch ( $operation ) {
			case 'insert-row':
				if ( empty( $data ) ) {
					return array( 'error' => 'data_required' );
				}
				$result = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
				if ( false === $result ) {
					return array( 'error' => 'insert_failed', 'message' => $wpdb->last_error );
				}
				$id = (int) $wpdb->insert_id;
				$this->log->record( 'database', 'insert-row', $id, $table, sprintf( 'Inserted row #%d into %s', $id, $table ), null, false );
				return array( 'ok' => true, 'inserted_id' => $id, 'table' => $table );

			case 'update-rows':
				if ( empty( $data ) || empty( $where ) ) {
					return array( 'error' => 'data_and_where_required' );
				}
				$before = $wpdb->get_results( $this->build_select_where( $table, $where, 200 ), ARRAY_A ); // phpcs:ignore
				$result = $wpdb->update( $table, $data, $where ); // phpcs:ignore WordPress.DB
				if ( false === $result ) {
					return array( 'error' => 'update_failed', 'message' => $wpdb->last_error );
				}
				$this->log->record( 'database', 'update-rows', 0, $table, sprintf( 'Updated %d row(s) in %s', (int) $result, $table ), array( 'rows' => $before ), true );
				return array( 'ok' => true, 'rows_updated' => (int) $result, 'before_rows' => count( (array) $before ) );

			case 'delete-rows':
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				if ( empty( $where ) ) {
					return array( 'error' => 'where_required' );
				}
				$before = $wpdb->get_results( $this->build_select_where( $table, $where, 200 ), ARRAY_A ); // phpcs:ignore
				$result = $wpdb->delete( $table, $where ); // phpcs:ignore WordPress.DB
				if ( false === $result ) {
					return array( 'error' => 'delete_failed', 'message' => $wpdb->last_error );
				}
				$this->log->record( 'database', 'delete-rows', 0, $table, sprintf( 'Deleted %d row(s) from %s', (int) $result, $table ), array( 'rows' => $before ), true );
				return array( 'deleted' => true, 'rows_deleted' => (int) $result, 'before_rows' => count( (array) $before ) );
		}

		return array( 'error' => 'unknown_operation' );
	}

	private function build_select_where( string $table, array $where, int $limit ): string {
		global $wpdb;
		$clauses = array();
		foreach ( $where as $column => $value ) {
			$clauses[] = $wpdb->prepare( '`' . sanitize_key( (string) $column ) . '` = %s', (string) $value ); // phpcs:ignore
		}
		return 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE ' . implode( ' AND ', $clauses ) . ' LIMIT ' . $limit;
	}
}
