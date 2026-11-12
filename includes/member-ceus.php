<?php
/**
 * PACCC Suite -- a member's CEU(s): one list, shown everywhere as one table.
 *
 * A member's CEUs come from two places but are always presented together:
 *   - picked from the approved CEU catalog ("+ Add a CEU" on the member edit
 *     screen), stored as post meta 'paccc_member_ceu_ids' (catalog post ID =>
 *     program name when added; the live title is used while the program
 *     exists, so renames carry through, and the stored name if it's deleted);
 *   - imported from the member's earlier records (includes/legacy-ceus.php),
 *     kept exactly as entered.
 * (Names saved by the retired free-text checklist, 'paccc_member_ceus', are
 * listed too.)
 *
 * Visibility: catalog CEUs are public (member page, [paccc_member_field
 * key="ceus"], and the directory with ceus="true"). Imported CEUs are members'
 * own free-text entries -- some are personal notes -- so they appear only in
 * the member's portal and to admins. The admin table shows which is which.
 *
 * Admins can remove any single CEU with its ×. A removed imported record is
 * moved to 'paccc_legacy_ceus_removed' (not lost), and the importer treats it
 * as already handled, so it is never added back.
 */

defined( 'ABSPATH' ) || exit;

define( 'PACCC_MD_MEMBER_CEU_META', 'paccc_member_ceu_ids' );
define( 'PACCC_MD_LEGACY_CEU_REMOVED_META', 'paccc_legacy_ceus_removed' );

/**
 * A member's catalog CEUs, in the order they were added.
 *
 * @return array[] Each: id, name, code, amount, amount_label, provider.
 */
function paccc_md_member_catalog_ceus( $post_id ) {
	$saved = get_post_meta( (int) $post_id, PACCC_MD_MEMBER_CEU_META, true );
	if ( ! is_array( $saved ) ) {
		return array();
	}
	$out = array();
	foreach ( $saved as $id => $stored_name ) {
		$id   = (int) $id;
		$p    = $id ? get_post( $id ) : null;
		$live = ( $p && PACCC_CEU_CPT === $p->post_type );
		$name = $live ? $p->post_title : (string) $stored_name;
		if ( '' === trim( $name ) ) {
			continue;
		}
		$provider = '';
		if ( $live ) {
			$terms = get_the_terms( $id, PACCC_CEU_TAX );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$provider = reset( $terms )->name;
			}
			if ( '' === $provider ) {
				$provider = (string) get_post_meta( $id, 'paccc_ceu_presenter', true );
			}
		}
		$out[] = array(
			'id'           => $id,
			'name'         => $name,
			'code'         => $live ? (string) get_post_meta( $id, 'paccc_ceu_program_code', true ) : '',
			'amount'       => $live ? (string) get_post_meta( $id, 'paccc_ceu_amount', true ) : '',
			'amount_label' => $live && function_exists( 'paccc_ceu_format_amount' ) ? paccc_ceu_format_amount( get_post_meta( $id, 'paccc_ceu_amount', true ) ) : '',
			'provider'     => $provider,
		);
	}
	return $out;
}

/** Names saved by the retired free-text CEU checklist, if any. */
function paccc_md_member_older_ceus( $post_id ) {
	$older = get_post_meta( (int) $post_id, 'paccc_member_ceus', true );
	if ( ! is_array( $older ) ) {
		$older = explode( ',', (string) $older );
	}
	return array_values( array_filter( array_map( 'trim', $older ), 'strlen' ) );
}

/**
 * Every CEU on a member as one list: imported records first (in the order they
 * were originally entered), then any older free-text names, then CEUs picked
 * from the catalog (in the order they were added).
 *
 * @return array[] Each: kind (imported|older|catalog), key, name, provider,
 *                 code, hours, date, public (bool).
 */
