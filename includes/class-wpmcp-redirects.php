<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Redirects {

	public const CODES = array( 301, 302, 307, 308 );

	private function normalize_path( string $path ): string {
		$path = wp_parse_url( $path, PHP_URL_PATH ) ?: $path;
		$path = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	public function all(): array {
		return (array) get_option( 'wpmcp_redirects', array() );
	}

	public function save_all( array $redirects ): void {
		update_option( 'wpmcp_redirects', array_values( $redirects ), false );
	}

	public function add( string $from, string $to, int $code = 301 ): array|WP_Error {
		if ( ! in_array( $code, self::CODES, true ) ) {
			return new WP_Error( 'wpmcp_bad_code', 'Code must be one of: ' . implode( ', ', self::CODES ) );
		}
		$from = $this->normalize_path( $from );
		if ( '' === $from ) {
			return new WP_Error( 'wpmcp_bad_from', 'A source path is required.' );
		}
		$to_host = wp_parse_url( $to, PHP_URL_HOST );
		$to_path = $this->normalize_path( $to );
		if ( null === $to_host && '' === filter_var( $to, FILTER_VALIDATE_URL ) && ! str_starts_with( $to, '/' ) ) {
			return new WP_Error( 'wpmcp_bad_to', 'Target must be an absolute URL or a site path starting with /.' );
		}
		if ( null === $to_host && $to_path === $from ) {
			return new WP_Error( 'wpmcp_loop', 'Source and target paths are identical.' );
		}
		$redirects = $this->all();
		foreach ( $redirects as $existing ) {
			if ( $existing['from'] === $from ) {
				return new WP_Error( 'wpmcp_duplicate', sprintf( 'A redirect for %s already exists.', $from ) );
			}
		}
		$redirects[] = array(
			'from'    => $from,
			'to'      => $to,
			'code'    => $code,
			'hits'    => 0,
			'enabled' => true,
		);
		$this->save_all( $redirects );
		return end( $redirects );
	}

	public function update( int $index, array $fields ): array|WP_Error {
		$redirects = $this->all();
		if ( ! isset( $redirects[ $index ] ) ) {
			return new WP_Error( 'wpmcp_not_found', 'No redirect at that index.' );
		}
		foreach ( array( 'to', 'code', 'enabled' ) as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$redirects[ $index ][ $field ] = $fields[ $field ];
			}
		}
		$this->save_all( $redirects );
		return $redirects[ $index ];
	}

	public function delete( int $index ): bool|WP_Error {
		$redirects = $this->all();
		if ( ! isset( $redirects[ $index ] ) ) {
			return new WP_Error( 'wpmcp_not_found', 'No redirect at that index.' );
		}
		unset( $redirects[ $index ] );
		$this->save_all( $redirects );
		return true;
	}

	public function maybe_redirect(): void {
		$redirects = $this->all();
		if ( empty( $redirects ) || is_admin() ) {
			return;
		}
		$request = esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' );
		if ( '' === $request ) {
			return;
		}
		$path = $this->normalize_path( rawurldecode( $request ) );
		foreach ( $redirects as $index => $redirect ) {
			if ( empty( $redirect['enabled'] ) ) {
				continue;
			}
			if ( $this->normalize_path( (string) $redirect['from'] ) !== $path ) {
				continue;
			}
			$redirects[ $index ]['hits'] = (int) ( $redirect['hits'] ?? 0 ) + 1;
			$this->save_all( $redirects );
			wp_safe_redirect( $redirect['to'], (int) $redirect['code'] );
			exit;
		}
	}
}
