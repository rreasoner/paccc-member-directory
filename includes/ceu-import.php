<?php
/**
 * Spreadsheet sync for the Approved CEU catalog (CEU Master List format).
 *
 * Upload the CEU Master List .xlsx and the catalog is brought into line with
 * its "Provider approved programs" tab:
 *   - rows not yet in the catalog are ADDED,
 *   - rows that exist but whose details differ are UPDATED,
 *   - programs in the catalog that are absent from the sheet are TRASHED
 *     (recoverable from Trash),
 *   - rows that already match exactly are left untouched.
 *
 * Matching is by Program Code + Program Name (case-insensitive): the code alone
 * isn't unique in the master list (a few different programs share one), and a
 * program listed twice collapses to one entry.
 *
 * Columns used (header row, case-insensitive):
 *   (column A, no header) = Presenter | Contact Person | Contact E-mail |
 *   Program Name | Program Code | CEU Value | URL (if applicable)
 * The URL column's *hyperlink target* is used when the cell has one (the
 * visible text is sometimes just a page title), else the cell text if it's a
 * URL. All other columns are ignored.
 */

defined( 'ABSPATH' ) || exit;

/** Find a column index from a list of acceptable normalized header names. */
function paccc_ceu_col( $map, $candidates ) {
	foreach ( (array) $candidates as $name ) {
		if ( isset( $map[ $name ] ) ) {
			return $map[ $name ];
		}
	}
	return null;
}

/** Normalize a CEU amount cell: "1.0" => "1", "1.50" => "1.5", "9.75" => "9.75". */
function paccc_ceu_normalize_amount_value( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v || ! is_numeric( $v ) ) {
		return '';
	}
	return rtrim( rtrim( sprintf( '%.2f', (float) $v ), '0' ), '.' );
}

/** Collapse whitespace/newlines inside a cell value. */
function paccc_ceu_clean_text( $v ) {
	return trim( preg_replace( '/\s+/u', ' ', (string) $v ) );
}

/** First email address found in a cell (cells sometimes carry stray newlines). */
function paccc_ceu_clean_email( $v ) {
	if ( ! preg_match( '/[^\s,;<>"]+@[^\s,;<>"]+/', (string) $v, $m ) ) {
		return '';
	}
	$email = sanitize_email( $m[0] );
	return is_email( $email ) ? $email : '';
}

/** Sync key for a program: code + name, case-insensitive. */
function paccc_ceu_row_key( $code, $name ) {
	return strtolower( trim( (string) $code ) ) . '|' . strtolower( paccc_ceu_clean_text( $name ) );
}

/** Get (or create) a presenter/provider term by name; caches ids across a run. */
function paccc_ceu_get_or_create_provider( $name, &$cache ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return 0;
	}
	if ( isset( $cache[ $name ] ) ) {
		return $cache[ $name ];
	}
	$existing = term_exists( $name, PACCC_CEU_TAX );
	if ( $existing && ! is_wp_error( $existing ) ) {
		$cache[ $name ] = (int) $existing['term_id'];
	} else {
		$new            = wp_insert_term( $name, PACCC_CEU_TAX );
		$cache[ $name ] = is_wp_error( $new ) ? 0 : (int) $new['term_id'];
	}
	return $cache[ $name ];
}

/**
 * Read the CEU Master List. Picks the programs tab by its headers (not by
 * position), keeps spreadsheet row numbers, and resolves cell hyperlinks.
 *
 * @return array|WP_Error {
 *     @type array $header    Normalized header cells, keyed by column index.
 *     @type int   $header_rn Spreadsheet row number of the header row.
 *     @type array $rows      Row number => [ column index => cell text ].
 *     @type array $links     Row number => [ column index => hyperlink URL ].
 * }
 */
