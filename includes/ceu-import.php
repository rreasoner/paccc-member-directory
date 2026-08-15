<?php
/**
 * Spreadsheet sync for the Approved CEU Courses catalog.
 *
 * Upload an .xlsx (same shape as the generated PACCC_CEU_Programs.xlsx) and the
 * catalog is brought into line with it:
 *   - rows not yet in the catalog are ADDED,
 *   - rows whose course already exists but whose details differ are UPDATED,
 *   - courses in the catalog that are absent from the sheet are TRASHED,
 *   - rows that already match exactly are left untouched.
 * Matching is by Course/Program Name (case-insensitive). Trashed courses are
 * recoverable from Trash.
 *
 * Columns (header row, case-insensitive; only Course/Program Name is required):
 *   Course/Program Name | Presenter | Provider | CEUs | Website | Biography | Image URL
 */

defined( 'ABSPATH' ) || exit;

/** Bundled starter spreadsheet (the 144 scraped courses). */
function paccc_ceu_bundled_sheet() {
	return PACCC_MD_DIR . 'data/PACCC_CEU_Programs.xlsx';
}

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
	if ( '' === $v ) {
		return '';
	}
	if ( is_numeric( $v ) ) {
		$v = rtrim( rtrim( sprintf( '%.2f', (float) $v ), '0' ), '.' );
	}
	return $v;
}

/** Get (or create) a provider term by name; caches ids across a sync run. */
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
 * Sync the catalog to the rows of a spreadsheet file.
 *
 * @param string $file Absolute path to an .xlsx file.
 * @return array|WP_Error counts: added, updated, deleted, unchanged, skipped.
 */
