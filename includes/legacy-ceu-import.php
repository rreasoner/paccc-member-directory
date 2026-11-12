<?php
/**
 * PACCC Suite -- import CEUs from the old site (PACCC Suite -> Import Old CEUs).
 *
 * Inputs, both exported from the old site's wpDataTables:
 *   - My CEUs CSV: CEU Provider, wdt_ID, User, CEU Title, CEU Code, Hours, Date
 *     (User = the old site's WordPress user ID).
 *   - Users CSV: user_id, user_login, user_email, display_name, first_name,
 *     last_name, user_registered.
 *   - Optional match-overrides CSV: old_user_id, member_post_id (0 = skip).
 *
 * Each old user is matched to a member profile on this site:
 *   1. by email -- the member's email or its linked login's email;
 *   2. otherwise by first + last name, but only to a member with NO email on
 *      file (a name match to a member whose email differs is listed for review
 *      and not imported);
 *   3. an override row always wins.
 *
 * The import only ever ADDS records to a member (see includes/legacy-ceus.php).
 * Records are keyed by the old wdt_ID, so re-running never duplicates, and
 * nothing already on a member -- old-site records or anything else -- is
 * changed. Values are stored as entered (trimmed); spelling and dates are not
 * corrected.
 *
 * Rows that are skipped: completely empty rows, rows whose User isn't a user
 * ID, rows whose old account no longer exists (not in the users CSV), obvious
 * test rows (title or code "test", "example", "ABCDEF", "dgdgd"), and rows with
 * neither a title nor a code (nothing to show).
 */

defined( 'ABSPATH' ) || exit;

function paccc_md_legacy_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=' . PACCC_MD_CPT,
		'Import Old CEUs',
		'Import Old CEUs',
		'manage_options',
		'paccc-legacy-ceus',
		'paccc_md_render_legacy_import'
	);
}
add_action( 'admin_menu', 'paccc_md_legacy_admin_menu' );

function paccc_md_legacy_page_url( $args = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'post_type' => PACCC_MD_CPT,
				'page'      => 'paccc-legacy-ceus',
			),
			$args
		),
		admin_url( 'edit.php' )
	);
}

function paccc_md_legacy_report_key() {
	return 'paccc_legacy_ceu_report_' . get_current_user_id();
}

/* ---------------------------------------------------------------------------
 * Parsing + matching helpers
 * ------------------------------------------------------------------------ */

/**
 * Read a CSV into rows of header => value. Headers are lower-cased and trimmed
 * (a UTF-8 BOM is dropped). Returns WP_Error if the file can't be read.
 */
