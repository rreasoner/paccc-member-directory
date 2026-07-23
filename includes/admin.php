<?php
/**
 * PACCC Member Directory -- admin.
 *
 * Members are a post type now, so WordPress supplies the list table,
 * search, pagination and trash. This file adds the custom columns, the
 * member detail meta box, the certification AJAX helper, and the settings
 * screen.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen lives under the Member Directory menu.
 */
function paccc_md_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=' . PACCC_MD_CPT,
		'Directory Settings',
		'Settings',
		'manage_options',
		'paccc-md-settings',
		'paccc_md_render_settings'
	);
}
add_action( 'admin_menu', 'paccc_md_admin_menu' );

/**
 * Assets: only on member screens and the settings screen.
 */
function paccc_md_admin_assets( $hook ) {
	$screen = get_current_screen();
	$is_member_screen = $screen && PACCC_MD_CPT === $screen->post_type;
	$is_settings      = ( 'paccc_member_page_paccc-md-settings' === $hook );

	if ( ! $is_member_screen && ! $is_settings ) {
		return;
	}

	wp_enqueue_style( 'paccc-md-admin', PACCC_MD_URL . 'assets/admin.css', array(), PACCC_MD_VERSION );
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'paccc-md-admin', PACCC_MD_URL . 'assets/admin.js', array( 'wp-color-picker' ), PACCC_MD_VERSION, true );

	wp_localize_script(
		'paccc-md-admin',
		'PACCC_MD',
		array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'certNonce'    => wp_create_nonce( 'paccc_md_add_cert' ),
			'fonts'        => paccc_md_google_fonts(),
			'weightLabels' => paccc_md_weight_labels(),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'paccc_md_admin_assets' );

/**
 * The post title IS the business name -- say so in the placeholder.
 */
function paccc_md_title_placeholder( $text, $post ) {
	if ( $post && PACCC_MD_CPT === $post->post_type ) {
		return 'Business Name';
	}
	return $text;
}
add_filter( 'enter_title_here', 'paccc_md_title_placeholder', 10, 2 );

/* ---------------------------------------------------------------------------
 * List table columns
 * ------------------------------------------------------------------------ */

function paccc_md_columns( $columns ) {
	return array(
		'cb'             => isset( $columns['cb'] ) ? $columns['cb'] : '',
		'paccc_state'    => 'State',
		'title'          => 'Business Name',
		'member_name'    => 'Member Name',
		'certifications' => 'Certification(s)',
		'unique_link'    => 'Unique Link',
		'date'           => 'Date',
	);
}
add_filter( 'manage_' . PACCC_MD_CPT . '_posts_columns', 'paccc_md_columns' );

function paccc_md_column_content( $column, $post_id ) {
	$member = paccc_md_get_member( $post_id );
	if ( ! $member ) {
		return;
	}

	switch ( $column ) {
		case 'paccc_state':
			$states = paccc_md_states();
			echo esc_html( isset( $states[ $member->state ] ) ? $states[ $member->state ] : $member->state );
			break;

		case 'member_name':
			echo esc_html( $member->member_name );
			break;

		case 'certifications':
			echo esc_html( implode( ', ', $member->certifications ) );
			break;

		case 'unique_link':
			$link = paccc_md_member_link( $post_id );
			echo '<code>' . esc_html( $member->member_number ) . '</code> ';
			echo '<button type="button" class="button button-small paccc-md-copy" data-link="' . esc_attr( $link ) . '">Copy Unique Link</button>';
			break;
	}
}
add_action( 'manage_' . PACCC_MD_CPT . '_posts_custom_column', 'paccc_md_column_content', 10, 2 );

function paccc_md_sortable_columns( $columns ) {
	$columns['paccc_state']  = 'paccc_state';
	$columns['member_name']  = 'member_name';
	$columns['unique_link']  = 'member_number';
	return $columns;
}
add_filter( 'manage_edit-' . PACCC_MD_CPT . '_sortable_columns', 'paccc_md_sortable_columns' );

/**
 * Translate the custom column sorts into meta queries, and let the admin
 * search box match member number / member name / city as well as the title.
 */
