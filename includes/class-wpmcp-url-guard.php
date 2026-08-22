<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Url_Guard {

	public static function validate( string $url ): WP_Error|true {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'wpmcp_bad_url', 'A valid absolute http(s) URL is required.' );
		}
		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'wpmcp_bad_scheme', 'Only http and https URLs are allowed.' );
		}
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return new WP_Error( 'wpmcp_credentials_in_url', 'URLs with embedded credentials are not allowed.' );
		}
		if ( isset( $parsed['port'] ) && ! in_array( (int) $parsed['port'], array( 80, 443 ), true ) ) {
			return new WP_Error( 'wpmcp_bad_port', 'Only ports 80 and 443 are allowed.' );
		}
		$host = strtolower( $parsed['host'] );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return new WP_Error( 'wpmcp_private_host', 'Requests to local hostnames are not allowed.' );
		}
		$ip = self::resolve_ip( $host );
		if ( null !== $ip && ! self::is_public_ip( $ip ) ) {
			return new WP_Error( 'wpmcp_private_ip', 'Requests to private or reserved IP addresses are blocked.' );
		}
		return true;
	}

	private static function resolve_ip( string $host ): ?string {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$records = dns_get_record( $host, DNS_A + DNS_AAAA );
		if ( empty( $records ) ) {
			return gethostbyname( $host );
		}
		foreach ( $records as $record ) {
			if ( ! empty( $record['ip'] ) ) {
				return $record['ip'];
			}
		}
		return null;
	}

	public static function is_public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( '169.254.169.254' === $ip ) {
			return false;
		}
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, $flags );
	}
}