function paccc_ceu_read_master_xlsx( $file ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'paccc_no_zip', 'The server is missing the ZipArchive PHP extension needed to read .xlsx files.' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file ) ) {
		return new WP_Error( 'paccc_bad_zip', 'That file could not be opened as an .xlsx spreadsheet.' );
	}

	// Workbook-wide shared strings (plain and rich-text runs).
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

	$best       = null;
	$best_score = 0;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( ! preg_match( '#^xl/worksheets/(sheet\d+\.xml)$#', $name, $m ) ) {
			continue;
		}
		$xml = @simplexml_load_string( $zip->getFromName( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $xml || ! isset( $xml->sheetData ) ) {
			continue;
		}

		$rows = array();
		foreach ( $xml->sheetData->row as $row ) {
			$cells   = array();
			$next_ci = 0;
			foreach ( $row->c as $c ) {
				$ref     = (string) $c['r'];
				$ci      = '' !== $ref ? paccc_md_col_to_index( $ref ) : $next_ci;
				$next_ci = $ci + 1;
				$type    = (string) $c['t'];
				if ( 's' === $type ) {
					$idx = (int) $c->v;
					$val = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
				} elseif ( 'inlineStr' === $type ) {
					$val = isset( $c->is->t ) ? (string) $c->is->t : '';
					foreach ( $c->is->r as $r ) {
						$val .= (string) $r->t;
					}
				} else {
					$val = isset( $c->v ) ? (string) $c->v : '';
				}
				$cells[ $ci ] = $val;
			}
			$rn          = (int) $row['r'];
			$rows[ $rn ? $rn : count( $rows ) + 1 ] = $cells;
		}
		if ( ! $rows ) {
			continue;
		}
		ksort( $rows );
		$header_rn = (int) key( $rows );
		$header    = array();
		foreach ( $rows[ $header_rn ] as $ci => $h ) {
			$header[ $ci ] = paccc_md_normalize_header( $h );
		}

		// Score tabs by their headers; the "Attendee approved programs" tab
		// also has Program Name/CEU Value, so its Provider Code counts against it.
		$score = 0;
		$score += in_array( 'program name', $header, true ) ? 2 : 0;
		$score += in_array( 'url (if applicable)', $header, true ) ? 2 : 0;
		$score += in_array( 'contact person', $header, true ) ? 1 : 0;
		$score += in_array( 'ceu value', $header, true ) ? 1 : 0;
		$score -= in_array( 'provider code', $header, true ) ? 3 : 0;
		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = array(
				'file'      => $m[1],
				'xml'       => $xml,
				'rows'      => $rows,
				'header'    => $header,
				'header_rn' => $header_rn,
			);
		}
	}

	if ( ! $best ) {
		$zip->close();
		return new WP_Error( 'paccc_no_sheet', 'No tab with a "Program Name" column was found. Please upload the CEU Master List.' );
	}

	// Hyperlinks: <hyperlink ref="M5" r:id="rId3"/> -> the sheet's .rels Target.
	$links = array();
	if ( isset( $best['xml']->hyperlinks ) ) {
		$rels     = array();
		$rels_raw = $zip->getFromName( 'xl/worksheets/_rels/' . $best['file'] . '.rels' );
		if ( false !== $rels_raw ) {
			$rx = @simplexml_load_string( $rels_raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $rx ) {
				foreach ( $rx->Relationship as $rel ) {
					$rels[ (string) $rel['Id'] ] = (string) $rel['Target'];
				}
			}
		}
		foreach ( $best['xml']->hyperlinks->hyperlink as $hl ) {
			$ref = (string) $hl['ref'];
			$rid = (string) $hl->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			if ( '' === $ref || '' === $rid || ! isset( $rels[ $rid ] ) ) {
				continue;
			}
			// A link can cover a range ("M5:M7"); apply it to every cell in it.
			$parts = explode( ':', $ref );
			if ( ! preg_match( '/^([A-Z]+)(\d+)$/i', $parts[0], $a ) ) {
				continue;
			}
			$b = ( isset( $parts[1] ) && preg_match( '/^([A-Z]+)(\d+)$/i', $parts[1], $bm ) ) ? $bm : $a;
			$c1 = paccc_md_col_to_index( $a[1] );
			$c2 = paccc_md_col_to_index( $b[1] );
			for ( $r = (int) $a[2]; $r <= (int) $b[2] && $r <= (int) $a[2] + 5000; $r++ ) {
				for ( $c = $c1; $c <= $c2; $c++ ) {
					$links[ $r ][ $c ] = $rels[ $rid ];
				}
			}
		}
	}
	$zip->close();

	return array(
		'header'    => $best['header'],
		'header_rn' => $best['header_rn'],
		'rows'      => $best['rows'],
		'links'     => $links,
	);
}

/**
 * Sync the catalog to the CEU Master List.
 *
 * @param string $file Absolute path to an .xlsx file.
 * @return array|WP_Error counts: added, updated, deleted, unchanged, skipped.
 */
