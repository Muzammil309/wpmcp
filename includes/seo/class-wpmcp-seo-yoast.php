<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_Yoast extends WPMCP_SEO_Adapter_Base {

	const PREFIX = '_yoast_wpseo_';

	public function slug(): string {
		return 'yoast';
	}

	public function label(): string {
		return 'Yoast SEO';
	}

	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	public function get_post_seo( int $post_id ): array {
		$out = array(
			'plugin'  => $this->label(),
			'title'   => (string) get_post_meta( $post_id, self::PREFIX . 'title', true ),
			'description' => (string) get_post_meta( $post_id, self::PREFIX . 'metadesc', true ),
			'canonical'   => (string) get_post_meta( $post_id, self::PREFIX . 'canonical', true ),
			'noindex'     => '1' === get_post_meta( $post_id, self::PREFIX . 'meta-robots-noindex', true ),
			'nofollow'    => '1' === get_post_meta( $post_id, self::PREFIX . 'meta-robots-nofollow', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, self::PREFIX . 'focuskw', true ),
			'og_title'    => (string) get_post_meta( $post_id, self::PREFIX . 'opengraph-title', true ),
			'og_description' => (string) get_post_meta( $post_id, self::PREFIX . 'opengraph-description', true ),
			'twitter_title' => (string) get_post_meta( $post_id, self::PREFIX . 'twitter-title', true ),
			'twitter_description' => (string) get_post_meta( $post_id, self::PREFIX . 'twitter-description', true ),
		);
		$og_image = $this->image_payload( (int) get_post_meta( $post_id, self::PREFIX . 'opengraph-image-id', true ), (string) get_post_meta( $post_id, self::PREFIX . 'opengraph-image', true ) );
		if ( $og_image ) {
			$out['og_image'] = $og_image;
		}
		$tw_image = $this->image_payload( (int) get_post_meta( $post_id, self::PREFIX . 'twitter-image-id', true ), (string) get_post_meta( $post_id, self::PREFIX . 'twitter-image', true ) );
		if ( $tw_image ) {
			$out['twitter_image'] = $tw_image;
		}
		return $this->clean( $out );
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$fields = $this->filter_fields( $fields );
		$updated = array();
		$map = array(
			'title'       => self::PREFIX . 'title',
			'description' => self::PREFIX . 'metadesc',
			'canonical'   => self::PREFIX . 'canonical',
			'focus_keyword' => self::PREFIX . 'focuskw',
			'og_title'    => self::PREFIX . 'opengraph-title',
			'og_description' => self::PREFIX . 'opengraph-description',
			'twitter_title' => self::PREFIX . 'twitter-title',
			'twitter_description' => self::PREFIX . 'twitter-description',
		);
		foreach ( $map as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			update_post_meta( $post_id, self::PREFIX . 'meta-robots-noindex', $fields['noindex'] ? '1' : '0' );
			$updated[] = 'noindex';
		}
		if ( isset( $fields['nofollow'] ) ) {
			update_post_meta( $post_id, self::PREFIX . 'meta-robots-nofollow', $fields['nofollow'] ? '1' : '0' );
			$updated[] = 'nofollow';
		}
		if ( isset( $fields['og_image'] ) ) {
			$this->write_image( $post_id, 'opengraph-image', $fields['og_image'] );
			$updated[] = 'og_image';
		}
		if ( isset( $fields['twitter_image'] ) ) {
			$this->write_image( $post_id, 'twitter-image', $fields['twitter_image'] );
			$updated[] = 'twitter_image';
		}
		return array( 'updated' => $updated );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		$option_key = 'wpseo_taxonomy_meta';
		$all = get_option( $option_key, array() );
		$meta = $all[ $taxonomy ][ $term_id ] ?? array();
		$out = array(
			'plugin'      => $this->label(),
			'title'       => (string) ( $meta['wpseo_title'] ?? '' ),
			'description' => (string) ( $meta['wpseo_desc'] ?? '' ),
			'canonical'   => (string) ( $meta['wpseo_canonical'] ?? '' ),
			'noindex'     => isset( $meta['wpseo_noindex'] ) && 'index' !== $meta['wpseo_noindex'] && '' !== $meta['wpseo_noindex'],
			'focus_keyword' => (string) ( $meta['wpseo_focuskw'] ?? '' ),
		);
		return $this->clean( $out );
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		$all     = get_option( 'wpseo_taxonomy_meta', array() );
		$meta    = $all[ $taxonomy ][ $term_id ] ?? array();
		$map = array(
			'title'       => 'wpseo_title',
			'description' => 'wpseo_desc',
			'canonical'   => 'wpseo_canonical',
			'focus_keyword' => 'wpseo_focuskw',
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$meta[ $key ] = sanitize_text_field( (string) $fields[ $field ] );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			$meta['wpseo_noindex'] = $fields['noindex'] ? 'noindex' : 'index';
			$updated[] = 'noindex';
		}
		if ( $updated ) {
			$all[ $taxonomy ][ $term_id ] = $meta;
			update_option( 'wpseo_taxonomy_meta', $all, false );
		}
		return array( 'updated' => $updated );
	}

	public function get_settings(): array {
		$titles = get_option( 'wpseo_titles', array() );
		return array(
			'plugin'              => $this->label(),
			'separator'           => $titles['separator'] ?? '',
			'company_name'        => $titles['company_name'] ?? '',
			'company_or_person'   => $titles['company_or_person'] ?? '',
			'title_post'          => $titles['title-post'] ?? '',
			'title_page'          => $titles['title-page'] ?? '',
			'metadesc_post'       => $titles['metadesc-post'] ?? '',
			'metadesc_page'       => $titles['metadesc-page'] ?? '',
			'noindex_author_archives' => (bool) ( $titles['noindex-author-wpseo'] ?? false ),
		);
	}

	private function write_image( int $post_id, string $base, $value ): void {
		$id  = 0;
		$url = '';
		if ( is_array( $value ) ) {
			$id  = (int) ( $value['id'] ?? 0 );
			$url = (string) ( $value['url'] ?? '' );
		} elseif ( is_numeric( $value ) ) {
			$id = (int) $value;
		} else {
			$url = (string) $value;
		}
		if ( ! $id && '' !== $url ) {
			$id = $this->attachment_id_from( $url );
		}
		if ( $id ) {
			update_post_meta( $post_id, self::PREFIX . $base . '-id', $id );
			delete_post_meta( $post_id, self::PREFIX . $base );
			return;
		}
		if ( '' !== $url ) {
			update_post_meta( $post_id, self::PREFIX . $base, esc_url_raw( $url ) );
			delete_post_meta( $post_id, self::PREFIX . $base . '-id' );
		}
	}

	private function clean( array $out ): array {
		return array_filter(
			$out,
			static fn( $v ) => '' !== $v && false !== $v && null !== $v && array() !== $v
		);
	}
}
