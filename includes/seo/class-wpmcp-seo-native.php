<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_SEO_Native extends WPMCP_SEO_Adapter_Base {

	const META = '_wpmcp_seo';

	public function slug(): string {
		return 'native';
	}

	public function label(): string {
		return 'Native (no SEO plugin detected)';
	}

	public function is_active(): bool {
		return true;
	}

	public function get_post_seo( int $post_id ): array {
		$data = get_post_meta( $post_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		return $this->normalize( $data );
	}

	public function update_post_seo( int $post_id, array $fields ): array {
		$fields = $this->filter_fields( $fields );
		if ( empty( $fields ) ) {
			return array( 'updated' => array() );
		}
		$data = get_post_meta( $post_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		foreach ( $fields as $key => $value ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
				continue;
			}
			$data[ $key ] = $value;
		}
		update_post_meta( $post_id, self::META, $data );
		return array( 'updated' => array_keys( $fields ) );
	}

	public function get_term_seo( int $term_id, string $taxonomy ): array {
		$data = get_term_meta( $term_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		return $this->normalize( $data );
	}

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array {
		$fields = $this->filter_fields( $fields );
		if ( empty( $fields ) ) {
			return array( 'updated' => array() );
		}
		$data = get_term_meta( $term_id, self::META, true );
		$data = is_array( $data ) ? $data : array();
		foreach ( $fields as $key => $value ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
				continue;
			}
			$data[ $key ] = $value;
		}
		update_term_meta( $term_id, self::META, $data );
		return array( 'updated' => array_keys( $fields ) );
	}

	public function get_settings(): array {
		return array(
			'site_title'       => get_bloginfo( 'name' ),
			'tagline'          => get_bloginfo( 'description' ),
			'blog_public'      => (bool) get_option( 'blog_public' ),
			'permalink_struct' => get_option( 'permalink_structure' ),
		);
	}

	public function render_head(): void {
		if ( is_singular() ) {
			$seo  = $this->get_post_seo( get_queried_object_id() );
			$post = get_post();
			$this->print_tags( $seo, $post ? get_permalink( $post ) : '' );
			return;
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$seo = $this->get_term_seo( $term->term_id, $term->taxonomy );
				$this->print_tags( $seo, get_term_link( $term ) );
			}
		}
	}

	private function print_tags( array $seo, string $canonical ): void {
		echo "\n<!-- WP MCP Suite SEO -->\n";
		if ( ! empty( $seo['title'] ) ) {
			printf( "<meta property=\"wpmcp:seo-title\" content=\"%s\" />\n", esc_attr( $seo['title'] ) );
		}
		if ( ! empty( $seo['description'] ) ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $seo['description'] ) );
		}
		$canonical_url = (string) ( $seo['canonical'] ?? '' );
		if ( '' !== $canonical_url || ( $canonical && ! is_wp_error( $canonical ) ) ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( '' !== $canonical_url ? $canonical_url : $canonical ) );
		}
		if ( ! empty( $seo['noindex'] ) ) {
			echo "<meta name=\"robots\" content=\"noindex" . ( ! empty( $seo['nofollow'] ) ? ',nofollow' : '' ) . "\" />\n";
		}
		foreach ( array( 'og_title' => 'og:title', 'og_description' => 'og:description', 'twitter_title' => 'twitter:title', 'twitter_description' => 'twitter:description' ) as $key => $tag ) {
			if ( ! empty( $seo[ $key ] ) ) {
				printf( "<meta property=\"%s\" content=\"%s\" />\n", esc_attr( $tag ), esc_attr( $seo[ $key ] ) );
			}
		}
		foreach ( array( 'og_image' => 'og:image', 'twitter_image' => 'twitter:image' ) as $key => $tag ) {
			$img = $this->image_payload( (int) ( $seo[ $key ]['id'] ?? 0 ), (string) ( $seo[ $key ]['url'] ?? '' ) );
			if ( $img ) {
				printf( "<meta property=\"%s\" content=\"%s\" />\n", esc_attr( $tag ), esc_url( $img['url'] ) );
			}
		}
		$schema = get_post_meta( get_the_ID(), '_wpmcp_schema_jsonld', true );
		if ( $schema ) {
			echo '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema, true ) ) . "</script>\n";
		}
	}

	private function normalize( array $data ): array {
		$out = array();
		foreach ( $this->supported_fields() as $field ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
				$out[ $field ] = $data[ $field ];
			}
		}
		return $out;
	}
}

add_action( 'wp_head', array( new WPMCP_SEO_Native(), 'render_head' ), 1 );