function paccc_ceu_sync_from_file( $file ) {
	if ( ! file_exists( $file ) ) {
		return new WP_Error( 'no_file', 'Spreadsheet file not found.' );
	}

	$data = paccc_ceu_read_master_xlsx( $file );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$header = $data['header'];
	$map    = array();
	foreach ( $header as $ci => $h ) {
		if ( '' !== $h && ! isset( $map[ $h ] ) ) {
			$map[ $h ] = $ci;
		}
	}

	$c_name    = paccc_ceu_col( $map, array( 'program name', 'course/program name', 'course name', 'program', 'course' ) );
	$c_code    = paccc_ceu_col( $map, array( 'program code', 'code' ) );
	$c_contact = paccc_ceu_col( $map, array( 'contact person', 'contact name', 'contact' ) );
	$c_email   = paccc_ceu_col( $map, array( 'contact e-mail', 'contact email', 'e-mail', 'email' ) );
	$c_amount  = paccc_ceu_col( $map, array( 'ceu value', 'ceus', 'ceu', 'number of ceus', 'ceu amount' ) );
	$c_url     = paccc_ceu_col( $map, array( 'url (if applicable)', 'url', 'apply url', 'apply link', 'link' ) );
	// The presenter is column A, which has no header in the master list.
	$c_presenter = paccc_ceu_col( $map, array( 'presenter', 'organization', 'provider' ) );
	if ( null === $c_presenter && ( ! isset( $header[0] ) || '' === $header[0] ) ) {
		$c_presenter = 0;
	}

	if ( null === $c_name ) {
		return new WP_Error( 'no_name_col', 'No "Program Name" column found in the spreadsheet.' );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- several hundred rows
	}
	wp_defer_term_counting( true );

	// Existing catalog keyed like the sheet. Full post objects (not ids) so
	// WordPress primes the meta + term caches in bulk. Any duplicate posts
	// with the same key are trashed.
	$existing = array();
	$extra    = array();
	$posts    = get_posts(
		array(
			'post_type'   => PACCC_CEU_CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
		)
	);
	foreach ( $posts as $p ) {
		$k = paccc_ceu_row_key( get_post_meta( $p->ID, 'paccc_ceu_program_code', true ), $p->post_title );
		if ( isset( $existing[ $k ] ) ) {
			$extra[] = (int) $p->ID;
		} else {
			$existing[ $k ] = $p;
		}
	}

	$added     = 0;
	$updated   = 0;
	$unchanged = 0;
	$skipped   = 0;
	$seen      = array();
	$terms     = array();

	$cell = static function ( $row, $idx ) {
		return ( null !== $idx && isset( $row[ $idx ] ) ) ? (string) $row[ $idx ] : '';
	};

	foreach ( $data['rows'] as $rn => $row ) {
		if ( $rn <= $data['header_rn'] ) {
			continue;
		}
		$name = paccc_ceu_clean_text( $cell( $row, $c_name ) );
		if ( '' === $name ) {
			continue; // blank row
		}
		$code = paccc_ceu_clean_text( $cell( $row, $c_code ) );
		$key  = paccc_ceu_row_key( $code, $name );
		if ( isset( $seen[ $key ] ) ) {
			$skipped++; // same program listed twice in the sheet
			continue;
		}
		$seen[ $key ] = true;

		$presenter = paccc_ceu_clean_text( $cell( $row, $c_presenter ) );

		$url = '';
		if ( null !== $c_url ) {
			if ( ! empty( $data['links'][ $rn ][ $c_url ] ) ) {
				$url = $data['links'][ $rn ][ $c_url ];
			} else {
				$text = trim( $cell( $row, $c_url ) );
				if ( preg_match( '#^https?://#i', $text ) ) {
					$url = $text;
				}
			}
		}

		$fields = array(
			'paccc_ceu_program_code'  => $code,
			'paccc_ceu_presenter'     => $presenter,
			'paccc_ceu_contact_name'  => paccc_ceu_clean_text( $cell( $row, $c_contact ) ),
			'paccc_ceu_contact_email' => paccc_ceu_clean_email( $cell( $row, $c_email ) ),
			'paccc_ceu_amount'        => paccc_ceu_normalize_amount_value( $cell( $row, $c_amount ) ),
			'paccc_ceu_website'       => esc_url_raw( trim( $url ) ),
		);

		if ( isset( $existing[ $key ] ) ) {
			$p = $existing[ $key ];

			$cur_terms = get_the_terms( $p->ID, PACCC_CEU_TAX );
			$cur_prov  = ( $cur_terms && ! is_wp_error( $cur_terms ) ) ? reset( $cur_terms )->name : '';

			$same = ( $p->post_title === $name ) && ( $cur_prov === $presenter );
			foreach ( $fields as $mk => $mv ) {
				if ( ! $same ) {
					break;
				}
				$same = ( (string) get_post_meta( $p->ID, $mk, true ) === $mv );
			}
			if ( $same ) {
				$unchanged++;
				continue;
			}

			if ( $p->post_title !== $name ) {
				wp_update_post(
					array(
						'ID'         => $p->ID,
						'post_title' => wp_slash( $name ),
					)
				);
			}
			foreach ( $fields as $mk => $mv ) {
				update_post_meta( $p->ID, $mk, $mv );
			}
			$tid = paccc_ceu_get_or_create_provider( $presenter, $terms );
			wp_set_object_terms( $p->ID, $tid ? array( $tid ) : array(), PACCC_CEU_TAX, false );

			$updated++;
			continue;
		}

		// New program.
		$pid = wp_insert_post(
			array(
				'post_type'   => PACCC_CEU_CPT,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $name ),
			),
			true
		);
		if ( is_wp_error( $pid ) || ! $pid ) {
			$skipped++;
			continue;
		}
		foreach ( $fields as $mk => $mv ) {
			update_post_meta( $pid, $mk, $mv );
		}
		$tid = paccc_ceu_get_or_create_provider( $presenter, $terms );
		if ( $tid ) {
			wp_set_object_terms( $pid, array( $tid ), PACCC_CEU_TAX, false );
		}
		$added++;
	}

	// Trash catalog entries absent from the sheet, plus duplicate posts.
	$deleted = 0;
	foreach ( $existing as $key => $p ) {
		if ( ! isset( $seen[ $key ] ) ) {
			wp_trash_post( $p->ID );
			$deleted++;
		}
	}
	foreach ( $extra as $pid ) {
		wp_trash_post( $pid );
		$deleted++;
	}

	wp_defer_term_counting( false );

	return array(
		'added'     => $added,
		'updated'   => $updated,
		'deleted'   => $deleted,
		'unchanged' => $unchanged,
		'skipped'   => $skipped,
	);
}