function paccc_md_admin_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || PACCC_MD_CPT !== $query->get( 'post_type' ) ) {
		return;
	}

	$map = array(
		'paccc_state'   => 'paccc_state',
		'member_name'   => 'paccc_member_name',
		'member_number' => 'paccc_member_number',
	);

	$orderby = $query->get( 'orderby' );
	if ( isset( $map[ $orderby ] ) ) {
		$query->set( 'meta_key', $map[ $orderby ] );
		$query->set( 'orderby', 'meta_value' );
	}
}
add_action( 'pre_get_posts', 'paccc_md_admin_query' );

/**
 * Extend the admin search to cover member meta. WP's default search only
 * looks at post title/content, which would miss member numbers entirely.
 */
function paccc_md_admin_search( $where, $query ) {
	global $wpdb;

	if ( ! is_admin() || ! $query->is_main_query() || PACCC_MD_CPT !== $query->get( 'post_type' ) ) {
		return $where;
	}
	$term = $query->get( 's' );
	if ( ! $term ) {
		return $where;
	}

	$like = '%' . $wpdb->esc_like( $term ) . '%';
	$ids  = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key IN ( 'paccc_member_number', 'paccc_member_name', 'paccc_city' )
			 AND meta_value LIKE %s",
			$like
		)
	);

	if ( $ids ) {
		$in     = implode( ',', array_map( 'absint', $ids ) );
		$where .= " OR ({$wpdb->posts}.ID IN ($in) AND {$wpdb->posts}.post_type = '" . PACCC_MD_CPT . "' AND {$wpdb->posts}.post_status != 'trash')";
	}

	return $where;
}
add_filter( 'posts_search', 'paccc_md_admin_search', 10, 2 );

/* ---------------------------------------------------------------------------
 * Member detail meta box
 * ------------------------------------------------------------------------ */

