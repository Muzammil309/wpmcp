<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Users {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'user-read',
			array(
				'title'       => 'User Read',
				'description' => 'Read WordPress users. Operations: list-users (filter by role/search), get-user (profile detail; admins flagged, never off-limits to read). Admin-only.',
				'category'    => 'users',
				'capability'  => 'list_users',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'list-users', 'get-user' ), 'default' => 'list-users' ),
						'role'      => array( 'type' => 'string', 'description' => 'Filter by role slug, e.g. editor' ),
						'search'    => array( 'type' => 'string', 'description' => 'Matches login, email and display name' ),
						'id'        => array( 'type' => 'integer', 'description' => 'get-user: user ID' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'user-write',
			array(
				'title'       => 'User Write',
				'description' => 'Create or edit users. Creates are non-admin with an auto-generated password (returned once); edits never touch roles or passwords on admins. Requires manage_options.',
				'category'    => 'users',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation'   => array( 'type' => 'string', 'enum' => array( 'create-user', 'update-user' ), 'required' => true ),
						'username'    => array( 'type' => 'string', 'description' => 'create-user only' ),
						'email'       => array( 'type' => 'string' ),
						'id'          => array( 'type' => 'integer', 'description' => 'update-user only' ),
						'first_name'  => array( 'type' => 'string' ),
						'last_name'   => array( 'type' => 'string' ),
						'display_name' => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string' ),
						'role'        => array( 'type' => 'string', 'description' => 'Non-admin role slug; admin/edit_network roles refused' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function row( WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
			'registered'   => $user->user_registered,
			'post_count'   => (int) count_user_posts( $user->ID ),
			'is_admin'     => in_array( 'administrator', $user->roles, true ),
		);
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-users' );
		if ( 'get-user' === $operation ) {
			$user = get_user_by( 'id', (int) ( $args['id'] ?? 0 ) );
			if ( ! $user ) {
				return array( 'error' => 'user_not_found' );
			}
			return $this->row( $user );
		}
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$query    = new WP_User_Query(
			array(
				'role'    => '' !== ( $args['role'] ?? '' ) ? sanitize_key( (string) $args['role'] ) : '',
				'search'  => '*' . sanitize_text_field( (string) ( $args['search'] ?? '' ) ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'  => $per_page,
				'paged'   => $page,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);
		return array(
			'total' => (int) $query->get_total(),
			'page'  => $page,
			'users' => array_map( array( $this, 'row' ), array_values( $query->get_results() ) ),
		);
	}

	private static function forbidden_roles(): array {
		return array( 'administrator', 'super_admin' );
	}

	public function write( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'create-user' === $operation ) {
			$username = sanitize_user( (string) ( $args['username'] ?? '' ), true );
			$email    = sanitize_email( (string) ( $args['email'] ?? '' ) );
			if ( '' === $username || ! is_email( $email ) ) {
				return array( 'error' => 'username_and_valid_email_required' );
			}
			if ( username_exists( $username ) || email_exists( $email ) ) {
				return array( 'error' => 'user_already_exists' );
			}
			$password = wp_generate_password( 16, true, false );
			$userdata = array(
				'user_login' => $username,
				'user_pass'  => $password,
				'user_email' => $email,
				'first_name' => sanitize_text_field( (string) ( $args['first_name'] ?? '' ) ),
				'last_name'  => sanitize_text_field( (string) ( $args['last_name'] ?? '' ) ),
				'display_name' => sanitize_text_field( (string) ( $args['display_name'] ?? $username ) ),
				'role'       => self::safe_role( (string) ( $args['role'] ?? 'editor' ) ),
			);
			$user_id = wp_insert_user( $userdata );
			if ( is_wp_error( $user_id ) ) {
				return array( 'error' => 'create_failed', 'message' => $user_id->get_error_message() );
			}
			$this->log->record( 'users', 'create-user', $user_id, $username, sprintf( 'Created %s user', $userdata['role'] ) );
			return array(
				'ok'       => true,
				'id'       => $user_id,
				'username' => $username,
				'password' => $password,
				'note'     => 'Password shown once; send it to the user through a secure channel.',
			);
		}

		if ( 'update-user' === $operation ) {
			$id   = (int) ( $args['id'] ?? 0 );
			$user = get_user_by( 'id', $id );
			if ( ! $user ) {
				return array( 'error' => 'user_not_found' );
			}
			if ( in_array( 'administrator', $user->roles, true ) ) {
				return array( 'error' => 'admin_users_off_limits', 'message' => 'Administrator accounts cannot be edited over MCP.' );
			}
			$userdata = array( 'ID' => $id );
			$fields   = array(
				'first_name'   => 'first_name',
				'last_name'    => 'last_name',
				'display_name' => 'display_name',
				'description'  => 'description',
				'url'          => 'user_url',
			);
			$updated = array();
			foreach ( $fields as $arg => $field ) {
				if ( isset( $args[ $arg ] ) ) {
					$userdata[ $field ] = 'url' === $arg ? esc_url_raw( (string) $args[ $arg ] ) : sanitize_text_field( (string) $args[ $arg ] );
					$updated[]          = $arg;
				}
			}
			if ( isset( $args['email'] ) ) {
				$email = sanitize_email( (string) $args['email'] );
				if ( is_email( $email ) && ( ! email_exists( $email ) || $email === $user->user_email ) ) {
					$userdata['user_email'] = $email;
					$updated[]              = 'email';
				}
			}
			if ( isset( $args['role'] ) ) {
				$role = (string) $args['role'];
				if ( in_array( $role, self::forbidden_roles(), true ) || ! get_role( $role ) ) {
					return array( 'error' => 'invalid_or_forbidden_role' );
				}
				$userdata['role'] = $role;
				$updated[]        = 'role';
			}
			$result = wp_update_user( $userdata );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => 'update_failed', 'message' => $result->get_error_message() );
			}
			$this->log->record( 'users', 'update-user', $id, $user->user_login, sprintf( 'Updated: %s', implode( ', ', $updated ) ), array(), false );
			return array( 'ok' => true, 'id' => $id, 'updated' => $updated );
		}

		return array( 'error' => 'unknown_operation' );
	}

	private static function safe_role( string $requested ): string {
		if ( in_array( $requested, self::forbidden_roles(), true ) || ! get_role( $requested ) ) {
			return 'editor';
		}
		return $requested;
	}
}
