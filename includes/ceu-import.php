<?php
/**
 * One-click importer that seeds the Approved CEU Courses catalog from the
 * data/ceu_seed.json file (scraped from paccert.org/ceu-approved/). Runs from
 * a panel on the "All CEUs" list screen. Safe to re-run: entries already
 * imported are matched by their source id and skipped.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import courses from the bundled seed file.
 *
 * @return array|WP_Error [ 'created' => int, 'skipped' => int ]
 */
function paccc_ceu_import_from_seed() {
	$file = PACCC_MD_DIR . 'data/ceu_seed.json';
	if ( ! file_exists( $file ) ) {
		return new WP_Error( 'no_file', 'Seed file not found.' );
	}

	$rows = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $rows ) ) {
		return new WP_Error( 'bad_json', 'Seed file could not be read.' );
	}

	$created = 0;
	$skipped = 0;
	$terms   = array(); // provider name => term_id cache

	foreach ( $rows as $r ) {
		$course = isset( $r['course'] ) ? trim( (string) $r['course'] ) : '';
		if ( '' === $course ) {
			continue;
		}

		$src = isset( $r['src_id'] ) ? (string) $r['src_id'] : '';
		if ( $src ) {
			$dupe = get_posts(
				array(
					'post_type'   => PACCC_CEU_CPT,
					'post_status' => 'any',
					'numberposts' => 1,
					'meta_key'    => '_paccc_ceu_src_id',
					'meta_value'  => $src,
					'fields'      => 'ids',
				)
			);
			if ( $dupe ) {
				$skipped++;
				continue;
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => PACCC_CEU_CPT,
				'post_status'  => 'publish',
				'post_title'   => wp_slash( $course ),
				'post_content' => wp_slash( isset( $r['biography'] ) ? (string) $r['biography'] : '' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		update_post_meta( $post_id, 'paccc_ceu_presenter', sanitize_text_field( isset( $r['presenter'] ) ? $r['presenter'] : '' ) );
		update_post_meta( $post_id, 'paccc_ceu_website', esc_url_raw( isset( $r['website'] ) ? $r['website'] : '' ) );
		update_post_meta( $post_id, 'paccc_ceu_amount', sanitize_text_field( isset( $r['ceu_amount'] ) ? $r['ceu_amount'] : '' ) );
		if ( ! empty( $r['photo'] ) ) {
			update_post_meta( $post_id, 'paccc_ceu_photo_url', esc_url_raw( $r['photo'] ) );
		}
		if ( $src ) {
			update_post_meta( $post_id, '_paccc_ceu_src_id', $src );
		}

		$provider = isset( $r['provider'] ) ? trim( (string) $r['provider'] ) : '';
		if ( '' !== $provider ) {
			if ( ! isset( $terms[ $provider ] ) ) {
				$existing = term_exists( $provider, PACCC_CEU_TAX );
				if ( $existing && ! is_wp_error( $existing ) ) {
					$terms[ $provider ] = (int) $existing['term_id'];
				} else {
					$new = wp_insert_term( $provider, PACCC_CEU_TAX );
					$terms[ $provider ] = is_wp_error( $new ) ? 0 : (int) $new['term_id'];
				}
			}
			if ( ! empty( $terms[ $provider ] ) ) {
				wp_set_object_terms( $post_id, $terms[ $provider ], PACCC_CEU_TAX, false );
			}
		}

		$created++;
	}

	return array(
		'created' => $created,
		'skipped' => $skipped,
	);
}

/**
 * Import panel at the top of the All CEUs list screen.
 */
function paccc_ceu_import_panel() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-' . PACCC_CEU_CPT !== $screen->id ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$seed_file  = PACCC_MD_DIR . 'data/ceu_seed.json';
	$seed_count = 0;
	if ( file_exists( $seed_file ) ) {
		$rows       = json_decode( file_get_contents( $seed_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$seed_count = is_array( $rows ) ? count( $rows ) : 0;
	}
	if ( ! $seed_count ) {
		return;
	}

	// Result notice after an import run.
	if ( isset( $_GET['paccc_ceu_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$c = (int) $_GET['paccc_ceu_imported']; // phpcs:ignore WordPress.Security.NonceVerification
		$s = isset( $_GET['paccc_ceu_skipped'] ) ? (int) $_GET['paccc_ceu_skipped'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( 'Imported %d CEU course(s); %d already present were skipped.', $c, $s ) )
		);
	}

	?>
	<div class="notice notice-info paccc-ceu-import-panel">
		<p style="font-size:14px;">
			<strong><?php esc_html_e( 'Import approved CEUs', 'paccc-member-directory' ); ?></strong> —
			<?php
			printf(
				/* translators: %d: number of courses in the seed file */
				esc_html__( 'seed data from paccert.org/ceu-approved/ contains %d courses. Already-imported courses are skipped, so this is safe to run more than once.', 'paccc-member-directory' ),
				(int) $seed_count
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
			<?php wp_nonce_field( 'paccc_ceu_import', 'paccc_ceu_import_nonce' ); ?>
			<input type="hidden" name="action" value="paccc_ceu_import" />
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Import CEUs now', 'paccc-member-directory' ); ?></button>
		</form>
	</div>
	<?php
}
add_action( 'admin_notices', 'paccc_ceu_import_panel' );

/**
 * Handle the import form submission.
 */
function paccc_ceu_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'paccc_ceu_import', 'paccc_ceu_import_nonce' );

	$result = paccc_ceu_import_from_seed();

	$args = array( 'post_type' => PACCC_CEU_CPT );
	if ( is_wp_error( $result ) ) {
		$args['paccc_ceu_error'] = 1;
	} else {
		$args['paccc_ceu_imported'] = $result['created'];
		$args['paccc_ceu_skipped']  = $result['skipped'];
	}

	wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
	exit;
}
add_action( 'admin_post_paccc_ceu_import', 'paccc_ceu_handle_import' );
