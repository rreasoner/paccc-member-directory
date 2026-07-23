<?php
/**
 * PACCC Member Directory -- frontend.
 *
 * [paccc_directory] renders the US map + member list (each entry linking to
 * that member's own page). Single member pages get their own details block
 * and LocalBusiness schema.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Full titles for certification acronyms, used for the frontend legend and
 * the <abbr> tooltips on each certification badge.
 *
 * A title belongs to the certification itself, not to any one member, so it's
 * stored once in the paccc_certification_labels option. The member edit screen
 * writes to that option; the three built-in certifications fall back to their
 * default wording if a saved title is blank, so an accidental clear can't
 * strip the legend.
 */
function paccc_md_cert_labels() {
	$defaults = array(
		'CPACP' => 'Certified Professional Animal Care Provider',
		'CPACM' => 'Certified Professional Animal Care Manager',
		'CPACO' => 'Certified Professional Animal Care Operator',
	);

	$saved = get_option( 'paccc_certification_labels', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	return apply_filters( 'paccc_md_cert_labels', array_merge( $defaults, array_filter( $saved ) ) );
}

/**
 * Formatted address lines for display.
 */
function paccc_md_address_lines( $m ) {
	$city_line = trim( (string) $m->city );
	$st_zip    = trim( $m->state . ' ' . $m->zip );
	if ( $city_line && $st_zip ) {
		$city_line .= ', ' . $st_zip;
	} elseif ( $st_zip ) {
		$city_line = $st_zip;
	}
	return array_filter( array( $m->address1, $m->address2, $city_line ) );
}

/**
 * A lone state or zip isn't a usable map destination -- require a street
 * address, or a city and state together.
 */
function paccc_md_has_address( $m ) {
	return ( '' !== trim( (string) $m->address1 ) )
		|| ( '' !== trim( (string) $m->city ) && '' !== trim( (string) $m->state ) );
}

/**
 * Address string used for Google Maps queries. Address 2 (suite/unit) is
 * omitted because it tends to confuse geocoding.
 */
function paccc_md_map_query( $m ) {
	return implode( ', ', array_filter( array( $m->address1, $m->city, $m->state, $m->zip ) ) );
}

/**
 * Human-friendly form of a website URL for link text: drop the scheme and any
 * trailing slash so "https://example.com/" reads as "example.com".
 */
function paccc_md_display_url( $url ) {
	return untrailingslashit( preg_replace( '#^https?://#i', '', (string) $url ) );
}

/**
 * Website + email rows for a member's detail <dl>. Both are optional, so each
 * row is emitted only when that field is set. Shared by the directory
 * quick-view panel and the single member page so they render identically.
 */
function paccc_md_render_contact_rows( $m ) {
	if ( '' !== trim( (string) $m->website ) ) :
		?>
		<div>
			<dt>Website</dt>
			<dd>
				<a class="paccc-member-contact" href="<?php echo esc_url( $m->website ); ?>" target="_blank" rel="noopener noreferrer nofollow">
					<?php echo esc_html( paccc_md_display_url( $m->website ) ); ?><span class="screen-reader-text"> (opens in a new tab)</span>
				</a>
			</dd>
		</div>
		<?php
	endif;

	if ( '' !== trim( (string) $m->email ) ) :
		?>
		<div>
			<dt>Email</dt>
			<dd><a class="paccc-member-contact" href="<?php echo esc_url( 'mailto:' . $m->email ); ?>"><?php echo esc_html( $m->email ); ?></a></dd>
		</div>
		<?php
	endif;
}

/**
 * Shared asset registration.
 */
function paccc_md_enqueue_frontend( $with_map = false ) {
	$map_settings = paccc_md_map_settings();

	if ( $with_map ) {
		wp_enqueue_style( 'jsvectormap', PACCC_MD_URL . 'assets/vendor/jsvectormap.min.css', array(), '1.7.0' );
		wp_enqueue_script( 'jsvectormap', PACCC_MD_URL . 'assets/vendor/jsvectormap.min.js', array(), '1.7.0', true );
		wp_enqueue_script( 'jsvectormap-us', PACCC_MD_URL . 'assets/vendor/us-aea-en.js', array( 'jsvectormap' ), '1.7.0', true );
	}

	if ( $map_settings['font'] ) {
		$font_url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode( $map_settings['font'] ) .
			':wght@' . rawurlencode( $map_settings['weight'] ) . '&display=swap';
		wp_enqueue_style( 'paccc-md-map-font', $font_url, array(), null );
	}

	wp_enqueue_style( 'paccc-md-frontend', PACCC_MD_URL . 'assets/frontend.css', array(), PACCC_MD_VERSION );
	wp_add_inline_style(
		'paccc-md-frontend',
		'.paccc-directory-wrap,.paccc-member-single{--paccc-accent:' . esc_attr( $map_settings['highlight'] ) . ';}'
	);

	return $map_settings;
}

/* ---------------------------------------------------------------------------
 * Directory shortcode
 * ------------------------------------------------------------------------ */

function paccc_md_shortcode( $atts ) {
	// Record the page hosting the shortcode (used for the back-link on
	// member pages) unless one has been chosen manually.
	if ( ! (int) get_option( 'paccc_directory_page_id' ) && is_singular() && get_the_ID() ) {
		update_option( 'paccc_directory_page_id', (int) get_the_ID() );
	}

	$members = paccc_md_get_members();
	$states  = paccc_md_states();

	$state_counts = array();
	foreach ( $members as $m ) {
		if ( $m->state && isset( $states[ $m->state ] ) ) {
			$state_counts[ $m->state ] = isset( $state_counts[ $m->state ] ) ? $state_counts[ $m->state ] + 1 : 1;
		}
	}

	$map_settings = paccc_md_enqueue_frontend( true );

	wp_enqueue_script( 'paccc-md-frontend', PACCC_MD_URL . 'assets/frontend.js', array( 'jsvectormap-us' ), PACCC_MD_VERSION, true );
	wp_localize_script(
		'paccc-md-frontend',
		'PACCC_DIR',
		array(
			'counts'     => $state_counts,
			'names'      => $states,
			'highlight'  => $map_settings['highlight'],
			'fontFamily' => $map_settings['font'],
			'fontWeight' => $map_settings['weight'],
			'perPage'    => (int) apply_filters( 'paccc_md_per_page', 20 ),
		)
	);

	ob_start();

	$schema = paccc_md_directory_schema( $members );
	if ( $schema ) {
		echo '<script type="application/ld+json">' . $schema . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	$terms = paccc_md_terms_schema();
	if ( $terms ) {
		echo '<script type="application/ld+json">' . $terms . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	?>
	<div class="paccc-directory-wrap">
		<div id="paccc-map" class="paccc-map" role="img" aria-label="Map of the United States highlighting states with PACCC members"></div>
		<p class="paccc-map-hint">Tap a highlighted state to meet its members &mdash; or browse below.</p>

		<div class="paccc-directory-panel">
			<div class="paccc-controls">
				<label for="paccc-state-filter">Browse by state</label>
				<select id="paccc-state-filter">
					<option value="">All States</option>
					<?php foreach ( $states as $code => $name ) : ?>
						<?php $count = isset( $state_counts[ $code ] ) ? (int) $state_counts[ $code ] : 0; ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $count ? $name . ' (' . $count . ')' : $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="paccc-alpha-filter" role="group" aria-label="Filter members by first letter of business name">
				<span class="paccc-alpha-label">Jump to a letter</span>
				<button type="button" class="paccc-alpha paccc-alpha-current" data-letter="" aria-pressed="true">All</button>
				<?php foreach ( range( 'A', 'Z' ) as $letter ) : ?>
					<button type="button" class="paccc-alpha" data-letter="<?php echo esc_attr( $letter ); ?>" aria-pressed="false"><?php echo esc_html( $letter ); ?></button>
				<?php endforeach; ?>
			</div>

			<p class="paccc-status" role="status" aria-live="polite"></p>

			<?php
			$legend = array_intersect_key( paccc_md_cert_labels(), array_flip( paccc_md_certifications() ) );
			?>
			<?php if ( $legend ) : ?>
				<?php
				/*
				 * A <dl> is the correct element for term/definition pairs: better
				 * for screen readers, and it marks these up as defined terms
				 * rather than an arbitrary list.
				 */
				?>
				<section class="paccc-legend" aria-labelledby="paccc-legend-heading">
					<h2 class="paccc-legend-heading" id="paccc-legend-heading">Certification key</h2>
					<dl class="paccc-legend-list">
						<?php foreach ( $legend as $abbr => $full ) : ?>
							<div class="paccc-legend-item">
								<dt class="paccc-legend-term"><span class="paccc-cert-badge"><?php echo esc_html( $abbr ); ?></span></dt>
								<dd class="paccc-legend-def"><?php echo esc_html( $full ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endif; ?>

			<div class="paccc-members" id="paccc-members">
				<?php if ( ! $members ) : ?>
					<p class="paccc-empty">No members in the directory just yet.</p>
				<?php else : ?>
					<?php
					foreach ( $members as $m ) :
						$certs    = implode( ', ', $m->certifications );
						$panel_id = 'paccc-panel-' . $m->member_number;
						$lines    = paccc_md_address_lines( $m );
						$has_addr = paccc_md_has_address( $m );
						$mapq     = paccc_md_map_query( $m );
						?>
						<?php
						$cert_labels = paccc_md_cert_labels();
						$location    = trim( $m->city . ( $m->city && $m->state ? ', ' : '' ) . $m->state );
						// Bucket for the A-Z name filter: first letter of the business
						// name, uppercased; anything not A-Z (numbers, symbols) is "#".
						$name_first  = strtoupper( substr( remove_accents( trim( (string) $m->business_name ) ), 0, 1 ) );
						$name_letter = ( $name_first >= 'A' && $name_first <= 'Z' ) ? $name_first : '#';
						?>
						<article class="paccc-member" id="member-<?php echo esc_attr( $m->member_number ); ?>" data-state="<?php echo esc_attr( $m->state ); ?>" data-letter="<?php echo esc_attr( $name_letter ); ?>">
							<div class="paccc-member-summary">
								<?php
								/*
								 * Monogram avatar. Decorative only -- the business name sits
								 * right beside it, so it's hidden from screen readers rather
								 * than read out as a stray letter.
								 */
								?>
								<span class="paccc-member-avatar" aria-hidden="true"><?php echo esc_html( '#' === $name_letter ? $name_first : $name_letter ); ?></span>
								<div class="paccc-member-identity">
									<h3 class="paccc-member-name">
										<a href="<?php echo esc_url( $m->permalink ); ?>"><?php echo esc_html( $m->business_name ); ?></a>
									</h3>
									<p class="paccc-member-meta">
										<?php if ( $m->member_name ) : ?>
											<span class="paccc-member-person"><?php echo esc_html( $m->member_name ); ?></span>
										<?php endif; ?>
										<?php if ( $location ) : ?>
											<span class="paccc-member-location"><?php echo esc_html( $location ); ?></span>
										<?php endif; ?>
									</p>
									<?php if ( $m->certifications ) : ?>
										<ul class="paccc-cert-list">
											<?php foreach ( $m->certifications as $cert ) : ?>
												<li class="paccc-cert">
													<?php if ( isset( $cert_labels[ $cert ] ) ) : ?>
														<abbr title="<?php echo esc_attr( $cert_labels[ $cert ] ); ?>"><?php echo esc_html( $cert ); ?></abbr>
													<?php else : ?>
														<?php echo esc_html( $cert ); ?>
													<?php endif; ?>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
									<div class="paccc-member-actions">
										<button type="button" class="paccc-view-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">Quick view</button>
										<a class="paccc-member-page-btn" href="<?php echo esc_url( $m->permalink ); ?>">View Member Page</a>
									</div>
								</div>
							</div>
							<div class="paccc-member-panel" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
								<dl class="paccc-member-details">
									<div>
										<dt>Member Number</dt>
										<dd><?php echo esc_html( $m->member_number ); ?></dd>
									</div>
									<div>
										<dt>Business Name</dt>
										<dd><?php echo esc_html( $m->business_name ); ?></dd>
									</div>
									<div>
										<dt>Member Name</dt>
										<dd><?php echo esc_html( $m->member_name ); ?></dd>
									</div>
									<div>
										<dt>Certification(s)</dt>
										<dd><?php echo esc_html( $certs ? $certs : '—' ); ?></dd>
									</div>
									<div>
										<dt>Address</dt>
										<dd>
											<?php echo $lines ? nl2br( esc_html( implode( "\n", $lines ) ) ) : '—'; ?>
											<?php if ( $has_addr ) : ?>
												<a class="paccc-directions" href="<?php echo esc_url( 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $mapq ) ); ?>" target="_blank" rel="noopener noreferrer">
													Get Directions<span class="screen-reader-text"> to <?php echo esc_html( $m->business_name ); ?> (opens in a new tab)</span>
												</a>
											<?php endif; ?>
										</dd>
									</div>
									<?php paccc_md_render_contact_rows( $m ); ?>
								</dl>
								<p class="paccc-member-permalink">
									<a href="<?php echo esc_url( $m->permalink ); ?>">View full member page</a>
								</p>
								<?php if ( $has_addr ) : ?>
									<div class="paccc-map-embed" data-address="<?php echo esc_attr( $mapq ); ?>"></div>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<nav class="paccc-pagination" aria-label="Member directory pages" hidden></nav>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'paccc_directory', 'paccc_md_shortcode' );

/* ---------------------------------------------------------------------------
 * Single member page
 * ------------------------------------------------------------------------ */

/**
 * Append the member details to the (empty) post content on single member
 * pages, so the theme's normal single template renders them.
 */
function paccc_md_single_content( $content ) {
	if ( ! is_singular( PACCC_MD_CPT ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$m = paccc_md_get_member( get_the_ID() );
	if ( ! $m ) {
		return $content;
	}

	return $content . paccc_md_member_details_html( $m );
}
add_filter( 'the_content', 'paccc_md_single_content' );

/**
 * The full member detail block (certification pills, details list, contact
 * links, map, and back-to-directory link) as an HTML string. Shared by the
 * the_content injection above and the [paccc_member] shortcode, so a Beaver
 * Themer layout (or any page builder) renders exactly what the built-in
 * single-member template does.
 */
function paccc_md_member_details_html( $m, $show_business_name = false ) {
	paccc_md_enqueue_frontend( false );
	wp_enqueue_script( 'paccc-md-single', PACCC_MD_URL . 'assets/single.js', array(), PACCC_MD_VERSION, true );

	$lines       = paccc_md_address_lines( $m );
	$has_addr    = paccc_md_has_address( $m );
	$mapq        = paccc_md_map_query( $m );
	$dir_page    = (int) get_option( 'paccc_directory_page_id' );
	$dir_link    = $dir_page ? get_permalink( $dir_page ) : '';
	$cert_labels = paccc_md_cert_labels();

	ob_start();
	?>
	<div class="paccc-member-single">
		<div class="paccc-member-card">
			<?php if ( $m->certifications ) : ?>
				<ul class="paccc-cert-list">
					<?php foreach ( $m->certifications as $cert ) : ?>
						<li class="paccc-cert">
							<?php if ( isset( $cert_labels[ $cert ] ) ) : ?>
								<abbr title="<?php echo esc_attr( $cert_labels[ $cert ] ); ?>"><?php echo esc_html( $cert ); ?></abbr>
							<?php else : ?>
								<?php echo esc_html( $cert ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php
			/*
			 * On the built-in single template the business name is the page's
			 * H1, so it's dropped here to avoid repeating it ($show_business_name
			 * is false). The [paccc_member] shortcode has no such heading, so it
			 * passes true and the name shows above Member Name.
			 * Certification(s) is dropped too -- shown as pills above instead.
			 * Member Number stays last and de-emphasized rather than removed
			 * outright, since it's still useful for e.g. a member
			 * cross-checking their own certificate.
			 */
			?>
			<dl class="paccc-member-details">
				<?php if ( $show_business_name && '' !== trim( (string) $m->business_name ) ) : ?>
					<div>
						<dt>Business Name</dt>
						<dd><?php echo esc_html( $m->business_name ); ?></dd>
					</div>
				<?php endif; ?>
				<div>
					<dt>Member Name</dt>
					<dd><?php echo esc_html( $m->member_name ); ?></dd>
				</div>
				<div>
					<dt>Address</dt>
					<dd>
						<?php echo $lines ? nl2br( esc_html( implode( "\n", $lines ) ) ) : '—'; ?>
						<?php if ( $has_addr ) : ?>
							<br>
							<a class="paccc-directions" href="<?php echo esc_url( 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $mapq ) ); ?>" target="_blank" rel="noopener noreferrer">
								Get Directions<span class="screen-reader-text"> to <?php echo esc_html( $m->business_name ); ?> (opens in a new tab)</span>
							</a>
						<?php endif; ?>
					</dd>
				</div>
				<?php paccc_md_render_contact_rows( $m ); ?>
				<div class="paccc-member-number-row">
					<dt>Member Number</dt>
					<dd><?php echo esc_html( $m->member_number ); ?></dd>
				</div>
			</dl>

			<?php if ( $has_addr ) : ?>
				<h2 class="paccc-map-label">Location</h2>
				<div class="paccc-map-embed" data-address="<?php echo esc_attr( $mapq ); ?>"></div>
			<?php endif; ?>
		</div>

		<?php if ( $dir_link ) : ?>
			<p class="paccc-back-link"><a href="<?php echo esc_url( $dir_link ); ?>">&laquo; Back to all members</a></p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [paccc_member] -- the full member detail block for a page builder or a
 * Beaver Themer singular layout. With no attributes it renders the current
 * member (the queried object on a member page); pass id="123" to target a
 * specific member post.
 */
function paccc_md_member_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'paccc_member' );

	$id = (int) $atts['id'];
	if ( ! $id ) {
		$id = (int) get_the_ID();
	}
	if ( ! $id ) {
		$id = (int) get_queried_object_id();
	}

	$m = paccc_md_get_member( $id );
	// Show the business name (the built-in template omits it as its H1, but a
	// shortcode placement has no guaranteed heading).
	return $m ? paccc_md_member_details_html( $m, true ) : '';
}
add_shortcode( 'paccc_member', 'paccc_md_member_shortcode' );

/**
 * Resolve the member a field shortcode targets: an explicit id="123" attribute,
 * else the current member in the loop, else the queried object. Also enqueues
 * the frontend stylesheet so pills / links / buttons are styled wherever a
 * field shortcode is dropped. Returns the member object, or null.
 */
function paccc_md_shortcode_target( $atts ) {
	$id = isset( $atts['id'] ) ? (int) $atts['id'] : 0;
	if ( ! $id ) {
		$id = (int) get_the_ID();
	}
	if ( ! $id ) {
		$id = (int) get_queried_object_id();
	}

	$m = paccc_md_get_member( $id );
	if ( $m ) {
		paccc_md_enqueue_frontend( false );
	}
	return $m;
}

/**
 * [paccc_member_field key="member_name"] -- a single member field as plain
 * text, for placing individual values in separate page-builder modules.
 *
 * Keys: business_name, member_name, member_number, address1, address2, city,
 * state, zip, location, address, website, email, permalink. For state, add
 * format="name" to output the full state name instead of the 2-letter code.
 */
function paccc_md_field_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'key' => '', 'id' => 0, 'format' => '' ), $atts, 'paccc_member_field' );
	$m    = paccc_md_shortcode_target( $atts );
	if ( ! $m ) {
		return '';
	}

	switch ( sanitize_key( $atts['key'] ) ) {
		case 'business_name':
		case 'name':
			return esc_html( $m->business_name );
		case 'member_name':
			return esc_html( $m->member_name );
		case 'member_number':
			return esc_html( $m->member_number );
		case 'address1':
			return esc_html( $m->address1 );
		case 'address2':
			return esc_html( $m->address2 );
		case 'city':
			return esc_html( $m->city );
		case 'zip':
			return esc_html( $m->zip );
		case 'state':
			if ( 'name' === $atts['format'] ) {
				$states = paccc_md_states();
				return esc_html( isset( $states[ $m->state ] ) ? $states[ $m->state ] : $m->state );
			}
			return esc_html( $m->state );
		case 'location':
			return esc_html( trim( $m->city . ( $m->city && $m->state ? ', ' : '' ) . $m->state ) );
		case 'address':
			$lines = paccc_md_address_lines( $m );
			return $lines ? nl2br( esc_html( implode( "\n", $lines ) ) ) : '';
		case 'website':
			return esc_html( $m->website );
		case 'email':
			return esc_html( $m->email );
		case 'permalink':
		case 'url':
			return esc_url( $m->permalink );
	}
	return '';
}
add_shortcode( 'paccc_member_field', 'paccc_md_field_shortcode' );

/**
 * [paccc_member_business_name] -- the member's business name (the post title)
 * as plain text, for a page-builder heading element.
 *
 * Falls back to the member's name when no business name is set, so an H1 built
 * from this shortcode is never left empty.
 */
function paccc_md_business_name_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'paccc_member_business_name' );
	$m    = paccc_md_shortcode_target( $atts );
	if ( ! $m ) {
		return '';
	}

	$name = trim( (string) $m->business_name );
	if ( '' === $name ) {
		$name = trim( (string) $m->member_name );
	}
	return esc_html( $name );
}
add_shortcode( 'paccc_member_business_name', 'paccc_md_business_name_shortcode' );

/**
 * [paccc_member_certifications] -- the certification pills (same markup as the
 * directory and single-member views), or nothing if the member has none.
 */
function paccc_md_certifications_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'paccc_member_certifications' );
	$m    = paccc_md_shortcode_target( $atts );
	if ( ! $m || ! $m->certifications ) {
		return '';
	}

	$cert_labels = paccc_md_cert_labels();
	$out         = '<ul class="paccc-cert-list">';
	foreach ( $m->certifications as $cert ) {
		$out .= '<li class="paccc-cert">';
		if ( isset( $cert_labels[ $cert ] ) ) {
			$out .= '<abbr title="' . esc_attr( $cert_labels[ $cert ] ) . '">' . esc_html( $cert ) . '</abbr>';
		} else {
			$out .= esc_html( $cert );
		}
		$out .= '</li>';
	}
	$out .= '</ul>';
	return $out;
}
add_shortcode( 'paccc_member_certifications', 'paccc_md_certifications_shortcode' );