function paccc_ceu_sync_from_file( $file ) {
	if ( ! file_exists( $file ) ) {
		return new WP_Error( 'no_file', 'Spreadsheet file not found.' );
	}

	$rows = paccc_md_read_xlsx( $file );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( count( $rows ) < 2 ) {
		return new WP_Error( 'empty', 'The spreadsheet has no data rows.' );
	}

	$header = array_map( 'paccc_md_normalize_header', array_shift( $rows ) );
	$map    = array();
	foreach ( $header as $i => $h ) {
		if ( '' !== $h && ! isset( $map[ $h ] ) ) {
			$map[ $h ] = $i;
		}
	}

	$c_course    = paccc_ceu_col( $map, array( 'course/program name', 'course name', 'program name', 'course', 'program', 'name' ) );
	$c_presenter = paccc_ceu_col( $map, array( 'presenter' ) );
	$c_provider  = paccc_ceu_col( $map, array( 'provider', 'organization' ) );
	$c_amount    = paccc_ceu_col( $map, array( 'ceus', 'ceu', 'number of ceus', 'ceu amount', 'amount' ) );
	$c_website   = paccc_ceu_col( $map, array( 'website', 'url' ) );
	$c_bio       = paccc_ceu_col( $map, array( 'biography', 'bio', 'description' ) );
	$c_image     = paccc_ceu_col( $map, array( 'image url', 'image', 'photo url', 'photo', 'logo' ) );

	if ( null === $c_course ) {
		return new WP_Error( 'no_course_col', 'No "Course/Program Name" column found in the spreadsheet.' );
	}

	// Existing catalog, keyed by lower-cased title.
	$existing = array();
	$posts    = get_posts(
		array(
			'post_type'   => PACCC_CEU_CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $posts as $pid ) {
		$key = strtolower( trim( get_the_title( $pid ) ) );
		if ( '' !== $key && ! isset( $existing[ $key ] ) ) {
			$existing[ $key ] = (int) $pid;
		}
	}

	$added     = 0;
	$updated   = 0;
	$unchanged = 0;
	$skipped   = 0;
	$seen      = array();
	$terms     = array();

	$cell = static function ( $row, $idx ) {
		return ( null !== $idx && isset( $row[ $idx ] ) ) ? trim( (string) $row[ $idx ] ) : '';
	};

	foreach ( $rows as $row ) {
		$course = $cell( $row, $c_course );
		if ( '' === $course ) {
			continue;
		}
		$key = strtolower( $course );
		if ( isset( $seen[ $key ] ) ) {
			$skipped++; // duplicate row in the sheet
			continue;
		}
		$seen[ $key ] = true;

		$presenter = $cell( $row, $c_presenter );
		$provider  = $cell( $row, $c_provider );
		$amount    = paccc_ceu_normalize_amount_value( $cell( $row, $c_amount ) );
		$website   = esc_url_raw( $cell( $row, $c_website ) );
		$bio       = $cell( $row, $c_bio );
		$image     = esc_url_raw( $cell( $row, $c_image ) );

		if ( isset( $existing[ $key ] ) ) {
			$pid = $existing[ $key ];

			$cur_terms = get_the_terms( $pid, PACCC_CEU_TAX );
			$cur_prov  = ( $cur_terms && ! is_wp_error( $cur_terms ) ) ? reset( $cur_terms )->name : '';

			$same = get_the_title( $pid ) === $course
				&& (string) get_post_meta( $pid, 'paccc_ceu_presenter', true ) === $presenter
				&& (string) get_post_meta( $pid, 'paccc_ceu_amount', true ) === $amount
				&& (string) get_post_meta( $pid, 'paccc_ceu_website', true ) === $website
				&& (string) get_post_meta( $pid, 'paccc_ceu_photo_url', true ) === $image
				&& trim( (string) get_post( $pid )->post_content ) === $bio
				&& $cur_prov === $provider;

			if ( $same ) {
				$unchanged++;
				continue;
			}

			wp_update_post(
				array(
					'ID'           => $pid,
					'post_title'   => wp_slash( $course ),
					'post_content' => wp_slash( $bio ),
				)
			);
			update_post_meta( $pid, 'paccc_ceu_presenter', $presenter );
			update_post_meta( $pid, 'paccc_ceu_amount', $amount );
			update_post_meta( $pid, 'paccc_ceu_website', $website );
			update_post_meta( $pid, 'paccc_ceu_photo_url', $image );

			$tid = paccc_ceu_get_or_create_provider( $provider, $terms );
			wp_set_object_terms( $pid, $tid ? array( $tid ) : array(), PACCC_CEU_TAX, false );

			$updated++;
			continue;
		}

		// New course.
		$pid = wp_insert_post(
			array(
				'post_type'    => PACCC_CEU_CPT,
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $course ),
				'post_content' => wp_slash( $bio ),
			),
			true
		);
		if ( is_wp_error( $pid ) || ! $pid ) {
			$skipped++;
			continue;
		}
		update_post_meta( $pid, 'paccc_ceu_presenter', $presenter );
		update_post_meta( $pid, 'paccc_ceu_amount', $amount );
		update_post_meta( $pid, 'paccc_ceu_website', $website );
		if ( '' !== $image ) {
			update_post_meta( $pid, 'paccc_ceu_photo_url', $image );
		}
		$tid = paccc_ceu_get_or_create_provider( $provider, $terms );
		if ( $tid ) {
			wp_set_object_terms( $pid, array( $tid ), PACCC_CEU_TAX, false );
		}
		$added++;
	}

	// Trash catalog entries absent from the sheet.
	$deleted = 0;
	foreach ( $existing as $key => $pid ) {
		if ( ! isset( $seen[ $key ] ) ) {
			wp_trash_post( $pid );
			$deleted++;
		}
	}

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
					'CEU sync complete — %d added, %d updated, %d trashed, %d unchanged, %d skipped.',
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

	$has_bundled = file_exists( paccc_ceu_bundled_sheet() );
	?>
	<div class="notice notice-info paccc-ceu-import-panel">
		<p style="font-size:14px;margin-bottom:4px;">
			<strong><?php esc_html_e( 'Sync CEUs from a spreadsheet', 'paccc-member-directory' ); ?></strong>
		</p>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Upload an .xlsx in the PACCC_CEU_Programs format. New courses are added, changed ones updated, and courses missing from the sheet are moved to Trash (recoverable). Matching is by Course/Program Name.', 'paccc-member-directory' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin:10px 0;">
			<?php wp_nonce_field( 'paccc_ceu_import', 'paccc_ceu_import_nonce' ); ?>
			<input type="hidden" name="action" value="paccc_ceu_import" />
			<input type="file" name="paccc_ceu_file" accept=".xlsx" required style="margin-right:8px;" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Import & sync', 'paccc-member-directory' ); ?></button>
		</form>
		<?php if ( $has_bundled ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 10px;">
				<?php wp_nonce_field( 'paccc_ceu_import', 'paccc_ceu_import_nonce' ); ?>
				<input type="hidden" name="action" value="paccc_ceu_import" />
				<input type="hidden" name="paccc_ceu_use_bundled" value="1" />
				<button type="submit" class="button"><?php esc_html_e( 'Or sync from the bundled starter spreadsheet', 'paccc-member-directory' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'admin_notices', 'paccc_ceu_import_panel' );

/**
 * Handle the sync form submission (uploaded file or bundled starter file).
 */
function paccc_ceu_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'paccc_ceu_import', 'paccc_ceu_import_nonce' );

	$redirect = add_query_arg( 'post_type', PACCC_CEU_CPT, admin_url( 'edit.php' ) );

	// Determine the source file.
	$path        = '';
	$use_bundled = ! empty( $_POST['paccc_ceu_use_bundled'] );
	if ( $use_bundled ) {
		$path = paccc_ceu_bundled_sheet();
	} elseif ( isset( $_FILES['paccc_ceu_file'] ) && empty( $_FILES['paccc_ceu_file']['error'] ) ) {
		$name = isset( $_FILES['paccc_ceu_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['paccc_ceu_file']['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'xlsx' !== $ext ) {
			wp_safe_redirect( add_query_arg( 'paccc_ceu_error', rawurlencode( 'Please upload an .xlsx file.' ), $redirect ) );
			exit;
		}
		$path = isset( $_FILES['paccc_ceu_file']['tmp_name'] ) ? $_FILES['paccc_ceu_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	if ( ! $path || ! file_exists( $path ) ) {
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
