<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Blocks {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'list-blocks',
			array(
				'title'       => 'List Blocks',
				'description' => 'Catalog of registered Gutenberg block types with categories and attribute names. Filter by search term.',
				'category'    => 'blocks',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 50, 'maximum' => 200 ),
					),
				),
				'handler'     => array( $this, 'list_blocks' ),
			)
		);

		$this->registry->register(
			'get-block-schema',
			array(
				'title'       => 'Get Block Schema',
				'description' => 'Real attribute names, types and defaults for one block type, straight from its registration.',
				'category'    => 'blocks',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string', 'description' => 'e.g. core/heading' ),
					),
					'required'   => array( 'name' ),
				),
				'handler'     => array( $this, 'get_block_schema' ),
			)
		);

		$this->registry->register(
			'get-post-blocks',
			array(
				'title'       => 'Get Post Blocks',
				'description' => 'Parsed block tree of a post. Each node carries a numeric path (index per level) you pass back to insert/update/remove/move.',
				'category'    => 'blocks',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'max_depth'   => array( 'type' => 'integer', 'default' => 4 ),
						'include_html' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Include each block innerHTML (truncated)' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'get_post_blocks' ),
			)
		);

		$this->registry->register(
			'insert-block',
			array(
				'title'       => 'Insert Block',
				'description' => 'Insert a block into a post at an optional path position (append by default). Provide the block name, attributes and its rendered HTML.',
				'category'    => 'blocks',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'name'     => array( 'type' => 'string', 'description' => 'Block name, e.g. core/paragraph' ),
						'attrs'    => array( 'type' => 'object' ),
						'content_html' => array( 'type' => 'string', 'description' => 'Rendered inner HTML, e.g. <p>Hi</p>' ),
						'path'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Optional insertion path; last int = sibling index. Omit to append.' ),
					),
					'required'   => array( 'post_id', 'name' ),
				),
				'handler'     => array( $this, 'insert_block' ),
			)
		);

		$this->registry->register(
			'update-block-attrs',
			array(
				'title'       => 'Update Block Attributes',
				'description' => 'Merge attributes into a block identified by path (from get-post-blocks). Optionally replace its HTML too.',
				'category'    => 'blocks',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'attrs'   => array( 'type' => 'object' ),
						'content_html' => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'handler'     => array( $this, 'update_block_attrs' ),
			)
		);

		$this->registry->register(
			'remove-block',
			array(
				'title'       => 'Remove Block',
				'description' => 'Delete a block by path. Requires confirm:true.',
				'category'    => 'blocks',
				'write'       => true,
				'confirm'     => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'post_id', 'path', 'confirm' ),
				),
				'handler'     => array( $this, 'remove_block' ),
			)
		);

		$this->registry->register(
			'move-block',
			array(
				'title'       => 'Move Block',
				'description' => 'Move a block to another path/index. Moving into own subtree is refused as a no-op.',
				'category'    => 'blocks',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'path'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Source path' ),
						'to_path'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Destination parent path + target index as last element' ),
					),
					'required'   => array( 'post_id', 'path', 'to_path' ),
				),
				'handler'     => array( $this, 'move_block' ),
			)
		);
		$this->registry->register(
			'list-patterns',
			array(
				'title'       => 'List Patterns',
				'description' => 'Registered block patterns (prebuilt compositions). Filter by category or search term.',
				'category'    => 'blocks',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Substring match on name, title or description' ),
						'category' => array( 'type' => 'string' ),
					),
				),
				'handler'     => array( $this, 'list_patterns' ),
			)
		);

		$this->registry->register(
			'insert-pattern',
			array(
				'title'       => 'Insert Pattern',
				'description' => 'Insert a registered block pattern into a post by name, at an optional path position.',
				'category'    => 'blocks',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'pattern_name' => array( 'type' => 'string', 'description' => 'e.g. core/query-standard-posts' ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Optional insertion path; omit to append' ),
					),
					'required'   => array( 'post_id', 'pattern_name' ),
				),
				'handler'     => array( $this, 'insert_pattern' ),
			)
		);

		$this->registry->register(
			'duplicate-block',
			array(
				'title'       => 'Duplicate Block',
				'description' => 'Clone the block at an index path (with its inner blocks) and insert the copy immediately after it.',
				'category'    => 'blocks',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'handler'     => array( $this, 'duplicate_block' ),
			)
		);
	}

	public function list_patterns( array $args ): array {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array( 'error' => 'patterns_unavailable' );
		}
		$search   = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$category = sanitize_title( (string) ( $args['category'] ?? '' ) );
		$out      = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( '' !== $category && empty( $pattern['categories'] ) || ! in_array( $category, (array) ( $pattern['categories'] ?? array() ), true ) ) {
				continue;
			}
			$haystack = strtolower( $pattern['name'] . ' ' . ( $pattern['title'] ?? '' ) . ' ' . wp_strip_all_tags( (string) ( $pattern['description'] ?? '' ) ) );
			if ( '' !== $search && ! str_contains( $haystack, $search ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'],
				'categories'  => (array) ( $pattern['categories'] ?? array() ),
				'description' => wp_strip_all_tags( (string) ( $pattern['description'] ?? '' ) ),
				'block_types' => (array) ( $pattern['blockTypes'] ?? array() ),
			);
		}
		return array( 'total' => count( $out ), 'patterns' => $out );
	}

	public function insert_pattern( array $args ): array {
		return $this->mutate(
			(int) $args['post_id'],
			function ( array &$blocks ) use ( $args ): string {
				$name = sanitize_text_field( (string) $args['pattern_name'] );
				$registry = WP_Block_Patterns_Registry::get_instance();
				if ( ! $registry->is_registered( $name ) ) {
					return 'unknown_pattern';
				}
				$pattern = $registry->get_registered( $name );
				$parsed  = $this->normalize( parse_blocks( $pattern['content'] ) );
				if ( empty( $parsed ) ) {
					return 'empty_pattern';
				}
				$path = isset( $args['path'] ) && is_array( $args['path'] ) ? array_values( array_map( 'intval', $args['path'] ) ) : array();
				if ( empty( $path ) ) {
					$parent_path = array();
					$index       = PHP_INT_MAX;
				} else {
					$parent_path = array_slice( $path, 0, -1 );
					$index       = (int) end( $path );
				}
				$list =& $this->resolve_parent( $blocks, $parent_path );
				if ( null === $list ) {
					return 'invalid_path';
				}
				$index = max( 0, min( count( $list ), $index ) );
				array_splice( $list, $index, 0, $parsed );
				return sprintf( 'inserted pattern %s (%d blocks)', $name, count( $parsed ) );
			},
			'insert-pattern'
		);
	}

	public function duplicate_block( array $args ): array {
		return $this->mutate(
			(int) $args['post_id'],
			function ( array &$blocks ) use ( $args ): string {
				$path = array_map( 'intval', (array) $args['path'] );
				if ( empty( $path ) ) {
					return 'invalid_path';
				}
				$index = array_pop( $path );
				$list  =& $this->resolve_parent( $blocks, $path );
				if ( null === $list || ! isset( $list[ $index ] ) ) {
					return 'invalid_path';
				}
				$clone = $list[ $index ];
				if ( isset( $clone['attrs']['anchor'] ) && '' !== $clone['attrs']['anchor'] ) {
					$clone['attrs']['anchor'] = $clone['attrs']['anchor'] . '-copy';
				}
				array_splice( $list, (int) $index + 1, 0, array( $clone ) );
				return sprintf( 'duplicated %s at %d', (string) $clone['blockName'], (int) $index + 1 );
			},
			'duplicate-block'
		);
	}

	public function list_blocks( array $args ): array {
		$registry = WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$search   = strtolower( sanitize_text_field( $args['search'] ?? '' ) );
		$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		$out      = array();
		foreach ( $all as $name => $block_type ) {
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $block_type->title ), $search ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'title'       => $block_type->title,
				'category'    => $block_type->category,
				'is_dynamic'  => $block_type->is_dynamic(),
				'attributes'  => array_keys( (array) $block_type->attributes ),
			);
			if ( count( $out ) >= $per_page ) {
				break;
			}
		}
		return array( 'total_registered' => count( $all ), 'blocks' => $out );
	}

	public function get_block_schema( array $args ): array {
		$name       = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $block_type ) {
			return array( 'error' => 'unknown_block', 'message' => sprintf( '%s is not registered.', $name ) );
		}
		$attributes = array();
		foreach ( (array) $block_type->attributes as $attr_name => $definition ) {
			$attributes[ $attr_name ] = array(
				'type'    => is_array( $definition ) ? ( $definition['type'] ?? null ) : null,
				'default' => is_array( $definition ) ? ( $definition['default'] ?? null ) : null,
				'source'  => is_array( $definition ) ? ( $definition['source'] ?? null ) : null,
			);
		}
		return array(
			'name'        => $name,
			'title'       => $block_type->title,
			'category'    => $block_type->category,
			'supports'    => (array) $block_type->supports,
			'attributes'  => $attributes,
			'example'     => $block_type->example ?? null,
			'description' => wp_strip_all_tags( (string) $block_type->description ),
		);
	}

	public function get_post_blocks( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$max_depth  = max( 1, (int) ( $args['max_depth'] ?? 4 ) );
		$with_html  = ! empty( $args['include_html'] );
		$blocks     = $this->normalize( parse_blocks( (string) $post->post_content ) );
		$tree       = $this->walk( $blocks, array(), $max_depth, $with_html );
		return array(
			'post_id'     => $post->ID,
			'is_gutenberg' => has_blocks( $post ),
			'blocks'      => $tree,
		);
	}

	private function normalize( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( empty( $block['blockName'] ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->normalize( $block['innerBlocks'] );
			} else {
				$block['innerBlocks'] = array();
			}
			$out[] = $block;
		}
		return $out;
	}

	private function walk( array $blocks, array $path, int $max_depth, bool $with_html ): array {
		$out = array();
		foreach ( array_values( $blocks ) as $i => $block ) {
			if ( empty( $block['blockName'] ) && '' === trim( $block['innerHTML'] ?? '' ) ) {
				continue;
			}
			$node_path = array_merge( $path, array( $i ) );
			$node      = array(
				'path'      => $node_path,
				'name'      => (string) $block['blockName'],
				'attrs'     => (object) ( $block['attrs'] ?? array() ),
				'has_inner' => ! empty( $block['innerBlocks'] ),
			);
			if ( $with_html ) {
				$node['html'] = mb_substr( (string) $block['innerHTML'], 0, 300 );
			}
			if ( ! empty( $block['innerBlocks'] ) && count( $path ) < $max_depth ) {
				$node['innerBlocks'] = $this->walk( $block['innerBlocks'], $node_path, $max_depth, $with_html );
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$node['inner_count'] = count( $block['innerBlocks'] );
			}
			$out[] = $node;
		}
		return $out;
	}

	public function insert_block( array $args ): array {
		return $this->mutate(
			(int) $args['post_id'],
			function ( array &$blocks ) use ( $args ): string {
				$name = sanitize_text_field( (string) $args['name'] );
				if ( empty( WP_Block_Type_Registry::get_instance()->get_registered( $name ) ) ) {
					return 'unknown_block';
				}
				$html = (string) ( $args['content_html'] ?? '' );
				$new  = array(
					'blockName'    => $name,
					'attrs'        => isset( $args['attrs'] ) && is_array( $args['attrs'] ) ? $args['attrs'] : array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $html,
					'innerContent' => '' === $html ? array() : array( $html ),
				);
				$path = isset( $args['path'] ) && is_array( $args['path'] ) ? array_values( array_map( 'intval', $args['path'] ) ) : array();
				if ( empty( $path ) ) {
					$parent_path = array();
					$index       = PHP_INT_MAX;
				} else {
					$parent_path = array_slice( $path, 0, -1 );
					$index       = (int) end( $path );
				}
				$list =& $this->resolve_parent( $blocks, $parent_path );
				if ( null === $list ) {
					return 'invalid_path';
				}
				$index = max( 0, min( count( $list ), $index ) );
				array_splice( $list, $index, 0, array( $new ) );
				return sprintf( 'inserted %s at %d', $name, $index );
			},
			'insert-block'
		);
	}

	public function update_block_attrs( array $args ): array {
		return $this->mutate(
			(int) $args['post_id'],
			function ( array &$blocks ) use ( $args ): string {
				$path = array_map( 'intval', (array) $args['path'] );
				$ref  =& $this->find_ref( $blocks, $path );
				if ( null === $ref ) {
					return 'invalid_path';
				}
				foreach ( (array) ( $args['attrs'] ?? array() ) as $key => $value ) {
					$ref['attrs'][ sanitize_key( (string) $key ) ] = $value;
				}
				if ( isset( $args['content_html'] ) ) {
					$html                = (string) $args['content_html'];
					$ref['innerHTML']    = $html;
					$ref['innerContent'] = array( $html );
				}
				return 'updated';
			},
			'update-block-attrs'
		);
	}

	public function remove_block( array $args ): array {
		return $this->mutate(
			(int) $args['post_id'],
			function ( array &$blocks ) use ( $args ) {
				$path = array_map( 'intval', (array) $args['path'] );
				if ( empty( $path ) ) {
					return 'invalid_path';
				}
				$index = array_pop( $path );
				$list  =& $this->resolve_parent( $blocks, $path );
				if ( null === $list || ! isset( $list[ $index ] ) ) {
					return 'invalid_path';
				}
				$name = (string) $list[ $index ]['blockName'];
				array_splice( $list, (int) $index, 1 );
				return sprintf( 'removed %s', $name );
			},
			'remove-block'
		);
	}

	public function move_block( array $args ): array {
		return $this->mutate(
			(int) $args['post_id'],
			function ( array &$blocks ) use ( $args ) {
				$from  = array_map( 'intval', (array) $args['path'] );
				$to    = array_map( 'intval', (array) $args['to_path'] );
				$index = array_pop( $from );
				$src   =& $this->resolve_parent( $blocks, $from );
				if ( null === $src || ! isset( $src[ $index ] ) ) {
					return 'invalid_source_path';
				}
				$moving = $src[ $index ];
				$dest_parent = $to;
				$dest_index  = array_pop( $dest_parent );
				if ( $this->path_in_subtree( $moving, $dest_parent ) ) {
					return 'no_op_subtree';
				}
				$dst =& $this->resolve_parent( $blocks, $dest_parent );
				if ( null === $dst ) {
					return 'invalid_dest_path';
				}
				array_splice( $src, (int) $index, 1 );
				$dest_index = max( 0, min( count( $dst ), (int) $dest_index ) );
				array_splice( $dst, $dest_index, 0, array( $moving ) );
				return sprintf( 'moved to index %d', $dest_index );
			},
			'move-block'
		);
	}

	private function mutate( int $post_id, callable $fn, string $action ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$before_content = $post->post_content;
		$blocks         = $this->normalize( parse_blocks( (string) $before_content ) );
		$outcome        = $fn( $blocks );
		if ( in_array( $outcome, array( 'unknown_block', 'invalid_path', 'invalid_source_path', 'invalid_dest_path', 'no_op_subtree', 'unknown_pattern', 'empty_pattern' ), true ) ) {
			return array( 'error' => $outcome );
		}
		$content = implode( '', array_map( 'serialize_block', $blocks ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);
		clean_post_cache( $post_id );
		$this->log->record( 'blocks', $action, $post_id, $post->post_title, $outcome, array( 'content' => $before_content ), true );
		return array(
			'ok'      => true,
			'action'  => $outcome,
			'block_summary' => $this->summary( parse_blocks( $content ) ),
		);
	}

	private function summary( array $blocks, int $depth = 0 ): array {
		$names = array();
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$names[] = $b['blockName'];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->summary( $b['innerBlocks'], $depth ) );
			}
		}
		return $names;
	}

	private function &resolve_parent( array &$blocks, array $path ) {
		$current =& $blocks;
		foreach ( $path as $segment ) {
			if ( ! isset( $current[ $segment ]['innerBlocks'] ) ) {
				$null = null;
				return $null;
			}
			$tmp =& $current[ $segment ]['innerBlocks'];
			unset( $current );
			$current =& $tmp;
		}
		return $current;
	}

	private function &find_ref( array &$blocks, array $path ) {
		$parent_path = $path;
		$index       = array_pop( $parent_path );
		$list        =& $this->resolve_parent( $blocks, $parent_path );
		if ( null === $list || ! isset( $list[ $index ] ) ) {
			$null = null;
			return $null;
		}
		return $list[ $index ];
	}

	private function path_in_subtree( array $block, array $candidate_path ): bool {
		if ( empty( $block['innerBlocks'] ) || empty( $candidate_path ) ) {
			return false;
		}
		$head = (int) $candidate_path[0];
		if ( ! isset( $block['innerBlocks'][ $head ] ) ) {
			return false;
		}
		$rest = array_slice( $candidate_path, 1 );
		if ( empty( $rest ) ) {
			return true;
		}
		return $this->path_in_subtree( $block['innerBlocks'][ $head ], $rest );
	}
}
