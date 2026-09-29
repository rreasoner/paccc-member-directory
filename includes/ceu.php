<?php
/**
 * Approved CEU Courses.
 *
 * A catalog of PACCC-approved continuing-education courses/programs, entirely
 * separate from the per-member "CEU" tags in the member editor. Each course is
 * a `paccc_ceu` post with a Presenter, Website, Biography (the editor), a CEU
 * amount, an optional photo (featured image), and a Provider/Organization
 * (the `paccc_ceu_provider` taxonomy). The front end renders them via
 * [paccc_ceu_directory] with two dropdown filters (CEU amount + Provider) and
 * an "Apply Now" button that pre-fills a Gravity Form on the application page.
 */

defined( 'ABSPATH' ) || exit;

/** Post type + provider taxonomy slugs. */
define( 'PACCC_CEU_CPT', 'paccc_ceu' );
define( 'PACCC_CEU_TAX', 'paccc_ceu_provider' );

/**
 * The CEU amounts offered, matching the source directory's category list.
 * Stored per course as a plain numeric string ("0.5", "1", "9.75").
 */
function paccc_ceu_amounts() {
	return array( '0.5', '1', '1.5', '2', '2.5', '3', '3.5', '4', '5', '6', '7', '8', '9', '9.75', '10', '12', '14', '15', '16', '20', '22', '24' );
}

/**
 * Display label for a CEU amount, e.g. "0.5" => ".5 CEU", "1" => "1 CEU".
 * Mirrors how the amounts read on paccert.org/ceu-approved/.
 */
function paccc_ceu_format_amount( $amount ) {
	$amount = trim( (string) $amount );
	if ( '' === $amount ) {
		return '';
	}
	// Drop a leading zero before the decimal (0.5 -> .5), tidy trailing ".0".
	$amount = preg_replace( '/\.0$/', '', $amount );
	$amount = preg_replace( '/^0(?=\.)/', '', $amount );
	return $amount . ' CEU';
}

/**
 * Register the Approved CEU Courses post type, nested under the Member
 * Directory admin menu as "All CEUs" / "Add CEU". Not public: there are no
 * single-course pages -- the [paccc_ceu_directory] shortcode is the front end.
 */
function paccc_ceu_register_cpt() {
	register_post_type(
		PACCC_CEU_CPT,
		array(
			'labels'          => array(
				'name'               => 'CEUs',
				'singular_name'      => 'CEU Course',
				'add_new'            => 'Add New CEU',
				'add_new_item'       => 'Add New CEU',
				'edit_item'          => 'Edit CEU',
				'new_item'           => 'New CEU',
				'view_item'          => 'View CEU',
				'search_items'       => 'Search CEUs',
				'not_found'          => 'No CEUs found.',
				'not_found_in_trash' => 'No CEUs found in Trash.',
				'all_items'          => 'All CEUs',
				'menu_name'          => 'CEUs',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'edit.php?post_type=' . PACCC_MD_CPT,
			'show_in_rest'    => false,
			'has_archive'     => false,
			'rewrite'         => false,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'capabilities'    => paccc_md_admin_only_post_caps(), // Administrators only
			'map_meta_cap'    => true,
		)
	);

	register_taxonomy(
		PACCC_CEU_TAX,
		PACCC_CEU_CPT,
		array(
			'labels'            => array(
				'name'          => 'Providers',
				'singular_name' => 'Provider',
				'menu_name'     => 'Providers',
				'all_items'     => 'All Providers',
				'edit_item'     => 'Edit Provider',
				'add_new_item'  => 'Add Provider',
				'new_item_name' => 'New Provider Name',
				'search_items'  => 'Search Providers',
			),
			'public'            => false,
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			// Administrators only (default would let Editors manage terms and
			// anyone who can edit posts assign them).
			'capabilities'      => array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'manage_options',
			),
			'show_in_rest'      => false,
			'rewrite'           => false,
		)
	);

}
add_action( 'init', 'paccc_ceu_register_cpt' );