/**
 * [paccc_member_website] -- the member's website as a link (empty if not set).
 * Link text defaults to the bare domain; override with text="Visit site".
 */
function paccc_md_website_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'text' => '' ), $atts, 'paccc_member_website' );
	$m    = paccc_md_shortcode_target( $atts );
	if ( ! $m || '' === trim( (string) $m->website ) ) {
		return '';
	}

	$text = '' !== $atts['text'] ? $atts['text'] : paccc_md_display_url( $m->website );
	return '<a class="paccc-member-contact" href="' . esc_url( $m->website ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( $text ) . '</a>';
}
add_shortcode( 'paccc_member_website', 'paccc_md_website_shortcode' );

/**
 * [paccc_member_email] -- the member's email as a mailto link (empty if unset).
 */
function paccc_md_email_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'text' => '' ), $atts, 'paccc_member_email' );
	$m    = paccc_md_shortcode_target( $atts );
	if ( ! $m || '' === trim( (string) $m->email ) ) {
		return '';
	}

	$text = '' !== $atts['text'] ? $atts['text'] : $m->email;
	return '<a class="paccc-member-contact" href="' . esc_url( 'mailto:' . $m->email ) . '">' . esc_html( $text ) . '</a>';
}
add_shortcode( 'paccc_member_email', 'paccc_md_email_shortcode' );