function paccc_md_member_ceu_rows( $post_id ) {
	$rows = array();
	foreach ( paccc_md_legacy_ceus( $post_id ) as $r ) {
		$rows[] = array(
			'kind'     => 'imported',
			'key'      => isset( $r['wdt_id'] ) ? (string) $r['wdt_id'] : '',
			'name'     => isset( $r['title'] ) ? (string) $r['title'] : '',
			'provider' => isset( $r['provider'] ) ? (string) $r['provider'] : '',
			'code'     => isset( $r['code'] ) ? (string) $r['code'] : '',
			'hours'    => isset( $r['hours'] ) ? (string) $r['hours'] : '',
			'date'     => isset( $r['date'] ) ? (string) $r['date'] : '',
			'public'   => false,
		);
	}
	foreach ( paccc_md_member_older_ceus( $post_id ) as $name ) {
		$rows[] = array(
			'kind'     => 'older',
			'key'      => $name,
			'name'     => $name,
			'provider' => '',
			'code'     => '',
			'hours'    => '',
			'date'     => '',
			'public'   => true,
		);
	}
	foreach ( paccc_md_member_catalog_ceus( $post_id ) as $c ) {
		$rows[] = array(
			'kind'     => 'catalog',
			'key'      => (string) $c['id'],
			'name'     => $c['name'],
			'provider' => $c['provider'],
			'code'     => $c['code'],
			'hours'    => $c['amount'],
			'date'     => '',
			'public'   => true,
		);
	}
	return $rows;
}

/** Kept for callers from 2.39: the portal table / CSV rows. */
function paccc_md_portal_ceu_rows( $post_id ) {
	return paccc_md_member_ceu_rows( $post_id );
}

/** Just the names of a member's catalog CEUs. */
function paccc_md_member_catalog_ceu_names( $post_id ) {
	return wp_list_pluck( paccc_md_member_catalog_ceus( $post_id ), 'name' );
}

/** "Program name — CC170015 · 4 CEU" -- the picker's label for a program. */
function paccc_md_catalog_ceu_label( $name, $code, $amount_label ) {
	$extra = trim( $code . ( $code && $amount_label ? ' · ' : '' ) . $amount_label );
	return $name . ( '' !== $extra ? ' — ' . $extra : '' );
}

/* ---------------------------------------------------------------------------
 * Member edit screen: the CEU(s) row (called from paccc_md_render_meta_box)
 * ------------------------------------------------------------------------ */

