<?php
/**
 * PACCC Member Directory — uninstall cleanup.
 * Runs only when the plugin is deleted (not on deactivation).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete member posts and their meta.
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'paccc_member' ) );
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}

// The pre-2.0 table, if it's still around as a migration backup.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}paccc_members" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'paccc_certifications' );
delete_option( 'paccc_certification_labels' );
delete_option( 'paccc_directory_page_id' );
delete_option( 'paccc_md_db_version' );
delete_option( 'paccc_md_map_font' );
delete_option( 'paccc_md_map_font_weight' );
delete_option( 'paccc_md_map_highlight' );
delete_option( 'paccc_md_migrated_to_cpt' );
delete_option( 'paccc_md_migrated_count' );
