<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Tool_A11y {

	private WPMCP_Registry $registry;
	private WPMCP_Change_Log $log;

	private const GENERIC_LINK_TEXT = array( 'click here', 'read more', 'here', 'learn more', 'more', 'link', 'this page', 'download' );

	public function __construct( WPMCP_Registry $registry, WPMCP_Change_Log $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	public function register(): void {
		$this->registry->register(
			'audit-page-a11y',
			array(
				'title'       => 'Audit Page Accessibility',
				'description' => 'WCAG-oriented accessibility audit of the rendered page: images without alt, heading order, generic link text, unlabeled form fields, lang attribute, and (for Elementor pages) text/background color-pair contrast. Returns a scored report.',
				'category'    => 'accessibility',
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'required' => True ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'audit' ),
			)
		);

		$this->registry->register(
			'fix-color-contrast',
			array(
				'title'       => 'Fix Color Contrast',
				'description' => 'Propose adjusted text colors so failing pairs meet WCAG AA (4.5:1). Dry-run by default; apply:true rewrites the Elementor element colors. Every change lands in the change ledger.',
				'category'    => 'accessibility',
				'write'       => True,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'required' => True ),
						'apply'   => array( 'type' => 'boolean', 'default' => False ),
						'target_ratio' => array( 'type' => 'number', 'default' => 4.5, 'description' => 'WCAG AA normal text = 4.5, large text = 3.0' ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'fix_contrast' ),
			)
		);

		$this->registry->register(
			'add-alt-text-from-context',
			array(
				'title'       => 'Add Alt Text from Context',
				'description' => 'Propose alt text for images missing it, derived from filename, post title and nearest heading. Dry-run by default; apply:true writes alt text to the Media Library items.',
				'category'    => 'accessibility',
				'write'       => True,
				'capability'  => 'edit_posts',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'required' => True ),
						'apply'   => array( 'type' => 'boolean', 'default' => False ),
						'limit'   => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
					'required'   => array( 'post_id' ),
				),
				'handler'     => array( $this, 'add_alt_text' ),
			)
		);
	}

	// ---------- shared helpers ----------

	private function fetch_html( int $post_id ): ?string {
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return null;
		}
		$response = wp_safe_remote_get(
			$permalink,
			array( 'timeout' => 25, 'user-agent' => 'WPMCP-Suite/' . WPMCP_VERSION . '; a11y audit' )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$html = wp_remote_retrieve_body( $response );
		return '' !== $html ? $html : null;
	}

	private function relative_luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return -1;
		}
		$chan = static function ( $c ) {
			$c = hexdec( $c ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $chan( substr( $hex, 0, 2 ) ) + 0.7152 * $chan( substr( $hex, 2, 2 ) ) + 0.0722 * $chan( substr( $hex, 4, 2 ) );
	}

	private function contrast_ratio( string $fg, string $bg ): float {
		$l1 = $this->relative_luminance( $fg );
		$l2 = $this->relative_luminance( $bg );
		if ( $l1 < 0 || $l2 < 0 ) {
			return -1;
		}
		$hi = max( $l1, $l2 );
		$lo = min( $l1, $l2 );
		return round( ( $hi + 0.05 ) / ( $lo + 0.05 ), 2 );
	}

	private function readable_on( string $bg, float $ratio = 4.5 ): string {
		$dark  = '#111827';
		$light = '#f9fafb';
		return $this->contrast_ratio( $dark, $bg ) >= $ratio ? $dark : $light;
	}

	private function pick_bg( string $el_id, array $index ): string {
		$id = $el_id;
		while ( '' !== $id && isset( $index[ $id ] ) ) {
			$node = $index[ $id ];
			foreach ( array( 'background_color', 'background_color_b' ) as $key ) {
				if ( ! empty( $node['settings'][ $key ] ) && is_string( $node['settings'][ $key ] ) ) {
					return (string) $node['settings'][ $key ];
				}
			}
			$id = (string) ( $node['_parent'] ?? '' );
		}
		return '#ffffff';
	}

	private function index_elementor( int $post_id ): array {
		$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		$index = array();
		$walk = static function ( array $elements, string $parent ) use ( &$walk, &$index ) {
			foreach ( $elements as $el ) {
				$id = (string) ( $el['id'] ?? '' );
				$index[ $id ] = array(
					'id' => $id,
					'_parent' => $parent,
					'widgetType' => $el['widgetType'] ?? null,
					'elType' => $el['elType'] ?? '',
					'settings' => (array) ( $el['settings'] ?? array() ),
				);
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'], $id );
				}
			}
		};
		if ( is_array( $data ) ) {
			$walk( $data, '' );
		}
		return $index;
	}

	// ---------- audit ----------

	public function audit( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}

		$html      = $this->fetch_html( $post_id );
		$findings  = array();
		$score     = 100;

		// Lang attribute.
		if ( null !== $html && ! preg_match( '/<html[^>]*\slang=/i', $html ) ) {
			$findings[] = array( 'type' => 'lang_missing', 'severity' => 'medium', 'message' => '<html> element has no lang attribute.' );
			$score -= 8;
		}

		// Images without alt.
		$imgs_total = 0; $imgs_no_alt = 0; $img_samples = array();
		if ( null !== $html ) {
			if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
				foreach ( $m[0] as $tag ) {
					$imgs_total++;
					if ( ! preg_match( '/\balt=/i', $tag ) ) {
						$imgs_no_alt++;
						if ( count( $img_samples ) < 10 ) {
							preg_match( '/src=["\']([^"\']+)/i', $tag, $s );
							$img_samples[] = basename( (string) ( $s[1] ?? '?' ) );
						}
					}
				}
			}
		}
		if ( $imgs_no_alt > 0 ) {
			$findings[] = array( 'type' => 'img_alt_missing', 'severity' => 'high', 'count' => $imgs_no_alt, 'samples' => $img_samples, 'message' => sprintf( '%d of %d images have no alt attribute.', $imgs_no_alt, $imgs_total ) );
			$score -= min( 30, 6 * $imgs_no_alt );
		}

		// Heading order.
		$h1 = 0; $levels = array();
		if ( null !== $html && preg_match_all( '/<h([1-6])\b/i', (string) $html, $m ) ) {
			$levels = array_map( 'intval', $m[1] );
			$h1     = count( array_keys( $levels, 1, true ) );
			$prev   = 0; $skips = 0;
			foreach ( $levels as $lvl ) {
				if ( $prev > 0 && $lvl > $prev + 1 ) { $skips++; }
				$prev = $lvl;
			}
			if ( 1 !== $h1 ) {
				$findings[] = array( 'type' => 'h1_count', 'severity' => 'high', 'count' => $h1, 'message' => sprintf( 'Page has %d H1 elements; exactly one expected.', $h1 ) );
				$score -= 15;
			}
			if ( $skips > 0 ) {
				$findings[] = array( 'type' => 'heading_skipped', 'severity' => 'medium', 'count' => $skips, 'message' => sprintf( '%d heading level skips detected (e.g. H2 -> H4).', $skips ) );
				$score -= min( 15, 5 * $skips );
			}
		}

		// Generic link text.
		$generic = 0;
		if ( null !== $html && preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $html, $m ) ) {
			foreach ( $m[1] as $text ) {
				$t = trim( wp_strip_all_tags( $text ) );
				if ( '' !== $t && in_array( mb_strtolower( $t ), self::GENERIC_LINK_TEXT, true ) ) {
					$generic++;
				}
			}
		}
		if ( $generic > 0 ) {
			$findings[] = array( 'type' => 'link_generic_text', 'severity' => 'low', 'count' => $generic, 'message' => sprintf( '%d links use generic anchor text.', $generic ) );
			$score -= min( 10, 2 * $generic );
		}

		// Form labels.
		$unlabeled = 0;
		if ( null !== $html && preg_match_all( '/<input\b[^>]*>/i', $html, $m ) ) {
			$labels_for = array();
			if ( preg_match_all( '/<label[^>]*for=["\']([^"\']+)["\']/i', (string) $html, $lm ) ) {
				$labels_for = $lm[1];
			}
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/type=["\']?(hidden|submit|button|checkbox|radio)/i', $tag ) ) {
					continue;
				}
				if ( preg_match( '/aria-label=|aria-labelledby=|<label[^>]*>\s*$/i', $tag ) ) {
					continue;
				}
				preg_match( '/id=["\']([^"\']+)["\']/i', $tag, $idm );
				$input_id = $idm[1] ?? '';
				if ( '' === $input_id || ! in_array( $input_id, $labels_for, true ) ) {
					$unlabeled++;
				}
			}
		}
		if ( $unlabeled > 0 ) {
			$findings[] = array( 'type' => 'form_label_missing', 'severity' => 'medium', 'count' => $unlabeled, 'message' => sprintf( '%d form fields lack an associated label.', $unlabeled ) );
			$score -= min( 15, 3 * $unlabeled );
		}

		// Contrast (Elementor pages only).
		$contrast_checked = false; $pairs_failed = 0; $proposals = array();
		$is_elementor = 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
		if ( $is_elementor ) {
			$contrast_checked = true;
			$index = $this->index_elementor( $post_id );
			foreach ( $index as $el ) {
				$is_text_widget = in_array( $el['widgetType'], array( 'heading', 'text-editor', 'button', 'icon-box', 'counter' ), true );
				if ( ! $is_text_widget ) {
					continue;
				}
				$fg = '';
				foreach ( array( 'title_color', 'text_color', 'button_text_color', 'description_color' ) as $key ) {
					if ( ! empty( $el['settings'][ $key ] ) && is_string( $el['settings'][ $key ] ) && 0 === strpos( $el['settings'][ $key ], '#' ) ) {
						$fg = $el['settings'][ $key ];
						break;
					}
				}
				if ( '' === $fg ) {
					continue;
				}
				$bg     = $this->pick_bg( $el['id'], $index );
				$ratio  = $this->contrast_ratio( $fg, $bg );
				$contrast_checked = true;
				if ( $ratio >= 0 && $ratio < 4.5 ) {
					$pairs_failed++;
					$proposals[] = array(
						'element_id' => $el['id'],
						'widget'     => (string) $el['widgetType'],
						'fg'         => $fg,
						'bg'         => $bg,
						'ratio'      => $ratio,
						'suggested'  => $this->readable_on( $bg ),
					);
				}
			}
			if ( $pairs_failed > 0 ) {
				$findings[] = array( 'type' => 'contrast_low', 'severity' => 'high', 'count' => $pairs_failed, 'message' => sprintf( '%d text/background pairs fail WCAG AA. Run fix-color-contrast for one-click adjustments.', $pairs_failed ) );
				$score -= min( 25, 5 * $pairs_failed );
			}
		}

		return array(
			'post_id'          => $post_id,
			'score'            => max( 0, min( 100, $score ) ),
			'grade'            => $this->grade( $score ),
			'images'           => array( 'total' => $imgs_total, 'missing_alt' => $imgs_no_alt ),
			'headings'         => array( 'h1_count' => $h1, 'levels' => $levels ),
			'contrast'         => array( 'checked' => $contrast_checked, 'failed_pairs' => $pairs_failed, 'proposals' => $proposals ),
			'findings'         => $findings,
		);
	}

	private function grade( int $score ): string {
		if ( $score >= 90 ) { return 'A'; }
		if ( $score >= 80 ) { return 'B'; }
		if ( $score >= 70 ) { return 'C'; }
		if ( $score >= 60 ) { return 'D'; }
		return 'F';
	}

	// ---------- fix contrast ----------

	public function fix_contrast( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$ratio_target = (float) ( $args['target_ratio'] ?? 4.5 );

		$audit  = $this->audit( array( 'post_id' => $post_id ) );
		$props  = $audit['contrast']['proposals'] ?? array();

		$changes = array();
		foreach ( $props as $p ) {
			if ( $p['ratio'] >= $ratio_target ) {
				continue;
			}
			$changes[] = array(
				'element_id' => $p['element_id'],
				'widget'     => $p['widget'],
				'old_color'  => $p['fg'],
				'background' => $p['bg'],
				'old_ratio'  => $p['ratio'],
				'new_color'  => $this->readable_on( $p['bg'], max( 3.0, $ratio_target ) ),
			);
		}

		if ( empty( $args['apply'] ) ) {
			return array(
				'dry_run'  => true,
				'post_id'  => $post_id,
				'proposals' => $changes,
				'hint'     => 'Re-run with apply:true to write the suggested colors.',
			);
		}

		$doc = WPMCP_EL_Document::load( $post_id );
		if ( is_wp_error( $doc ) ) {
			return array( 'error' => $doc->get_error_code(), 'message' => $doc->get_error_message() );
		}

		$key_map = array(
			'heading'      => 'title_color',
			'text-editor'  => 'text_color',
			'button'       => 'button_text_color',
			'icon-box'     => 'description_color',
			'counter'      => 'title_color',
		);

		$applied = array();
		foreach ( $changes as $c ) {
			$ref =& $doc->find_element_ref( $c['element_id'] );
			if ( null === $ref ) {
				continue;
			}
			$key = $key_map[ $c['widget'] ] ?? 'title_color';
			$ref['settings'][ $key ] = $c['new_color'];
			$applied[] = $c['element_id'];
		}
		$doc->mark_dirty();
		$saved = $doc->save();

		wpmcp_plugin()->change_log->record(
			'a11y', 'fix-color-contrast', $post_id, get_the_title( $post_id ),
			sprintf( 'Adjusted %d low-contrast text colors', count( $applied ) ),
			array( 'changes' => $changes ), true
		);

		return array(
			'ok'        => true,
			'applied'   => count( $applied ),
			'elements'  => $applied,
			'saved'     => (bool) ( $saved['saved'] ?? false ),
		);
	}

	// ---------- alt text ----------

	public function add_alt_text( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'error' => 'post_not_found_or_forbidden' );
		}
		$limit = min( 100, max( 1, (int) ( $args['limit'] ?? 20 ) ) );
		$apply = ! empty( $args['apply'] );

		$title = $post->post_title;
		$context_heading = '';
		if ( preg_match( '/<h[12][^>]*>(.*?)<\/h[12]>/is', (string) $post->post_content, $hm ) ) {
			$context_heading = trim( wp_strip_all_tags( $hm[1] ) );
		}

		// Collect images without alt from rendered HTML (covers both classic + builder output).
		$html = $this->fetch_html( $post_id );
		$targets = array();
		if ( null !== $html && preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/\balt=["\']([^"\']*)/i', $tag, $am ) && '' !== trim( $am[1] ) ) {
					continue;
				}
				preg_match( '/src=["\']([^"\']+)/i', $tag, $sm );
				$src = (string) ( $sm[1] ?? '' );
				if ( '' === $src ) {
					continue;
				}
				$att_id = attachment_url_to_postid( $src );
				if ( ! $att_id ) {
					continue;
				}
				$existing = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
				if ( '' !== trim( $existing ) ) {
					continue;
				}
				$targets[] = array( 'attachment_id' => $att_id, 'file' => basename( $src ) );
				if ( count( $targets ) >= $limit ) {
					break;
				}
			}
		}

		$proposals = array();
		foreach ( $targets as $t ) {
			$base   = pathinfo( $t['file'], PATHINFO_FILENAME );
			$base   = ucwords( str_replace( array( '-', '_' ), ' ', $base ) );
			$base   = preg_replace( '/\s*(copy|edited|\d{6,})$/i', '', $base );
			$suggest = trim( ( $context_heading ? $context_heading . ' - ' : '' ) . $base );
			if ( '' === $suggest ) {
				$suggest = $title;
			}
			$proposals[] = array(
				'attachment_id' => $t['attachment_id'],
				'file'          => $t['file'],
				'current_alt'   => '',
				'proposed_alt'  => sanitize_text_field( $suggest ),
			);
		}

		if ( ! $apply ) {
			return array(
				'dry_run'   => true,
				'post_id'   => $post_id,
				'found'     => count( $proposals ),
				'proposals' => $proposals,
				'hint'      => 'Re-run with apply:true to write the proposed alt texts.',
			);
		}

		$written = 0; $before_map = array();
		foreach ( $proposals as $p ) {
			$before_map[ $p['attachment_id'] ] = '';
			update_post_meta( $p['attachment_id'], '_wp_attachment_image_alt', $p['proposed_alt'] );
			$written++;
		}
		$this->log->record(
			'a11y', 'add-alt-text-from-context', $post_id, $title,
			sprintf( 'Wrote alt text for %d images', $written ),
			array( 'alts' => $before_map ), true
		);
		return array( 'ok' => true, 'applied' => $written );
	}
}
