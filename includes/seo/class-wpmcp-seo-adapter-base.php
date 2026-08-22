<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WPMCP_SEO_Adapter_Base implements WPMCP_SEO_Adapter {

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

	protected function filter_fields( array $fields ): array {
		return array_intersect_key( $fields, array_flip( $this->supported_fields() ) );
	}

	protected function attachment_id_from( $value ): int {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		if ( is_string( $value ) && '' !== $value ) {
			$found = attachment_url_to_postid( $value );
			if ( $found ) {
				return (int) $found;
			}
		}
		return 0;
	}

	protected function image_payload( int $id, string $url ): ?array {
		if ( $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			return array(
				'id'  => $id,
				'url' => $src ? $src[0] : wp_get_attachment_url( $id ),
			);
		}
		if ( '' !== $url ) {
			return array(
				'id'  => null,
				'url' => $url,
			);
		}
		return null;
	}
}
