<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_SEOPress extends WPMCP_SEO_Adapter_Base {

	public function slug(): string {
		return 'seopress';
	}

	public function label(): string {
		return 'SEOPress';
	}

	public function is_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || class_exists( 'SEOPress' );
	}

	public function get_post_seo( int $post_id ): array {
		$out = array(
			'plugin'      => $this->label(),
			'title'       => (string) get_post_meta( $post_id, '_seopress_titles_title', true ),
			'description' => (string) get_post_meta( $post_id, '_seopress_titles_desc', true ),
			'canonical'   => (string) get_post_meta( $post_id, '_seopress_robots_canonical', true ),
			'noindex'     => 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ),
			'nofollow'    => 'yes' === get_post_meta( $post_id, '_seopress_robots_follow', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_seopress_analysis_target_kw', true ),
			'og_title'    => (string) get_post_meta( $post_id, '_seopress_social_fb_title', true ),
			'og_description' => (string) get_post_meta( $post_id, '_seopress_social_fb_desc', true ),
			'twitter_title' => (string) get_post_meta( $post_id, '_seopress_social_twitter_title', true ),
			'twitter_description' => (string) get_post_meta( $post_id, '_seopress_social_twitter_desc', true ),
		);
		foreach ( array( 'og_image' => '_seopress_social_fb_img', 'twitter_image' => '_seopress_social_twitter_img' ) as $field => $key ) {
			$img = $this->image_from_meta( $post_id, $key );
			if ( $img ) {
				$out[ $field ] = $img;
			}
		}
		return $this->clean( $out );
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		$map     = array(
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
			'canonical'   => '_seopress_robots_canonical',
			'focus_keyword' => '_seopress_analysis_target_kw',
			'og_title'    => '_seopress_social_fb_title',
			'og_description' => '_seopress_social_fb_desc',
			'twitter_title' => '_seopress_social_twitter_title',
			'twitter_description' => '_seopress_social_twitter_desc',
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			update_post_meta( $post_id, '_seopress_robots_index', $fields['noindex'] ? 'yes' : '' );
			$updated[] = 'noindex';
		}
		if ( isset( $fields['nofollow'] ) ) {
			update_post_meta( $post_id, '_seopress_robots_follow', $fields['nofollow'] ? 'yes' : '' );
			$updated[] = 'nofollow';
		}
		foreach ( array( 'og_image' => '_seopress_social_fb_img', 'twitter_image' => '_seopress_social_twitter_img' ) as $field => $key ) {
			if ( isset( $fields[ $field ] ) ) {
				$this->write_image( $post_id, $key, $fields[ $field ] );
				$updated[] = $field;
			}
		}
		return array( 'updated' => $updated );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		return $this->clean(
			array(
				'plugin'      => $this->label(),
				'title'       => (string) get_term_meta( $term_id, '_seopress_titles_title', true ),
				'description' => (string) get_term_meta( $term_id, '_seopress_titles_desc', true ),
				'noindex'     => 'yes' === get_term_meta( $term_id, '_seopress_robots_index', true ),
			)
		);
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		foreach ( array( 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc' ) as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_term_meta( $term_id, $key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			update_term_meta( $term_id, '_seopress_robots_index', $fields['noindex'] ? 'yes' : '' );
			$updated[] = 'noindex';
		}
		return array( 'updated' => $updated );
	}

	public function get_settings(): array {
		$titles  = get_option( 'seopress_titles_option_name', array() );
		$social  = get_option( 'seopress_social_option_name', array() );
		return array(
			'plugin'          => $this->label(),
			'site_title'      => $titles['seopress_titles_home_site_title'] ?? '',
			'home_description' => $titles['seopress_titles_home_site_desc'] ?? '',
			'og_default_image' => $social['seopress_social_og_img']['url'] ?? '',
		);
	}

	private function image_from_meta( int $post_id, string $key ): ?array {
		$value = get_post_meta( $post_id, $key, true );
		if ( empty( $value ) ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return $this->image_payload( (int) $value, '' );
		}
		return $this->image_payload( 0, (string) $value );
	}

	private function write_image( int $post_id, string $key, $value ): void {
		$id  = is_array( $value ) ? (int) ( $value['id'] ?? 0 ) : ( is_numeric( $value ) ? (int) $value : 0 );
		$url = is_array( $value ) ? (string) ( $value['url'] ?? '' ) : (string) $value;
		if ( ! $id && '' !== $url ) {
			$id = $this->attachment_id_from( $url );
		}
		if ( $id ) {
			update_post_meta( $post_id, $key, $id );
			return;
		}
		if ( '' !== $url ) {
			update_post_meta( $post_id, $key, esc_url_raw( $url ) );
		}
	}

	private function clean( array $out ): array {
		return array_filter(
			$out,
			static fn( $v ) => '' !== $v && false !== $v && null !== $v && array() !== $v
		);
	}
}
