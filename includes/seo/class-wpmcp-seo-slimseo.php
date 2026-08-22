<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_SlimSEO extends WPMCP_SEO_Adapter_Base {

	const META = 'slim_seo';

	public function slug(): string {
		return 'slimseo';
	}

	public function label(): string {
		return 'Slim SEO';
	}

	public function is_active(): bool {
		return defined( 'SLIM_SEO_VER' ) || class_exists( 'SlimSEO' );
	}

	public function supported_fields(): array {
		return array(
			'title',
			'description',
			'canonical',
			'noindex',
			'nofollow',
			'og_image',
			'twitter_image',
		);
	}

	public function get_post_seo( int $post_id ): array {
		$data = get_post_meta( $post_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		return $this->clean(
			array(
				'plugin'      => $this->label(),
				'title'       => (string) ( $data['title'] ?? '' ),
				'description' => (string) ( $data['description'] ?? '' ),
				'canonical'   => (string) ( $data['canonical'] ?? '' ),
				'noindex'     => ! empty( $data['noindex'] ),
				'nofollow'    => ! empty( $data['nofollow'] ),
				'og_image'    => isset( $data['social_image'] ) ? $this->image_payload( 0, (string) $data['social_image'] ) : null,
			)
		);
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		if ( empty( $fields ) ) {
			return array( 'updated' => array() );
		}
		$data = get_post_meta( $post_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		$map  = array(
			'title'       => 'title',
			'description' => 'description',
			'canonical'   => 'canonical',
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $key ] = sanitize_text_field( (string) $fields[ $field ] );
				$updated[] = $field;
			}
		}
		foreach ( array( 'noindex', 'nofollow' ) as $flag ) {
			if ( isset( $fields[ $flag ] ) ) {
				if ( $fields[ $flag ] ) {
					$data[ $flag ] = true;
				} else {
					unset( $data[ $flag ] );
				}
				$updated[] = $flag;
			}
		}
		if ( isset( $fields['og_image'] ) ) {
			$url = is_array( $fields['og_image'] ) ? (string) ( $fields['og_image']['url'] ?? '' ) : (string) $fields['og_image'];
			$id  = is_array( $fields['og_image'] ) ? (int) ( $fields['og_image']['id'] ?? 0 ) : ( is_numeric( $fields['og_image'] ) ? (int) $fields['og_image'] : 0 );
			if ( ! $id && '' !== $url ) {
				$id = $this->attachment_id_from( $url );
			}
			if ( $id && '' === $url ) {
				$url = wp_get_attachment_url( $id );
			}
			if ( '' !== $url ) {
				$data['social_image'] = esc_url_raw( $url );
				$updated[]            = 'og_image';
			}
		}
		update_post_meta( $post_id, self::META, $data );
		return array( 'updated' => $updated );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		$data = get_term_meta( $term_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		return $this->clean(
			array(
				'plugin'      => $this->label(),
				'title'       => (string) ( $data['title'] ?? '' ),
				'description' => (string) ( $data['description'] ?? '' ),
				'canonical'   => (string) ( $data['canonical'] ?? '' ),
				'noindex'     => ! empty( $data['noindex'] ),
			)
		);
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		if ( empty( $fields ) ) {
			return array( 'updated' => array() );
		}
		$data = get_term_meta( $term_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		foreach ( array( 'title', 'description', 'canonical' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = sanitize_text_field( (string) $fields[ $key ] );
				$updated[] = $key;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			if ( $fields['noindex'] ) {
				$data['noindex'] = true;
			} else {
				unset( $data['noindex'] );
			}
			$updated[] = 'noindex';
		}
		update_term_meta( $term_id, self::META, $data );
		return array( 'updated' => $updated );
	}

	public function get_settings(): array {
		$settings = get_option( 'slim_seo', array() );
		return array(
			'plugin'          => $this->label(),
			'site_title'      => $settings['homepage_title'] ?? '',
			'homepage_description' => $settings['homepage_description'] ?? '',
			'noindex_homepage' => ! empty( $settings['homepage_noindex'] ),
			'linkedin_verification' => $settings['linkedin_verification'] ?? '',
		);
	}

	private function clean( array $out ): array {
		return array_filter(
			$out,
			static fn( $v ) => '' !== $v && false !== $v && null !== $v && array() !== $v
		);
	}
}
