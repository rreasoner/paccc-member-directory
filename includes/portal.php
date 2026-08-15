<?php
/**
 * PACCC Member Directory -- member portal + logins.
 *
 * Members log in with a WordPress account under a dedicated "PACCC Member" role
 * (no wp-admin access) and edit their OWN record from a front-end page via the
 * [paccc_member_portal] shortcode. Member Number, Certifications and CEUs are
 * shown read-only -- only administrators change those (from the member edit
 * screen). Admins provision and manage the accounts from the settings screen
 * and each member's edit screen.
 */

defined( 'ABSPATH' ) || exit;

const PACCC_MD_ROLE = 'paccc_member';

/* ---------------------------------------------------------------------------
 * Role
 * ------------------------------------------------------------------------ */

/**
 * The member role can do nothing but read (log in). It never gets editing caps;
 * the front-end portal performs the update after verifying ownership.
 */
function paccc_md_register_member_role() {
	add_role( PACCC_MD_ROLE, 'PACCC Member', array( 'read' => true ) );
}

/**
 * Self-heal on load so the role exists after a plugin update (not just a fresh
 * activation). add_role() is a no-op once it exists.
 */
function paccc_md_ensure_member_role() {
	if ( ! wp_roles()->is_role( PACCC_MD_ROLE ) ) {
		paccc_md_register_member_role();
	}
}
add_action( 'init', 'paccc_md_ensure_member_role' );

/* ---------------------------------------------------------------------------
 * User <-> member linking
 * ------------------------------------------------------------------------ */

/**
 * Member post id linked to a user (0 if none / trashed).
 */
function paccc_md_member_post_for_user( $user_id ) {
	$pid = (int) get_user_meta( $user_id, 'paccc_member_post_id', true );
	if ( $pid && PACCC_MD_CPT === get_post_type( $pid ) && 'trash' !== get_post_status( $pid ) ) {
		return $pid;
	}
	return 0;
}

/**
 * WP user id linked to a member post (0 if none).
 */
function paccc_md_user_for_member( $post_id ) {
	return (int) get_post_meta( $post_id, 'paccc_user_id', true );
}

function paccc_md_link_user_member( $user_id, $post_id ) {
	update_post_meta( $post_id, 'paccc_user_id', (int) $user_id );
	update_user_meta( $user_id, 'paccc_member_post_id', (int) $post_id );
}

/* ---------------------------------------------------------------------------
 * Provisioning
 * ------------------------------------------------------------------------ */

function paccc_md_generate_member_username( $m ) {
	$base = '';
	if ( $m->email && is_email( $m->email ) && false !== strpos( $m->email, '@' ) ) {
		$base = sanitize_user( substr( $m->email, 0, strpos( $m->email, '@' ) ), true );
	}
	if ( '' === $base ) {
		$base = sanitize_user( $m->member_name ? $m->member_name : $m->business_name, true );
	}
	if ( '' === $base ) {
		$base = 'member' . $m->member_number;
	}
	$base = strtolower( $base );
	if ( '' === $base ) {
		$base = 'member';
	}
	$user = $base;
	$i    = 1;
	while ( username_exists( $user ) ) {
		$user = $base . $i;
		$i++;
	}
	return $user;
}

/**
 * Create (or link an existing user to) a login for a member post.
 * Returns the user id, or WP_Error. Sends no email.
 */
function paccc_md_provision_member_login( $post_id ) {
	$existing = paccc_md_user_for_member( $post_id );
	if ( $existing && get_userdata( $existing ) ) {
		return $existing;
	}

	$m = paccc_md_get_member( $post_id );
	if ( ! $m ) {
		return new WP_Error( 'paccc_no_member', 'Not a member.' );
	}

	$email = ( $m->email && is_email( $m->email ) ) ? $m->email : '';

	// If a user already has this email, link to it rather than duplicate.
	if ( $email ) {
		$u = get_user_by( 'email', $email );
		if ( $u ) {
			$u->add_role( PACCC_MD_ROLE );
			paccc_md_link_user_member( $u->ID, $post_id );
			return $u->ID;
		}
	}

	$userdata = array(
		'user_login'   => paccc_md_generate_member_username( $m ),
		'user_pass'    => wp_generate_password( 24 ),
		'role'         => PACCC_MD_ROLE,
		'display_name' => $m->business_name ? $m->business_name : $m->member_name,
		'nickname'     => $m->member_name,
	);
	if ( $email ) {
		$userdata['user_email'] = $email;
	}

	$uid = wp_insert_user( $userdata );
	if ( is_wp_error( $uid ) ) {
		return $uid;
	}
	paccc_md_link_user_member( $uid, $post_id );
	return $uid;
}

