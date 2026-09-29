<?php
/**
 * Member page URLs follow the member's (person's) name, not the business name:
 *   /paccc-certified-members/amanda-troutman/
 *
 * The post title stays the business name; only the slug (post_name) changes.
 * Falls back to the business name when a member has no person name. Duplicate
 * names get WordPress's usual numeric suffix (-2, -3, ...), earliest member
 * first. Kept in sync whenever a member is saved in the editor, created by the
 * spreadsheet import, or edits their name in the portal.
 *
 * Slug changes go through wp_update_post() (not a raw DB write) so Yoast and
 * anything else hooked to post saves sees the new URL -- otherwise Yoast's
 * stored canonical/sitemap URL would keep pointing at the old address.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The slug a member's URL should be based on (before any -2/-3 suffix).
 */
function paccc_md_member_slug_base( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}

	$source = trim( (string) get_post_meta( $post->ID, 'paccc_member_name', true ) );
	if ( '' === $source ) {
		$source = trim( (string) $post->post_title );
	}
	$base = sanitize_title( $source );
	if ( '' === $base ) {
		$base = 'member-' . $post->ID;
	}

	// Never let a member URL shadow a per-state directory URL (/texas/ etc.).
	if ( function_exists( 'paccc_md_state_slugs' ) && in_array( $base, (array) paccc_md_state_slugs(), true ) ) {
		$base .= '-member';
	}
	return $base;
}

/**
 * Save a slug via wp_update_post() without re-running the member editor's own
 * save routine (it would re-read $_POST and, for a new member with no number,
 * assign a second member number) and without re-entering our sync hook.
 */
function paccc_md_write_member_slug( $post_id, $slug ) {
	$had_editor_save = has_action( 'save_post', 'paccc_md_save_member' );
	if ( false !== $had_editor_save ) {
		remove_action( 'save_post', 'paccc_md_save_member', $had_editor_save );
	}
	remove_action( 'save_post', 'paccc_md_sync_member_slug_on_save', 20 );

	wp_update_post(
		array(
			'ID'        => (int) $post_id,
			'post_name' => $slug,
		)
	);

	add_action( 'save_post', 'paccc_md_sync_member_slug_on_save', 20, 2 );
	if ( false !== $had_editor_save ) {
		add_action( 'save_post', 'paccc_md_save_member', $had_editor_save, 2 );
	}
}

/**
 * Point a member's slug at their name if it isn't already. A slug that is the
 * base plus a numeric suffix (amy-garofalo-2) counts as in sync, so duplicates
 * don't churn on every save.
 *
 * @return bool True if the slug changed.
 */
function paccc_md_sync_member_slug( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || PACCC_MD_CPT !== $post->post_type || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return false;
	}

	$base    = paccc_md_member_slug_base( $post->ID );
	$current = (string) $post->post_name;
	if ( '' === $base || $current === $base || preg_match( '/^' . preg_quote( $base, '/' ) . '-\d+$/', $current ) ) {
		return false;
	}

	// 'publish' so uniqueness is enforced even for drafts.
	paccc_md_write_member_slug( $post->ID, wp_unique_post_slug( $base, $post->ID, 'publish', PACCC_MD_CPT, 0 ) );

	// The directory list caches member permalinks.
	if ( function_exists( 'paccc_md_flush_directory_cache' ) ) {
		paccc_md_flush_directory_cache();
	}
	return true;
}

/**
 * Editor saves. Priority 20: the member-name meta box saves on save_post at
 * priority 10, so the new name is already stored when this runs.
 */
function paccc_md_sync_member_slug_on_save( $post_id, $post ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( $post && PACCC_MD_CPT === $post->post_type ) {
		paccc_md_sync_member_slug( $post_id );
	}
}
add_action( 'save_post', 'paccc_md_sync_member_slug_on_save', 20, 2 );

/**
 * One-time switch of every existing member to name-based URLs.
 *
 * Two passes so the result is deterministic: first every member gets a
 * temporary slug (raw DB write -- nothing reads these), freeing all current
 * ones; then each gets its name-based slug via wp_update_post() in ID order,
 * so the earliest member gets the plain URL, later duplicates get -2, -3, and
 * nobody picks up a needless suffix just because another member's *old*
 * business-name slug happened to match their name.
 *
 * @return int Number of members processed.
 */
function paccc_md_migrate_member_slugs() {
	global $wpdb;

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$ids = array_map(
		'intval',
		$wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC",
				PACCC_MD_CPT
			)
		)
	);

	foreach ( $ids as $id ) {
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'paccc-tmp-' . $id ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( $id );
	}

	foreach ( $ids as $id ) {
		paccc_md_write_member_slug( $id, wp_unique_post_slug( paccc_md_member_slug_base( $id ), $id, 'publish', PACCC_MD_CPT, 0 ) );
		// WordPress records the slug it replaced for old-URL redirects; the
		// temporary one is meaningless, so drop it.
		delete_post_meta( $id, '_wp_old_slug', 'paccc-tmp-' . $id );
	}

	if ( function_exists( 'paccc_md_flush_directory_cache' ) ) {
		paccc_md_flush_directory_cache();
	}
	return count( $ids );
}

/**
 * Run the one-time migration on the first admin page load after this update.
 * Marked done only once it completes; if a run is interrupted (e.g. a
 * timeout), the next admin load after 5 minutes simply runs it again -- the
 * migration is repeatable, so a re-run lands on the same result. The
 * "started" timestamp keeps concurrent admin requests from overlapping.
 */
function paccc_md_maybe_migrate_member_slugs() {
	if ( 'done' === get_option( 'paccc_md_slugs_by_member_name' ) ) {
		return;
	}
	// admin_init also fires for admin-post.php / admin-ajax.php requests from
	// logged-out visitors -- only let an administrator trigger the bulk rewrite.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$started = (int) get_option( 'paccc_md_slugs_migration_started', 0 );
	if ( $started && ( time() - $started ) < 5 * MINUTE_IN_SECONDS ) {
		return; // another request is running it right now
	}
	update_option( 'paccc_md_slugs_migration_started', time(), false );

	$count = paccc_md_migrate_member_slugs();

	update_option( 'paccc_md_slugs_by_member_name', 'done' );
	update_option( 'paccc_md_slugs_migrated_count', $count, false );
	delete_option( 'paccc_md_slugs_migration_started' );
}
add_action( 'admin_init', 'paccc_md_maybe_migrate_member_slugs' );