function paccc_md_legacy_read_csv( $path ) {
	$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'paccc_csv', 'A CSV file could not be read.' );
	}
	$header = fgetcsv( $fh, 0, ',', '"', '' );
	if ( ! $header ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'paccc_csv', 'A CSV file is empty.' );
	}
	foreach ( $header as $i => $h ) {
		$h            = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h );
		$header[ $i ] = strtolower( trim( $h ) );
	}
	$rows = array();
	while ( false !== ( $line = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
		if ( array( null ) === $line ) {
			continue; // blank line
		}
		$row = array();
		foreach ( $header as $i => $h ) {
			$row[ $h ] = isset( $line[ $i ] ) ? (string) $line[ $i ] : '';
		}
		$rows[] = $row;
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return array(
		'header' => $header,
		'rows'   => $rows,
	);
}

/** "Karen (Kay)  Mizell" -> "karen kay mizell". */
function paccc_md_legacy_norm_name( $s ) {
	$s = strtolower( remove_accents( (string) $s ) );
	$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
	return trim( $s );
}

function paccc_md_legacy_norm_email( $s ) {
	return strtolower( trim( (string) $s ) );
}

/** Obvious test rows from the old site's setup. */
function paccc_md_legacy_is_test( $title, $code ) {
	$re = '/^(test|example\s*\d*|abcdef|dgdgd)$/i';
	return (bool) ( preg_match( $re, trim( $title ) ) || preg_match( $re, trim( $code ) ) );
}

/**
 * Every member profile (except Trash) with the fields used for matching, plus
 * email/name lookups and an index of old-site row ids already imported.
 */
function paccc_md_legacy_member_index() {
	$posts = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);

	$members = array();
	$emails  = array();
	$names   = array();
	$wdt     = array();

	foreach ( $posts as $p ) {
		$member_email = (string) get_post_meta( $p->ID, 'paccc_email', true );
		$member_name  = (string) get_post_meta( $p->ID, 'paccc_member_name', true );
		$uid          = (int) get_post_meta( $p->ID, 'paccc_user_id', true );
		$user         = $uid ? get_userdata( $uid ) : false;
		$user_email   = $user ? (string) $user->user_email : '';
		$user_name    = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';

		$members[ $p->ID ] = array(
			'id'           => $p->ID,
			'business'     => $p->post_title,
			'member_name'  => $member_name,
			'member_email' => $member_email,
			'user_email'   => $user_email,
			'has_email'    => ( '' !== trim( $member_email ) || '' !== trim( $user_email ) ),
			'tokens'       => array_unique( array_filter( explode( ' ', paccc_md_legacy_norm_name( $member_name . ' ' . $user_name ) ) ) ),
		);

		foreach ( array( $member_email, $user_email ) as $e ) {
			$e = paccc_md_legacy_norm_email( $e );
			if ( '' !== $e ) {
				$emails[ $e ][ $p->ID ] = true;
			}
		}
		foreach ( array( $member_name, $user_name ) as $n ) {
			$n = paccc_md_legacy_norm_name( $n );
			if ( '' !== $n ) {
				$names[ $n ][ $p->ID ] = true;
			}
		}

		$existing = get_post_meta( $p->ID, PACCC_MD_LEGACY_CEU_META, true );
		if ( is_array( $existing ) ) {
			foreach ( array_keys( $existing ) as $k ) {
				$wdt[ (int) $k ] = $p->ID;
			}
		}
	}

	return array(
		'members' => $members,
		'emails'  => $emails,
		'names'   => $names,
		'wdt'     => $wdt,
	);
}

/**
 * Match one old user to a member profile.
 *
 * @return array status (matched|review|ambiguous|unmatched|skipped|override_invalid),
 *               method, post_id, suggestions (post ids).
 */
function paccc_md_legacy_match( $old, $index, $overrides ) {
	$members = $index['members'];
	$result  = array(
		'status'      => 'unmatched',
		'method'      => '',
		'post_id'     => 0,
		'suggestions' => array(),
	);

	if ( isset( $overrides[ $old['id'] ] ) ) {
		$pid = (int) $overrides[ $old['id'] ];
		if ( 0 === $pid ) {
			$result['status'] = 'skipped';
			$result['method'] = 'override (0 = skip)';
		} elseif ( isset( $members[ $pid ] ) ) {
			$result['status']  = 'matched';
			$result['method']  = 'override';
			$result['post_id'] = $pid;
		} else {
			$result['status'] = 'override_invalid';
			$result['method'] = 'override: member #' . $pid . ' not found';
		}
		return $result;
	}

	$name = paccc_md_legacy_norm_name( $old['first'] . ' ' . $old['last'] );

	// 1. Email.
	$email = paccc_md_legacy_norm_email( $old['email'] );
	if ( '' !== $email && ! empty( $index['emails'][ $email ] ) ) {
		$cands = array_keys( $index['emails'][ $email ] );
		if ( 1 === count( $cands ) ) {
			$result['status']  = 'matched';
			$result['method']  = 'email';
			$result['post_id'] = $cands[0];
			return $result;
		}
		// Several members share the email: let the name decide.
		$by_name = ( '' !== $name && ! empty( $index['names'][ $name ] ) ) ? array_values( array_intersect( $cands, array_keys( $index['names'][ $name ] ) ) ) : array();
		if ( 1 === count( $by_name ) ) {
			$result['status']  = 'matched';
			$result['method']  = 'email + name';
			$result['post_id'] = $by_name[0];
			return $result;
		}
		$result['status']      = 'ambiguous';
		$result['method']      = 'email shared by several members';
		$result['suggestions'] = $cands;
		return $result;
	}

	if ( '' === $name ) {
		$result['method'] = 'no email match and no name on the old account';
		return $result;
	}

	// 2. Exact name, only to members with no email on file.
	if ( ! empty( $index['names'][ $name ] ) ) {
		$cands    = array_keys( $index['names'][ $name ] );
		$no_email = array_values(
			array_filter(
				$cands,
				static function ( $pid ) use ( $members ) {
					return ! $members[ $pid ]['has_email'];
				}
			)
		);
		if ( 1 === count( $no_email ) ) {
			$result['status']  = 'matched';
			$result['method']  = 'name (member has no email)';
			$result['post_id'] = $no_email[0];
			return $result;
		}
		if ( count( $no_email ) > 1 ) {
			$result['status']      = 'ambiguous';
			$result['method']      = 'name matches several members';
			$result['suggestions'] = $no_email;
			return $result;
		}
		$result['status']      = 'review';
		$result['method']      = 'name matches, but that member has a different email';
		$result['suggestions'] = $cands;
		return $result;
	}

	// 3. Partial name (first + last both appear in the member's name): review only.
	$parts = explode( ' ', $name );
	$first = paccc_md_legacy_norm_name( $old['first'] );
	$last  = paccc_md_legacy_norm_name( $old['last'] );
	$f     = '' !== $first ? strtok( $first, ' ' ) : '';
	$l     = '' !== $last ? substr( $last, strrpos( ' ' . $last, ' ' ) ) : '';
	if ( '' !== $f && '' !== $l && count( $parts ) > 1 ) {
		$cands = array();
		foreach ( $members as $pid => $m ) {
			if ( in_array( $f, $m['tokens'], true ) && in_array( $l, $m['tokens'], true ) ) {
				$cands[] = $pid;
			}
		}
		if ( $cands ) {
			$result['status']      = 'review';
			$result['method']      = 'partial name match';
			$result['suggestions'] = $cands;
			return $result;
		}
	}

	$result['method'] = 'no email or name match';
	return $result;
}