/* ---------------------------------------------------------------------------
 * Front-end portal
 * ------------------------------------------------------------------------ */

/**
 * The portal page's URL (falls back to the home page).
 */
function paccc_md_portal_url() {
	$pid = (int) get_option( 'paccc_portal_page_id' );
	return $pid ? get_permalink( $pid ) : home_url( '/' );
}

add_shortcode( 'paccc_member_portal', 'paccc_md_portal_shortcode' );
function paccc_md_portal_shortcode( $atts ) {
	// Remember which page hosts the portal (for login / admin redirects).
	if ( ! (int) get_option( 'paccc_portal_page_id' ) && is_singular() && get_the_ID() ) {
		update_option( 'paccc_portal_page_id', (int) get_the_ID() );
	}

	paccc_md_enqueue_frontend( false );

	if ( ! is_user_logged_in() ) {
		ob_start();
		?>
		<div class="paccc-portal paccc-directory-wrap">
			<h2 class="paccc-portal-heading">Member Login</h2>
			<?php wp_login_form( array( 'redirect' => paccc_md_portal_url() ) ); ?>
			<p class="paccc-portal-reset"><a href="<?php echo esc_url( wp_lostpassword_url( paccc_md_portal_url() ) ); ?>">Forgot your password?</a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	$uid     = get_current_user_id();
	$post_id = paccc_md_member_post_for_user( $uid );
	if ( ! $post_id ) {
		return '<div class="paccc-portal paccc-directory-wrap"><p>No member profile is linked to your account yet. Please contact the administrator.</p><p><a href="' . esc_url( wp_logout_url( paccc_md_portal_url() ) ) . '">Log out</a></p></div>';
	}

	$m      = paccc_md_get_member( $post_id );
	$states = paccc_md_states();
	$status = isset( $_GET['paccc_portal'] ) ? sanitize_key( wp_unslash( $_GET['paccc_portal'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	ob_start();
	?>
	<div class="paccc-portal paccc-directory-wrap">
		<div class="paccc-portal-bar">
			<span>Signed in as <strong><?php echo esc_html( wp_get_current_user()->user_login ); ?></strong></span>
			<a href="<?php echo esc_url( wp_logout_url( paccc_md_portal_url() ) ); ?>">Log out</a>
		</div>

		<?php if ( 'updated' === $status ) : ?>
			<div class="paccc-portal-notice">Your profile was updated.</div>
		<?php elseif ( 'imgerror' === $status ) : ?>
			<div class="paccc-portal-notice paccc-portal-notice-error">Your details were saved, but the image couldn&rsquo;t be uploaded. Please use a JPG, PNG, GIF or WebP under 5&nbsp;MB.</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="paccc-portal-form" enctype="multipart/form-data">
			<input type="hidden" name="action" value="paccc_md_portal_save" />
			<?php wp_nonce_field( 'paccc_md_portal_save' ); ?>

			<?php $paccc_img = paccc_md_member_image_url( $m->image_id, 'medium' ); ?>
			<div class="paccc-portal-field paccc-portal-image">
				<span class="paccc-portal-imglabel">Logo / Photo</span>
				<?php if ( $paccc_img ) : ?>
					<img src="<?php echo esc_url( $paccc_img ); ?>" alt="" class="paccc-portal-image-preview" />
				<?php endif; ?>
				<input type="file" name="paccc_image" accept="image/jpeg,image/png,image/gif,image/webp" />
				<?php if ( $paccc_img ) : ?>
					<label class="paccc-portal-imgdelete"><input type="checkbox" name="paccc_image_delete" value="1" /> Delete current image</label>
				<?php endif; ?>
				<span class="paccc-portal-note">Upload a JPG, PNG, GIF or WebP (5 MB max). Uploading a new image replaces the current one.</span>
			</div>

			<p class="paccc-portal-field">
				<label for="paccc-portal-business">Business Name</label>
				<input type="text" id="paccc-portal-business" name="business_name" value="<?php echo esc_attr( $m->business_name ); ?>" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-name">Member Name</label>
				<input type="text" id="paccc-portal-name" name="member_name" value="<?php echo esc_attr( $m->member_name ); ?>" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-addr1">Address 1</label>
				<input type="text" id="paccc-portal-addr1" name="address1" value="<?php echo esc_attr( $m->address1 ); ?>" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-addr2">Address 2</label>
				<input type="text" id="paccc-portal-addr2" name="address2" value="<?php echo esc_attr( $m->address2 ); ?>" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-city">City</label>
				<input type="text" id="paccc-portal-city" name="city" value="<?php echo esc_attr( $m->city ); ?>" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-state">State</label>
				<select id="paccc-portal-state" name="state">
					<option value="">— Select —</option>
					<?php foreach ( $states as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $m->state, $code ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-zip">Zip</label>
				<input type="text" id="paccc-portal-zip" name="zip" value="<?php echo esc_attr( $m->zip ); ?>" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-website">Website</label>
				<input type="url" id="paccc-portal-website" name="website" value="<?php echo esc_attr( $m->website ); ?>" placeholder="https://example.com" />
			</p>
			<p class="paccc-portal-field">
				<label for="paccc-portal-email">Email</label>
				<input type="email" id="paccc-portal-email" name="email" value="<?php echo esc_attr( $m->email ); ?>" />
			</p>

			<div class="paccc-portal-readonly">
				<p><span class="paccc-portal-rolabel">Member Number</span> <?php echo esc_html( $m->member_number ); ?></p>
				<p><span class="paccc-portal-rolabel">Certification(s)</span> <?php echo $m->certifications ? esc_html( implode( ', ', $m->certifications ) ) : '—'; ?></p>
				<p><span class="paccc-portal-rolabel">CEU(s)</span> <?php echo $m->ceus ? esc_html( implode( ', ', $m->ceus ) ) : '—'; ?></p>
				<p class="paccc-portal-note">Member Number, Certifications and CEUs are maintained by PACCC and can only be changed by an administrator.</p>
			</div>

			<p><button type="submit" class="paccc-portal-submit">Save changes</button></p>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Save the member's own edits. Only the allowed fields are written -- member
 * number, certifications and CEUs are never touched here.
 */
function paccc_md_portal_save() {
	if ( ! is_user_logged_in() ) {
		wp_die( 'You must be logged in to do that.' );
	}
	check_admin_referer( 'paccc_md_portal_save' );

	$uid     = get_current_user_id();
	$post_id = paccc_md_member_post_for_user( $uid );
	if ( ! $post_id || paccc_md_user_for_member( $post_id ) !== $uid ) {
		wp_die( 'You are not allowed to edit this profile.' );
	}

	$business = isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '';
	if ( '' !== $business ) {
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $business,
			)
		);
	}

	update_post_meta( $post_id, 'paccc_member_name', isset( $_POST['member_name'] ) ? sanitize_text_field( wp_unslash( $_POST['member_name'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_address1', isset( $_POST['address1'] ) ? sanitize_text_field( wp_unslash( $_POST['address1'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_address2', isset( $_POST['address2'] ) ? sanitize_text_field( wp_unslash( $_POST['address2'] ) ) : '' );
	update_post_meta( $post_id, 'paccc_city', isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '' );

	$states = paccc_md_states();
	$state  = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
	update_post_meta( $post_id, 'paccc_state', isset( $states[ $state ] ) ? $state : '' );

	update_post_meta( $post_id, 'paccc_zip', isset( $_POST['zip'] ) ? sanitize_text_field( wp_unslash( $_POST['zip'] ) ) : '' );

	$website = isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '';
	update_post_meta( $post_id, 'paccc_website', $website );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	update_post_meta( $post_id, 'paccc_email', is_email( $email ) ? $email : '' );

	// Image: delete on request, otherwise upload a new one (which replaces any
	// existing member-owned image). Handled after fields so a bad upload only
	// affects the image, not the rest of the save.
	$img_error = false;
	if ( ! empty( $_POST['paccc_image_delete'] ) ) {
		paccc_md_set_member_image( $post_id, 0 );
	} elseif ( ! empty( $_FILES['paccc_image']['name'] ) ) {
		$res = paccc_md_handle_member_image_upload( $post_id, 'paccc_image' );
		if ( is_wp_error( $res ) ) {
			$img_error = true;
		}
	}

	// A member edited their own profile -- refresh the cached directory list.
	paccc_md_flush_directory_cache();

	$args = array( 'paccc_portal' => $img_error ? 'imgerror' : 'updated' );
	wp_safe_redirect( add_query_arg( $args, paccc_md_portal_url() ) );
	exit;
}
add_action( 'admin_post_paccc_md_portal_save', 'paccc_md_portal_save' );

/* ---------------------------------------------------------------------------
 * Keep members out of wp-admin
 * ------------------------------------------------------------------------ */

function paccc_md_is_portal_member( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	return $user && $user->exists()
		&& in_array( PACCC_MD_ROLE, (array) $user->roles, true )
		&& ! user_can( $user, 'manage_options' )
		&& ! user_can( $user, 'edit_posts' );
}

function paccc_md_block_member_admin() {
	// admin_init also fires on admin-ajax.php and admin-post.php, which the
	// portal itself uses -- don't redirect those, only real dashboard views.
	if ( wp_doing_ajax() ) {
		return;
	}
	$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
	if ( 'admin-post.php' === $pagenow || 'admin-ajax.php' === $pagenow ) {
		return;
	}
	if ( paccc_md_is_portal_member() ) {
		wp_safe_redirect( paccc_md_portal_url() );
		exit;
	}
}
add_action( 'admin_init', 'paccc_md_block_member_admin' );

function paccc_md_member_admin_bar( $show ) {
	return paccc_md_is_portal_member() ? false : $show;
}
add_filter( 'show_admin_bar', 'paccc_md_member_admin_bar' );

function paccc_md_member_login_redirect( $redirect_to, $requested, $user ) {
	if ( is_a( $user, 'WP_User' ) && paccc_md_is_portal_member( $user ) ) {
		return paccc_md_portal_url();
	}
	return $redirect_to;
}
add_filter( 'login_redirect', 'paccc_md_member_login_redirect', 10, 3 );

/* ---------------------------------------------------------------------------
 * Admin: per-member "Member Login" meta box
 * ------------------------------------------------------------------------ */

function paccc_md_add_login_meta_box() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_meta_box( 'paccc_md_login', 'Member Login', 'paccc_md_render_login_meta_box', PACCC_MD_CPT, 'side', 'default' );
}
add_action( 'add_meta_boxes', 'paccc_md_add_login_meta_box' );

function paccc_md_render_login_meta_box( $post ) {
	$uid  = paccc_md_user_for_member( $post->ID );
	$user = $uid ? get_userdata( $uid ) : false;
	$base = admin_url( 'admin-post.php' );
	?>
	<?php if ( $user ) : ?>
		<p>
			<strong>Username:</strong> <?php echo esc_html( $user->user_login ); ?><br />
			<strong>Email:</strong> <?php echo $user->user_email ? esc_html( $user->user_email ) : '<em>none on file</em>'; ?>
		</p>

		<?php if ( $user->user_email ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'paccc_md_send_reset', 'post' => $post->ID ), $base ), 'paccc_md_login_' . $post->ID ) ); ?>">Send password reset</a>
			</p>
		<?php else : ?>
			<p class="description">No email on file, so a reset link can&rsquo;t be sent. Set a temporary password below, or add an email and save.</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $base ); ?>">
			<input type="hidden" name="action" value="paccc_md_set_temp_password" />
			<input type="hidden" name="post" value="<?php echo esc_attr( $post->ID ); ?>" />
			<?php wp_nonce_field( 'paccc_md_login_' . $post->ID ); ?>
			<p>
				<label for="paccc-temp-pass"><strong>Set a temporary password</strong></label><br />
				<input type="text" id="paccc-temp-pass" name="temp_password" class="widefat" autocomplete="off" placeholder="Type a password to set" />
			</p>
			<p><button type="submit" class="button">Set password</button></p>
		</form>
	<?php else : ?>
		<p>No login exists for this member yet.</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'paccc_md_create_login', 'post' => $post->ID ), $base ), 'paccc_md_login_' . $post->ID ) ); ?>">Create login</a>
		</p>
		<p class="description">Creates a WordPress account linked to this member. No email is sent; use &ldquo;Send password reset&rdquo; or set a temporary password afterward.</p>
	<?php endif; ?>
	<?php
}