function paccc_md_add_meta_box() {
	add_meta_box(
		'paccc_md_details',
		'Member Details',
		'paccc_md_render_meta_box',
		PACCC_MD_CPT,
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'paccc_md_add_meta_box' );

function paccc_md_render_meta_box( $post ) {
	$member = paccc_md_get_member( $post );
	$number = $member->member_number ? $member->member_number : paccc_md_next_member_number();
	$states = paccc_md_states();
	$certs  = paccc_md_certifications();

	// Certification titles and the certification list are global settings; only
	// admins may change them (see paccc_md_save_member / paccc_md_ajax_add_cert).
	// Non-admins still pick which certifications a member holds, but see the
	// shared titles read-only and don't get the "add certification" control.
	$can_manage = current_user_can( 'manage_options' );

	wp_nonce_field( 'paccc_md_save_member', 'paccc_md_nonce' );
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="paccc_member_number">Member Number</label></th>
			<td>
				<input type="text" id="paccc_member_number" name="paccc_member_number"
					value="<?php echo esc_attr( $number ); ?>" pattern="[0-9]{7}" maxlength="7" class="regular-text" />
				<p class="description">7 digits, unique. Auto-assigned for new members.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="paccc_member_name">Member Name</label></th>
			<td><input type="text" id="paccc_member_name" name="paccc_member_name" value="<?php echo esc_attr( $member->member_name ); ?>" class="regular-text" /></td>
		</tr>
		<tr>
			<th scope="row">Certification(s)</th>
			<td>
				<?php $cert_labels = paccc_md_cert_labels(); ?>
				<div id="paccc-md-cert-list">
					<?php foreach ( $certs as $cert ) : ?>
						<div class="paccc-md-cert-row">
							<label class="paccc-md-cert">
								<input type="checkbox" name="paccc_certifications[]" value="<?php echo esc_attr( $cert ); ?>"
									<?php checked( in_array( $cert, $member->certifications, true ) ); ?> />
								<strong><?php echo esc_html( $cert ); ?></strong>
							</label>
							<input type="text"
								class="paccc-md-cert-label regular-text"
								<?php echo $can_manage ? 'name="paccc_cert_labels[' . esc_attr( $cert ) . ']"' : 'readonly'; ?>
								value="<?php echo esc_attr( isset( $cert_labels[ $cert ] ) ? $cert_labels[ $cert ] : '' ); ?>"
								placeholder="Full title, e.g. Certified Professional Animal Care Provider" />
						</div>
					<?php endforeach; ?>
				</div>
				<p class="description">
					Full titles appear on the frontend as a legend and as tooltips on each badge.
					<?php if ( $can_manage ) : ?>
						A title belongs to the certification itself, so editing it here updates it for every member.
						Certifications can be removed under <em>Member Directory &rarr; Settings</em>.
					<?php else : ?>
						Titles are shared across all members and can only be changed by an administrator.
					<?php endif; ?>
				</p>
				<?php if ( $can_manage ) : ?>
					<p>
						<a href="#" id="paccc-md-add-cert-toggle">+ Add a certification</a>
						<span id="paccc-md-cert-feedback"></span>
					</p>
					<p id="paccc-md-add-cert-row" hidden>
						<input type="text" id="paccc-md-new-cert" class="regular-text" placeholder="e.g. CPACT" />
						<button type="button" class="button" id="paccc-md-add-cert-btn">Add</button>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="paccc_address1">Address 1</label></th>
			<td><input type="text" id="paccc_address1" name="paccc_address1" value="<?php echo esc_attr( $member->address1 ); ?>" class="regular-text" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="paccc_address2">Address 2</label></th>
			<td><input type="text" id="paccc_address2" name="paccc_address2" value="<?php echo esc_attr( $member->address2 ); ?>" class="regular-text" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="paccc_city">City</label></th>
			<td><input type="text" id="paccc_city" name="paccc_city" value="<?php echo esc_attr( $member->city ); ?>" class="regular-text" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="paccc_state">State</label></th>
			<td>
				<select id="paccc_state" name="paccc_state">
					<option value="">— Select —</option>
					<?php foreach ( $states as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $member->state, $code ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="paccc_zip">Zip</label></th>
			<td><input type="text" id="paccc_zip" name="paccc_zip" value="<?php echo esc_attr( $member->zip ); ?>" class="regular-text" /></td>
		</tr>
	</table>
	<?php
}

/**
 * Save the meta box.
 */
function paccc_md_save_member( $post_id, $post ) {
	if ( PACCC_MD_CPT !== $post->post_type ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['paccc_md_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['paccc_md_nonce'] ) ), 'paccc_md_save_member' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Member number: keep 7 digits and enforce uniqueness across members.
	$number = isset( $_POST['paccc_member_number'] ) ? preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['paccc_member_number'] ) ) : '';
	if ( 7 !== strlen( $number ) ) {
		$number = paccc_md_next_member_number();
	}
	$owner = paccc_md_find_by_number( $number );
	if ( $owner && $owner !== $post_id ) {
		$number = paccc_md_next_member_number();
	}
	update_post_meta( $post_id, 'paccc_member_number', $number );

	update_post_meta( $post_id, 'paccc_member_name', isset( $_POST['paccc_member_name'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_member_name'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_address1', isset( $_POST['paccc_address1'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_address1'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_address2', isset( $_POST['paccc_address2'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_address2'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_city', isset( $_POST['paccc_city'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_city'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_zip', isset( $_POST['paccc_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_zip'] ) ) : '' );

	$states = paccc_md_states();
	$state  = isset( $_POST['paccc_state'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_state'] ) ) : '';
	update_post_meta( $post_id, 'paccc_state', isset( $states[ $state ] ) ? $state : '' );

	$known    = paccc_md_certifications();
	$selected = isset( $_POST['paccc_certifications'] ) ? (array) wp_unslash( $_POST['paccc_certifications'] ) : array();
	$selected = array_values( array_intersect( array_map( 'sanitize_text_field', $selected ), $known ) );
	update_post_meta( $post_id, 'paccc_certifications', $selected );

	// Certification titles are shared across all members, so they save to the
	// global option rather than to this member's meta. That makes them a
	// site-wide setting: gate the write behind manage_options so a user with
	// edit access to a single member can't rewrite every member's cert titles.
	if ( current_user_can( 'manage_options' ) && isset( $_POST['paccc_cert_labels'] ) && is_array( $_POST['paccc_cert_labels'] ) ) {
		$labels = array();
		foreach ( wp_unslash( $_POST['paccc_cert_labels'] ) as $abbr => $full ) {
			$abbr = sanitize_text_field( $abbr );
			$full = sanitize_text_field( $full );
			if ( '' !== $abbr && '' !== $full ) {
				$labels[ $abbr ] = $full;
			}
		}
		update_option( 'paccc_certification_labels', $labels );
	}
}
add_action( 'save_post', 'paccc_md_save_member', 10, 2 );

/* ---------------------------------------------------------------------------
 * Add-a-certification AJAX
 * ------------------------------------------------------------------------ */

function paccc_md_ajax_add_cert() {
	check_ajax_referer( 'paccc_md_add_cert', 'nonce' );
	// Adding a certification writes the global paccc_certifications option that
	// renders on the public frontend, so it takes the same manage_options
	// capability as deleting one -- not the post-editing cap, which would let a
	// Contributor inject site-wide config.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Not allowed.' ) );
	}

	$name = isset( $_POST['cert'] ) ? sanitize_text_field( wp_unslash( $_POST['cert'] ) ) : '';
	$name = trim( str_replace( ',', '', $name ) );
	if ( '' === $name ) {
		wp_send_json_error( array( 'message' => 'Enter a certification name.' ) );
	}

	$certs = paccc_md_certifications();
	foreach ( $certs as $existing ) {
		if ( 0 === strcasecmp( $existing, $name ) ) {
			wp_send_json_success( array( 'name' => $existing, 'existing' => true ) );
		}
	}

	$certs[] = $name;
	update_option( 'paccc_certifications', $certs );
	wp_send_json_success( array( 'name' => $name, 'existing' => false ) );
}
add_action( 'wp_ajax_paccc_md_add_cert', 'paccc_md_ajax_add_cert' );

/* ---------------------------------------------------------------------------
 * Certification management
 * ------------------------------------------------------------------------ */

/**
 * How many members currently hold each certification. Used to warn before a
 * deletion that would strip the certification from existing members.
 */
function paccc_md_cert_usage() {
	$usage = array_fill_keys( paccc_md_certifications(), 0 );

	$ids = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		$certs = get_post_meta( $id, 'paccc_certifications', true );
		if ( ! is_array( $certs ) ) {
			continue;
		}
		foreach ( $certs as $cert ) {
			if ( isset( $usage[ $cert ] ) ) {
				$usage[ $cert ]++;
			}
		}
	}

	return $usage;
}

/**
 * Delete a certification: remove it from the list, drop its shared title, and
 * strip it from every member that holds it. Leaving it on members would show
 * a badge for a certification that no longer exists.
 */
function paccc_md_handle_delete_cert() {
	$cert = isset( $_GET['cert'] ) ? sanitize_text_field( wp_unslash( $_GET['cert'] ) ) : '';

	check_admin_referer( 'paccc_md_delete_cert_' . $cert );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	$certs = paccc_md_certifications();
	if ( '' === $cert || ! in_array( $cert, $certs, true ) ) {
		wp_safe_redirect( paccc_md_settings_url( array( 'paccc_msg' => 'cert_missing' ) ) );
		exit;
	}

	update_option( 'paccc_certifications', array_values( array_diff( $certs, array( $cert ) ) ) );

	$labels = get_option( 'paccc_certification_labels', array() );
	if ( is_array( $labels ) && isset( $labels[ $cert ] ) ) {
		unset( $labels[ $cert ] );
		update_option( 'paccc_certification_labels', $labels );
	}

	$ids     = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	$stripped = 0;

	foreach ( $ids as $id ) {
		$member_certs = get_post_meta( $id, 'paccc_certifications', true );
		if ( is_array( $member_certs ) && in_array( $cert, $member_certs, true ) ) {
			update_post_meta( $id, 'paccc_certifications', array_values( array_diff( $member_certs, array( $cert ) ) ) );
			$stripped++;
		}
	}

	wp_safe_redirect(
		paccc_md_settings_url(
			array(
				'paccc_msg'      => 'cert_deleted',
				'paccc_cert'     => rawurlencode( $cert ),
				'paccc_stripped' => $stripped,
			)
		)
	);
	exit;
}
add_action( 'admin_post_paccc_md_delete_cert', 'paccc_md_handle_delete_cert' );

/**
 * URL of the settings screen, with optional query args.
 */
function paccc_md_settings_url( $args = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'post_type' => PACCC_MD_CPT,
				'page'      => 'paccc-md-settings',
			),
			$args
		),
		admin_url( 'edit.php' )
	);
}

/* ---------------------------------------------------------------------------
 * Settings screen
 * ------------------------------------------------------------------------ */

function paccc_md_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	$map_settings  = paccc_md_map_settings();
	$fonts         = paccc_md_google_fonts();
	$weight_labels = paccc_md_weight_labels();
	$migrated      = (int) get_option( 'paccc_md_migrated_count' );
	?>
	<div class="wrap">
		<h1>Directory Settings</h1>

		<?php
		// phpcs:disable WordPress.Security.NonceVerification
		$msg = isset( $_GET['paccc_msg'] ) ? sanitize_key( wp_unslash( $_GET['paccc_msg'] ) ) : '';
		?>
		<?php if ( 'settings' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
		<?php elseif ( 'cert_deleted' === $msg ) : ?>
			<?php
			$deleted  = isset( $_GET['paccc_cert'] ) ? sanitize_text_field( wp_unslash( $_GET['paccc_cert'] ) ) : '';
			$stripped = isset( $_GET['paccc_stripped'] ) ? absint( $_GET['paccc_stripped'] ) : 0;
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php echo esc_html( sprintf( 'Deleted the "%s" certification.', $deleted ) ); ?>
					<?php if ( $stripped ) : ?>
						<?php echo esc_html( sprintf( 'It was removed from %d member%s.', $stripped, 1 === $stripped ? '' : 's' ) ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php elseif ( 'cert_missing' === $msg ) : ?>
			<div class="notice notice-error is-dismissible"><p>That certification no longer exists.</p></div>
		<?php endif; ?>
		<?php // phpcs:enable WordPress.Security.NonceVerification ?>

		<?php if ( $migrated ) : ?>
			<div class="notice notice-info">
				<p><?php echo esc_html( sprintf( '%d member(s) were migrated from the old directory table into member pages. The old table was left in place as a backup.', $migrated ) ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="paccc_md_save_settings" />
			<?php wp_nonce_field( 'paccc_md_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="paccc_directory_page_id">Directory page</label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'paccc_directory_page_id',
								'id'                => 'paccc_directory_page_id',
								'selected'          => (int) get_option( 'paccc_directory_page_id' ),
								'show_option_none'  => '— Auto-detect from shortcode —',
								'option_none_value' => '0',
							)
						);
						?>
						<p class="description">The page holding the <code>[paccc_directory]</code> shortcode. Used for breadcrumb links back to the directory.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="paccc_map_font">Map label font</label></th>
					<td>
						<select name="paccc_map_font" id="paccc_map_font">
							<option value="">— Theme default —</option>
							<?php foreach ( $fonts as $family => $weights ) : ?>
								<option value="<?php echo esc_attr( $family ); ?>" <?php selected( $map_settings['font'], $family ); ?>><?php echo esc_html( $family ); ?></option>
							<?php endforeach; ?>
						</select>

						<label for="paccc_map_font_weight" class="paccc-md-inline-label">Style</label>
						<select name="paccc_map_font_weight" id="paccc_map_font_weight">
							<?php
							$current_weights = isset( $fonts[ $map_settings['font'] ] ) ? $fonts[ $map_settings['font'] ] : array( '400' );
							foreach ( $current_weights as $w ) :
								?>
								<option value="<?php echo esc_attr( $w ); ?>" <?php selected( $map_settings['weight'], $w ); ?>>
									<?php echo esc_html( isset( $weight_labels[ $w ] ) ? $weight_labels[ $w ] : $w ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">Applies to the state names on the frontend map.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="paccc_map_highlight">Member state color</label></th>
					<td>
						<input type="text" name="paccc_map_highlight" id="paccc_map_highlight"
							value="<?php echo esc_attr( $map_settings['highlight'] ); ?>"
							class="paccc-md-color" data-default-color="#ffe399" />
						<p class="description">Background color for states with at least one member. Also the accent for directory buttons and pagination.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<h2>Certifications</h2>
		<p class="description">
			Titles are edited on any member's edit screen. Deleting a certification
			also removes it from every member that currently holds it.
		</p>

		<?php
		$usage      = paccc_md_cert_usage();
		$all_certs  = paccc_md_certifications();
		$all_labels = paccc_md_cert_labels();
		?>
		<table class="wp-list-table widefat striped paccc-md-cert-table">
			<thead>
				<tr>
					<th scope="col">Certification</th>
					<th scope="col">Full title</th>
					<th scope="col">Members</th>
					<th scope="col">Action</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $all_certs ) : ?>
					<tr><td colspan="4">No certifications yet. Add one from any member's edit screen.</td></tr>
				<?php else : ?>
					<?php
					foreach ( $all_certs as $cert ) :
						$count      = isset( $usage[ $cert ] ) ? (int) $usage[ $cert ] : 0;
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'action' => 'paccc_md_delete_cert',
									'cert'   => rawurlencode( $cert ),
								),
								admin_url( 'admin-post.php' )
							),
							'paccc_md_delete_cert_' . $cert
						);

						$confirm = $count
							? sprintf(
								'Delete "%s"? It will also be removed from %d member%s. This cannot be undone.',
								$cert,
								$count,
								1 === $count ? '' : 's'
							)
							: sprintf( 'Delete "%s"? This cannot be undone.', $cert );
						?>
						<tr>
							<td><strong><?php echo esc_html( $cert ); ?></strong></td>
							<td><?php echo esc_html( isset( $all_labels[ $cert ] ) ? $all_labels[ $cert ] : '—' ); ?></td>
							<td><?php echo esc_html( $count ); ?></td>
							<td>
								<a href="<?php echo esc_url( $delete_url ); ?>"
									class="paccc-md-delete paccc-md-delete-cert"
									data-confirm="<?php echo esc_attr( $confirm ); ?>">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function paccc_md_handle_save_settings() {
	check_admin_referer( 'paccc_md_save_settings' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	update_option( 'paccc_directory_page_id', isset( $_POST['paccc_directory_page_id'] ) ? absint( $_POST['paccc_directory_page_id'] ) : 0 );

	$fonts  = paccc_md_google_fonts();
	$family = isset( $_POST['paccc_map_font'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_map_font'] ) ) : '';
	if ( ! isset( $fonts[ $family ] ) ) {
		$family = '';
	}
	update_option( 'paccc_md_map_font', $family );

	$weight = isset( $_POST['paccc_map_font_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['paccc_map_font_weight'] ) ) : '400';
	if ( ! $family || ! in_array( $weight, $fonts[ $family ], true ) ) {
		$weight = $family ? $fonts[ $family ][0] : '400';
	}
	update_option( 'paccc_md_map_font_weight', $weight );

	$color = isset( $_POST['paccc_map_highlight'] ) ? sanitize_hex_color( wp_unslash( $_POST['paccc_map_highlight'] ) ) : '';
	update_option( 'paccc_md_map_highlight', $color ? $color : '#ffe399' );

	wp_safe_redirect(
		add_query_arg(
			array(
				'post_type'  => PACCC_MD_CPT,
				'page'       => 'paccc-md-settings',
				'paccc_msg'  => 'settings',
			),
			admin_url( 'edit.php' )
		)
	);
	exit;
}
add_action( 'admin_post_paccc_md_save_settings', 'paccc_md_handle_save_settings' );
