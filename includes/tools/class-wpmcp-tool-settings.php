<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Settings {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	private const ALLOWED = array(
		'blogname'              => array( 'label' => 'Site title', 'type' => 'string' ),
		'blogdescription'       => array( 'label' => 'Tagline', 'type' => 'string' ),
		'site_icon'             => array( 'label' => 'Site icon (attachment ID)', 'type' => 'int' ),
		'timezone_string'       => array( 'label' => 'Timezone', 'type' => 'string' ),
		'date_format'           => array( 'label' => 'Date format', 'type' => 'string' ),
		'time_format'           => array( 'label' => 'Time format', 'type' => 'string' ),
		'start_of_week'         => array( 'label' => 'Start of week (0=Sun)', 'type' => 'int' ),
		'posts_per_page'        => array( 'label' => 'Posts per page', 'type' => 'int' ),
		'blog_public'           => array( 'label' => 'Search engine visibility (1=public)', 'type' => 'bool' ),
		'default_comment_status' => array( 'label' => 'Default comment status', 'type' => 'string' ),
		'comment_moderation'    => array( 'label' => 'Comment moderation', 'type' => 'bool' ),
		'comment_registration'  => array( 'label' => 'Users must be registered to comment', 'type' => 'bool' ),
		'require_name_email'    => array( 'label' => 'Require name/email on comments', 'type' => 'bool' ),
		'thread_comments'       => array( 'label' => 'Threaded comments', 'type' => 'bool' ),
		'thumbnail_size_w'      => array( 'label' => 'Thumbnail width', 'type' => 'int' ),
		'thumbnail_size_h'      => array( 'label' => 'Thumbnail height', 'type' => 'int' ),
		'medium_size_w'         => array( 'label' => 'Medium width', 'type' => 'int' ),
		'medium_size_h'         => array( 'label' => 'Medium height', 'type' => 'int' ),
		'large_size_w'          => array( 'label' => 'Large width', 'type' => 'int' ),
		'large_size_h'          => array( 'label' => 'Large height', 'type' => 'int' ),
	);

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'get-settings',
			array(
				'title'       => 'Get Settings',
				'description' => 'Read core WordPress settings from a curated allowlist (general, reading, discussion, media) plus the active SEO plugin settings.',
				'category'    => 'settings',
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional subset of setting keys' ),
					),
				),
				'handler'     => array( $this, 'get_settings' ),
			)
		);

		$this->registry->register(
			'update-settings',
			array(
				'title'       => 'Update Settings',
				'description' => 'Batch-update allowlisted WordPress settings. Unknown keys are reported in skipped[]. Permalink changes flush rewrite rules.',
				'category'    => 'settings',
				'write'       => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'values' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => 'Map of setting key to new value',
						),
					),
					'required'   => array( 'values' ),
				),
				'handler'     => array( $this, 'update_settings' ),
			)
		);
	}

	public function get_settings( array $args ): array {
		$keys = isset( $args['keys'] ) && is_array( $args['keys'] ) ? array_map( 'sanitize_key', $args['keys'] ) : array_keys( self::ALLOWED );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( ! isset( self::ALLOWED[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = array(
				'label' => self::ALLOWED[ $key ]['label'],
				'value' => get_option( $key ),
			);
		}
		return array(
			'settings'   => $out,
			'seo_plugin' => wpmcp_plugin()->seo->get_settings(),
		);
	}

	public function update_settings( array $args ): array {
		$values = $args['values'] ?? array();
		if ( ! is_array( $values ) || empty( $values ) ) {
			return array( 'error' => 'values_object_required' );
		}
		$updated = array();
		$skipped = array();
		$before  = array();
		foreach ( $values as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! isset( self::ALLOWED[ $key ] ) ) {
				$skipped[] = $key;
				continue;
			}
			$typed = $this->cast( self::ALLOWED[ $key ]['type'], $value );
			$before[ $key ] = get_option( $key );
			update_option( $key, $typed );
			$updated[] = $key;
		}
		if ( isset( $values['permalink_structure'] ) && ! in_array( 'permalink_structure', $skipped, true ) ) {
			flush_rewrite_rules();
		}
		if ( $updated ) {
			$this->log->record( 'settings', 'update-settings', 0, '', sprintf( 'Updated settings: %s', implode( ', ', $updated ) ), $before, true );
		}
		return array(
			'updated' => $updated,
			'skipped' => $skipped,
		);
	}

	private function cast( string $type, $value ) {
		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