/**
 * Sync panel at the top of the All CEUs list screen.
 */
function paccc_ceu_import_panel() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-' . PACCC_CEU_CPT !== $screen->id ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Result notice after a sync run.
	if ( isset( $_GET['paccc_ceu_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$g = static function ( $k ) {
			return isset( $_GET[ $k ] ) ? (int) $_GET[ $k ] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		};
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					'CEU sync complete — %d added, %d updated, %d trashed, %d unchanged, %d skipped (duplicates).',
					$g( 'added' ),
					$g( 'updated' ),
					$g( 'deleted' ),
					$g( 'unchanged' ),
					$g( 'skipped' )
				)
			)
		);
	}
	if ( isset( $_GET['paccc_ceu_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( sanitize_text_field( wp_unslash( $_GET['paccc_ceu_error'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
		);
	}
	?>
	<div class="notice notice-info paccc-ceu-import-panel">
		<p style="font-size:14px;margin-bottom:4px;">
			<strong><?php esc_html_e( 'Sync CEUs from the CEU Master List', 'paccc-member-directory' ); ?></strong>
		</p>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Upload the CEU Master List (.xlsx). Programs are read from the "Provider approved programs" tab: new ones are added, changed ones updated, and programs missing from the sheet are moved to Trash (recoverable). Matching is by Program Code + Program Name.', 'paccc-member-directory' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin:10px 0;">
			<?php wp_nonce_field( 'paccc_ceu_import', 'paccc_ceu_import_nonce' ); ?>
			<input type="hidden" name="action" value="paccc_ceu_import" />
			<input type="file" name="paccc_ceu_file" accept=".xlsx" required style="margin-right:8px;" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Import & sync', 'paccc-member-directory' ); ?></button>
		</form>
	</div>
	<?php
}
add_action( 'admin_notices', 'paccc_ceu_import_panel' );

/**
 * Handle the sync form submission.
 */
function paccc_ceu_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'paccc_ceu_import', 'paccc_ceu_import_nonce' );

	$redirect = add_query_arg( 'post_type', PACCC_CEU_CPT, admin_url( 'edit.php' ) );

	$path = '';
	if ( isset( $_FILES['paccc_ceu_file'] ) && empty( $_FILES['paccc_ceu_file']['error'] ) ) {
		$name = isset( $_FILES['paccc_ceu_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['paccc_ceu_file']['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' !== $ext ) {
			wp_safe_redirect( add_query_arg( 'paccc_ceu_error', rawurlencode( 'Please upload an .xlsx file.' ), $redirect ) );
			exit;
		}
		$path = isset( $_FILES['paccc_ceu_file']['tmp_name'] ) ? $_FILES['paccc_ceu_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	// Only ever read a file PHP itself received in this upload.
	if ( ! $path || ! is_uploaded_file( $path ) ) {
		wp_safe_redirect( add_query_arg( 'paccc_ceu_error', rawurlencode( 'No spreadsheet was received.' ), $redirect ) );
		exit;
	}

	$result = paccc_ceu_sync_from_file( $path );

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'paccc_ceu_error', rawurlencode( $result->get_error_message() ), $redirect ) );
		exit;
	}

	$result['paccc_ceu_synced'] = 1;
	wp_safe_redirect( add_query_arg( $result, $redirect ) );
	exit;
}
add_action( 'admin_post_paccc_ceu_import', 'paccc_ceu_handle_import' );
