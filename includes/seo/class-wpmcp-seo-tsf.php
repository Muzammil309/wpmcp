<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_TSF extends WPMCP_SEO_Adapter_Base {

	public function slug(): string {
		return 'tsf';
	}

	public function label(): string {
		return 'The SEO Framework';
	}

	public function is_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' );
	}

	public function get_post_seo( int $post_id ): array {
		$out = array(
			'plugin'      => $this->label(),
			'title'       => (string) get_post_meta( $post_id, '_genesis_title', true ),
			'description' => (string) get_post_meta( $post_id, '_genesis_description', true ),
			'canonical'   => (string) get_post_meta( $post_id, '_genesis_canonical', true ),
			'noindex'     => '1' === get_post_meta( $post_id, '_genesis_noindex', true ),
			'nofollow'    => '1' === get_post_meta( $post_id, '_genesis_nofollow', true ),
			'og_title'    => (string) get_post_meta( $post_id, '_open_graph_title', true ),
			'og_description' => (string) get_post_meta( $post_id, '_open_graph_description', true ),
			'twitter_title' => (string) get_post_meta( $post_id, '_twitter_title', true ),
			'twitter_description' => (string) get_post_meta( $post_id, '_twitter_description', true ),
		);
		foreach ( array( 'og_image' => '_social_image_url', 'twitter_image' => '_social_image_url' ) as $field => $key ) {
			$url = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $url ) {
				$out[ $field ] = $this->image_payload( 0, $url );
				break;
			}
		}
		return $this->clean( $out );
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		$map     = array(
			'title'       => '_genesis_title',
			'description' => '_genesis_description',
			'canonical'   => '_genesis_canonical',
			'og_title'    => '_open_graph_title',
			'og_description' => '_open_graph_description',
			'twitter_title' => '_twitter_title',
			'twitter_description' => '_twitter_description',
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		foreach ( array( 'noindex' => '_genesis_noindex', 'nofollow' => '_genesis_nofollow' ) as $field => $key ) {
			if ( isset( $fields[ $field ] ) ) {
				update_post_meta( $post_id, $key, $fields[ $field ] ? '1' : '' );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['og_image'] ) ) {
			$url = is_array( $fields['og_image'] ) ? (string) ( $fields['og_image']['url'] ?? '' ) : (string) $fields['og_image'];
			$id  = is_array( $fields['og_image'] ) ? (int) ( $fields['og_image']['id'] ?? 0 ) : 0;
			if ( ! $id && '' !== $url ) {
				$id = $this->attachment_id_from( $url );
			}
			if ( $id ) {
				$url = wp_get_attachment_url( $id );
			}
			if ( '' !== $url ) {
				update_post_meta( $post_id, '_social_image_url', esc_url_raw( $url ) );
				if ( $id ) {
					update_post_meta( $post_id, '_social_image_id', $id );
				}
				$updated[] = 'og_image';
			}
		}
		return array( 'updated' => $updated );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		return $this->clean(
			array(
				'plugin'      => $this->label(),
				'title'       => (string) get_term_meta( $term_id, '_genesis_title', true ),
				'description' => (string) get_term_meta( $term_id, '_genesis_description', true ),
				'noindex'     => '1' === get_term_meta( $term_id, '_genesis_noindex', true ),
			)
		);
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		foreach ( array( 'title' => '_genesis_title', 'description' => '_genesis_description' ) as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_term_meta( $term_id, $key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			update_term_meta( $term_id, '_genesis_noindex', $fields['noindex'] ? '1' : '' );
			$updated[] = 'noindex';
		}
		return array( 'updated' => $updated );
	}

	public function get_settings(): array {
		$options = (array) get_option( 'the_seo_framework', array() );
		return array(
			'plugin'      => $this->label(),
			'home_title'  => $options['homepage_title'] ?? '',
			'home_description' => $options['homepage_description'] ?? '',
		);
	}

	private function clean( array $out ): array {
		return array_filter(
			$out,
			static fn( $v ) => '' !== $v && false !== $v && null !== $v && array() !== $v
		);
	}
}
