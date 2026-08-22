<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Registry {

	private array $tools = array();

	public function register( string $name, array $config ): void {
		if ( isset( $this->tools[ $name ] ) ) {
			return;
		}
		if ( ! empty( $config['pro'] ) && ! wpmcp_is_pro() ) {
			return;
		}
		$config = wp_parse_args(
			$config,
			array(
				'title'       => $name,
				'description' => '',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'handler'     => null,
				'capability'  => 'edit_posts',
				'category'    => 'general',
				'write'       => false,
				'confirm'     => false,
				'pro'         => false,
			)
		);
		if ( ! is_callable( $config['handler'] ) ) {
			return;
		}
		$this->tools[ $name ] = $config;
	}

	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	public function get( string $name ): ?array {
		return $this->tools[ $name ] ?? null;
	}

	public function all(): array {
		return $this->tools;
	}

	public function is_enabled( string $name ): bool {
		$tool = $this->get( $name );
		if ( ! $tool ) {
			return false;
		}
		$disabled = get_option( 'wpmcp_disabled_tools', array() );
		return ! in_array( $name, (array) $disabled, true );
	}

	public function compact_mode(): bool {
		return (bool) get_option( 'wpmcp_compact_mode', 0 );
	}

	public function list_for_client(): array {
		if ( $this->compact_mode() ) {
			return $this->meta_tools();
		}
		$out = array();
		foreach ( $this->all() as $name => $tool ) {
			if ( ! $this->is_enabled( $name ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'title'       => $tool['title'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
				'annotations' => array(
					'readOnlyHint' => ! $tool['write'],
				),
			);
		}
		return $out;
	}

	private function meta_tools(): array {
		return array(
			array(
				'name'        => 'list-tools',
				'title'       => 'List Tools',
				'description' => 'Compact mode is ON. Browse the full tool catalog. Filter by category or search term; returns name, one-line summary and write flag per tool. Use get-tool-schema for inputs, call-tool to run.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Substring matched against tool names and descriptions' ),
						'category' => array( 'type' => 'string', 'description' => 'Filter by category, e.g. seo, content, media' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'get-tool-schema',
				'title'       => 'Get Tool Schema',
				'description' => 'Compact mode is ON. Fetch full JSON input schemas for one or more tools by name.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'names' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tool names' ),
					),
					'required'   => array( 'names' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'call-tool',
				'title'       => 'Call Tool',
				'description' => 'Compact mode is ON. Run any catalog tool by name with its arguments. The tool must be enabled and your user must hold its capability; disabled tools are refused.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array( 'type' => 'string' ),
						'arguments' => array( 'type' => 'object', 'default' => new stdClass() ),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array( 'readOnlyHint' => false ),
			),
		);
	}

	public function catalog( string $search = '', string $category = '' ): array {
		$out = array();
		foreach ( $this->all() as $name => $tool ) {
			if ( ! $this->is_enabled( $name ) ) {
				continue;
			}
			if ( '' !== $category && $tool['category'] !== $category ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $tool['title'] . ' ' . $tool['description'] ), strtolower( $search ) ) ) {
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'category' => $tool['category'],
				'summary'  => mb_substr( $tool['description'], 0, 120 ),
				'write'    => $tool['write'],
			);
		}
		return $out;
	}

	public function schemas( array $names ): array {
		$out = array();
		foreach ( $names as $name ) {
			$tool = $this->get( sanitize_key( (string) $name ) );
			if ( $tool && $this->is_enabled( sanitize_key( (string) $name ) ) ) {
				$out[ $name ] = $tool['inputSchema'];
			}
		}
		return $out;
	}

	public function call_meta( string $meta_name, array $args ) {
		if ( ! $this->compact_mode() ) {
			return array(
				'error'   => 'wpmcp_compact_off',
				'message' => 'Meta tools are only available when Compact tool mode is enabled.',
			);
		}
		switch ( $meta_name ) {
			case 'list-tools':
				return array(
					'total'     => count( $this->catalog( (string) ( $args['search'] ?? '' ), (string) ( $args['category'] ?? '' ) ) ),
					'tools'     => $this->catalog( (string) ( $args['search'] ?? '' ), (string) ( $args['category'] ?? '' ) ),
					'categories' => array_values( array_unique( array_map( static fn( $t ) => $t['category'], $this->all() ) ) ),
				);
			case 'get-tool-schema':
				$names = isset( $args['names'] ) && is_array( $args['names'] ) ? $args['names'] : array();
				return array( 'schemas' => $this->schemas( $names ) );
			case 'call-tool':
				$name = (string) ( $args['name'] ?? '' );
				$call_args = isset( $args['arguments'] ) && is_array( $args['arguments'] ) ? $args['arguments'] : array();
				return $this->call( $name, $call_args );
			default:
				return new WP_Error( 'wpmcp_unknown_meta_tool', 'Unknown meta tool.' );
		}
	}

	public function call( string $name, array $args ) {
		if ( in_array( $name, array( 'list-tools', 'get-tool-schema', 'call-tool' ), true ) ) {
			return $this->call_meta( $name, $args );
		}
		$tool = $this->get( $name );
		if ( ! $tool ) {
			return new WP_Error( 'wpmcp_unknown_tool', sprintf( 'Unknown tool: %s', $name ), array( 'status' => 404 ) );
		}
		if ( ! $this->is_enabled( $name ) ) {
			return new WP_Error( 'wpmcp_tool_disabled', sprintf( 'Tool %s is disabled. Enable it under WP MCP -> Tools.', $name ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( $tool['capability'] ) ) {
			return new WP_Error( 'wpmcp_forbidden', 'Current user lacks the required capability: ' . $tool['capability'], array( 'status' => 403 ) );
		}
		if ( $tool['confirm'] && empty( $args['confirm'] ) ) {
			return array(
				'error'   => 'confirm_required',
				'message' => sprintf( '%s is destructive. Re-run with confirm:true to proceed.', $name ),
			);
		}
		try {
			return call_user_func( $tool['handler'], $args );
		} catch ( Throwable $e ) {
			return new WP_Error( 'wpmcp_tool_error', $e->getMessage() );
		}
	}
}
