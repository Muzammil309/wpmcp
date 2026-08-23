<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Memory {

	private WPMCP_Registry $registry;
	private const OPT_PENDING  = 'wpmcp_memory_pending';
	private const OPT_APPROVED = 'wpmcp_memory_approved';
	private const OPT_SESSIONS = 'wpmcp_sessions';

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'memory-read',
			array(
				'title'       => 'Recall Project Memory',
				'description' => 'Read approved site guidance (guardrails, facts, conventions) and recent session summaries so the agent does not re-guess context. Operations: approved, sessions, pending (admins only).',
				'category'    => 'memory',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'approved', 'sessions', 'pending' ), 'default' => 'approved' ),
						'type'      => array( 'type' => 'string', 'description' => 'Filter approved items by type: guardrail|fact|convention|instruction' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'memory-write',
			array(
				'title'       => 'Write Project Memory',
				'description' => 'Propose durable guidance (stored pending until an admin approves), approve pending proposals, forget entries, or save a session summary. Pro only. Admin-only.',
				'category'    => 'memory',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'enum' => array( 'propose', 'approve', 'forget', 'save-session' ), 'required' => true ),
						'type'      => array( 'type' => 'string', 'enum' => array( 'guardrail', 'fact', 'convention', 'instruction' ), 'description' => 'propose only' ),
						'text'      => array( 'type' => 'string', 'description' => 'propose: guidance text; save-session: summary text' ),
						'id'        => array( 'type' => 'string', 'description' => 'approve/forget target id' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function bucket( string $which ): array {
		$v = get_option( $which, array() );
		return is_array( $v ) ? $v : array();
	}

	private function put_bucket( string $which, array $items ): void {
		update_option( $which, array_values( $items ), false );
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'approved' );

		if ( 'sessions' === $operation ) {
			return array( 'sessions' => $this->bucket( self::OPT_SESSIONS ) );
		}
		if ( 'pending' === $operation ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return array( 'error' => 'forbidden' );
			}
			return array( 'pending' => $this->bucket( self::OPT_PENDING ) );
		}

		$type_filter = sanitize_key( (string) ( $args['type'] ?? '' ) );
		$approved    = $this->bucket( self::OPT_APPROVED );
		if ( '' !== $type_filter ) {
			$approved = array_values( array_filter( $approved, static fn( $i ) => ( $i['type'] ?? '' ) === $type_filter ) );
		}
		return array( 'total' => count( $approved ), 'items' => $approved );
	}

	public function write( array $args ): array {
		$operation = (string) ( $args['operation'] ?? '' );

		if ( 'propose' === $operation ) {
			$text = trim( (string) ( $args['text'] ?? '' ) );
			$type = sanitize_key( (string) ( $args['type'] ?? 'fact' ) );
			if ( '' === $text || ! in_array( $type, array( 'guardrail', 'fact', 'convention', 'instruction' ), true ) ) {
				return array( 'error' => 'type_and_text_required' );
			}
			$pending   = $this->bucket( self::OPT_PENDING );
			$id        = 'mem_' . substr( md5( $text . microtime() ), 0, 10 );
			$pending[] = array( 'id' => $id, 'type' => $type, 'text' => $text, 'proposed_by' => wp_get_current_user()->user_login, 'created' => gmdate( 'c' ) );
			$this->put_bucket( self::OPT_PENDING, $pending );
			return array( 'ok' => true, 'id' => $id, 'status' => 'pending_approval' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'forbidden' );
		}

		if ( 'approve' === $operation ) {
			$id      = sanitize_text_field( (string) ( $args['id'] ?? '' ) );
			$pending = $this->bucket( self::OPT_PENDING );
			$found   = null;
			foreach ( $pending as $i => $item ) {
				if ( ( $item['id'] ?? '' ) === $id ) {
					$found = $item;
					unset( $pending[ $i ] );
					break;
				}
			}
			if ( null === $found ) {
				return array( 'error' => 'proposal_not_found' );
			}
			$this->put_bucket( self::OPT_PENDING, $pending );
			$approved = $this->bucket( self::OPT_APPROVED );
			$approved[] = $found;
			$this->put_bucket( self::OPT_APPROVED, $approved );
			return array( 'ok' => true, 'approved' => $id );
		}

		if ( 'forget' === $operation ) {
			$id       = sanitize_text_field( (string) ( $args['id'] ?? '' ) );
			$approved = $this->bucket( self::OPT_APPROVED );
			$kept     = array_values( array_filter( $approved, static fn( $i ) => ( $i['id'] ?? '' ) !== $id ) );
			if ( count( $kept ) === count( $approved ) ) {
				return array( 'error' => 'entry_not_found' );
			}
			$this->put_bucket( self::OPT_APPROVED, $kept );
			return array( 'forgotten' => true, 'id' => $id );
		}

		if ( 'save-session' === $operation ) {
			$summary = trim( (string) ( $args['text'] ?? '' ) );
			if ( '' === $summary ) {
				return array( 'error' => 'text_required' );
			}
			$sessions   = $this->bucket( self::OPT_SESSIONS );
			$sessions[] = array(
				'id'      => 'ses_' . substr( md5( $summary . microtime() ), 0, 8 ),
				'summary' => $summary,
				'agent'   => wp_get_current_user()->user_login,
				'created' => gmdate( 'c' ),
			);
			$sessions   = array_slice( $sessions, -20 );
			$this->put_bucket( self::OPT_SESSIONS, $sessions );
			return array( 'ok' => true, 'saved' => true );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