/**
 * "Featured image" reads oddly for a course photo; relabel it in the editor.
 */
function paccc_ceu_thumbnail_labels( $content, $post_id ) {
	if ( PACCC_CEU_CPT === get_post_type( $post_id ) ) {
		$content = str_replace( 'featured image', 'course photo', $content );
		$content = str_replace( 'Featured image', 'Course photo', $content );
	}
	return $content;
}
add_filter( 'admin_post_thumbnail_html', 'paccc_ceu_thumbnail_labels', 10, 2 );

/**
 * When a post type is nested under another menu (show_in_menu is a string),
 * WordPress adds only its "All Items" link -- not "Add New", and not the
 * attached taxonomy screen. Add "Add CEU" and "Providers" ourselves.
 */
function paccc_ceu_admin_submenus() {
	$parent = 'edit.php?post_type=' . PACCC_MD_CPT;

	add_submenu_page(
		$parent,
		'Add New CEU',
		'Add New CEU',
		'manage_options', // Administrators only
		'post-new.php?post_type=' . PACCC_CEU_CPT
	);

	add_submenu_page(
		$parent,
		'Providers',
		'Providers',
		'manage_options', // Administrators only
		'edit-tags.php?taxonomy=' . PACCC_CEU_TAX . '&amp;post_type=' . PACCC_CEU_CPT
	);
}
add_action( 'admin_menu', 'paccc_ceu_admin_submenus' );

/**
 * Order the Member Directory submenu: All Members, Add New Member, All CEUs,
 * Add CEU, Providers, Settings. Runs late so every item is already registered.
 */
function paccc_ceu_reorder_submenu() {
	global $submenu;
	$parent = 'edit.php?post_type=' . PACCC_MD_CPT;
	if ( empty( $submenu[ $parent ] ) ) {
		return;
	}

	$order = array(
		'edit.php?post_type=' . PACCC_MD_CPT,
		'post-new.php?post_type=' . PACCC_MD_CPT,
		'edit.php?post_type=' . PACCC_CEU_CPT,
		'post-new.php?post_type=' . PACCC_CEU_CPT,
		'edit-tags.php?taxonomy=' . PACCC_CEU_TAX . '&amp;post_type=' . PACCC_CEU_CPT,
		'paccc-md-settings',
	);
	$rank = array_flip( $order );

	usort(
		$submenu[ $parent ],
		static function ( $a, $b ) use ( $rank ) {
			$ra = isset( $rank[ $a[2] ] ) ? $rank[ $a[2] ] : 999;
			$rb = isset( $rank[ $b[2] ] ) ? $rank[ $b[2] ] : 999;
			return $ra <=> $rb;
		}
	);
}
add_action( 'admin_menu', 'paccc_ceu_reorder_submenu', 999 );

/* -------------------------------------------------------------------------
 * Provider term meta: a logo and a website URL per provider.
 * ---------------------------------------------------------------------- */

/** Attachment ID of a provider's logo (used as the photo fallback). */
function paccc_ceu_provider_logo_id( $term_id ) {
	return (int) get_term_meta( (int) $term_id, 'paccc_provider_logo_id', true );
}

function paccc_ceu_provider_logo_url( $term_id, $size = 'medium' ) {
	$id = paccc_ceu_provider_logo_id( $term_id );
	return $id ? (string) wp_get_attachment_image_url( $id, $size ) : '';
}

function paccc_ceu_provider_url( $term_id ) {
	return (string) get_term_meta( (int) $term_id, 'paccc_provider_url', true );
}