function paccc_md_render_member_ceu_picker( $post ) {
	$rows    = paccc_md_member_ceu_rows( $post->ID );
	$catalog = get_posts(
		array(
			'post_type'   => PACCC_CEU_CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	// Catalog options for the type-to-pick box (labels must be unique).
	$options = array();
	$seen    = array();
	foreach ( $catalog as $c ) {
		$code   = (string) get_post_meta( $c->ID, 'paccc_ceu_program_code', true );
		$amount = (string) get_post_meta( $c->ID, 'paccc_ceu_amount', true );
		$label  = paccc_md_catalog_ceu_label( $c->post_title, $code, paccc_ceu_format_amount( $amount ) );
		if ( isset( $seen[ $label ] ) ) {
			$label .= ' (#' . $c->ID . ')';
		}
		$seen[ $label ] = true;
		$provider       = '';
		$terms          = get_the_terms( $c->ID, PACCC_CEU_TAX );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$provider = reset( $terms )->name;
		}
		if ( '' === $provider ) {
			$provider = (string) get_post_meta( $c->ID, 'paccc_ceu_presenter', true );
		}
		$options[] = array(
			'id'       => $c->ID,
			'label'    => $label,
			'name'     => $c->post_title,
			'provider' => $provider,
			'code'     => $code,
			'amount'   => $amount,
		);
	}

	$imported_shown = array();
	foreach ( $rows as $r ) {
		if ( 'imported' === $r['kind'] ) {
			$imported_shown[] = $r['key'];
		}
	}
	$input_name = array(
		'imported' => 'paccc_member_imported_keep[]',
		'older'    => 'paccc_member_older_keep[]',
		'catalog'  => 'paccc_member_ceu_ids[]',
	);
	?>
	<input type="hidden" name="paccc_md_ceu_picker" value="1" />
	<input type="hidden" name="paccc_md_imported_shown" value="<?php echo esc_attr( implode( ',', $imported_shown ) ); ?>" />

	<div class="paccc-md-ceu-table-wrap">
		<table class="widefat striped paccc-md-ceu-table">
			<thead>
				<tr>
					<th scope="col">CEU</th>
					<th scope="col">Provider</th>
					<th scope="col">Code</th>
					<th scope="col">Hours / CEUs</th>
					<th scope="col">Date</th>
					<th scope="col" title="Shown on the member's public page? Every CEU appears in the member's own portal.">Public page</th>
					<th scope="col"><span class="screen-reader-text">Remove</span></th>
				</tr>
			</thead>
			<tbody id="paccc-md-ceu-picked">
				<?php foreach ( $rows as $r ) : ?>
					<tr class="paccc-md-ceu-pick" data-kind="<?php echo esc_attr( $r['kind'] ); ?>" data-id="<?php echo esc_attr( $r['key'] ); ?>">
						<td class="paccc-md-ceu-pick-name"><?php echo esc_html( '' !== $r['name'] ? $r['name'] : '—' ); ?></td>
						<td><?php echo esc_html( $r['provider'] ); ?></td>
						<td><?php echo esc_html( $r['code'] ); ?></td>
						<td><?php echo esc_html( $r['hours'] ); ?></td>
						<td><?php echo esc_html( $r['date'] ); ?></td>
						<td><?php echo $r['public'] ? 'Yes' : '<span title="Private: shown only in the member’s portal">No</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></td>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $input_name[ $r['kind'] ] ); ?>" value="<?php echo esc_attr( $r['key'] ); ?>" />
							<button type="button" class="paccc-md-ceu-remove" aria-label="<?php echo esc_attr( 'Remove ' . ( '' !== $r['name'] ? $r['name'] : $r['code'] ) ); ?>">&times;</button>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr id="paccc-md-ceu-none" <?php echo $rows ? 'hidden' : ''; ?>>
					<td colspan="7" class="description">No CEUs yet.</td>
				</tr>
			</tbody>
		</table>
	</div>

	<p style="margin-top:8px;">
		<a href="#" id="paccc-md-add-ceu-toggle">+ Add a CEU</a>
	</p>
	<p id="paccc-md-add-ceu-row" hidden>
		<input type="text" id="paccc-md-new-ceu" list="paccc-md-ceu-catalog" autocomplete="off"
			style="width:100%;max-width:640px;"
			placeholder="<?php echo esc_attr( sprintf( 'Start typing a CEU name or program code (%d on the All CEUs page)', count( $options ) ) ); ?>" />
		<button type="button" class="button" id="paccc-md-add-ceu-btn">Add</button>
		<span id="paccc-md-ceu-msg" class="description" aria-live="polite" style="display:block;margin-top:4px;"></span>
	</p>
	<datalist id="paccc-md-ceu-catalog">
		<?php foreach ( $options as $o ) : ?>
			<option value="<?php echo esc_attr( $o['label'] ); ?>" data-id="<?php echo esc_attr( $o['id'] ); ?>" data-name="<?php echo esc_attr( $o['name'] ); ?>" data-provider="<?php echo esc_attr( $o['provider'] ); ?>" data-code="<?php echo esc_attr( $o['code'] ); ?>" data-amount="<?php echo esc_attr( $o['amount'] ); ?>"></option>
		<?php endforeach; ?>
	</datalist>
	<p class="description">
		Add CEUs from the programs on the <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PACCC_CEU_CPT ) ); ?>">All CEUs</a> page; use &times; to remove one from this member. Every CEU shows in the member&rsquo;s own portal; those marked &ldquo;Yes&rdquo; also show on their public page. Changes save when you click Update.
	</p>
	<?php
}

/**
 * Save the member's CEU list. Runs after the main member save (same nonce),
 * and only when the CEU table was on the submitted form.
 */
