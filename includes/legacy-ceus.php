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
 * The CEU names shown on the directory, member page and portal: the admin-set
 * CEU(s) first, then old-site CEUs. A name appears once even if it was logged
 * several times (compared ignoring case and spacing); the first spelling wins.
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

/* ---------------------------------------------------------------------------
 * Admin: read-only "CEUs from the old site" box on the member edit screen
 * ------------------------------------------------------------------------ */

function paccc_md_add_legacy_ceu_meta_box() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_meta_box( 'paccc_md_legacy_ceus', 'CEUs from the old site', 'paccc_md_render_legacy_ceu_meta_box', PACCC_MD_CPT, 'normal', 'default' );
}
add_action( 'add_meta_boxes', 'paccc_md_add_legacy_ceu_meta_box' );

function paccc_md_render_legacy_ceu_meta_box( $post ) {
	$rows = paccc_md_legacy_ceus( $post->ID );
	if ( isset( $_GET['paccc_legacy_removed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success inline"><p>Old-site CEUs removed from this member.</p></div>';
	}
	if ( ! $rows ) {
		echo '<p>None. Old-site CEUs are added from <strong>PACCC Suite &rarr; Import Old CEUs</strong>.</p>';
		return;
	}
	?>
	<p class="description">
		Imported as entered on the old site. Only the name (or the code, if the name was blank) shows on the directory and member page; a name logged more than once shows once.
	</p>
	<table class="widefat striped" style="margin-top:8px;">
		<thead>
			<tr><th>Name</th><th>Provider</th><th>Code</th><th>Hours</th><th>Date</th><th>Old row</th></tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( isset( $row['title'] ) ? $row['title'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['provider'] ) ? $row['provider'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['code'] ) ? $row['code'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['hours'] ) ? $row['hours'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['date'] ) ? $row['date'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['wdt_id'] ) ? $row['wdt_id'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'paccc_md_remove_legacy_ceus',
				'post'   => $post->ID,
			),
			admin_url( 'admin-post.php' )
		),
		'paccc_md_remove_legacy_ceus_' . $post->ID
	);
	?>
	<p style="margin-top:10px;">
		<a href="<?php echo esc_url( $url ); ?>" class="button-link" style="color:#b32d2e;"
			onclick="return confirm('Remove all <?php echo (int) count( $rows ); ?> old-site CEUs from this member? Use this if they were matched to the wrong member. Re-running the import will add them back unless you map this person elsewhere in a match-overrides file.');">
			Remove all old-site CEUs from this member
		</a>
	</p>
	<?php
}

function paccc_md_handle_remove_legacy_ceus() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'paccc_md_remove_legacy_ceus_' . $post_id );
	if ( ! current_user_can( 'manage_options' ) || PACCC_MD_CPT !== get_post_type( $post_id ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	delete_post_meta( $post_id, PACCC_MD_LEGACY_CEU_META );
	paccc_md_flush_directory_cache();
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
	wp_safe_redirect( add_query_arg( 'paccc_legacy_removed', 1, get_edit_post_link( $post_id, 'url' ) ) );
	exit;
}
add_action( 'admin_post_paccc_md_remove_legacy_ceus', 'paccc_md_handle_remove_legacy_ceus' );
