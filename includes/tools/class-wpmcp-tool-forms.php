<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Forms {

	private WPMCP_Registry $registry;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
	}

	public static function providers(): array {
		$out = array();
		if ( class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7' ) ) {
			$out[] = 'cf7';
		}
		if ( function_exists( 'wpforms' ) && defined( 'WPFORMS_VERSION' ) ) {
			$out[] = 'wpforms';
		}
		if ( class_exists( 'GFCommon' ) || class_exists( 'GFForms' ) ) {
			$out[] = 'gravityforms';
		}
		return $out;
	}

	public function register(): void {
		if ( empty( self::providers() ) ) {
			return;
		}

		$this->registry->register(
			'forms-read',
			array(
				'title'       => 'Forms Read',
				'description' => 'Read contact-form data across installed form plugins (Contact Form 7, WPForms, Gravity Forms). Operations: providers, list-forms, get-form. Entries are not included in wave one.',
				'category'    => 'forms',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'provider' => array( 'type' => 'string', 'enum' => array( 'cf7', 'wpforms', 'gravityforms' ), 'description' => 'Omit with operation=providers to list available providers' ),
						'operation' => array( 'type' => 'string', 'enum' => array( 'providers', 'list-forms', 'get-form' ), 'default' => 'list-forms' ),
						'form_id'  => array( 'type' => 'integer', 'description' => 'get-form only' ),
					),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'forms-write',
			array(
				'title'       => 'Forms Write',
				'description' => 'Manage form entries (WPForms, Gravity Forms). Operations: set-entry-status, delete-entry (confirm:true). Pro only.',
				'category'    => 'forms',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'provider' => array( 'type' => 'string', 'enum' => array( 'wpforms', 'gravityforms' ), 'required' => true ),
						'operation' => array( 'type' => 'string', 'enum' => array( 'set-entry-status', 'delete-entry' ), 'required' => true ),
						'entry_id' => array( 'type' => 'integer', 'required' => true ),
						'status'   => array( 'type' => 'string', 'description' => 'set-entry-status: provider-specific status word' ),
						'confirm'  => array( 'type' => 'boolean', 'description' => 'delete-entry only' ),
					),
					'required'   => array( 'provider', 'operation', 'entry_id' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function guard( string $provider ): ?array {
		if ( ! in_array( $provider, self::providers(), true ) ) {
			return array( 'error' => 'provider_unavailable', 'available' => self::providers() );
		}
		return null;
	}

	public function read( array $args ): array {
		$operation = (string) ( $args['operation'] ?? 'list-forms' );

		if ( 'providers' === $operation ) {
			return array( 'providers' => self::providers() );
		}

		$provider = sanitize_key( (string) ( $args['provider'] ?? '' ) );
		if ( '' === $provider ) {
			return array( 'error' => 'provider_required', 'available' => self::providers() );
		}
		if ( $err = $this->guard( $provider ) ) {
			return $err;
		}

		switch ( $provider ) {
			case 'cf7':
				return $this->read_cf7( $operation, $args );
			case 'wpforms':
				return $this->read_wpforms( $operation, $args );
			case 'gravityforms':
				return $this->read_gf( $operation, $args );
		}
		return array( 'error' => 'unknown_provider' );
	}

	private function read_cf7( string $op, array $args ): array {
		if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
			return array( 'error' => 'cf7_post_type_missing' );
		}
		if ( 'get-form' === $op ) {
			$id   = (int) ( $args['form_id'] ?? 0 );
			$post = get_post( $id );
			if ( ! $post || 'wpcf7_contact_form' !== $post->post_type ) {
				return array( 'error' => 'form_not_found' );
			}
			$form  = WPCF7_ContactForm::get_instance( $id );
			return array(
				'id'      => $id,
				'title'   => $post->post_title,
				'slug'    => $post->post_name,
				'shortcode' => sprintf( '[contact-form-7 id="%d" title="%s"]', $id, esc_attr( $post->post_title ) ),
				'fields'  => $form->scan_form_tags() ? array_values( array_filter( array_map( static fn( $t ) => 'text' !== $t->type || '' !== $t->name ? $t->name : null, $form->scan_form_tags() ) ) ) : array(),
				'mail'    => array( 'recipient' => $form->mail()['recipient'] ?? '' ),
			);
		}
		$posts = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 100, 'orderby' => 'title', 'order' => 'ASC' ) );
		return array(
			'total'  => count( $posts ),
			'forms'  => array_map( static fn( $p ) => array( 'id' => $p->ID, 'title' => $p->post_title ), $posts ),
		);
	}

	private function read_wpforms( string $op, array $args ): array {
		if ( 'get-form' === $op ) {
			$form = wpforms()->obj( 'form' )->get( (int) ( $args['form_id'] ?? 0 ) );
			if ( ! $form ) {
				return array( 'error' => 'form_not_found' );
			}
			$fields = array();
			foreach ( (array) ( $form->post_content ? json_decode( $form->post_content, true )['fields'] ?? [] : [] ) as $fid => $f ) {
				$fields[] = array( 'id' => $fid, 'label' => $f['label'] ?? '', 'type' => $f['type'] ?? '' );
			}
			return array( 'id' => $form->ID, 'title' => $form->post_title, 'fields' => $fields, 'settings_count' => count( (array) ( json_decode( $form->post_content, true )['settings'] ?? [] ) ) );
		}
		$forms = wpforms()->obj( 'form' )->get( '', array( 'orderby' => 'title' ) );
		$out   = array_map( static fn( $f ) => array( 'id' => $f->ID, 'title' => $f->post_title ), (array) $forms );
		return array( 'total' => count( $out ), 'forms' => $out );
	}

	private function read_gf( string $op, array $args ): array {
		if ( ! class_exists( 'GFFormsModel' ) ) {
			return array( 'error' => 'gf_model_missing' );
		}
		if ( 'get-form' === $op ) {
			$form_id = (int) ( $args['form_id'] ?? 0 );
			$form    = GFFormsModel::get_form_meta( $form_id );
			if ( empty( $form ) ) {
				return array( 'error' => 'form_not_found' );
			}
			$fields = array();
			foreach ( (array) ( $form['fields'] ?? [] ) as $f ) {
				if ( ! empty( $f->inputs ) ) {
					continue; // skip subfield parents for brevity
				}
				$fields[] = array( 'id' => $f->id, 'label' => $f->label, 'type' => $f->type );
			}
			return array( 'id' => $form_id, 'title' => $form['title'] ?? '', 'fields' => $fields );
		}
		$forms = GFFormsModel::get_forms( true );
		$out   = array_map( static fn( $f ) => array( 'id' => (int) $f->id, 'title' => $f->title, 'is_active' => (bool) $f->is_active ), (array) $forms );
		return array( 'total' => count( $out ), 'forms' => $out );
	}

	public function write( array $args ): array {
		$provider = sanitize_key( (string) ( $args['provider'] ?? '' ) );
		if ( $err = $this->guard( $provider ) ) {
			return $err;
		}
		$entry_id = (int) ( $args['entry_id'] ?? 0 );
		if ( $entry_id <= 0 ) {
			return array( 'error' => 'entry_id_required' );
		}

		if ( 'gravityforms' === $provider ) {
			if ( ! class_exists( 'GFFormsModel' ) ) {
				return array( 'error' => 'gf_model_missing' );
			}
			$entry = GFFormsModel::get_entry( $entry_id );
			if ( ! $entry ) {
				return array( 'error' => 'entry_not_found' );
			}
			if ( 'set-entry-status' === ( $args['operation'] ?? '' ) ) {
				$status = sanitize_key( (string) ( $args['status'] ?? 'active' ) );
				if ( ! in_array( $status, array( 'active', 'inactive', 'spam', 'trash' ), true ) ) {
					return array( 'error' => 'invalid_status' );
				}
				$ok = 'spam' === $status ? GFFormsModel::update_entry_property( $entry_id, 'status', 'spam' )
					: ( 'trash' === $status ? GFFormsModel::delete_entry( $entry_id )
					: GFFormsModel::update_entry_property( $entry_id, 'status', $status ) );
				if ( 'trash' === $status ) {
					$ok = true;
				}
				if ( ! $ok ) {
					return array( 'error' => 'update_failed' );
				}
				return array( 'ok' => true, 'entry_id' => $entry_id, 'status' => 'trash' === $status ? 'trashed' : $status );
			}
			if ( 'delete-entry' === ( $args['operation'] ?? '' ) ) {
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				GFFormsModel::delete_entry( $entry_id );
				return array( 'deleted' => true, 'entry_id' => $entry_id );
			}
		}

		if ( 'wpforms' === $provider ) {
			global $wpdb;
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}wpforms_entries WHERE entry_id = %d", $entry_id ) );
			if ( ! $exists ) {
				return array( 'error' => 'entry_not_found' );
			}
			if ( 'set-entry-status' === ( $args['operation'] ?? '' ) ) {
				$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
				if ( ! in_array( $status, array( 'approved', 'unapproved', 'spam', 'trash' ), true ) ) {
					return array( 'error' => 'invalid_status' );
				}
				$wpdb->update( "{$wpdb->prefix}wpforms_entries", array( 'status' => $status ), array( 'entry_id' => $entry_id ) );
				return array( 'ok' => true, 'entry_id' => $entry_id, 'status' => $status );
			}
			if ( 'delete-entry' === ( $args['operation'] ?? '' ) ) {
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				$wpdb->delete( "{$wpdb->prefix}wpforms_entries", array( 'entry_id' => $entry_id ) );
				$wpdb->delete( "{$wpdb->prefix}wpforms_entry_fields", array( 'entry_id' => $entry_id ) );
				return array( 'deleted' => true, 'entry_id' => $entry_id );
			}
		}

		return array( 'error' => 'unsupported_operation_for_provider' );
	}
}