/* ---------------------------------------------------------------------------
 * The run (preview or import)
 * ------------------------------------------------------------------------ */

/**
 * @param bool $write False = preview only.
 * @return array|WP_Error Report.
 */
function paccc_md_legacy_run( $ceus_path, $users_path, $over_path, $write ) {
	$ceus = paccc_md_legacy_read_csv( $ceus_path );
	if ( is_wp_error( $ceus ) ) {
		return $ceus;
	}
	$users = paccc_md_legacy_read_csv( $users_path );
	if ( is_wp_error( $users ) ) {
		return $users;
	}
	foreach ( array( 'wdt_id', 'user', 'ceu title', 'ceu code' ) as $col ) {
		if ( ! in_array( $col, $ceus['header'], true ) ) {
			return new WP_Error( 'paccc_csv', 'The CEUs CSV is missing the "' . $col . '" column. Expected: CEU Provider, wdt_ID, User, CEU Title, CEU Code, Hours, Date.' );
		}
	}
	foreach ( array( 'user_id', 'user_email' ) as $col ) {
		if ( ! in_array( $col, $users['header'], true ) ) {
			return new WP_Error( 'paccc_csv', 'The users CSV is missing the "' . $col . '" column. Expected: user_id, user_login, user_email, display_name, first_name, last_name.' );
		}
	}

	$overrides = array();
	if ( $over_path ) {
		$over = paccc_md_legacy_read_csv( $over_path );
		if ( is_wp_error( $over ) ) {
			return $over;
		}
		if ( ! in_array( 'old_user_id', $over['header'], true ) || ! in_array( 'member_post_id', $over['header'], true ) ) {
			return new WP_Error( 'paccc_csv', 'The match-overrides CSV needs the columns old_user_id and member_post_id.' );
		}
		foreach ( $over['rows'] as $r ) {
			$oid = trim( $r['old_user_id'] );
			$pid = trim( $r['member_post_id'] );
			if ( ctype_digit( $oid ) && '' !== $pid && ctype_digit( $pid ) ) {
				$overrides[ $oid ] = (int) $pid;
			}
		}
	}

	$old_users = array();
	foreach ( $users['rows'] as $u ) {
		$id = trim( $u['user_id'] );
		if ( ! ctype_digit( $id ) ) {
			continue;
		}
		$old_users[ $id ] = array(
			'id'    => $id,
			'email' => isset( $u['user_email'] ) ? trim( $u['user_email'] ) : '',
			'first' => isset( $u['first_name'] ) ? trim( $u['first_name'] ) : '',
			'last'  => isset( $u['last_name'] ) ? trim( $u['last_name'] ) : '',
			'login' => isset( $u['user_login'] ) ? trim( $u['user_login'] ) : '',
		);
	}

	$get = static function ( $row, $key ) {
		return isset( $row[ $key ] ) ? trim( $row[ $key ] ) : '';
	};

	// Sort rows into skips and per-owner groups.
	$skips    = array(
		'empty'           => 0,
		'bad_owner'       => 0,
		'deleted_account' => 0,
		'test'            => 0,
		'no_name'         => 0,
		'bad_row'         => 0,
	);
	$by_owner = array();
	$deleted  = array();
	foreach ( $ceus['rows'] as $row ) {
		$wdt   = $get( $row, 'wdt_id' );
		$owner = $get( $row, 'user' );
		$title = $get( $row, 'ceu title' );
		$code  = $get( $row, 'ceu code' );
		$prov  = $get( $row, 'ceu provider' );
		$hours = $get( $row, 'hours' );
		$date  = $get( $row, 'date' );

		if ( ! ctype_digit( $wdt ) ) {
			$skips['bad_row']++;
		} elseif ( '' === $title . $code . $prov . $hours . $date ) {
			$skips['empty']++;
		} elseif ( ! ctype_digit( $owner ) ) {
			$skips['bad_owner']++;
		} elseif ( ! isset( $old_users[ $owner ] ) ) {
			$skips['deleted_account']++;
			$deleted[ $owner ] = isset( $deleted[ $owner ] ) ? $deleted[ $owner ] + 1 : 1;
		} elseif ( paccc_md_legacy_is_test( $title, $code ) ) {
			$skips['test']++;
		} elseif ( '' === $title && '' === $code ) {
			$skips['no_name']++;
		} else {
			$by_owner[ $owner ][] = array(
				'wdt_id'      => (int) $wdt,
				'title'       => sanitize_text_field( $title ),
				'provider'    => sanitize_text_field( $prov ),
				'code'        => sanitize_text_field( $code ),
				'hours'       => sanitize_text_field( $hours ),
				'date'        => sanitize_text_field( $date ),
				'old_user_id' => (int) $owner,
			);
		}
	}

	$index   = paccc_md_legacy_member_index();
	$members = $index['members'];
	$people  = array();
	$adds    = array(); // post_id => [ wdt_id => record ]
	$totals  = array(
		'rows_in_file'       => count( $ceus['rows'] ),
		'rows_to_add'        => 0,
		'already_present'    => 0,
		'imported_elsewhere' => 0,
		'not_matched_rows'   => 0,
	);

	ksort( $by_owner, SORT_NUMERIC );
	foreach ( $by_owner as $owner => $rows ) {
		$old   = $old_users[ $owner ];
		$match = paccc_md_legacy_match( $old, $index, $overrides );

		$person = array(
			'old_user_id' => $owner,
			'old_name'    => trim( $old['first'] . ' ' . $old['last'] ),
			'old_email'   => $old['email'],
			'rows'        => count( $rows ),
			'status'      => $match['status'],
			'method'      => $match['method'],
			'post_id'     => $match['post_id'],
			'suggestions' => $match['suggestions'],
			'add'         => 0,
			'already'     => 0,
			'elsewhere'   => 0,
		);

		if ( 'matched' === $match['status'] ) {
			$pid = $match['post_id'];
			foreach ( $rows as $rec ) {
				$w = $rec['wdt_id'];
				if ( isset( $index['wdt'][ $w ] ) ) {
					if ( $index['wdt'][ $w ] === $pid ) {
						$person['already']++;
						$totals['already_present']++;
					} else {
						$person['elsewhere']++;
						$totals['imported_elsewhere']++;
					}
					continue;
				}
				$rec['imported']        = gmdate( 'Y-m-d H:i:s' );
				$adds[ $pid ][ $w ]     = $rec;
				$index['wdt'][ $w ]     = $pid;
				$person['add']++;
				$totals['rows_to_add']++;
			}
		} else {
			$totals['not_matched_rows'] += count( $rows );
		}
		$people[] = $person;
	}

	if ( $write && $adds ) {
		foreach ( $adds as $pid => $recs ) {
			$existing = get_post_meta( $pid, PACCC_MD_LEGACY_CEU_META, true );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}
			// Add only; never replace a record already on the member.
			foreach ( $recs as $w => $rec ) {
				if ( ! isset( $existing[ $w ] ) ) {
					$existing[ $w ] = $rec;
				}
			}
			update_post_meta( $pid, PACCC_MD_LEGACY_CEU_META, $existing );
		}
		paccc_md_flush_directory_cache();
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket: member pages now show more CEUs
		}
	}

	// Member labels for the report.
	$label = static function ( $pid ) use ( $members ) {
		if ( ! isset( $members[ $pid ] ) ) {
			return '#' . $pid;
		}
		$m = $members[ $pid ];
		$n = '' !== trim( $m['member_name'] ) ? $m['member_name'] : $m['business'];
		return $n . ( '' !== trim( $m['member_name'] ) && $m['business'] ? ' (' . $m['business'] . ')' : '' ) . ' #' . $pid;
	};
	foreach ( $people as &$person ) {
		$person['member_label']      = $person['post_id'] ? $label( $person['post_id'] ) : '';
		$person['suggestion_labels'] = array_map( $label, $person['suggestions'] );
	}
	unset( $person );

	ksort( $deleted, SORT_NUMERIC );

	return array(
		'mode'            => $write ? 'import' : 'preview',
		'time'            => current_time( 'mysql' ),
		'overrides'       => count( $overrides ),
		'skips'           => $skips,
		'totals'          => $totals,
		'members_touched' => count( $adds ),
		'people'          => $people,
		'deleted'         => $deleted,
	);
}