/**
 * Redirect back to a member's edit screen with a status message.
 */
function paccc_md_login_redirect_back( $post_id, $msg ) {
	wp_safe_redirect(
		add_query_arg(
			array( 'paccc_login_msg' => $msg ),
			get_edit_post_link( $post_id, 'url' )
		)
	);
	exit;
}

function paccc_md_handle_create_login() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'paccc_md_login_' . $post_id );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	$res = paccc_md_provision_member_login( $post_id );
	paccc_md_login_redirect_back( $post_id, is_wp_error( $res ) ? 'login_error' : 'login_created' );
}
add_action( 'admin_post_paccc_md_create_login', 'paccc_md_handle_create_login' );

function paccc_md_handle_send_reset() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'paccc_md_login_' . $post_id );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	$uid  = paccc_md_user_for_member( $post_id );
	$user = $uid ? get_userdata( $uid ) : false;
	$msg  = 'reset_error';
	if ( $user && $user->user_email ) {
		$res = retrieve_password( $user->user_login );
		$msg = ( true === $res ) ? 'reset_sent' : 'reset_error';
	}
	paccc_md_login_redirect_back( $post_id, $msg );
}
add_action( 'admin_post_paccc_md_send_reset', 'paccc_md_handle_send_reset' );

function paccc_md_handle_set_temp_password() {
	$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
	check_admin_referer( 'paccc_md_login_' . $post_id );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	$uid  = paccc_md_user_for_member( $post_id );
	$pass = isset( $_POST['temp_password'] ) ? (string) wp_unslash( $_POST['temp_password'] ) : '';
	$msg  = 'temp_error';
	if ( $uid && '' !== trim( $pass ) ) {
		wp_set_password( $pass, $uid );
		$msg = 'temp_set';
	}
	paccc_md_login_redirect_back( $post_id, $msg );
}
add_action( 'admin_post_paccc_md_set_temp_password', 'paccc_md_handle_set_temp_password' );

