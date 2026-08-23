<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_BrandKits {

	private WPMCP_Registry $registry;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
	}

	public static function kits(): array {
		return array(
			'modern-mint'   => array( 'name' => 'Modern Mint', 'colors' => array( '#060a09', '#2de3a7', '#59b7ff', '#e9efec', '#93a19b' ), 'headings' => 'Space Grotesk', 'body' => 'Inter' ),
			'sunset-coral'  => array( 'name' => 'Sunset Coral', 'colors' => array( '#1a0e0c', '#ff7a59', '#ffc24b', '#fff4ec', '#b08968' ), 'headings' => 'Poppins', 'body' => 'Inter' ),
			'ocean-deep'    => array( 'name' => 'Ocean Deep', 'colors' => array( '#04121f', '#0ea5e9', '#38bdf8', '#e0f2fe', '#7dd3fc' ), 'headings' => 'Sora', 'body' => 'Inter' ),
			'forest-calm'   => array( 'name' => 'Forest Calm', 'colors' => array( '#0c1410', '#4ade80', '#a3e635', '#ecfdf5', '#86a396' ), 'headings' => 'Manrope', 'body' => 'Inter' ),
			'mono-slate'    => array( 'name' => 'Mono Slate', 'colors' => array( '#0f0f10', '#e4e4e7', '#a1a1aa', '#fafafa', '#71717a' ), 'headings' => 'Archivo', 'body' => 'Inter' ),
			'berry-pop'     => array( 'name' => 'Berry Pop', 'colors' => array( '#14060f', '#e879a6', '#c084fc', '#fdf2f8', '#d8a7ca' ), 'headings' => 'Outfit', 'body' => 'Inter' ),
			'amber-noir'    => array( 'name' => 'Amber Noir', 'colors' => array( '#0d0a06', '#fbbf24', '#f59e0b', '#fffbeb', '#d4a373' ), 'headings' => 'Space Grotesk', 'body' => 'Inter' ),
			'arctic-ice'    => array( 'name' => 'Arctic Ice', 'colors' => array( '#0a0f14', '#67e8f9', '#93c5fd', '#f0f9ff', '#94a3b8' ), 'headings' => 'Urbanist', 'body' => 'Inter' ),
		);
	}

	public function register(): void {
		$this->registry->register(
			'brand-kits-list',
			array(
				'title'       => 'List Brand Kits',
				'description' => 'Bundled color + typography kits. Each apply snapshots the current Elementor kit first (rollback via the change ledger).',
				'category'    => 'brand-kits',
				'capability'  => 'edit_posts',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'handler'     => static fn() => array( 'total' => count( self::kits() ), 'kits' => self::kits() ),
			)
		);

		$this->registry->register(
			'brand-kit-apply',
			array(
				'title'       => 'Apply Brand Kit',
				'description' => 'Apply a bundled brand kit to the Elementor site kit (system colors + heading/body typography). Snapshots the previous values into the change ledger before writing.',
				'category'    => 'brand-kits',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'edit_theme_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'kit' => array( 'type' => 'string', 'required' => true, 'description' => 'Kit slug from brand-kits-list' ),
					),
					'required'   => array( 'kit' ),
				),
				'handler'     => array( $this, 'apply' ),
			)
		);
	}

	public function apply( array $args ): array {
		$slug = sanitize_title( (string) ( $args['kit'] ?? '' ) );
		$kits = self::kits();
		if ( ! isset( $kits[ $slug ] ) ) {
			return array( 'error' => 'unknown_kit', 'available' => array_keys( $kits ) );
		}
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'wpmcp_elementor_missing' );
		}
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit ) {
			return array( 'error' => 'kit_unavailable' );
		}

		$k          = $kits[ $slug ];
		$before     = array(
			'system_colors'      => $kit->get_settings_for_display( 'system_colors' ),
			'custom_colors'      => $kit->get_settings_for_display( 'custom_colors' ),
			'system_typography'  => $kit->get_settings_for_display( 'system_typography' ),
		);

		$system_colors = array(
			array( '_id' => 'primary', 'title' => 'Primary', 'color' => $k['colors'][1] ),
			array( '_id' => 'secondary', 'title' => 'Secondary', 'color' => $k['colors'][2] ),
			array( '_id' => 'text', 'title' => 'Text', 'color' => $k['colors'][3] ),
			array( '_id' => 'accent', 'title' => 'Accent', 'color' => $k['colors'][0] ),
		);
		$typography = array(
			array( '_id' => 'primary', 'title' => 'Primary Headlines', 'typography_font_family' => $k['headings'], 'typography_font_weight' => '700' ),
			array( '_id' => 'secondary', 'title' => 'Secondary Headlines', 'typography_font_family' => $k['headings'], 'typography_font_weight' => '600' ),
			array( '_id' => 'text', 'title' => 'Body Text', 'typography_font_family' => $k['body'], 'typography_font_weight' => '400' ),
			array( '_id' => 'accent', 'title' => 'Accent Text', 'typography_font_family' => $k['body'], 'typography_font_weight' => '500' ),
		);

		$r1 = $kit->update_settings( array( 'system_colors' => $system_colors, 'system_typography' => $typography ) );
		if ( is_wp_error( $r1 ) ) {
			return array( 'error' => 'apply_failed', 'message' => $r1->get_error_message() );
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		wpmcp_plugin()->change_log->record(
			'elementor', 'apply-brand-kit', 0, $k['name'],
			sprintf( 'Applied brand kit %s', $k['name'] ),
			array( 'before' => array_filter( $before ) ), true
		);
		return array( 'ok' => true, 'kit' => $slug, 'name' => $k['name'] );
	}
}