/* ---------------------------------------------------------------------------
 * Handlers
 * ------------------------------------------------------------------------ */

/** Path of an uploaded CSV field, or '' if none was sent. */
function paccc_md_legacy_upload_path( $field ) {
	if ( empty( $_FILES[ $field ] ) || ! isset( $_FILES[ $field ]['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
		return '';
	}
	$name = isset( $_FILES[ $field ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ $field ]['name'] ) ) : '';
	if ( ! preg_match( '/\.csv$/i', $name ) ) {
		return '';
	}
	$tmp = isset( $_FILES[ $field ]['tmp_name'] ) ? $_FILES[ $field ]['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return ( $tmp && is_uploaded_file( $tmp ) ) ? $tmp : '';
}

function paccc_md_handle_legacy_run() {
	check_admin_referer( 'paccc_md_legacy_ceu_run' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$write = isset( $_POST['mode'] ) && 'import' === $_POST['mode'];
	$ceus  = paccc_md_legacy_upload_path( 'paccc_legacy_ceus_csv' );
	$users = paccc_md_legacy_upload_path( 'paccc_legacy_users_csv' );
	$over  = paccc_md_legacy_upload_path( 'paccc_legacy_overrides_csv' );

	if ( ! $ceus || ! $users ) {
		wp_safe_redirect( paccc_md_legacy_page_url( array( 'paccc_legacy_error' => rawurlencode( 'Please choose both the CEUs CSV and the users CSV (.csv files).' ) ) ) );
		exit;
	}

	$report = paccc_md_legacy_run( $ceus, $users, $over, $write );
	if ( is_wp_error( $report ) ) {
		wp_safe_redirect( paccc_md_legacy_page_url( array( 'paccc_legacy_error' => rawurlencode( $report->get_error_message() ) ) ) );
		exit;
	}

	set_transient( paccc_md_legacy_report_key(), $report, DAY_IN_SECONDS );
	wp_safe_redirect( paccc_md_legacy_page_url( array( 'paccc_legacy_done' => 1 ) ) );
	exit;
}
add_action( 'admin_post_paccc_md_legacy_ceu_run', 'paccc_md_handle_legacy_run' );

/** Download the last report as CSV (one line per old member). */
function paccc_md_handle_legacy_report_csv() {
	check_admin_referer( 'paccc_md_legacy_report_csv' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	$report = get_transient( paccc_md_legacy_report_key() );
	if ( ! is_array( $report ) ) {
		wp_die( 'No report found. Run a preview first.' );
	}

	$out = paccc_md_csv_open( 'paccc-old-ceu-' . $report['mode'] . '-report' );
	paccc_md_csv_row(
		$out,
		array( 'old_user_id', 'old_name', 'old_email', 'ceu_rows', 'status', 'how_matched', 'member_post_id', 'member', 'suggested_member_post_ids', 'suggested_members', 'ceus_added_or_to_add', 'already_on_member', 'already_on_another_member' )
	);
	foreach ( $report['people'] as $p ) {
		paccc_md_csv_row(
			$out,
			array(
				$p['old_user_id'],
				$p['old_name'],
				$p['old_email'],
				$p['rows'],
				$p['status'],
				$p['method'],
				$p['post_id'] ? $p['post_id'] : '',
				$p['member_label'],
				implode( ' ', $p['suggestions'] ),
				$p['suggestion_labels'],
				$p['add'],
				$p['already'],
				$p['elsewhere'],
			)
		);
	}
	foreach ( $report['deleted'] as $oid => $n ) {
		paccc_md_csv_row( $out, array( $oid, '', '', $n, 'deleted_account', 'account no longer exists on the old site; not imported', '', '', '', '', 0, 0, 0 ) );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_paccc_md_legacy_report_csv', 'paccc_md_handle_legacy_report_csv' );

/* ---------------------------------------------------------------------------
 * Screen
 * ------------------------------------------------------------------------ */

function paccc_md_render_legacy_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	$report = get_transient( paccc_md_legacy_report_key() );
	$status_labels = array(
		'matched'          => 'Matched',
		'review'           => 'Needs review',
		'ambiguous'        => 'Ambiguous',
		'unmatched'        => 'No match',
		'skipped'          => 'Skipped (override)',
		'override_invalid' => 'Bad override',
	);
	?>
	<div class="wrap">
		<h1>Import CEUs from the old site</h1>

		<?php if ( isset( $_GET['paccc_legacy_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['paccc_legacy_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification ?></p></div>
		<?php endif; ?>

		<p>
			Adds the CEUs members logged on the old site to their member profiles here. It only <strong>adds</strong>:
			nothing already on a member is changed, and each old record is added once, so running it again is safe.
			Records are kept exactly as entered; only the CEU name (or its code, if the name was blank) is shown on the directory and member pages.
		</p>
		<ol>
			<li>Choose the old site&rsquo;s <strong>My CEUs</strong> CSV and <strong>users</strong> CSV and click <strong>Preview</strong>. Nothing is written.</li>
			<li>Check the people marked <em>Needs review</em>, <em>Ambiguous</em> or <em>No match</em>. To assign one, add a line to a match-overrides CSV with the columns <code>old_user_id,member_post_id</code> (use <code>0</code> to skip someone), and preview again with it.</li>
			<li>Click <strong>Import</strong> with the same files.</li>
		</ol>
		<p class="description">Matching: by email first (the member&rsquo;s email or its login&rsquo;s email); otherwise by first + last name, but only to a member with no email on file. An override always wins.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin:16px 0;">
			<input type="hidden" name="action" value="paccc_md_legacy_ceu_run" />
			<?php wp_nonce_field( 'paccc_md_legacy_ceu_run' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="paccc_legacy_ceus_csv">My CEUs CSV</label></th>
					<td><input type="file" id="paccc_legacy_ceus_csv" name="paccc_legacy_ceus_csv" accept=".csv" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="paccc_legacy_users_csv">Users CSV</label></th>
					<td><input type="file" id="paccc_legacy_users_csv" name="paccc_legacy_users_csv" accept=".csv" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="paccc_legacy_overrides_csv">Match overrides CSV <span class="description">(optional)</span></label></th>
					<td><input type="file" id="paccc_legacy_overrides_csv" name="paccc_legacy_overrides_csv" accept=".csv" /></td>
				</tr>
			</table>
			<p>
				<button type="submit" name="mode" value="preview" class="button button-primary">Preview (no changes)</button>
				<button type="submit" name="mode" value="import" class="button" onclick="return confirm('Add the matched old-site CEUs to member profiles now?');">Import</button>
			</p>
		</form>

		<?php if ( is_array( $report ) ) : ?>
			<?php
			$t       = $report['totals'];
			$s       = $report['skips'];
			$counts  = array_count_values( wp_list_pluck( $report['people'], 'status' ) );
			$is_prev = ( 'preview' === $report['mode'] );
			?>
			<hr />
			<h2><?php echo $is_prev ? 'Last preview' : 'Last import'; ?> <span style="font-weight:normal;font-size:13px;">(<?php echo esc_html( $report['time'] ); ?><?php echo $report['overrides'] ? esc_html( ', ' . $report['overrides'] . ' overrides' ) : ''; ?>)</span></h2>
			<div class="notice <?php echo $is_prev ? 'notice-info' : 'notice-success'; ?> inline">
				<p><strong>
					<?php
					echo esc_html(
						sprintf(
							'%s %d CEUs to %d members.',
							$is_prev ? 'Would add' : 'Added',
							$t['rows_to_add'],
							$report['members_touched']
						)
					);
					?>
				</strong>
				<?php echo esc_html( sprintf( '%d already on the member', $t['already_present'] ) ); ?>,
				<?php echo esc_html( sprintf( '%d already imported to a different member (left there)', $t['imported_elsewhere'] ) ); ?>,
				<?php echo esc_html( sprintf( '%d in people not matched yet.', $t['not_matched_rows'] ) ); ?></p>
				<p>
					People: <?php echo esc_html( sprintf( '%d matched, %d need review, %d ambiguous, %d no match, %d skipped', isset( $counts['matched'] ) ? $counts['matched'] : 0, isset( $counts['review'] ) ? $counts['review'] : 0, isset( $counts['ambiguous'] ) ? $counts['ambiguous'] : 0, isset( $counts['unmatched'] ) ? $counts['unmatched'] : 0, ( isset( $counts['skipped'] ) ? $counts['skipped'] : 0 ) + ( isset( $counts['override_invalid'] ) ? $counts['override_invalid'] : 0 ) ) ); ?>.
				</p>
				<p>
					Rows not imported: <?php echo esc_html( sprintf( '%d empty, %d from accounts deleted on the old site, %d test rows, %d with no name or code, %d with no user ID', $s['empty'], $s['deleted_account'], $s['test'], $s['no_name'], $s['bad_owner'] + $s['bad_row'] ) ); ?> (of <?php echo esc_html( $t['rows_in_file'] ); ?> in the file).
				</p>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'paccc_md_legacy_report_csv', admin_url( 'admin-post.php' ) ), 'paccc_md_legacy_report_csv' ) ); ?>">Download report (CSV)</a></p>
			</div>

			<?php
			// Problems first, then matches.
			$order  = array( 'override_invalid' => 0, 'ambiguous' => 1, 'review' => 2, 'unmatched' => 3, 'skipped' => 4, 'matched' => 5 );
			$people = $report['people'];
			usort(
				$people,
				static function ( $a, $b ) use ( $order ) {
					return array( $order[ $a['status'] ], (int) $a['old_user_id'] ) <=> array( $order[ $b['status'] ], (int) $b['old_user_id'] );
				}
			);
			?>
			<table class="widefat striped" style="margin-top:12px;">
				<thead>
					<tr>
						<th>Old user</th><th>Old name / email</th><th>CEUs</th><th>Status</th><th>How</th><th>Member on this site</th><th><?php echo $is_prev ? 'To add' : 'Added'; ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $people as $p ) : ?>
						<tr>
							<td><?php echo esc_html( $p['old_user_id'] ); ?></td>
							<td><?php echo esc_html( $p['old_name'] ); ?><br /><span class="description"><?php echo esc_html( $p['old_email'] ); ?></span></td>
							<td><?php echo esc_html( $p['rows'] ); ?></td>
							<td><strong><?php echo esc_html( isset( $status_labels[ $p['status'] ] ) ? $status_labels[ $p['status'] ] : $p['status'] ); ?></strong></td>
							<td><?php echo esc_html( $p['method'] ); ?></td>
							<td>
								<?php if ( $p['post_id'] ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $p['post_id'] ) ); ?>"><?php echo esc_html( $p['member_label'] ); ?></a>
								<?php elseif ( $p['suggestions'] ) : ?>
									Possible:
									<?php foreach ( $p['suggestions'] as $i => $sid ) : ?>
										<br /><a href="<?php echo esc_url( get_edit_post_link( $sid ) ); ?>"><?php echo esc_html( $p['suggestion_labels'][ $i ] ); ?></a>
									<?php endforeach; ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $p['add'] ); ?>
								<?php if ( $p['already'] || $p['elsewhere'] ) : ?>
									<span class="description"><?php echo esc_html( sprintf( '(%d already there%s)', $p['already'], $p['elsewhere'] ? ', ' . $p['elsewhere'] . ' on another member' : '' ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