/**
 * Notices on the member edit screen after a login action.
 */
function paccc_md_login_admin_notice() {
	$screen = get_current_screen();
	if ( ! $screen || PACCC_MD_CPT !== $screen->post_type || 'post' !== $screen->base ) {
		return;
	}
	$msg = isset( $_GET['paccc_login_msg'] ) ? sanitize_key( wp_unslash( $_GET['paccc_login_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$map = array(
		'login_created' => array( 'success', 'Login created for this member.' ),
		'login_error'   => array( 'error', 'The login could not be created (the email may already be in use).' ),
		'reset_sent'    => array( 'success', 'A password-reset email was sent to this member.' ),
		'reset_error'   => array( 'error', 'The password-reset email could not be sent.' ),
		'temp_set'      => array( 'success', 'Temporary password set. Share it with the member securely.' ),
		'temp_error'    => array( 'error', 'The temporary password could not be set.' ),
	);
	if ( isset( $map[ $msg ] ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
	}
}
add_action( 'admin_notices', 'paccc_md_login_admin_notice' );

/* ---------------------------------------------------------------------------
 * Admin: bulk provisioning (settings screen)
 * ------------------------------------------------------------------------ */

/**
 * The "Member logins" section on the settings screen.
 */
function paccc_md_render_logins_section() {
	global $wpdb;
	$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", PACCC_MD_CPT ) );
	$linked    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'paccc_user_id' AND meta_value <> '0' AND meta_value <> ''" );
	$portal_id = (int) get_option( 'paccc_portal_page_id' );
	?>
	<h2>Member logins</h2>
	<p class="description">
		Give members accounts so they can sign in and edit their own details (everything except Member Number, Certifications and CEUs)
		on the page holding the <code>[paccc_member_portal]</code> shortcode<?php echo $portal_id ? ' (<a href="' . esc_url( get_permalink( $portal_id ) ) . '">' . esc_html( get_the_title( $portal_id ) ) . '</a>)' : '' ; ?>.
		Creating logins sends no email &mdash; use &ldquo;Send password reset&rdquo; or set a temporary password on each member&rsquo;s edit screen.
	</p>
	<p><strong><?php echo esc_html( sprintf( '%d of %d published members have a login.', $linked, $total ) ); ?></strong></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="paccc_md_bulk_provision" />
		<?php wp_nonce_field( 'paccc_md_bulk_provision' ); ?>
		<?php submit_button( 'Create logins for members without one', 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

function paccc_md_handle_bulk_provision() {
	check_admin_referer( 'paccc_md_bulk_provision' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$ids = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	$created = 0;
	$failed  = 0;
	foreach ( $ids as $id ) {
		if ( paccc_md_user_for_member( $id ) ) {
			continue;
		}
		$res = paccc_md_provision_member_login( $id );
		if ( is_wp_error( $res ) ) {
			$failed++;
		} else {
			$created++;
		}
	}

	wp_safe_redirect(
		paccc_md_settings_url(
			array(
				'paccc_msg'            => 'logins_created',
				'paccc_logins_created' => $created,
				'paccc_logins_failed'  => $failed,
			)
		)
	);
	exit;
}
add_action( 'admin_post_paccc_md_bulk_provision', 'paccc_md_handle_bulk_provision' );
