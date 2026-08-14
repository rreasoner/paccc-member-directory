<?php
/**
 * PACCC Member Directory -- spreadsheet import.
 *
 * Adds an "Import members from a spreadsheet" tool to the settings screen that
 * reads an .xlsx export (the paccert.org certified-directory format) and creates
 * a published member per row, mapping the columns to the member fields. Rows
 * already imported (matched by Profile URL) are skipped, so re-running is safe.
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Minimal .xlsx reader (no external library)
 * ------------------------------------------------------------------------ */

/**
 * Spreadsheet column letters (e.g. "AB") to a 0-based index.
 */
function paccc_md_col_to_index( $ref ) {
	$letters = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $ref ) );
	$n       = 0;
	$len     = strlen( $letters );
	for ( $i = 0; $i < $len; $i++ ) {
		$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
	}
	return $n - 1;
}

/**
 * Parse one worksheet's XML into an array of rows (each a 0-indexed array of
 * cell strings), resolving shared-string references.
 */
function paccc_md_parse_sheet_xml( $xml_str, $shared ) {
	if ( false === $xml_str || '' === $xml_str ) {
		return null;
	}
	$x = @simplexml_load_string( $xml_str ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $x || ! isset( $x->sheetData ) ) {
		return null;
	}

	$rows = array();
	foreach ( $x->sheetData->row as $row ) {
		$cells = array();
		$max   = -1;
		foreach ( $row->c as $c ) {
			$ci   = (string) $c['r'] ? paccc_md_col_to_index( (string) $c['r'] ) : count( $cells );
			$type = (string) $c['t'];
			if ( 's' === $type ) {
				$idx = (int) $c->v;
				$val = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
			} elseif ( 'inlineStr' === $type ) {
				$val = isset( $c->is->t ) ? (string) $c->is->t : '';
			} else {
				$val = isset( $c->v ) ? (string) $c->v : '';
			}
			$cells[ $ci ] = $val;
			if ( $ci > $max ) {
				$max = $ci;
			}
		}
		$seq = array();
		for ( $i = 0; $i <= $max; $i++ ) {
			$seq[ $i ] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
		}
		$rows[] = $seq;
	}
	return $rows;
}

/**
 * Read an .xlsx file into rows. Picks the worksheet whose header row contains
 * the expected "Name" and "Credentials" columns (so the Summary tab is skipped).
 * Returns rows (first row = header) or a WP_Error.
 */
function paccc_md_read_xlsx( $file ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'paccc_no_zip', 'The server is missing the ZipArchive PHP extension needed to read .xlsx files.' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file ) ) {
		return new WP_Error( 'paccc_bad_zip', 'That file could not be opened as an .xlsx spreadsheet.' );
	}

	// Workbook-wide shared strings.
	$shared = array();
	$ss_raw = $zip->getFromName( 'xl/sharedStrings.xml' );
	if ( false !== $ss_raw ) {
		$sx = @simplexml_load_string( $ss_raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $sx ) {
			foreach ( $sx->si as $si ) {
				$t = isset( $si->t ) ? (string) $si->t : '';
				foreach ( $si->r as $r ) {
					$t .= (string) $r->t;
				}
				$shared[] = $t;
			}
		}
	}

	$best = null;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( ! preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $name ) ) {
			continue;
		}
		$rows = paccc_md_parse_sheet_xml( $zip->getFromName( $name ), $shared );
		if ( ! $rows ) {
			continue;
		}
		$hdr = array_map( 'paccc_md_normalize_header', $rows[0] );
		if ( in_array( 'name', $hdr, true ) && in_array( 'credentials', $hdr, true ) ) {
			$best = $rows;
			break;
		}
		if ( null === $best ) {
			$best = $rows; // fallback to the first readable sheet
		}
	}
	$zip->close();

	if ( ! $best ) {
		return new WP_Error( 'paccc_no_rows', 'No readable rows were found in the spreadsheet.' );
	}
	return $best;
}

function paccc_md_normalize_header( $h ) {
	return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $h ) ) );
}

/* ---------------------------------------------------------------------------
 * Value parsing
 * ------------------------------------------------------------------------ */

/**
 * Best-effort parse of a freeform address string into street / city / zip /
 * state abbreviation. Handles the common shapes in the export, e.g.
 * "Hawthorn Woods IL", "515 Towne Centre Blvd. Pineville NC 28134 United States".
 */