/**
 * [paccc_member_directions] -- the "Get Directions" button/link to Google Maps
 * (empty if the member has no usable address). Override label with text="...".
 */
function paccc_md_directions_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'text' => 'Get Directions' ), $atts, 'paccc_member_directions' );
	$m    = paccc_md_shortcode_target( $atts );
	if ( ! $m || ! paccc_md_has_address( $m ) ) {
		return '';
	}

	$url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( paccc_md_map_query( $m ) );
	return '<a class="paccc-directions" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
		. esc_html( $atts['text'] )
		. '<span class="screen-reader-text"> to ' . esc_html( $m->business_name ) . ' (opens in a new tab)</span></a>';
}
add_shortcode( 'paccc_member_directions', 'paccc_md_directions_shortcode' );

/**
 * Use the plugin's stripped-down single template (no sidebar, no author, no
 * date) for member pages.
 *
 * WordPress checks the theme first, so if the theme supplies its own
 * single-paccc_member.php we leave it alone — that's the supported way to
 * customize this without editing the plugin.
 */
function paccc_md_single_template( $template ) {
	if ( ! is_singular( PACCC_MD_CPT ) ) {
		return $template;
	}

	if ( locate_template( array( 'single-' . PACCC_MD_CPT . '.php' ) ) ) {
		return $template;
	}

	$plugin_template = PACCC_MD_DIR . 'templates/single-' . PACCC_MD_CPT . '.php';
	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'template_include', 'paccc_md_single_template' );

