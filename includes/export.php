<?php
/**
 * PACCC Suite -- CSV exports.
 *
 * Two read-only exports, each an "Export CSV" button at the top of its admin
 * list screen:
 *   - Members (All Members screen): every non-trashed member profile with its
 *     member fields, the linked WordPress login (if any), its admin-set CEU(s)
 *     and any CEUs imported from the old site.
 *   - Approved CEUs (All CEUs screen): every non-trashed catalog program.
 *
 * Nothing is written to the database. Administrators only.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Make a cell safe to open in Excel/Sheets: a value starting with = + - @ (or a
 * tab/CR) would otherwise run as a formula. Plain numbers such as "-1.5" are
 * left alone so the data itself is unchanged.
 */
function paccc_md_csv_cell( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( ' | ', array_map( 'strval', $value ) );
	}
	$value = (string) $value;
	if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) && ! is_numeric( $value ) ) {
		$value = "'" . $value;
	}
	return $value;
}

/**
 * Send CSV download headers plus a UTF-8 BOM (so Excel reads accents and curly
 * quotes correctly) and return the output stream.
 */
function paccc_md_csv_open( $basename ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $basename . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	return $out;
}

function paccc_md_csv_row( $out, $row ) {
	fputcsv( $out, array_map( 'paccc_md_csv_cell', $row ) );
}

/** URL of an export action (nonce'd). */
function paccc_md_export_url( $action ) {
	return wp_nonce_url( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ), $action );
}

/**
 * "Export CSV" button beside the filters at the top of the All Members and
 * All CEUs list screens.
 */
function paccc_md_export_button( $which ) {
	if ( 'top' !== $which || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit' !== $screen->base ) {
		return;
	}
	if ( PACCC_MD_CPT === $screen->post_type ) {
		$url   = paccc_md_export_url( 'paccc_md_export_members' );
		$label = 'Export members (CSV)';
	} elseif ( PACCC_CEU_CPT === $screen->post_type ) {
		$url   = paccc_md_export_url( 'paccc_ceu_export' );
		$label = 'Export CEUs (CSV)';
	} else {
		return;
	}
	printf(
		'<div class="alignleft actions"><a class="button" href="%s">%s</a></div>',
		esc_url( $url ),
		esc_html( $label )
	);
}
add_action( 'manage_posts_extra_tablenav', 'paccc_md_export_button' );

/* ---------------------------------------------------------------------------
 * Members
 * ------------------------------------------------------------------------ */

function paccc_md_handle_export_members() {
	check_admin_referer( 'paccc_md_export_members' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	$posts = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);

	// Prime the user cache for linked logins in one query.
	$user_ids = array();
	foreach ( $posts as $p ) {
		$uid = (int) get_post_meta( $p->ID, 'paccc_user_id', true );
		if ( $uid ) {
			$user_ids[] = $uid;
		}
	}
	if ( $user_ids && function_exists( 'cache_users' ) ) {
		cache_users( array_unique( $user_ids ) );
	}

	$out = paccc_md_csv_open( 'paccc-members' );
	paccc_md_csv_row(
		$out,
		array(
			'member_post_id',
			'post_status',
			'member_number',
			'business_name',
			'member_name',
			'member_email',
			'linked_user_id',
			'user_login',
			'user_email',
			'user_first_name',
			'user_last_name',
			'user_display_name',
			'certifications',
			'existing_ceus',
			'old_site_ceus',
			'city',
			'state',
			'country',
			'permalink',
		)
	);

	foreach ( $posts as $p ) {
		$m = paccc_md_get_member( $p );
		if ( ! $m ) {
			continue;
		}
		$uid  = (int) get_post_meta( $p->ID, 'paccc_user_id', true );
		$user = $uid ? get_userdata( $uid ) : false;

		paccc_md_csv_row(
			$out,
			array(
				$m->ID,
				$p->post_status,
				$m->member_number,
				$m->business_name,
				$m->member_name,
				$m->email,
				$user ? $user->ID : '',
				$user ? $user->user_login : '',
				$user ? $user->user_email : '',
				$user ? $user->first_name : '',
				$user ? $user->last_name : '',
				$user ? $user->display_name : '',
				$m->certifications,
				$m->ceus,
				paccc_md_ceu_display_names( array(), $m->legacy_ceus ),
				$m->city,
				$m->state,
				$m->country,
				$m->permalink,
			)
		);
	}

	fclose( $out );
	exit;
}
add_action( 'admin_post_paccc_md_export_members', 'paccc_md_handle_export_members' );

/* ---------------------------------------------------------------------------
 * Approved CEU catalog
 * ------------------------------------------------------------------------ */

function paccc_ceu_handle_export() {
	check_admin_referer( 'paccc_ceu_export' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	$posts = get_posts(
		array(
			'post_type'   => PACCC_CEU_CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	$out = paccc_md_csv_open( 'paccc-ceus' );
	paccc_md_csv_row(
		$out,
		array(
			'ceu_post_id',
			'post_status',
			'program_name',
			'program_code',
			'presenter',
			'provider_terms',
			'ceu_amount',
			'contact_name',
			'contact_email',
			'apply_now_url',
			'last_modified',
		)
	);

	foreach ( $posts as $p ) {
		$terms = get_the_terms( $p->ID, PACCC_CEU_TAX );
		$names = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();

		paccc_md_csv_row(
			$out,
			array(
				$p->ID,
				$p->post_status,
				$p->post_title,
				get_post_meta( $p->ID, 'paccc_ceu_program_code', true ),
				get_post_meta( $p->ID, 'paccc_ceu_presenter', true ),
				$names,
				get_post_meta( $p->ID, 'paccc_ceu_amount', true ),
				get_post_meta( $p->ID, 'paccc_ceu_contact_name', true ),
				get_post_meta( $p->ID, 'paccc_ceu_contact_email', true ),
				get_post_meta( $p->ID, 'paccc_ceu_website', true ),
				$p->post_modified,
			)
		);
	}

	fclose( $out );
	exit;
}
add_action( 'admin_post_paccc_ceu_export', 'paccc_ceu_handle_export' );