/** "Add Provider" screen: logo picker + website field. */
function paccc_ceu_provider_add_fields() {
	wp_enqueue_media();
	?>
	<div class="form-field">
		<label><?php esc_html_e( 'Logo', 'paccc-member-directory' ); ?></label>
		<div class="paccc-ceu-logo-field">
			<div class="paccc-ceu-logo-preview"></div>
			<input type="hidden" class="paccc-ceu-logo-id" name="paccc_provider_logo_id" value="" />
			<button type="button" class="button paccc-ceu-logo-select"><?php esc_html_e( 'Select logo', 'paccc-member-directory' ); ?></button>
			<button type="button" class="button-link paccc-ceu-logo-remove" style="display:none;color:#b32d2e;"><?php esc_html_e( 'Remove', 'paccc-member-directory' ); ?></button>
		</div>
		<p><?php esc_html_e( 'Shown for any course by this provider that has no course photo of its own.', 'paccc-member-directory' ); ?></p>
	</div>
	<div class="form-field">
		<label for="paccc_provider_url"><?php esc_html_e( 'Website', 'paccc-member-directory' ); ?></label>
		<input type="url" id="paccc_provider_url" name="paccc_provider_url" value="" placeholder="https://" />
	</div>
	<?php
}
add_action( PACCC_CEU_TAX . '_add_form_fields', 'paccc_ceu_provider_add_fields' );

/** "Edit Provider" screen: logo picker + website field. */
function paccc_ceu_provider_edit_fields( $term ) {
	wp_enqueue_media();
	$logo_id  = paccc_ceu_provider_logo_id( $term->term_id );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '';
	$url      = paccc_ceu_provider_url( $term->term_id );
	?>
	<tr class="form-field">
		<th scope="row"><label><?php esc_html_e( 'Logo', 'paccc-member-directory' ); ?></label></th>
		<td>
			<div class="paccc-ceu-logo-field">
				<div class="paccc-ceu-logo-preview">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:80px;width:auto;display:block;margin-bottom:6px;" />
					<?php endif; ?>
				</div>
				<input type="hidden" class="paccc-ceu-logo-id" name="paccc_provider_logo_id" value="<?php echo esc_attr( $logo_id ); ?>" />
				<button type="button" class="button paccc-ceu-logo-select"><?php esc_html_e( 'Select logo', 'paccc-member-directory' ); ?></button>
				<button type="button" class="button-link paccc-ceu-logo-remove" style="color:#b32d2e;<?php echo $logo_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'paccc-member-directory' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'Shown for any course by this provider that has no course photo of its own.', 'paccc-member-directory' ); ?></p>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="paccc_provider_url"><?php esc_html_e( 'Website', 'paccc-member-directory' ); ?></label></th>
		<td><input type="url" id="paccc_provider_url" name="paccc_provider_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://" /></td>
	</tr>
	<?php
}
add_action( PACCC_CEU_TAX . '_edit_form_fields', 'paccc_ceu_provider_edit_fields' );

/**
 * Persist provider logo + website on term create/edit.
 *
 * created_/edited_ hooks also fire when a term is created indirectly (e.g. a
 * CEU save auto-creating its Presenter term), so check the capability here
 * rather than trusting the caller. WordPress's own term screens verify their
 * nonces before these hooks run.
 */
