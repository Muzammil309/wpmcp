<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wpmcp_server_enabled' );
delete_option( 'wpmcp_disabled_tools' );
delete_option( 'wpmcp_redirects' );
delete_option( 'wpmcp_oauth_clients' );
delete_option( 'wpmcp_oauth_tokens' );
delete_option( 'wpmcp_aioseo_table_ok' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wpmcp\_oauth\_code\_%' OR option_name LIKE '\_transient\_timeout\_wpmcp\_oauth\_code\_%'" );
delete_metadata( 'post', 0, '_wpmcp_schema_jsonld', '', true );
delete_metadata( 'post', 0, '_wpmcp_seo', '', true );

$table = $wpdb->prefix . 'wpmcp_change_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
