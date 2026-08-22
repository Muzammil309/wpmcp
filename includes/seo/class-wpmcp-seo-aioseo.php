<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_AIOSEO extends WPMCP_SEO_Adapter_Base {

	public function slug(): string {
		return 'aioseo';
	}

	public function label(): string {
		return 'All in One SEO';
	}

	public function is_active(): bool {
		return defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin' );
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aioseo_posts';
	}

	private function table_exists(): bool {
		static $exists = null;
		if ( null === $exists ) {
			$exists = get_option( 'wpmcp_aioseo_table_ok', null );
			if ( null === $exists ) {
				global $wpdb;
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) ) ? '1' : '0';
				update_option( 'wpmcp_aioseo_table_ok', $exists );
			}
		}
		return '1' === $exists;
	}

	public function supported_fields(): array {
		return array(
			'title',
			'description',
			'canonical',
			'noindex',
			'nofollow',
			'focus_keyword',
			'og_title',
			'og_description',
			'og_image',
			'twitter_title',
			'twitter_description',
			'twitter_image',
		);
	}

	public function get_post_seo( int $post_id ): array {
		if ( ! $this->table_exists() ) {
			return array( 'error' => 'aioseo_table_missing' );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'plugin' => $this->label(), 'note' => 'No AIOSEO row for this post yet; values fall back to global defaults.' );
		}
		$keyphrases = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
		$out = array(
			'plugin'      => $this->label(),
			'title'       => (string) ( $row['title'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
			'canonical'   => (string) ( $row['canonical_url'] ?? '' ),
			'noindex'     => ! empty( $row['robots_noindex'] ) && 0 === (int) ( $row['robots_default'] ?? 1 ),
			'nofollow'    => ! empty( $row['robots_nofollow'] ) && 0 === (int) ( $row['robots_default'] ?? 1 ),
			'focus_keyword' => (string) ( $keyphrases['focus']['keyphrase'] ?? '' ),
			'og_title'    => (string) ( $row['og_title'] ?? '' ),
			'og_description' => (string) ( $row['og_description'] ?? '' ),
			'twitter_title' => (string) ( $row['twitter_title'] ?? '' ),
			'twitter_description' => (string) ( $row['twitter_description'] ?? '' ),
		);
		foreach ( array( 'og_image' => 'og_image_url', 'twitter_image' => 'twitter_image' ) as $field => $col ) {
			$url = (string) ( $row[ $col ] ?? '' );
			if ( '' !== $url ) {
				$out[ $field ] = $this->image_payload( 0, $url );
			}
		}
		return $this->clean( $out );
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		if ( ! $this->table_exists() ) {
			return array( 'error' => 'aioseo_table_missing' );
		}
		global $wpdb;
		$fields  = $this->filter_fields( $fields );
		$updated = array();
		if ( empty( $fields ) ) {
			return array( 'updated' => array() );
		}

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE post_id = %d", $post_id ), ARRAY_A );

		$data = array();
		$text_map = array(
			'title'       => 'title',
			'description' => 'description',
			'canonical'   => 'canonical_url',
			'og_title'    => 'og_title',
			'og_description' => 'og_description',
			'twitter_title' => 'twitter_title',
			'twitter_description' => 'twitter_description',
		);
		foreach ( $text_map as $field => $column ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $column ] = sanitize_text_field( (string) $fields[ $field ] );
				$updated[]       = $field;
			}
		}
		if ( isset( $fields['noindex'] ) || isset( $fields['nofollow'] ) ) {
			if ( isset( $fields['noindex'] ) ) {
				$data['robots_noindex'] = $fields['noindex'] ? 1 : 0;
				$data['robots_default'] = 0;
				$updated[] = 'noindex';
			}
			if ( isset( $fields['nofollow'] ) ) {
				$data['robots_nofollow'] = $fields['nofollow'] ? 1 : 0;
				$data['robots_default']  = 0;
				$updated[] = 'nofollow';
			}
		}
		if ( isset( $fields['focus_keyword'] ) ) {
			$data['keyphrases'] = wp_json_encode(
				array(
					'focus'    => array( 'keyphrase' => sanitize_text_field( (string) $fields['focus_keyword'] ) ),
					'analysis' => new stdClass(),
				)
			);
			$updated[] = 'focus_keyword';
		}
		foreach ( array( 'og_image' => 'og_image_url', 'twitter_image' => 'twitter_image' ) as $field => $column ) {
			if ( isset( $fields[ $field ] ) ) {
				$url = is_array( $fields[ $field ] ) ? (string) ( $fields[ $field ]['url'] ?? '' ) : '';
				if ( '' === $url && is_array( $fields[ $field ] ) && ! empty( $fields[ $field ]['id'] ) ) {
					$url = wp_get_attachment_url( (int) $fields[ $field ]['id'] );
				} elseif ( '' === $url && ! is_array( $fields[ $field ] ) ) {
					$id  = $this->attachment_id_from( (string) $fields[ $field ] );
					$url = $id ? wp_get_attachment_url( $id ) : (string) $fields[ $field ];
				}
				$data[ $column ] = esc_url_raw( $url );
				$updated[]       = $field;
			}
		}

		if ( $existing ) {
			$wpdb->update( $this->table(), $data, array( 'post_id' => $post_id ) );
		} else {
			$post = get_post( $post_id );
			$data = array_merge(
				array(
					'post_id'     => $post_id,
					'created'     => current_time( 'mysql', true ),
					'robots_default' => 0,
				),
				$data
			);
			$wpdb->insert(
				$this->table(),
				$data + array(
					'updated'      => current_time( 'mysql', true ),
					'post_type'    => $post ? $post->post_type : 'post',
					'robots_noindex' => 0,
					'robots_nofollow' => 0,
				)
			);
		}
		return array( 'updated' => $updated );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		return array( 'plugin' => $this->label(), 'note' => 'Term-level SEO not exposed by this adapter.' );
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		return array( 'error' => 'term_seo_unsupported', 'message' => 'AIOSEO term SEO is not supported by this adapter yet.' );
	}

	public function get_settings(): array {
		$search_appearance = get_option( 'aioseo_options_search_appearance', array() );
		$global_setting    = get_option( 'aioseo_options_search_appearance_global_setting', array() );
		return array(
			'plugin'        => $this->label(),
			'site_title'    => $global_setting['siteTitle'] ?? '',
			'separator'     => $global_setting['titleSeparator'] ?? '',
			'meta_tag'      => $search_appearance['metaTagAuthor'] ?? '',
		);
	}

	private function clean( array $out ): array {
		return array_filter(
			$out,
			static fn( $v ) => '' !== $v && false !== $v && null !== $v && array() !== $v
		);
	}
}