function paccc_ceu_provider_save_fields( $term_id ) {
	$tax = get_taxonomy( PACCC_CEU_TAX );
	if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
		return;
	}
	if ( isset( $_POST['paccc_provider_logo_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$logo_id = (int) $_POST['paccc_provider_logo_id']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $logo_id && wp_attachment_is_image( $logo_id ) ) {
			update_term_meta( $term_id, 'paccc_provider_logo_id', $logo_id );
		} else {
			delete_term_meta( $term_id, 'paccc_provider_logo_id' );
		}
	}
	if ( isset( $_POST['paccc_provider_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_term_meta( $term_id, 'paccc_provider_url', esc_url_raw( wp_unslash( $_POST['paccc_provider_url'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
}
add_action( 'created_' . PACCC_CEU_TAX, 'paccc_ceu_provider_save_fields' );
add_action( 'edited_' . PACCC_CEU_TAX, 'paccc_ceu_provider_save_fields' );

/* -------------------------------------------------------------------------
 * Course meta box: Presenter, Website, CEU amount.
 * (Biography = the editor, Photo = featured image, Provider = taxonomy box.)
 * ---------------------------------------------------------------------- */

function paccc_ceu_add_meta_box() {
	add_meta_box( 'paccc_ceu_details', 'CEU Details', 'paccc_ceu_render_meta_box', PACCC_CEU_CPT, 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'paccc_ceu_add_meta_box' );

function paccc_ceu_render_meta_box( $post ) {
	wp_nonce_field( 'paccc_ceu_save', 'paccc_ceu_nonce' );
	$code      = (string) get_post_meta( $post->ID, 'paccc_ceu_program_code', true );
	$presenter = (string) get_post_meta( $post->ID, 'paccc_ceu_presenter', true );
	$contact   = (string) get_post_meta( $post->ID, 'paccc_ceu_contact_name', true );
	$email     = (string) get_post_meta( $post->ID, 'paccc_ceu_contact_email', true );
	$website   = (string) get_post_meta( $post->ID, 'paccc_ceu_website', true );
	$amount    = (string) get_post_meta( $post->ID, 'paccc_ceu_amount', true );
	$amounts   = paccc_ceu_amounts();
	?>
	<p>
		<label for="paccc_ceu_program_code"><strong><?php esc_html_e( 'Program Code', 'paccc-member-directory' ); ?></strong></label><br />
		<input type="text" id="paccc_ceu_program_code" name="paccc_ceu_program_code" value="<?php echo esc_attr( $code ); ?>" class="regular-text" />
		<br /><span class="description"><?php esc_html_e( 'From the CEU Master List (e.g. CC170001). Used with the Program Name to match rows when you re-upload the spreadsheet.', 'paccc-member-directory' ); ?></span>
	</p>
	<p>
		<label for="paccc_ceu_presenter"><strong><?php esc_html_e( 'Presenter', 'paccc-member-directory' ); ?></strong></label><br />
		<input type="text" id="paccc_ceu_presenter" name="paccc_ceu_presenter" value="<?php echo esc_attr( $presenter ); ?>" class="widefat" />
	</p>
	<p>
		<label for="paccc_ceu_contact_name"><strong><?php esc_html_e( 'Contact Person', 'paccc-member-directory' ); ?></strong></label><br />
		<input type="text" id="paccc_ceu_contact_name" name="paccc_ceu_contact_name" value="<?php echo esc_attr( $contact ); ?>" class="widefat" />
	</p>
	<p>
		<label for="paccc_ceu_contact_email"><strong><?php esc_html_e( 'Contact Email', 'paccc-member-directory' ); ?></strong></label><br />
		<input type="email" id="paccc_ceu_contact_email" name="paccc_ceu_contact_email" value="<?php echo esc_attr( $email ); ?>" class="widefat" />
	</p>
	<p>
		<label for="paccc_ceu_website"><strong><?php esc_html_e( 'Apply Now URL', 'paccc-member-directory' ); ?></strong></label><br />
		<input type="url" id="paccc_ceu_website" name="paccc_ceu_website" value="<?php echo esc_attr( $website ); ?>" class="widefat" placeholder="https://" />
		<br /><span class="description"><?php esc_html_e( 'The spreadsheet\'s URL column. If blank, Apply Now uses the CEU Application Link from Settings.', 'paccc-member-directory' ); ?></span>
	</p>
	<p>
		<label for="paccc_ceu_amount"><strong><?php esc_html_e( 'Number of CEUs', 'paccc-member-directory' ); ?></strong></label><br />
		<select id="paccc_ceu_amount" name="paccc_ceu_amount">
			<option value=""><?php esc_html_e( '— Select —', 'paccc-member-directory' ); ?></option>
			<?php
			$has_current = in_array( $amount, $amounts, true );
			foreach ( $amounts as $value ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $amount, $value, false ),
					esc_html( paccc_ceu_format_amount( $value ) )
				);
			}
			if ( '' !== $amount && ! $has_current ) {
				printf( '<option value="%s" selected>%s</option>', esc_attr( $amount ), esc_html( paccc_ceu_format_amount( $amount ) ) );
			}
			?>
		</select>
	</p>
	<p class="description"><?php esc_html_e( 'The title above is the Program Name.', 'paccc-member-directory' ); ?></p>
	<?php
}

function paccc_ceu_save_meta( $post_id ) {
	if ( ! isset( $_POST['paccc_ceu_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['paccc_ceu_nonce'] ) ), 'paccc_ceu_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$text = static function ( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified above
	};

	$email = sanitize_email( $text( 'paccc_ceu_contact_email' ) );

	update_post_meta( $post_id, 'paccc_ceu_program_code', $text( 'paccc_ceu_program_code' ) );
	update_post_meta( $post_id, 'paccc_ceu_presenter', $text( 'paccc_ceu_presenter' ) );
	update_post_meta( $post_id, 'paccc_ceu_contact_name', $text( 'paccc_ceu_contact_name' ) );
	update_post_meta( $post_id, 'paccc_ceu_contact_email', is_email( $email ) ? $email : '' );
	update_post_meta( $post_id, 'paccc_ceu_website', isset( $_POST['paccc_ceu_website'] ) ? esc_url_raw( wp_unslash( $_POST['paccc_ceu_website'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_ceu_amount', $text( 'paccc_ceu_amount' ) );

	// The Presenter doubles as the Presenter filter on the front end.
	$presenter = $text( 'paccc_ceu_presenter' );
	if ( '' !== $presenter ) {
		wp_set_object_terms( $post_id, $presenter, PACCC_CEU_TAX, false );
	}
}
add_action( 'save_post_' . PACCC_CEU_CPT, 'paccc_ceu_save_meta' );

/** Admin JS (provider logo picker) on the provider term screens. */
function paccc_ceu_admin_assets( $hook ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && PACCC_CEU_TAX === $screen->taxonomy ) {
		wp_enqueue_media();
		wp_enqueue_script( 'paccc-ceu-admin', PACCC_MD_URL . 'assets/ceu-admin.js', array( 'jquery' ), PACCC_MD_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'paccc_ceu_admin_assets' );

/* -------------------------------------------------------------------------
 * Data accessor + Apply Now URL.
 * ---------------------------------------------------------------------- */

/**
 * A CEU course as a plain object for the front end.
 */
function paccc_ceu_get( $post ) {
	$post = get_post( $post );
	if ( ! $post || PACCC_CEU_CPT !== $post->post_type ) {
		return null;
	}

	$amount    = (string) get_post_meta( $post->ID, 'paccc_ceu_amount', true );
	$provider  = '';
	$prov_slug = '';
	$prov_url  = '';
	$prov_logo = '';
	$terms     = get_the_terms( $post->ID, PACCC_CEU_TAX );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$term      = reset( $terms );
		$provider  = $term->name;
		$prov_slug = $term->slug;
		$prov_url  = paccc_ceu_provider_url( $term->term_id );
		$prov_logo = paccc_ceu_provider_logo_url( $term->term_id, 'medium' );
	}

	/*
	 * Photo resolution: an uploaded course photo (featured image) wins; then a
	 * URL imported from the source directory; then the provider's logo.
	 */
	if ( has_post_thumbnail( $post ) ) {
		$photo = (string) get_the_post_thumbnail_url( $post, 'medium' );
	} else {
		$imported = (string) get_post_meta( $post->ID, 'paccc_ceu_photo_url', true );
		$photo    = '' !== $imported ? $imported : $prov_logo;
	}

	return (object) array(
		'ID'            => $post->ID,
		'course'        => $post->post_title,
		'presenter'     => (string) get_post_meta( $post->ID, 'paccc_ceu_presenter', true ),
		'contact_name'  => (string) get_post_meta( $post->ID, 'paccc_ceu_contact_name', true ),
		'contact_email' => (string) get_post_meta( $post->ID, 'paccc_ceu_contact_email', true ),
		'program_code'  => (string) get_post_meta( $post->ID, 'paccc_ceu_program_code', true ),
		'website'       => (string) get_post_meta( $post->ID, 'paccc_ceu_website', true ),
		'biography'     => $post->post_content,
		'amount'        => $amount,
		'amount_label'  => paccc_ceu_format_amount( $amount ),
		'provider'      => $provider,
		'provider_slug' => $prov_slug,
		'provider_url'  => $prov_url,
		'photo'         => $photo,
	);
}

/** Base URL of the page holding the Gravity Form (the CEU application). */
function paccc_ceu_apply_base_url() {
	$link = trim( (string) get_option( 'paccc_ceu_application_link', '' ) );
	if ( '' === $link ) {
		$link = home_url( '/attendee-app/' );
	}
	return apply_filters( 'paccc_ceu_apply_url', $link );
}

/** Gravity Forms dynamic-population parameter names for the 3 forwarded fields. */
function paccc_ceu_apply_params() {
	return apply_filters(
		'paccc_ceu_apply_params',
		array(
			'course'    => 'ceu_course',
			'presenter' => 'ceu_presenter',
			'org'       => 'ceu_org',
		)
	);
}

/**
 * Build the Apply Now URL for a course, pre-filling Course/Presenter/
 * Organization as query args the Gravity Form can populate.
 */
function paccc_ceu_apply_url( $ceu ) {
	// A program with its own Apply Now URL (the master list's URL column /
	// the Edit CEU screen; stored as paccc_ceu_website) applies there as-is.
	$website = trim( (string) $ceu->website );
	if ( '' !== $website ) {
		return $website;
	}

	// Otherwise fall back to the CEU Application Link from Settings, with the
	// course details appended for the Gravity Form pre-fill.
	$params = paccc_ceu_apply_params();
	$org    = '' !== $ceu->provider ? $ceu->provider : $ceu->presenter;
	$args   = array(
		$params['course']    => $ceu->course,
		$params['presenter'] => $ceu->presenter,
		$params['org']       => $org,
	);
	$args = array_filter(
		$args,
		static function ( $v ) {
			return '' !== $v;
		}
	);
	return add_query_arg( array_map( 'rawurlencode', $args ), paccc_ceu_apply_base_url() );
}

/* -------------------------------------------------------------------------
 * Front end: [paccc_ceu_directory]
 * ---------------------------------------------------------------------- */

function paccc_ceu_all() {
	$posts = get_posts(
		array(
			'post_type'   => PACCC_CEU_CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);
	$out = array();
	foreach ( $posts as $p ) {
		$c = paccc_ceu_get( $p );
		if ( $c ) {
			$out[] = $c;
		}
	}
	return $out;
}

function paccc_ceu_register_frontend_assets() {
	wp_register_style( 'paccc-ceu', PACCC_MD_URL . 'assets/ceu.css', array(), PACCC_MD_VERSION );
	wp_register_script( 'paccc-ceu', PACCC_MD_URL . 'assets/ceu.js', array(), PACCC_MD_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'paccc_ceu_register_frontend_assets' );

function paccc_ceu_directory_shortcode( $atts ) {
	wp_enqueue_style( 'paccc-ceu' );
	wp_enqueue_script( 'paccc-ceu' );

	$atts     = shortcode_atts( array( 'per_page' => 10 ), $atts, 'paccc_ceu_directory' );
	$per_page = max( 0, (int) $atts['per_page'] );

	$courses = paccc_ceu_all();

	// Distinct amounts present, sorted numerically.
	$amounts = array();
	foreach ( $courses as $c ) {
		if ( '' !== $c->amount ) {
			$amounts[ $c->amount ] = true;
		}
	}
	$amounts = array_keys( $amounts );
	usort(
		$amounts,
		static function ( $a, $b ) {
			return ( (float) $a <=> (float) $b );
		}
	);

	// Distinct providers present, alphabetical.
	$providers = array();
	foreach ( $courses as $c ) {
		if ( '' !== $c->provider ) {
			$providers[ $c->provider_slug ] = $c->provider;
		}
	}
	asort( $providers, SORT_NATURAL | SORT_FLAG_CASE );

	ob_start();
	?>
	<div class="paccc-ceu-directory" id="paccc-ceu-directory" data-per-page="<?php echo esc_attr( $per_page ); ?>">
		<div class="paccc-ceu-filters">
			<label class="paccc-ceu-filter">
				<span><?php esc_html_e( 'Number of CEUs', 'paccc-member-directory' ); ?></span>
				<select class="paccc-ceu-filter-amount">
					<option value=""><?php esc_html_e( 'All CEU amounts', 'paccc-member-directory' ); ?></option>
					<?php foreach ( $amounts as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( paccc_ceu_format_amount( $value ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="paccc-ceu-filter">
				<span><?php esc_html_e( 'Presenter', 'paccc-member-directory' ); ?></span>
				<select class="paccc-ceu-filter-provider">
					<option value=""><?php esc_html_e( 'All presenters', 'paccc-member-directory' ); ?></option>
					<?php foreach ( $providers as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<p class="paccc-ceu-status" aria-live="polite"></p>

		<div class="paccc-ceu-list">
			<?php foreach ( $courses as $c ) : ?>
				<?php
				$apply_url = paccc_ceu_apply_url( $c );
				?>
				<article class="paccc-ceu-card" data-amount="<?php echo esc_attr( $c->amount ); ?>" data-provider="<?php echo esc_attr( $c->provider_slug ); ?>" data-course="<?php echo esc_attr( strtolower( $c->course ) ); ?>">
					<div class="paccc-ceu-card-body">
						<?php if ( $c->amount_label ) : ?>
							<span class="paccc-ceu-badge"><?php echo esc_html( $c->amount_label ); ?></span>
						<?php endif; ?>
						<h3 class="paccc-ceu-card-title"><?php echo esc_html( $c->course ); ?></h3>
						<?php if ( $c->presenter ) : ?>
							<p class="paccc-ceu-presenter"><strong><?php esc_html_e( 'Presenter:', 'paccc-member-directory' ); ?></strong> <?php echo esc_html( $c->presenter ); ?></p>
						<?php endif; ?>
						<?php if ( $c->contact_name ) : ?>
							<p class="paccc-ceu-presenter paccc-ceu-contact"><strong><?php esc_html_e( 'Contact:', 'paccc-member-directory' ); ?></strong> <?php echo esc_html( $c->contact_name ); ?></p>
						<?php endif; ?>
						<?php if ( $c->contact_email && is_email( $c->contact_email ) ) : ?>
							<?php // antispambot() entity-encodes the address so simple scrapers can't harvest it. ?>
							<p class="paccc-ceu-presenter paccc-ceu-contact"><strong><?php esc_html_e( 'Email:', 'paccc-member-directory' ); ?></strong> <a href="mailto:<?php echo antispambot( $c->contact_email ); // phpcs:ignore WordPress.Security.EscapeOutput -- is_email-validated, entity-encoded ?>"><?php echo antispambot( $c->contact_email ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></p>
						<?php endif; ?>
						<p class="paccc-ceu-apply">
							<a class="paccc-ceu-apply-btn" href="<?php echo esc_url( $apply_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Apply Now', 'paccc-member-directory' ); ?></a>
						</p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<p class="paccc-ceu-empty" hidden><?php esc_html_e( 'No CEUs match your filters.', 'paccc-member-directory' ); ?></p>

		<nav class="paccc-ceu-pagination" aria-label="<?php esc_attr_e( 'CEU list pages', 'paccc-member-directory' ); ?>" hidden></nav>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'paccc_ceu_directory', 'paccc_ceu_directory_shortcode' );
