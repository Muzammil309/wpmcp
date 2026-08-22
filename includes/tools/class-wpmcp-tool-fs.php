<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_FS {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	private const FORBIDDEN_FILES = array( 'wp-config.php', '.htaccess' );

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$base = array( 'category' => 'filesystem', 'capability' => 'manage_options' );

		$this->registry->register(
			'fs-read',
			$base + array(
				'title'       => 'Filesystem Read',
				'description' => 'Read-only filesystem access inside the WordPress install. Operations: read-file (offset/limit for big files), list-directory (recursive up to 5 levels), search-files (bounded content grep). wp-config.php and .htaccess are refused.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation'  => array( 'type' => 'string', 'enum' => array( 'read-file', 'list-directory', 'search-files' ), 'required' => true ),
						'path'       => array( 'type' => 'string', 'description' => 'Relative to the WordPress root' ),
						'offset'     => array( 'type' => 'integer', 'description' => 'read-file: 1-based start line' ),
						'limit'      => array( 'type' => 'integer', 'description' => 'read-file: number of lines' ),
						'recursive'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'list-directory' ),
						'query'      => array( 'type' => 'string', 'description' => 'search-files: substring (case-sensitive)' ),
						'extensions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'search-files: e.g. ["php","css"]' ),
						'max_results' => array( 'type' => 'integer', 'default' => 100, 'maximum' => 500, 'description' => 'search-files cap' ),
					),
					'required'   => array( 'operation' ),
				),
				'handler'     => array( $this, 'read' ),
			)
		);

		$this->registry->register(
			'fs-write',
			array(
				'title'       => 'Filesystem Write',
				'description' => 'Create/overwrite a file or replace an exact string in one (both back up the original first), or delete a file (confirm:true). Refuses wp-config.php and .htaccess.',
				'category'    => 'filesystem',
				'write'       => true,
				'pro'         => true,
				'capability'  => 'manage_options',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'operation'  => array( 'type' => 'string', 'enum' => array( 'write-file', 'edit-file', 'delete-file' ), 'required' => true ),
						'path'       => array( 'type' => 'string', 'required' => true ),
						'content'    => array( 'type' => 'string', 'description' => 'write-file: full new content' ),
						'search'     => array( 'type' => 'string', 'description' => 'edit-file: exact string to find' ),
						'replace'    => array( 'type' => 'string', 'description' => 'edit-file: replacement' ),
						'confirm'    => array( 'type' => 'boolean', 'description' => 'delete-file only' ),
					),
					'required'   => array( 'operation', 'path' ),
				),
				'handler'     => array( $this, 'write' ),
			)
		);
	}

	private function guard_path( string $relative ): ?string {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || str_contains( $relative, '..' ) ) {
			return 'invalid_path';
		}
		foreach ( self::FORBIDDEN_FILES as $forbidden ) {
			if ( basename( $relative ) === $forbidden ) {
				return 'protected_file';
			}
		}
		return null;
	}

	private function absolute( string $relative ): string {
		return ABSPATH . ltrim( str_replace( '\\', '/', $relative ), '/' );
	}

	public function read( array $args ): array {
		$path = (string) ( $args['path'] ?? '' );
		$error = $this->guard_path( $path );
		if ( null !== $error && 'search-files' !== ( $args['operation'] ?? '' ) ) {
			return array( 'error' => $error );
		}
		$operation = (string) ( $args['operation'] ?? '' );

		switch ( $operation ) {
			case 'read-file':
				$file = $this->absolute( $path );
				if ( ! is_file( $file ) || ! is_readable( $file ) ) {
					return array( 'error' => 'not_found_or_unreadable' );
				}
				$lines   = file( $file );
				$total   = count( $lines );
				$offset  = max( 1, (int) ( $args['offset'] ?? 1 ) );
				$limit   = min( 2000, max( 1, (int) ( $args['limit'] ?? 500 ) ) );
				$slice   = array_slice( $lines, $offset - 1, $limit );
				return array(
					'path'  => $path,
					'total_lines' => $total,
					'from_line' => $offset,
					'content' => implode( '', array_slice( $slice, 0, 4000 ) ),
				);

			case 'list-directory':
				$dir = rtrim( $this->absolute( $path ), '/' );
				if ( is_file( $dir ) ) {
					$dir = dirname( $dir );
				}
				if ( ! is_dir( $dir ) ) {
					return array( 'error' => 'directory_not_found' );
				}
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME ),
					! empty( $args['recursive'] ) ? RecursiveIteratorIterator::SELF_FIRST : RecursiveIteratorIterator::CHILD_FIRST
				);
				$iterator->setMaxDepth( ! empty( $args['recursive'] ) ? 5 : 1 );
				$out = array();
				try {
					foreach ( $iterator as $item ) {
						if ( count( $out ) >= 500 ) {
							break;
						}
						$out[] = array(
							'path' => ltrim( str_replace( ABSPATH, '', (string) $item ), '/' ),
							'type' => is_dir( (string) $item ) ? 'dir' : 'file',
							'size' => is_file( (string) $item ) ? filesize( (string) $item ) : null,
						);
					}
				} catch ( UnexpectedValueException $e ) {
					// unreadable subtree; return what we have.
				}
				return array( 'path' => $path, 'entries' => $out );

			case 'search-files':
				$query = (string) ( $args['query'] ?? '' );
				if ( '' === $query ) {
					return array( 'error' => 'query_required' );
				}
				$root = rtrim( $this->absolute( '' !== $path ? $path : 'wp-content' ), '/' );
				if ( ! is_dir( $root ) ) {
					return array( 'error' => 'directory_not_found' );
				}
				$max   = min( 500, max( 1, (int) ( $args['max_results'] ?? 100 ) ) );
				$exts  = isset( $args['extensions'] ) && is_array( $args['extensions'] )
					? array_map( 'strtolower', array_map( 'sanitize_key', (array) $args['extensions'] ) )
					: array();
				$matches = array();
				$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME ) );
				foreach ( $it as $file ) {
					if ( count( $matches ) >= $max ) {
						break;
					}
					if ( ! is_file( (string) $file ) || filesize( (string) $file ) > 1048576 ) {
						continue;
					}
					$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
					if ( $exts && ! in_array( $ext, $exts, true ) ) {
						continue;
					}
					$lines = @file( (string) $file );
					if ( ! $lines ) {
						continue;
					}
					foreach ( $lines as $i => $line ) {
						if ( false !== strpos( $line, $query ) ) {
							$matches[] = array(
								'file' => ltrim( str_replace( ABSPATH, '', (string) $file ), '/' ),
								'line' => $i + 1,
								'text' => trim( mb_substr( $line, 0, 300 ) ),
							);
							if ( count( $matches ) >= $max ) {
								break;
							}
						}
					}
				}
				return array( 'query' => $query, 'matches' => $matches, 'truncated' => count( $matches ) >= $max );
		}

		return array( 'error' => 'unknown_operation' );
	}

	public function write( array $args ): array {
		$path  = (string) ( $args['path'] ?? '' );
		$error = $this->guard_path( $path );
		if ( null !== $error ) {
			return array( 'error' => $error );
		}
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		$fs = new WP_Filesystem_Direct( false );
		$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		$file = $this->absolute( $path );
		$operation = (string) ( $args['operation'] ?? '' );

		switch ( $operation ) {
			case 'write-file':
				$content = (string) ( $args['content'] ?? '' );
				$before  = $fs->exists( $file ) ? $fs->get_contents( $file ) : null;
				$dir     = dirname( $file );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					return array( 'error' => 'mkdir_failed' );
				}
				if ( ! $fs->put_contents( $file, $content, $chmod ) ) {
					return array( 'error' => 'write_failed' );
				}
				$this->log->record( 'filesystem', 'write-file', 0, $path, sprintf( 'Wrote %d bytes to %s', strlen( $content ), $path ), null !== $before ? array( 'content' => $before ) : null, true );
				return array( 'ok' => true, 'path' => $path, 'bytes' => strlen( $content ), 'created' => null === $before );

			case 'edit-file':
				$search  = (string) ( $args['search'] ?? '' );
				$replace = (string) ( $args['replace'] ?? '' );
				if ( '' === $search ) {
					return array( 'error' => 'search_required' );
				}
				if ( ! $fs->exists( $file ) ) {
					return array( 'error' => 'not_found_or_unreadable' );
				}
				$content = $fs->get_contents( $file );
				$count   = substr_count( (string) $content, $search );
				if ( 0 === $count ) {
					return array( 'error' => 'search_string_not_found' );
				}
				if ( $count > 1 && empty( $args['replace_all'] ) ) {
					return array( 'error' => 'multiple_matches', 'message' => "Found {$count} matches; pass replace_all:true or narrow the search string." );
				}
				$new = str_replace( $search, $replace, (string) $content );
				if ( ! $fs->put_contents( $file, $new, $chmod ) ) {
					return array( 'error' => 'write_failed' );
				}
				$this->log->record( 'filesystem', 'edit-file', 0, $path, sprintf( 'Edited %s (%d occurrence(s))', $path, $count ), array( 'content' => $content ), true );
				return array( 'ok' => true, 'path' => $path, 'replacements' => $count );

			case 'delete-file':
				if ( empty( $args['confirm'] ) ) {
					return array( 'error' => 'confirm_required' );
				}
				if ( ! $fs->exists( $file ) ) {
					return array( 'error' => 'not_found_or_unreadable' );
				}
				$before = $fs->get_contents( $file );
				if ( ! $fs->delete( $file ) ) {
					return array( 'error' => 'delete_failed' );
				}
				$this->log->record( 'filesystem', 'delete-file', 0, $path, sprintf( 'Deleted %s', $path ), array( 'content' => $before ), true );
				return array( 'deleted' => true, 'path' => $path );
		}

		return array( 'error' => 'unknown_operation' );
	}
}
