<?php
/**
 * PACCC Suite -- a member's CEU(s), chosen from the approved CEU catalog.
 *
 * On the member edit screen, "+ Add a CEU" offers every program on the All CEUs
 * page (type to filter by name or program code). Each member keeps their own
 * list, stored as post meta 'paccc_member_ceu_ids': catalog post ID => the
 * program name when it was added. The live catalog title is shown when the
 * program still exists (so renames carry through); the stored name is the
 * fallback if a program is ever permanently deleted. A program the catalog
 * sync moves to Trash stays on members who completed it.
 *
 * These are the member's PUBLIC CEU(s): they show on the member page, in the
 * portal, via [paccc_member_field key="ceus"], and on the directory with
 * [paccc_directory ceus="true"]. (Old-site CEUs stay private -- see
 * includes/legacy-ceus.php.)
 *
 * This replaces the earlier site-wide free-text CEU checklist (the paccc_ceus
 * option + paccc_member_ceus meta). Any names already saved that way are still
 * shown and kept; they just can't be added to any more.
 */

defined( 'ABSPATH' ) || exit;

define( 'PACCC_MD_MEMBER_CEU_META', 'paccc_member_ceu_ids' );

/**
 * A member's catalog CEUs, in the order they were added.
 *
 * @return array[] Each: id, name, code, amount_label.
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
		$out[] = array(
			'id'           => $id,
			'name'         => $name,
			'code'         => $live ? (string) get_post_meta( $id, 'paccc_ceu_program_code', true ) : '',
			'amount_label' => $live && function_exists( 'paccc_ceu_format_amount' ) ? paccc_ceu_format_amount( get_post_meta( $id, 'paccc_ceu_amount', true ) ) : '',
		);
	}
	return $out;
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
	$picked  = paccc_md_member_catalog_ceus( $post->ID );
	$catalog = get_posts(
		array(
			'post_type'   => PACCC_CEU_CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	// Labels must be unique for the type-to-pick lookup.
	$options = array();
	$seen    = array();
	foreach ( $catalog as $c ) {
		$label = paccc_md_catalog_ceu_label(
			$c->post_title,
			(string) get_post_meta( $c->ID, 'paccc_ceu_program_code', true ),
			paccc_ceu_format_amount( get_post_meta( $c->ID, 'paccc_ceu_amount', true ) )
		);
		if ( isset( $seen[ $label ] ) ) {
			$label .= ' (#' . $c->ID . ')';
		}
		$seen[ $label ] = true;
		$options[]      = array(
			'id'    => $c->ID,
			'label' => $label,
			'name'  => $c->post_title,
		);
	}

	// Names saved by the earlier free-text checklist, if any.
	$older = get_post_meta( $post->ID, 'paccc_member_ceus', true );
	$older = is_array( $older ) ? array_values( array_filter( array_map( 'trim', $older ) ) ) : array();
	?>
	<input type="hidden" name="paccc_md_ceu_picker" value="1" />
	<ul id="paccc-md-ceu-picked" class="paccc-md-ceu-picked">
		<?php foreach ( $picked as $c ) : ?>
			<li class="paccc-md-ceu-pick" data-id="<?php echo esc_attr( $c['id'] ); ?>">
				<span class="paccc-md-ceu-pick-name"><?php echo esc_html( $c['name'] ); ?></span>
				<?php if ( $c['code'] || $c['amount_label'] ) : ?>
					<span class="description"><?php echo esc_html( trim( $c['code'] . ( $c['code'] && $c['amount_label'] ? ' · ' : '' ) . $c['amount_label'] ) ); ?></span>
				<?php endif; ?>
				<input type="hidden" name="paccc_member_ceu_ids[]" value="<?php echo esc_attr( $c['id'] ); ?>" />
				<button type="button" class="paccc-md-ceu-remove" aria-label="<?php echo esc_attr( 'Remove ' . $c['name'] ); ?>">&times;</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<p id="paccc-md-ceu-none" class="description" <?php echo $picked ? 'hidden' : ''; ?>>No CEUs added yet.</p>

	<?php if ( $older ) : ?>
		<p class="description" style="margin-top:8px;">
			<strong>Added before the CEU catalog picker:</strong> <?php echo esc_html( implode( ', ', $older ) ); ?>
		</p>
	<?php endif; ?>

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
			<option value="<?php echo esc_attr( $o['label'] ); ?>" data-id="<?php echo esc_attr( $o['id'] ); ?>" data-name="<?php echo esc_attr( $o['name'] ); ?>"></option>
		<?php endforeach; ?>
	</datalist>
	<p class="description">
		Choose from the programs on the <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PACCC_CEU_CPT ) ); ?>">All CEUs</a> page. These show on the member&rsquo;s public page and in their portal (and on the directory with <code>[paccc_directory ceus="true"]</code>). Changes save when you click Update.
	</p>
	<?php
}

/**
 * Save the member's catalog CEUs. Runs after the main member save (same nonce),
 * and only when the picker was on the submitted form.
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
