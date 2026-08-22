<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_Audit {

	private WPMCP_Registry $registry;
	private WPMCP_SEO_Manager $seo;

	private const STOPWORDS = 'a,about,above,after,again,against,all,am,an,and,any,are,as,at,be,because,been,before,being,below,between,both,but,by,can,did,do,does,doing,down,during,each,few,for,from,further,had,has,have,having,he,her,here,hers,him,his,how,i,if,in,into,is,it,its,itself,just,me,more,most,my,no,nor,not,now,of,off,on,once,only,or,other,our,out,over,own,same,she,should,so,some,such,than,that,the,their,them,then,there,these,they,this,those,through,to,too,under,until,up,very,was,we,were,what,when,where,which,while,who,whom,why,will,with,you,your';

	public function __construct( WPMCP_Registry $registry, WPMCP_SEO_Manager $seo ) {
		$this->registry = $registry;
		$this->seo      = $seo;
	}

	public function register(): void {
		$this->registry->register(
			'audit-page-seo',
			array(
				'title'       => 'Audit Page SEO',
				'description' => 'Scored on-page SEO report computed from the real post content: title/meta presence and length, H1 count, heading hierarchy, image alt coverage, internal/external links, word count and target-keyword usage. Read-only, no AI cost.',
				'category'    => 'seo',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer' ),
						'target_keyword' => array( 'type' => 'string', 'description' => 'Optional keyword to check usage for' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'audit_page_seo' ),
			)
		);

		$this->registry->register(
			'extract-keywords-from-content',
			array(
				'title'       => 'Extract Keywords',
				'description' => 'Most frequent meaningful words and two-word phrases from a post (stop-word filtered, no external service). Use to pick a target keyword before auditing or generating meta tags.',
				'category'    => 'seo',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'limit'   => array( 'type' => 'integer', 'default' => 20, 'maximum' => 50 ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'extract_keywords' ),
			)
		);

		$this->registry->register(
			'generate-meta-tags',
			array(
				'title'       => 'Generate Meta Tags',
				'description' => 'Propose an SEO title (<=60 chars) and meta description (<=155 chars) from the page content, keyword-front-loaded when target_keyword is given. Dry-run by default; apply:true writes them through the active SEO plugin.',
				'category'    => 'seo',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer' ),
						'target_keyword' => array( 'type' => 'string' ),
						'apply'          => array( 'type' => 'boolean', 'default' => false, 'description' => 'false returns a proposal only; true writes to the active SEO plugin' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'generate_meta_tags' ),
			)
		);

		$this->registry->register(
			'generate-schema-markup',
			array(
				'title'       => 'Generate Schema Markup',
				'description' => 'Generate JSON-LD structured data: Article, LocalBusiness, FAQPage, Product or auto-inferred. Dry-run by default; apply:true stores it and outputs it on the page front end (replaced in place on re-apply).',
				'category'    => 'seo',
				'write'       => true,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'type'     => array( 'type' => 'string', 'enum' => array( 'auto', 'Article', 'LocalBusiness', 'FAQPage', 'Product' ), 'default' => 'auto' ),
						'business' => array(
							'type'       => 'object',
							'description' => 'For LocalBusiness: name, address, phone, url, image, price_range, geo {latitude, longitude}',
							'properties' => array(
								'name'        => array( 'type' => 'string' ),
								'address'     => array( 'type' => 'string' ),
								'phone'       => array( 'type' => 'string' ),
								'url'         => array( 'type' => 'string' ),
								'image'       => array( 'type' => 'string' ),
								'price_range' => array( 'type' => 'string' ),
							),
						),
						'faqs'     => array(
							'type'        => 'array',
							'description' => 'For FAQPage: [{question, answer}]',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'question' => array( 'type' => 'string' ),
									'answer'   => array( 'type' => 'string' ),
								),
							),
						),
						'apply'    => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'generate_schema_markup' ),
			)
		);
	}

	public function audit_page_seo( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}
		$dom   = $this->parse_content( $post );
		$findings = array();
		$score  = 100;

		$seo_data = $this->seo->get_post_seo( $post->ID );
		$title    = (string) ( $seo_data['title'] ?? '' );
		$desc     = (string) ( $seo_data['description'] ?? '' );

		if ( '' === trim( $title ) ) {
			$findings[] = array( 'severity' => 'high', 'check' => 'meta_title', 'message' => 'No SEO title set.' );
			$score -= 15;
		} elseif ( mb_strlen( $title ) > 60 ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'meta_title', 'message' => sprintf( 'SEO title is %d chars (aim <= 60).', mb_strlen( $title ) ) );
			$score -= 5;
		} elseif ( mb_strlen( $title ) < 30 ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'meta_title', 'message' => sprintf( 'SEO title is short (%d chars); aim 30-60.', mb_strlen( $title ) ) );
			$score -= 3;
		}

		if ( '' === trim( $desc ) ) {
			$findings[] = array( 'severity' => 'high', 'check' => 'meta_description', 'message' => 'No meta description set.' );
			$score -= 15;
		} elseif ( mb_strlen( $desc ) > 155 ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'meta_description', 'message' => sprintf( 'Meta description is %d chars (aim <= 155).', mb_strlen( $desc ) ) );
			$score -= 5;
		}

		$h1s = $dom->getElementsByTagName( 'h1' );
		if ( 0 === $h1s->length ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'h1', 'message' => 'No H1 in content.' );
			$score -= 8;
		} elseif ( $h1s->length > 1 ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'h1', 'message' => sprintf( '%d H1 tags found; use exactly one.', $h1s->length ) );
			$score -= 6;
		}

		$headings = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$headings[ $tag ] = $dom->getElementsByTagName( $tag )->length;
		}
		$prev_level = 0;
		$hierarchy_ok = true;
		foreach ( $headings as $tag => $count ) {
			$level = (int) substr( $tag, 1 );
			for ( $i = 0; $i < $count && $hierarchy_ok; $i++ ) {
				if ( $prev_level > 0 && $level - $prev_level > 1 ) {
					$hierarchy_ok = false;
					$findings[]   = array( 'severity' => 'low', 'check' => 'heading_hierarchy', 'message' => sprintf( 'Heading level skips (h%d after h%d).', $level, $prev_level ) );
					$score -= 4;
				}
				$prev_level = $level;
			}
		}

		$images      = $dom->getElementsByTagName( 'img' );
		$img_total   = $images->length;
		$img_missing = 0;
		foreach ( $images as $img ) {
			if ( '' === trim( (string) $img->getAttribute( 'alt' ) ) ) {
				$img_missing++;
			}
		}
		if ( $img_missing > 0 ) {
			$findings[] = array( 'severity' => 'medium', 'check' => 'image_alt', 'message' => sprintf( '%d of %d images missing alt text.', $img_missing, $img_total ) );
			$score -= min( 12, $img_missing * 3 );
		}

		$internal = 0;
		$external = 0;
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = (string) $a->getAttribute( 'href' );
			if ( '' === $href || str_starts_with( $href, '#' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( null === $host || $host === $site_host ) {
				$internal++;
			} else {
				$external++;
			}
		}
		if ( 0 === $internal ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'internal_links', 'message' => 'No internal links found.' );
			$score -= 5;
		}

		$text = wp_strip_all_tags( $post->post_content );
		$words = count( preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
		if ( $words < 300 ) {
			$findings[] = array( 'severity' => 'low', 'check' => 'word_count', 'message' => sprintf( 'Thin content: %d words (aim >= 300).', $words ) );
			$score -= 5;
		}

		$keyword_report = null;
		$keyword = trim( (string) ( $args['target_keyword'] ?? '' ) );
		if ( '' !== $keyword ) {
			$k_lc = mb_strtolower( $keyword );
			$count_in_title = '' !== $title && str_contains( mb_strtolower( $title ), $k_lc ) ? 1 : 0;
			$count_in_desc  = '' !== $desc && str_contains( mb_strtolower( $desc ), $k_lc ) ? 1 : 0;
			$count_in_text  = substr_count( mb_strtolower( $text ), $k_lc );
			$density = $words > 0 ? round( ( $count_in_text * max( 1, str_word_count( $keyword ) ) / $words ) * 100, 2 ) : 0;
			$keyword_report = array(
				'keyword'          => $keyword,
				'in_title'         => (bool) $count_in_title,
				'in_description'   => (bool) $count_in_desc,
				'in_content_count' => $count_in_text,
				'density_percent'  => $density,
			);
			if ( ! $count_in_title ) {
				$findings[] = array( 'severity' => 'medium', 'check' => 'keyword_title', 'message' => sprintf( 'Target keyword "%s" not in SEO title.', $keyword ) );
				$score -= 6;
			}
			if ( ! $count_in_desc ) {
				$findings[] = array( 'severity' => 'low', 'check' => 'keyword_description', 'message' => sprintf( 'Target keyword "%s" not in meta description.', $keyword ) );
				$score -= 3;
			}
		}

		return array(
			'post_id'   => $post->ID,
			'score'     => max( 0, min( 100, $score ) ),
			'grade'     => $this->grade( max( 0, $score ) ),
			'metrics'   => array(
				'title_length'      => mb_strlen( $title ),
				'description_length' => mb_strlen( $desc ),
				'headings'          => $headings,
				'images_total'      => $img_total,
				'images_missing_alt' => $img_missing,
				'internal_links'    => $internal,
				'external_links'    => $external,
				'word_count'        => $words,
			),
			'keyword'   => $keyword_report,
			'findings'  => $findings,
			'seo_plugin' => $this->seo->active_label(),
		);
	}

	public function extract_keywords( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}
		$limit = min( 50, max( 1, (int) ( $args['limit'] ?? 20 ) ) );
		$text  = mb_strtolower( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		preg_match_all( '/[a-z\x80-\xff][a-z\x80-\xff\'-]{2,}/u', $text, $matches );
		$stop = array_flip( explode( ',', self::STOPWORDS ) );

		$unigrams = array();
		foreach ( $matches[0] as $word ) {
			if ( isset( $stop[ $word ] ) || is_numeric( $word ) ) {
				continue;
			}
			$unigrams[ $word ] = ( $unigrams[ $word ] ?? 0 ) + 1;
		}
		arsort( $unigrams );

		$bigrams = array();
		$tokens  = $matches[0];
		for ( $i = 0; $i < count( $tokens ) - 1; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			if ( isset( $stop[ $a ] ) || isset( $stop[ $b ] ) || is_numeric( $a ) || is_numeric( $b ) ) {
				continue;
			}
			$phrase = $a . ' ' . $b;
			$bigrams[ $phrase ] = ( $bigrams[ $phrase ] ?? 0 ) + 1;
		}
		arsort( $bigrams );

		return array(
			'unigrams' => array_slice( $unigrams, 0, $limit, true ),
			'bigrams'  => array_slice( array_filter( $bigrams, static fn( $c ) => $c > 1 ), 0, $limit, true ),
		);
	}

	public function generate_meta_tags( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}
		$keyword = trim( (string) ( $args['target_keyword'] ?? '' ) );
		$text    = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$sentences = preg_split( '/(?<=[.!?])\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$title_source = '' !== $keyword
			? $keyword . ' | ' . $post->post_title
			: $post->post_title;
		$title = $this->clamp_chars( $title_source, 60 );

		$description = '';
		foreach ( $sentences as $sentence ) {
			if ( '' !== $keyword && ! str_contains( mb_strtolower( $sentence ), mb_strtolower( $keyword ) ) ) {
				continue;
			}
			$description = $sentence;
			break;
		}
		if ( '' === $description ) {
			$description = $sentences[0] ?? '';
		}
		if ( '' !== $keyword && ! str_contains( mb_strtolower( $description ), mb_strtolower( $keyword ) ) ) {
			$description = $keyword . ': ' . $description;
		}
		$description = $this->clamp_chars( $description, 155 );

		$proposal = array(
			'post_id'        => $post->ID,
			'title'          => $title,
			'title_length'   => mb_strlen( $title ),
			'description'    => $description,
			'description_length' => mb_strlen( $description ),
			'target_keyword' => $keyword,
			'applied'        => false,
		);

		if ( empty( $args['apply'] ) ) {
			$proposal['note'] = 'Dry run. Re-run with apply:true to write via ' . $this->seo->active_label() . '.';
			return $proposal;
		}

		$result = $this->seo->update_post_seo(
			$post->ID,
			array_filter(
				array(
					'title'       => $title,
					'description' => $description,
				),
				static fn( $v ) => '' !== $v
			)
		);
		$proposal['applied'] = true;
		$proposal['result']  = $result;
		$proposal['plugin']  = $this->seo->active_label();
		wpmcp_plugin()->change_log->record( 'seo', 'generate-meta-tags', $post->ID, $post->post_title, 'Applied generated meta title/description', $this->seo->active()->get_post_seo( $post->ID ), true );
		return $proposal;
	}

	public function generate_schema_markup( array $args ): array {
		$post = get_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}
		$type = (string) ( $args['type'] ?? 'auto' );
		if ( 'auto' === $type ) {
			$type = $this->infer_type( $post, $args );
		}
		$url      = get_permalink( $post );
		$image_id = (int) get_post_thumbnail_id( $post );
		$image    = $image_id ? wp_get_attachment_url( $image_id ) : '';

		switch ( $type ) {
			case 'LocalBusiness':
				$b      = is_array( $args['business'] ?? null ) ? $args['business'] : array();
				$schema = array_filter(
					array(
						'@context'    => 'https://schema.org',
						'@type'       => 'LocalBusiness',
						'name'        => sanitize_text_field( $b['name'] ?? get_bloginfo( 'name' ) ),
						'description' => sanitize_text_field( $b['address'] ?? '' ) ?: null,
						'telephone'   => sanitize_text_field( $b['phone'] ?? '' ) ?: null,
						'url'         => esc_url_raw( $b['url'] ?? $url ) ?: null,
						'image'       => esc_url_raw( $b['image'] ?? $image ) ?: null,
						'priceRange'  => sanitize_text_field( $b['price_range'] ?? '' ) ?: null,
						'address'     => sanitize_text_field( $b['address'] ?? '' ) ?: null,
					),
					static fn( $v ) => null !== $v && '' !== $v
				);
				unset( $schema['description'] );
				if ( ! empty( $b['address'] ) ) {
					$schema['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => sanitize_text_field( $b['address'] ) );
				}
				break;
			case 'FAQPage':
				$faqs   = is_array( $args['faqs'] ?? null ) ? $args['faqs'] : array();
				$entities = array();
				foreach ( $faqs as $faq ) {
					if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) {
						continue;
					}
					$entities[] = array(
						'@type'          => 'Question',
						'name'           => sanitize_text_field( $faq['question'] ),
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => sanitize_textarea_field( $faq['answer'] ),
						),
					);
				}
				if ( empty( $entities ) ) {
					return array( 'error' => 'faqs_array_required', 'message' => 'FAQPage schema needs at least one {question, answer} pair.' );
				}
				$schema = array(
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
				);
				break;
			case 'Product':
				$schema = array(
					'@context' => 'https://schema.org',
					'@type'    => 'Product',
					'name'     => $post->post_title,
					'description' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' ),
					'url'      => $url,
				);
				if ( $image ) {
					$schema['image'] = $image;
				}
				break;
			default:
				$schema = array(
					'@context'    => 'https://schema.org',
					'@type'       => 'Article',
					'headline'    => $this->clamp_chars( $post->post_title, 110 ),
					'datePublished' => get_the_date( 'c', $post ),
					'dateModified' => get_the_modified_date( 'c', $post ),
					'author'      => array(
						'@type' => 'Person',
						'name'  => get_the_author_meta( 'display_name', $post->post_author ),
					),
					'mainEntityOfPage' => $url,
				);
				if ( $image ) {
					$schema['image'] = $image;
				}
		}

		if ( empty( $args['apply'] ) ) {
			return array(
				'post_id' => $post->ID,
				'type'    => $schema['@type'],
				'json_ld' => $schema,
				'applied' => false,
				'note'    => 'Dry run. Re-run with apply:true to store and output this JSON-LD on the page.',
			);
		}

		update_post_meta( $post->ID, '_wpmcp_schema_jsonld', wp_json_encode( $schema ) );
		wpmcp_plugin()->change_log->record( 'seo', 'generate-schema-markup', $post->ID, $post->post_title, sprintf( 'Applied %s JSON-LD schema', $schema['@type'] ), array( 'previous' => get_post_meta( $post->ID, '_wpmcp_schema_jsonld', true ) ), true );
		return array(
			'post_id' => $post->ID,
			'type'    => $schema['@type'],
			'json_ld' => $schema,
			'applied' => true,
		);
	}

	private function infer_type( WP_Post $post, array $args ): string {
		if ( ! empty( $args['faqs'] ) ) {
			return 'FAQPage';
		}
		if ( ! empty( $args['business'] ) ) {
			return 'LocalBusiness';
		}
		if ( 'product' === $post->post_type ) {
			return 'Product';
		}
		return 'Article';
	}

	private function parse_content( WP_Post $post ): DOMDocument {
		$html = apply_filters( 'the_content', $post->post_content );
		$dom  = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . '<div id="wpmcp-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		return $dom;
	}

	private function clamp_chars( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max - 1 );
		$cut = preg_replace( '/\s+\S*$/u', '', $cut ) ?? $cut;
		return $cut . '…';
	}

	private function grade( int $score ): string {
		return match ( true ) {
			$score >= 90 => 'A',
			$score >= 80 => 'B',
			$score >= 70 => 'C',
			$score >= 60 => 'D',
			default      => 'F',
		};
	}
}
