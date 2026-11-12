<?php
/**
 * PACCC Suite -- CEUs brought over from the old site.
 *
 * Old-site CEU records live in their own member meta key, separate from the
 * admin-managed CEU(s) checklist (paccc_member_ceus / the paccc_ceus option),
 * so the member save routine never touches them and an import can only ever
 * ADD to a member. Each record keeps everything the old site had (provider,
 * code, hours, date) exactly as entered; only the name is displayed for now.
 *
 * Storage: post meta 'paccc_legacy_ceus' on the member post, an array keyed by
 * the old wpDataTables row id (wdt_ID), which is what makes re-imports safe.
 */

defined( 'ABSPATH' ) || exit;

define( 'PACCC_MD_LEGACY_CEU_META', 'paccc_legacy_ceus' );

/**
 * A member's old-site CEU records, in the order they were entered on the old
 * site (by wdt_ID).
 *
 * @return array[] Each: wdt_id, title, provider, code, hours, date, old_user_id, imported.
 */
function paccc_md_legacy_ceus( $post_id ) {
	$rows = get_post_meta( (int) $post_id, PACCC_MD_LEGACY_CEU_META, true );
	if ( ! is_array( $rows ) ) {
		return array();
	}
	ksort( $rows, SORT_NUMERIC );
	return array_values( $rows );
}

/**
 * The name to show for an old-site CEU: its title, or its code when the title
 * was left blank. Empty if both are blank.
 */
function paccc_md_legacy_ceu_label( $row ) {
	$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
	if ( '' !== $title ) {
		return $title;
	}
	return isset( $row['code'] ) ? trim( (string) $row['code'] ) : '';
}

/**
 * A de-duplicated list of CEU names: the admin-set CEU(s) first, then any
 * old-site CEUs passed in. A name appears once even if it was logged several
 * times (compared ignoring case and spacing); the first spelling wins.
 *
 * Old-site CEUs are private: paccc_md_get_member() passes them only into
 * portal_ceu_names (the member's own portal), never into the public ceu_names.
 */
function paccc_md_ceu_display_names( $tag_ceus, $legacy_rows ) {
	$names = array();
	$seen  = array();
	$add   = static function ( $name ) use ( &$names, &$seen ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return;
		}
		$key = strtolower( preg_replace( '/\s+/u', ' ', $name ) );
		if ( isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		$names[]      = $name;
	};
	foreach ( (array) $tag_ceus as $c ) {
		$add( $c );
	}
	foreach ( (array) $legacy_rows as $row ) {
		$add( paccc_md_legacy_ceu_label( $row ) );
	}
	return $names;
}

/*
 * The admin view of these records is the member's single CEU table
 * (paccc_md_render_member_ceu_picker in includes/member-ceus.php).
 */

/**
 * 2.37.0 briefly showed old-site CEUs on public member pages. After updating
 * to a version that keeps them private, empty the directory cache and WP
 * Rocket's page cache once, so no cached public page still lists them.
 */
function paccc_md_purge_public_caches_after_update() {
	if ( get_option( 'paccc_md_purged_for_version' ) === PACCC_MD_VERSION ) {
		return;
	}
	update_option( 'paccc_md_purged_for_version', PACCC_MD_VERSION, false );
	paccc_md_flush_directory_cache();
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
}
add_action( 'admin_init', 'paccc_md_purge_public_caches_after_update' );
