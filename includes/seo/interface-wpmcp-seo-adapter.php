<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WPMCP_SEO_Adapter {

	public function slug(): string;

	public function label(): string;

	public function is_active(): bool;

	public function supported_fields(): array;

	public function get_post_seo( int $post_id ): array;

	public function update_post_seo( int $post_id, array $fields ): array;

	public function get_term_seo( int $term_id, string $taxonomy ): array;

	public function update_term_seo( int $term_id, string $taxonomy, array $fields ): array;

	public function get_settings(): array;
}