function paccc_md_parse_import_address( $raw ) {
	$out = array(
		'address1'   => '',
		'city'       => '',
		'zip'        => '',
		'state_abbr' => '',
	);
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return $out;
	}

	// Drop a trailing country.
	$raw = preg_replace( '/,?\s*(United States|USA|U\.S\.A\.|America|Canada)\.?\s*$/i', '', $raw );
	$raw = trim( $raw, " ,\t" );

	// Trailing ZIP (US 5[-4]) or Canadian postal code.
	if ( preg_match( '/\b(\d{5}(?:-\d{4})?)\s*$/', $raw, $m ) ) {
		$out['zip'] = $m[1];
		$raw        = trim( substr( $raw, 0, - strlen( $m[0] ) ), " ,\t" );
	} elseif ( preg_match( '/\b([A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d)\s*$/', $raw, $m ) ) {
		$out['zip'] = strtoupper( $m[1] );
		$raw        = trim( substr( $raw, 0, - strlen( $m[0] ) ), " ,\t" );
	}

	// Trailing 2-letter state / province code (also matches a bare "FL").
	if ( preg_match( '/(?:^|[\s,])([A-Za-z]{2})\s*$/', $raw, $m ) ) {
		$out['state_abbr'] = strtoupper( $m[1] );
		$raw               = trim( substr( $raw, 0, - strlen( $m[0] ) ), " ,\t" );
	}

	$raw = trim( $raw, " ,.\t" );
	if ( '' === $raw ) {
		return $out;
	}

	// A leading street number means street+city are run together with no
	// reliable separator, so keep the whole remainder as the street line.
	if ( preg_match( '/^\d/', $raw ) ) {
		$out['address1'] = $raw;
	} else {
		$out['city'] = $raw;
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Import UI + handler
 * ------------------------------------------------------------------------ */

/**
 * The import section, rendered on the settings screen.
 */
function paccc_md_render_import_section() {
	?>
	<h2>Import members from a spreadsheet</h2>
	<p class="description">
		Upload the PACCC certified-directory <code>.xlsx</code> export. Each row becomes a
		published member: <em>Name</em> &rarr; Member Name, <em>Credentials</em> &rarr;
		Certification(s), <em>Business / Organization</em> &rarr; Business Name (falls back to
		the person&rsquo;s name when blank), <em>Address</em> / <em>State</em> &rarr; location,
		plus Website and Email. &ldquo;Email Type&rdquo; and &ldquo;Photo on File&rdquo; are
		ignored. Rows already imported (matched by their Profile URL) are skipped, so importing
		the same file twice is safe. Members outside the U.S. import without a state (they
		won&rsquo;t appear on the map).
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="paccc_md_import" />
		<?php wp_nonce_field( 'paccc_md_import' ); ?>
		<input type="file" name="paccc_import_file" accept=".xlsx" required />
		<?php submit_button( 'Import Members', 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

/**
 * Handle the uploaded spreadsheet: create a member per row.
 */
function paccc_md_handle_import() {
	check_admin_referer( 'paccc_md_import' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	$redirect = paccc_md_settings_url();

	if ( empty( $_FILES['paccc_import_file'] ) || ! isset( $_FILES['paccc_import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['paccc_import_file']['error'] ) {
		wp_safe_redirect( paccc_md_settings_url( array( 'paccc_msg' => 'import_nofile' ) ) );
		exit;
	}

	$fname = isset( $_FILES['paccc_import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['paccc_import_file']['name'] ) ) : '';
	if ( ! preg_match( '/\.xlsx$/i', $fname ) ) {
		wp_safe_redirect( paccc_md_settings_url( array( 'paccc_msg' => 'import_badtype' ) ) );
		exit;
	}

	$tmp = isset( $_FILES['paccc_import_file']['tmp_name'] ) ? $_FILES['paccc_import_file']['tmp_name'] : '';
	if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
		wp_safe_redirect( paccc_md_settings_url( array( 'paccc_msg' => 'import_nofile' ) ) );
		exit;
	}

	$rows = paccc_md_read_xlsx( $tmp );
	if ( is_wp_error( $rows ) ) {
		wp_safe_redirect( paccc_md_settings_url( array( 'paccc_msg' => 'import_error' ) ) );
		exit;
	}

	// Header index (normalized name => column).
	$header = array_shift( $rows );
	$col    = array();
	foreach ( (array) $header as $i => $h ) {
		$col[ paccc_md_normalize_header( $h ) ] = $i;
	}
	$get = function ( $row, $key ) use ( $col ) {
		return ( isset( $col[ $key ] ) && isset( $row[ $col[ $key ] ] ) ) ? trim( (string) $row[ $col[ $key ] ] ) : '';
	};

	// Lookups.
	$known_upper = array();
	foreach ( paccc_md_certifications() as $c ) {
		$known_upper[ strtoupper( $c ) ] = $c;
	}
	$state_map = array();
	foreach ( paccc_md_states() as $code => $nm ) {
		$state_map[ strtolower( $nm ) ] = $code;
	}
	$us_codes = paccc_md_states();

	global $wpdb;
	$existing = array_flip( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_paccc_import_profile_url' AND meta_value <> ''" ) ); // phpcs:ignore WordPress.DB

	$next = (int) preg_replace( '/\D/', '', paccc_md_next_member_number() );
	if ( ! $next ) {
		$next = 1000001;
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$added   = 0;
	$skipped = 0;
	$failed  = 0;

	foreach ( $rows as $row ) {
		$name     = $get( $row, 'name' );
		$business = $get( $row, 'business / organization' );
		$title    = '' !== $business ? $business : $name;
		if ( '' === $title ) {
			$skipped++;
			continue;
		}

		$profile = $get( $row, 'profile url' );
		if ( '' !== $profile && isset( $existing[ $profile ] ) ) {
			$skipped++;
			continue;
		}

		// Certifications: split, keep only recognized codes.
		$certs = array();
		foreach ( preg_split( '/[,;\/]+/', $get( $row, 'credentials' ) ) as $part ) {
			$p = strtoupper( trim( $part ) );
			if ( '' !== $p && isset( $known_upper[ $p ] ) ) {
				$certs[] = $known_upper[ $p ];
			}
		}
		$certs = array_values( array_unique( $certs ) );

		// State: full name -> code; fall back to a parsed U.S. abbreviation.
		$state = '';
		$sp    = strtolower( $get( $row, 'state / province' ) );
		if ( isset( $state_map[ $sp ] ) ) {
			$state = $state_map[ $sp ];
		}

		$addr = paccc_md_parse_import_address( $get( $row, 'address (as listed)' ) );
		if ( '' === $state && '' !== $addr['state_abbr'] && isset( $us_codes[ $addr['state_abbr'] ] ) ) {
			$state = $addr['state_abbr'];
		}

		$website = $get( $row, 'website' );
		if ( '' !== $website && ! preg_match( '#^https?://#i', $website ) ) {
			$website = 'https://' . $website;
		}
		$email = sanitize_email( $get( $row, 'email' ) );

		$post_id = wp_insert_post(
			array(
				'post_type'   => PACCC_MD_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$failed++;
			continue;
		}

		update_post_meta( $post_id, 'paccc_member_number', str_pad( (string) $next, 7, '0', STR_PAD_LEFT ) );
		$next++;
		update_post_meta( $post_id, 'paccc_member_name', sanitize_text_field( $name ) );
		update_post_meta( $post_id, 'paccc_certifications', $certs );
		update_post_meta( $post_id, 'paccc_address1', sanitize_text_field( $addr['address1'] ) );
		update_post_meta( $post_id, 'paccc_address2', '' );
		update_post_meta( $post_id, 'paccc_city', sanitize_text_field( $addr['city'] ) );
		update_post_meta( $post_id, 'paccc_state', $state );
		update_post_meta( $post_id, 'paccc_zip', sanitize_text_field( $addr['zip'] ) );
		update_post_meta( $post_id, 'paccc_website', esc_url_raw( $website ) );
		update_post_meta( $post_id, 'paccc_email', is_email( $email ) ? $email : '' );
		if ( '' !== $profile ) {
			$purl = esc_url_raw( $profile );
			update_post_meta( $post_id, '_paccc_import_profile_url', $purl );
			$existing[ $profile ] = true;
		}
		$added++;
	}

	wp_safe_redirect(
		paccc_md_settings_url(
			array(
				'paccc_msg'     => 'imported',
				'paccc_added'   => $added,
				'paccc_skipped' => $skipped,
				'paccc_failed'  => $failed,
			)
		)
	);
	exit;
}
add_action( 'admin_post_paccc_md_import', 'paccc_md_handle_import' );