/**
 * Body classes for theme-specific tweaks on member pages.
 */
function paccc_md_body_class( $classes ) {
	if ( is_singular( PACCC_MD_CPT ) ) {
		$classes[] = 'paccc-member-page';
		$classes[] = 'paccc-no-sidebar';
	}
	return $classes;
}
add_filter( 'body_class', 'paccc_md_body_class' );

/* ---------------------------------------------------------------------------
 * Structured data
 * ------------------------------------------------------------------------ */

/**
 * LocalBusiness node for one member.
 */
function paccc_md_business_schema( $m ) {
	$business = array(
		'@type' => 'LocalBusiness',
		'name'  => $m->business_name,
		'url'   => $m->permalink,
	);

	// The member's own website (when set) is the same real-world entity as this
	// listing; sameAs links the two without displacing the permalink as `url`.
	if ( '' !== trim( (string) $m->website ) ) {
		$business['sameAs'] = array( $m->website );
	}
	if ( '' !== trim( (string) $m->email ) ) {
		$business['email'] = $m->email;
	}

	if ( paccc_md_has_address( $m ) ) {
		$states  = paccc_md_states();
		$street  = trim( $m->address1 . ' ' . $m->address2 );
		$address = array( '@type' => 'PostalAddress' );

		if ( '' !== $street ) {
			$address['streetAddress'] = $street;
		}
		if ( '' !== trim( (string) $m->city ) ) {
			$address['addressLocality'] = $m->city;
		}
		if ( isset( $states[ $m->state ] ) ) {
			$address['addressRegion'] = $m->state;
		}
		if ( '' !== trim( (string) $m->zip ) ) {
			$address['postalCode'] = $m->zip;
		}
		$address['addressCountry'] = 'US';
		$business['address']       = $address;
	}

	if ( $m->certifications ) {
		$credentials = array();
		foreach ( $m->certifications as $cert ) {
			$credentials[] = array(
				'@type'              => 'EducationalOccupationalCredential',
				'name'               => $cert,
				'credentialCategory' => 'certification',
				'recognizedBy'       => array(
					'@type' => 'Organization',
					'name'  => 'Professional Animal Care Certification Council',
				),
			);
		}
		$business['hasCredential'] = $credentials;
	}

	return $business;
}