function paccc_md_save_member_catalog_ceus( $post_id, $post ) {
	if ( PACCC_MD_CPT !== $post->post_type ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['paccc_md_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['paccc_md_nonce'] ) ), 'paccc_md_save_member' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || empty( $_POST['paccc_md_ceu_picker'] ) ) {
		return;
	}

	// 1. Catalog CEUs: exactly the ones on the form, in order.
	$ids = isset( $_POST['paccc_member_ceu_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['paccc_member_ceu_ids'] ) ) : array();
	$old = get_post_meta( $post_id, PACCC_MD_MEMBER_CEU_META, true );
	$old = is_array( $old ) ? $old : array();
	$new = array();
	foreach ( $ids as $id ) {
		if ( ! $id || isset( $new[ $id ] ) ) {
			continue;
		}
		$p = get_post( $id );
		if ( $p && PACCC_CEU_CPT === $p->post_type ) {
			$new[ $id ] = $p->post_title;
		} elseif ( isset( $old[ $id ] ) ) {
			$new[ $id ] = $old[ $id ]; // program deleted since: keep its saved name
		}
	}
	update_post_meta( $post_id, PACCC_MD_MEMBER_CEU_META, $new );

	// 2. Imported CEUs: remove only rows that were on the form and were
	// removed there (anything added since the page loaded is left alone).
	$shown = isset( $_POST['paccc_md_imported_shown'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['paccc_md_imported_shown'] ) ) ) ) ) : array();
	$keep  = isset( $_POST['paccc_member_imported_keep'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['paccc_member_imported_keep'] ) ) : array();
	$drop  = array_diff( $shown, $keep );
	if ( $drop ) {
		$imported = get_post_meta( $post_id, PACCC_MD_LEGACY_CEU_META, true );
		$imported = is_array( $imported ) ? $imported : array();
		$removed  = get_post_meta( $post_id, PACCC_MD_LEGACY_CEU_REMOVED_META, true );
		$removed  = is_array( $removed ) ? $removed : array();
		foreach ( $drop as $w ) {
			if ( isset( $imported[ $w ] ) ) {
				$removed[ $w ]               = $imported[ $w ];
				$removed[ $w ]['removed']    = gmdate( 'Y-m-d H:i:s' );
				$removed[ $w ]['removed_by'] = get_current_user_id();
				unset( $imported[ $w ] );
			}
		}
		if ( $imported ) {
			update_post_meta( $post_id, PACCC_MD_LEGACY_CEU_META, $imported );
		} else {
			delete_post_meta( $post_id, PACCC_MD_LEGACY_CEU_META );
		}
		update_post_meta( $post_id, PACCC_MD_LEGACY_CEU_REMOVED_META, $removed );
	}

	// 3. Older free-text names: keep only those still on the form.
	$older = paccc_md_member_older_ceus( $post_id );
	if ( $older ) {
		$keep_older = isset( $_POST['paccc_member_older_keep'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['paccc_member_older_keep'] ) ) : array();
		update_post_meta( $post_id, 'paccc_member_ceus', array_values( array_intersect( $older, $keep_older ) ) );
	}
}
add_action( 'save_post', 'paccc_md_save_member_catalog_ceus', 11, 2 );

/**
 * A catalog program renamed, trashed or deleted changes the CEU names shown on
 * members, so refresh the cached directory list.
 */
function paccc_md_flush_on_catalog_change( $post_id ) {
	if ( PACCC_CEU_CPT === get_post_type( $post_id ) ) {
		paccc_md_flush_directory_cache();
	}
}
add_action( 'save_post_' . PACCC_CEU_CPT, 'paccc_md_flush_directory_cache' );
add_action( 'trashed_post', 'paccc_md_flush_on_catalog_change' );
add_action( 'untrashed_post', 'paccc_md_flush_on_catalog_change' );
add_action( 'before_delete_post', 'paccc_md_flush_on_catalog_change' );
