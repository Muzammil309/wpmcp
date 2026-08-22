<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Snapshot {

	private WPMCP_Registry $registry;
	private WPMCP_SEO_Manager $seo;

	public function __construct( WPMCP_Registry $registry, WPMCP_SEO_Manager $seo ) {
		$this->registry = $registry;
		$this->seo      = $seo;
	}

	public function register(): void {
		$this->registry->register(
			'get-page-snapshot',
			array(
				'title'       => 'Get Page Snapshot',
				'description' => 'One normalized digest of a post so an agent can reason about it from a single call: content outline, heading/word/link/image counts, images missing alt text, the active SEO plugin data with lengths, JSON-LD schema presence and structural warnings.',
				'category'    => 'content',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'snapshot' ),
			)
		);
	}

	public function snapshot( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}

		$html = apply_filters( 'the_content', $post->post_content );
		$dom  = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . '<div id="wpmcp-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();

		$outline = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				$text = trim( preg_replace( '/\s+/', ' ', $node->textContent ?? '' ) ?? '' );
				$outline[] = array(
					'level' => (int) substr( $tag, 1 ),
					'text'  => mb_substr( $text, 0, 120 ),
				);
			}
		}

		$counts = array();
		foreach ( array( 'h1', 'h2', 'h3', 'p', 'ul', 'ol', 'table', 'blockquote', 'img', 'a', 'iframe', 'video' ) as $tag ) {
			$n = $dom->getElementsByTagName( $tag )->length;
			if ( $n > 0 ) {
				$counts[ $tag ] = $n;
			}
		}

		$images_total   = $dom->getElementsByTagName( 'img' )->length;
		$images_missing = 0;
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			if ( '' === trim( (string) $img->getAttribute( 'alt' ) ) ) {
				$images_missing++;
			}
		}

		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal   = 0;
		$external   = 0;
		$generic_cta = 0;
		$generic    = array( 'click here', 'read more', 'learn more', 'here' );
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || str_starts_with( $href, '#' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( null === $host || $host === $site_host ) {
				$internal++;
			} else {
				$external++;
			}
			$link_text = mb_strtolower( trim( preg_replace( '/\s+/', ' ', $a->textContent ?? '' ) ?? '' ) );
			if ( in_array( $link_text, $generic, true ) ) {
				$generic_cta++;
			}
		}

		$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$words = count( preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );

		$seo = $this->seo->get_post_seo( $post->ID );
		unset( $seo['permalink'] );
		if ( isset( $seo['title'] ) ) {
			$seo['title_length'] = mb_strlen( (string) $seo['title'] );
		}
		if ( isset( $seo['description'] ) ) {
			$seo['description_length'] = mb_strlen( (string) $seo['description'] );
		}

		$warnings = array();
		$h1_count = $dom->getElementsByTagName( 'h1' )->length;
		if ( 0 === $h1_count ) {
			$warnings[] = 'No H1 heading in content.';
		} elseif ( $h1_count > 1 ) {
			$warnings[] = sprintf( '%d H1 headings; use exactly one.', $h1_count );
		}
		if ( $images_missing > 0 ) {
			$warnings[] = sprintf( '%d of %d images missing alt text.', $images_missing, $images_total );
		}
		if ( $words < 300 && 'page' !== $post->post_type ) {
			$warnings[] = sprintf( 'Thin content: %d words.', $words );
		}
		if ( 0 === $internal ) {
			$warnings[] = 'No internal links.';
		}
		if ( $generic_cta > 0 ) {
			$warnings[] = sprintf( '%d generic link text(s) like "read more".', $generic_cta );
		}
		$schema_meta = get_post_meta( $post->ID, '_wpmcp_schema_jsonld', true );

		return array(
			'post_id'   => $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'title'     => $post->post_title,
			'permalink' => get_permalink( $post ),
			'modified'  => get_the_modified_date( 'c', $post ),
			'structure' => array(
				'outline'            => $outline,
				'element_counts'     => $counts,
				'word_count'         => $words,
				'images'             => array(
					'total'       => $images_total,
					'missing_alt' => $images_missing,
				),
				'links'              => array(
					'internal'        => $internal,
					'external'        => $external,
					'generic_text'    => $generic_cta,
				),
			),
			'seo'       => $seo + array(
				'plugin'          => $this->seo->active_label(),
				'schema_jsonld_present' => (bool) $schema_meta || (bool) get_post_meta( $post->ID, '_wpmcp_seo_schema', true ),
			),
			'warnings'  => $warnings,
		);
	}
}