/**
 * DefinedTermSet schema for the certification key.
 *
 * Tells search engines that CPACP (etc.) are formally defined credentials
 * issued by PACCC, rather than arbitrary strings — the entity relationship
 * that helps the council rank for its own certification names.
 */
function paccc_md_terms_schema() {
	$labels = array_intersect_key( paccc_md_cert_labels(), array_flip( paccc_md_certifications() ) );
	if ( ! $labels ) {
		return '';
	}

	$terms = array();
	foreach ( $labels as $abbr => $full ) {
		$terms[] = array(
			'@type'       => 'DefinedTerm',
			'name'        => $full,
			'alternateName' => $abbr,
			'description' => sprintf( '%s (%s) is a professional certification issued by the Professional Animal Care Certification Council.', $full, $abbr ),
		);
	}

	return wp_json_encode(
		array(
			'@context'      => 'https://schema.org',
			'@type'         => 'DefinedTermSet',
			'name'          => 'PACCC Certifications',
			'creator'       => array(
				'@type' => 'Organization',
				'name'  => 'Professional Animal Care Certification Council',
			),
			'hasDefinedTerm' => $terms,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
	);
}

/**
 * ItemList schema for the directory page, linking out to each member page.
 */
function paccc_md_directory_schema( $members ) {
	$items = array();
	$pos   = 0;

	foreach ( $members as $m ) {
		if ( '' === trim( (string) $m->business_name ) ) {
			continue;
		}
		$pos++;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'url'      => $m->permalink,
			'item'     => paccc_md_business_schema( $m ),
		);
	}

	if ( ! $items ) {
		return '';
	}

	return wp_json_encode(
		array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => 'PACCC Certified Member Directory',
			'numberOfItems'   => count( $items ),
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'itemListElement' => $items,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
	);
}

