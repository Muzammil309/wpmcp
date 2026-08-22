<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_RankMath extends WPMCP_SEO_Adapter_Base {

	const PREFIX = 'rank_math_';

	public function slug(): string {
		return 'rankmath';
	}

	public function label(): string {
		return 'Rank Math';
	}

	public function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	public function get_post_seo( int $post_id ): array {
		$robots = get_post_meta( $post_id, self::PREFIX . 'robots', true );
		$robots = is_array( $robots ) ? $robots : array();
		$out    = array(
			'plugin'      => $this->label(),
			'title'       => (string) get_post_meta( $post_id, self::PREFIX . 'title', true ),
			'description' => (string) get_post_meta( $post_id, self::PREFIX . 'description', true ),
			'canonical'   => (string) get_post_meta( $post_id, self::PREFIX . 'canonical', true ),
			'noindex'     => in_array( 'noindex', $robots, true ),
			'nofollow'    => in_array( 'nofollow', $robots, true ),
			'focus_keyword' => (string) get_post_meta( $post_id, self::PREFIX . 'focus_keyword', true ),
			'og_title'    => (string) get_post_meta( $post_id, self::PREFIX . 'facebook_title', true ),
			'og_description' => (string) get_post_meta( $post_id, self::PREFIX . 'facebook_description', true ),
			'twitter_title' => (string) get_post_meta( $post_id, self::PREFIX . 'twitter_title', true ),
			'twitter_description' => (string) get_post_meta( $post_id, self::PREFIX . 'twitter_description', true ),
		);
		$og_image = $this->image_payload( (int) get_post_meta( $post_id, self::PREFIX . 'facebook_image_id', true ), (string) get_post_meta( $post_id, self::PREFIX . 'facebook_image', true ) );
		if ( $og_image ) {
			$out['og_image'] = $og_image;
		}
		$tw_image = $this->image_payload( (int) get_post_meta( $post_id, self::PREFIX . 'twitter_image_id', true ), (string) get_post_meta( $post_id, self::PREFIX . 'twitter_image', true ) );
		if ( $tw_image ) {
			$out['twitter_image'] = $tw_image;
		}
		return $this->clean( $out );
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		$map     = array(
			'title'       => self::PREFIX . 'title',
			'description' => self::PREFIX . 'description',
			'canonical'   => self::PREFIX . 'canonical',
			'focus_keyword' => self::PREFIX . 'focus_keyword',
			'og_title'    => self::PREFIX . 'facebook_title',
			'og_description' => self::PREFIX . 'facebook_description',
			'twitter_title' => self::PREFIX . 'twitter_title',
			'twitter_description' => self::PREFIX . 'twitter_description',
		);
		foreach ( $map as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) || isset( $fields['nofollow'] ) ) {
			$robots   = get_post_meta( $post_id, self::PREFIX . 'robots', true );
			$robots   = is_array( $robots ) ? $robots : array( 'index', 'follow' );
			$base     = array_values( array_diff( $robots, array( 'index', 'noindex', 'follow', 'nofollow' ) ) );
			$noindex  = isset( $fields['noindex'] ) ? (bool) $fields['noindex'] : in_array( 'noindex', $robots, true );
			$nofollow = isset( $fields['nofollow'] ) ? (bool) $fields['nofollow'] : in_array( 'nofollow', $robots, true );
			$robots   = array_merge( $base, array( $noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow' ) );
			update_post_meta( $post_id, self::PREFIX . 'robots', array_values( $robots ) );
			if ( isset( $fields['noindex'] ) ) {
				$updated[] = 'noindex';
			}
			if ( isset( $fields['nofollow'] ) ) {
				$updated[] = 'nofollow';
			}
		}
		if ( isset( $fields['og_image'] ) ) {
			$this->write_image( $post_id, 'facebook_image', $fields['og_image'] );
			$updated[] = 'og_image';
		}
		if ( isset( $fields['twitter_image'] ) ) {
			$this->write_image( $post_id, 'twitter_image', $fields['twitter_image'] );
			$updated[] = 'twitter_image';
		}
		return array( 'updated' => $updated );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		$meta  = get_term_meta( $term_id );
		$value = static fn( string $key ): string => isset( $meta[ $key ] ) ? (string) $meta[ $key ][0] : '';
		$robots = array_filter( explode( ',', $value( self::PREFIX . 'robots' ) ) );
		return $this->clean(
			array(
				'plugin'      => $this->label(),
				'title'       => $value( self::PREFIX . 'title' ),
				'description' => $value( self::PREFIX . 'description' ),
				'canonical'   => $value( self::PREFIX . 'canonical' ),
				'noindex'     => in_array( 'noindex', $robots, true ),
				'focus_keyword' => $value( self::PREFIX . 'focus_keyword' ),
			)
		);
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		$map     = array(
			'title'       => self::PREFIX . 'title',
			'description' => self::PREFIX . 'description',
			'canonical'   => self::PREFIX . 'canonical',
			'focus_keyword' => self::PREFIX . 'focus_keyword',
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_term_meta( $term_id, $key, sanitize_text_field( (string) $fields[ $field ] ) );
				$updated[] = $field;
			}
		}
		if ( isset( $fields['noindex'] ) ) {
			update_term_meta( $term_id, self::PREFIX . 'robots', $fields['noindex'] ? 'noindex,follow' : 'index,follow' );
			$updated[] = 'noindex';
		}
		return array( 'updated' => $updated );
	}

	public function get_settings(): array {
		$titles = get_option( 'rank-math-options-titles', array() );
		return array(
			'plugin'            => $this->label(),
			'company_name'      => $titles['knowledgegraph_name'] ?? '',
			'company_or_person' => $titles['company_or_person'] ?? '',
			'title_post'        => $titles['pt_post_title'] ?? '',
			'title_page'        => $titles['pt_page_title'] ?? '',
			'metadesc_post'     => $titles['pt_post_description'] ?? '',
			'metadesc_page'     => $titles['pt_page_description'] ?? '',
			'noindex_author_archives' => (bool) ( $titles['noindex_author_archive'] ?? false ),
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
			update_post_meta( $post_id, self::PREFIX . $base . '_id', $id );
			delete_post_meta( $post_id, self::PREFIX . $base );
			return;
		}
		if ( '' !== $url ) {
			update_post_meta( $post_id, self::PREFIX . $base, esc_url_raw( $url ) );
			delete_post_meta( $post_id, self::PREFIX . $base . '_id' );
		}
	}

	private function clean( array $out ): array {
		return array_filter(
			$out,
			static fn( $v ) => '' !== $v && false !== $v && null !== $v && array() !== $v
		);
	}
}
