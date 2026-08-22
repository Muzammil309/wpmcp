<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_Manager {

	private ?WPMCP_SEO_Adapter $active = null;
	private array $adapters;

	public function __construct() {
		$this->adapters = array(
			new WPMCP_SEO_Yoast(),
			new WPMCP_SEO_RankMath(),
			new WPMCP_SEO_SlimSEO(),
			new WPMCP_SEO_AIOSEO(),
			new WPMCP_SEO_SEOPress(),
			new WPMCP_SEO_TSF(),
		);
	}

	public function adapters(): array {
		return $this->adapters;
	}

	public function active(): WPMCP_SEO_Adapter {
		if ( null !== $this->active ) {
			return $this->active;
		}
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_active() ) {
				$this->active = apply_filters( 'wpmcp_seo_adapter', $adapter );
				return $this->active;
			}
		}
		$this->active = apply_filters( 'wpmcp_seo_adapter', new WPMCP_SEO_Native() );
		return $this->active;
	}

	public function active_label(): string {
		return $this->active()->label();
	}

	public function active_slug(): string {
		return $this->active()->slug();
	}

	public function get_post_seo( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}
		$data = $this->active()->get_post_seo( $post_id );
		if ( empty( $data['title'] ) && empty( $data['description'] ) ) {
			$data['effective_title'] = sprintf( '%s – %s', $post->post_title, get_bloginfo( 'name' ) );
			$excerpt = $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
			if ( '' !== trim( $excerpt ) ) {
				$data['effective_description'] = $excerpt;
			}
		}
		$data['permalink'] = get_permalink( $post );
		return $data;
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$result = $this->active()->update_post_seo( $post_id, $fields );
		clean_post_cache( $post_id );
		return $result;
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return array( 'error' => 'term_not_found' );
		}
		return $this->active()->get_term_seo( $term_id, $taxonomy );
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$result = $this->active()->update_term_seo( $term_id, $taxonomy, $fields );
		clean_term_cache( $term_id, $taxonomy );
		return $result;
	}

	public function get_settings(): array {
		return $this->active()->get_settings();
	}

	public function status(): array {
		$detected = array();
		foreach ( $this->adapters as $adapter ) {
			$detected[] = array(
				'slug'  => $adapter->slug(),
				'label' => $adapter->label(),
				'active' => $adapter->is_active(),
				'fields' => $adapter->supported_fields(),
			);
		}
		return array(
			'active'  => $this->active_slug(),
			'label'   => $this->active_label(),
			'detected' => $detected,
		);
	}
}