/**
 * Standalone LocalBusiness schema in the head of each member page.
 */
function paccc_md_single_schema() {
	if ( ! is_singular( PACCC_MD_CPT ) ) {
		return;
	}
	$m = paccc_md_get_member( get_queried_object_id() );
	if ( ! $m ) {
		return;
	}

	$data = paccc_md_business_schema( $m );
	$data = array_merge( array( '@context' => 'https://schema.org' ), $data );

	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'wp_head', 'paccc_md_single_schema' );

/**
 * Give member pages a useful meta description when no SEO plugin sets one.
 */
function paccc_md_meta_description() {
	if ( ! is_singular( PACCC_MD_CPT ) ) {
		return;
	}
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
		return; // Let the SEO plugin own this.
	}

	$m = paccc_md_get_member( get_queried_object_id() );
	if ( ! $m ) {
		return;
	}

	$bits = array_filter(
		array(
			$m->business_name,
			$m->member_name,
			$m->certifications ? 'PACCC certified: ' . implode( ', ', $m->certifications ) : '',
			trim( $m->city . ( $m->city && $m->state ? ', ' : '' ) . $m->state ),
		)
	);

	echo '<meta name="description" content="' . esc_attr( wp_trim_words( implode( '. ', $bits ), 30 ) ) . '" />' . "\n";
}
add_action( 'wp_head', 'paccc_md_meta_description', 1 );
