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

/* ---------------------------------------------------------------------------
 * Member image (logo / photo)
 * ------------------------------------------------------------------------ */

/**
 * URL of a member's image at the requested size, or '' if none.
 */
function paccc_md_member_image_url( $image_id, $size = 'medium' ) {
	$image_id = (int) $image_id;
	if ( ! $image_id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $image_id, $size );
	return $url ? $url : '';
}

/**
 * Delete an attachment only when it belongs to (was uploaded for) this member,
 * so replacing / removing a member's photo never deletes an admin-picked Media
 * Library image shared elsewhere.
 */
function paccc_md_delete_owned_attachment( $att_id, $member_post_id ) {
	$att_id = (int) $att_id;
	if ( ! $att_id ) {
		return;
	}
	$att = get_post( $att_id );
	if ( $att && 'attachment' === $att->post_type && (int) $att->post_parent === (int) $member_post_id ) {
		wp_delete_attachment( $att_id, true );
	}
}

/**
 * Point a member at a new image, cleaning up the previous member-owned one.
 */
function paccc_md_set_member_image( $member_post_id, $new_att_id ) {
	$new_att_id = (int) $new_att_id;
	$old        = (int) get_post_meta( $member_post_id, 'paccc_image_id', true );
	if ( $old && $old !== $new_att_id ) {
		paccc_md_delete_owned_attachment( $old, $member_post_id );
	}
	if ( $new_att_id ) {
		update_post_meta( $member_post_id, 'paccc_image_id', $new_att_id );
	} else {
		delete_post_meta( $member_post_id, 'paccc_image_id' );
	}
	paccc_md_flush_directory_cache();
}

/**
 * Handle a front-end/back-end image upload for a member. Returns the new
 * attachment id, 0 if no file, or WP_Error on a rejected/failed upload.
 * $field is the $_FILES key.
 */
function paccc_md_handle_member_image_upload( $member_post_id, $field ) {
	if ( empty( $_FILES[ $field ]['name'] ) ) {
		return 0;
	}
	if ( ! isset( $_FILES[ $field ]['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
		return new WP_Error( 'paccc_upload', 'The image did not upload. Please try again.' );
	}

	$file    = $_FILES[ $field ];
	$check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	$allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
		return new WP_Error( 'paccc_image_type', 'Please upload a JPG, PNG, GIF or WebP image.' );
	}
	$max = (int) apply_filters( 'paccc_md_max_image_bytes', 5 * 1024 * 1024 );
	if ( isset( $file['size'] ) && (int) $file['size'] > $max ) {
		return new WP_Error( 'paccc_image_size', 'That image is too large (5 MB max).' );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$att_id = media_handle_upload( $field, $member_post_id );
	if ( is_wp_error( $att_id ) ) {
		return $att_id;
	}
	paccc_md_set_member_image( $member_post_id, $att_id );
	return $att_id;
}

/**
 * Website + email rows for a member's detail <dl>. Both are optional, so each
 * row is emitted only when that field is set. Shared by the directory
 * quick-view panel and the single member page so they render identically.
 */
function paccc_md_render_contact_rows( $m ) {
	if ( '' !== trim( (string) $m->website ) ) :
		?>
		<div class="paccc-member-website-row">
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
		<div class="paccc-member-email-row">
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
	// heading="0" / intro="0" hide the built-in heading and/or intro sentence,
	// independently -- e.g. a Themer layout with its own H1 can drop the
	// duplicate heading (heading="0") while keeping the intro sentence.
	$atts         = shortcode_atts( array( 'heading' => '1', 'intro' => '1' ), $atts, 'paccc_directory' );
	$paccc_falsey = array( '0', 'false', 'no' );
	$show_heading = ! in_array( strtolower( trim( (string) $atts['heading'] ) ), $paccc_falsey, true );
	$show_intro   = ! in_array( strtolower( trim( (string) $atts['intro'] ) ), $paccc_falsey, true );

	// Record the page hosting the shortcode (used for the back-link on
	// member pages) unless one has been chosen manually.
	if ( ! (int) get_option( 'paccc_directory_page_id' ) && is_singular() && get_the_ID() ) {
		update_option( 'paccc_directory_page_id', (int) get_the_ID() );
	}

	$states = paccc_md_states();

	// The member list, its JSON-LD, and the derived state/country facets are
	// costly to build on every hit but change only when a member changes, so
	// cache them. paccc_md_flush_directory_cache() clears this on any change.
	$cache = get_transient( 'paccc_md_directory_cache' );
	if ( ! is_array( $cache ) || empty( $cache['v'] ) || 1 !== (int) $cache['v'] ) {
		$members = paccc_md_get_members();

		// Prime attachment (logo) caches in one pass so per-member image lookups
		// don't each hit the database.
		$paccc_img_ids = array();
		foreach ( $members as $m ) {
			if ( $m->image_id ) {
				$paccc_img_ids[] = (int) $m->image_id;
			}
		}
		if ( $paccc_img_ids ) {
			_prime_post_caches( array_values( array_unique( $paccc_img_ids ) ), false, true );
		}

		$state_counts = array();
		foreach ( $members as $m ) {
			if ( $m->state && isset( $states[ $m->state ] ) ) {
				$state_counts[ $m->state ] = isset( $state_counts[ $m->state ] ) ? $state_counts[ $m->state ] + 1 : 1;
			}
		}

		// Non-US members drive the "Browse outside the U.S." dropdown: a tree of
		// country => [ name, total, regions[ regionKey => count ] ].
		$countries    = paccc_md_countries();
		$country_tree = array();
		foreach ( $members as $m ) {
			$code = $m->country ? $m->country : 'US';
			if ( 'US' === $code || ! isset( $countries[ $code ] ) ) {
				continue;
			}
			if ( ! isset( $country_tree[ $code ] ) ) {
				$country_tree[ $code ] = array(
					'name'    => $countries[ $code ],
					'total'   => 0,
					'regions' => array(),
				);
			}
			$country_tree[ $code ]['total']++;
			$region = paccc_md_member_region_key( $m );
			if ( '' !== $region ) {
				$country_tree[ $code ]['regions'][ $region ] = isset( $country_tree[ $code ]['regions'][ $region ] ) ? $country_tree[ $code ]['regions'][ $region ] + 1 : 1;
			}
		}
		uksort(
			$country_tree,
			static function ( $a, $b ) use ( $countries ) {
				return strcasecmp( $countries[ $a ], $countries[ $b ] );
			}
		);
		foreach ( $country_tree as &$paccc_ct ) {
			uksort( $paccc_ct['regions'], 'strcasecmp' );
		}
		unset( $paccc_ct );

		$total        = count( $members );
		$members_html = paccc_md_render_members_list( $members );

		$schema_html = '';
		$paccc_sc    = paccc_md_directory_schema( $members );
		if ( $paccc_sc ) {
			$schema_html .= '<script type="application/ld+json">' . $paccc_sc . '</script>';
		}
		$paccc_tm = paccc_md_terms_schema();
		if ( $paccc_tm ) {
			$schema_html .= '<script type="application/ld+json">' . $paccc_tm . '</script>';
		}

		set_transient(
			'paccc_md_directory_cache',
			array(
				'v'            => 1,
				'members_html' => $members_html,
				'schema'       => $schema_html,
				'state_counts' => $state_counts,
				'country_tree' => $country_tree,
				'total'        => $total,
			),
			DAY_IN_SECONDS
		);
	} else {
		$members_html = $cache['members_html'];
		$schema_html  = $cache['schema'];
		$state_counts = $cache['state_counts'];
		$country_tree = $cache['country_tree'];
		$total        = (int) $cache['total'];
	}

	$map_settings = paccc_md_enqueue_frontend( true );

	// State pre-selected from the URL (e.g. /paccc-certified-members/texas/). The
	// basePath + slugs let frontend.js keep the address bar in sync with the
	// dropdown/map via the History API without a page reload.
	$active_state = paccc_md_current_state_code();
	$dir_path     = paccc_md_directory_path();

	// Per-country province maps, lazy-loaded on selection. Only countries with a
	// bundled map file + real subdivisions; city-states fall back to the list.
	$country_maps = array();
	$ca_codes     = paccc_md_ca_province_codes();
	if ( isset( $country_tree['CA'] ) ) {
		$ca_regions = array();
		foreach ( $country_tree['CA']['regions'] as $rname => $rcount ) {
			if ( isset( $ca_codes[ $rname ] ) ) {
				$ca_regions[ $ca_codes[ $rname ] ] = array( 'name' => $rname, 'count' => (int) $rcount );
			}
		}
		if ( $ca_regions ) {
			$ca_names = array();
			foreach ( $ca_codes as $nm => $cd ) {
				if ( ! isset( $ca_names[ $cd ] ) ) {
					$ca_names[ $cd ] = $nm;
				}
			}
			$country_maps['CA'] = array(
				'map'     => 'canada',
				'file'    => PACCC_MD_URL . 'assets/vendor/maps/canada.js',
				'regions' => $ca_regions,
				'names'   => $ca_names,
				'w'       => 800,
				'h'       => 681,
			);
		}
	}

	wp_enqueue_script( 'paccc-md-frontend', PACCC_MD_URL . 'assets/frontend.js', array( 'jsvectormap-us' ), PACCC_MD_VERSION, true );
	wp_localize_script(
		'paccc-md-frontend',
		'PACCC_DIR',
		array(
			'counts'       => $state_counts,
			'names'        => $states,
			'slugs'        => paccc_md_state_slugs(),
			'basePath'     => $dir_path ? '/' . $dir_path . '/' : '',
			'initialState' => $active_state,
			'highlight'    => $map_settings['highlight'],
			'fontFamily'   => $map_settings['font'],
			'fontWeight'   => $map_settings['weight'],
			'perPage'      => (int) apply_filters( 'paccc_md_per_page', 20 ),
			'countryMaps'  => $country_maps,
		)
	);

	ob_start();

	echo $schema_html; // phpcs:ignore WordPress.Security.EscapeOutput
	?>
	<div class="paccc-directory-wrap">
		<?php
		/*
		 * Directory heading + intro. On a state URL these are state-specific
		 * ("...in Texas") for distinct, crawlable content; on the unfiltered
		 * directory they fall back to a generic heading and the total member
		 * count. frontend.js keeps them in step as the visitor filters.
		 * heading="0"/intro="0" drop either independently when a page builder
		 * supplies its own.
		 */
		if ( $show_heading ) :
			$paccc_heading_text = $active_state ? paccc_md_state_title_text( $active_state ) : paccc_md_directory_heading_text();
			?>
			<h2 class="paccc-state-heading"><?php echo esc_html( $paccc_heading_text ); ?></h2>
			<?php
		endif;
		if ( $show_intro ) :
			$paccc_intro_text = $active_state
				? paccc_md_state_intro_text( $active_state, isset( $state_counts[ $active_state ] ) ? (int) $state_counts[ $active_state ] : 0 )
				: paccc_md_all_members_intro_text( $total );
			?>
			<p class="paccc-state-intro"><?php echo esc_html( $paccc_intro_text ); ?></p>
			<?php
		endif;
		?>

		<div id="paccc-map" class="paccc-map" role="img" aria-label="Map of the United States highlighting states with PACCC members"></div>
		<p class="paccc-map-hint">Tap a highlighted state to meet its members &mdash; or browse below.</p>

		<?php if ( $country_tree ) : ?>
			<div class="paccc-country-chips" role="group" aria-label="Countries with certified members outside the United States">
				<span class="paccc-country-chips-label">Also certified in:</span>
				<?php foreach ( $country_tree as $paccc_cc => $paccc_cinfo ) : ?>
					<button type="button" class="paccc-country-chip" data-country="<?php echo esc_attr( $paccc_cc ); ?>" aria-pressed="false">
						<?php echo esc_html( $paccc_cinfo['name'] ); ?> <span class="paccc-country-chip-count">(<?php echo (int) $paccc_cinfo['total']; ?>)</span>
					</button>
				<?php endforeach; ?>
			</div>
			<?php // A country's province map is lazy-loaded into this box when a country with a map is selected. ?>
			<div id="paccc-country-map" class="paccc-country-map" hidden></div>
		<?php endif; ?>

		<div class="paccc-directory-panel">
			<div class="paccc-controls">
				<label for="paccc-state-filter">Browse by state</label>
				<select id="paccc-state-filter">
					<option value="">All States</option>
					<?php foreach ( $states as $code => $name ) : ?>
						<?php $count = isset( $state_counts[ $code ] ) ? (int) $state_counts[ $code ] : 0; ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $active_state, $code ); ?>><?php echo esc_html( $count ? $name . ' (' . $count . ')' : $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				/*
				 * Link to the selected state's own URL (/paccc-certified-members/texas/).
				 * The dropdown already updates the address bar via the History
				 * API, but this is the visible affordance for landing on -- and
				 * sharing -- the dedicated, indexable state page. Shown only when
				 * a state is active; frontend.js keeps its href/visibility synced.
				 */
				?>
				<a class="paccc-view-state" href="<?php echo $active_state ? esc_url( paccc_md_state_url( $active_state ) ) : '#'; ?>"<?php echo $active_state ? '' : ' hidden'; ?>>View State Page</a>

				<?php if ( $country_tree ) : ?>
					<label for="paccc-country-filter" class="paccc-country-label">Browse outside the U.S.</label>
					<select id="paccc-country-filter">
						<option value="">Outside the U.S.</option>
						<?php foreach ( $country_tree as $cc => $info ) : ?>
							<optgroup label="<?php echo esc_attr( $info['name'] ); ?>">
								<option value="<?php echo esc_attr( $cc ); ?>"><?php echo esc_html( 'All of ' . $info['name'] . ' (' . (int) $info['total'] . ')' ); ?></option>
								<?php foreach ( $info['regions'] as $paccc_region_key => $rcount ) : ?>
									<option value="<?php echo esc_attr( $cc . '|' . $paccc_region_key ); ?>"><?php echo esc_html( $paccc_region_key . ' (' . (int) $rcount . ')' ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
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

			<?php echo $members_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<nav class="paccc-pagination" aria-label="Member directory pages" hidden></nav>
		</div>

		<?php
		/*
		 * Crawlable per-state links. The dropdown/map filter client-side, so
		 * these real <a> tags are how search engines (and AI crawlers) actually
		 * discover the /paccc-certified-members/{state}/ pages. Only states with members.
		 */
		$paccc_linked_states = array_keys( array_intersect_key( $states, $state_counts ) );
		?>
		<?php if ( $paccc_linked_states ) : ?>
			<nav class="paccc-state-links" aria-label="Browse certified members by state">
				<h2 class="paccc-state-links-heading">Browse certified members by state</h2>
				<ul class="paccc-state-links-list">
					<?php foreach ( $paccc_linked_states as $paccc_lcode ) : ?>
						<li>
							<a href="<?php echo esc_url( paccc_md_state_url( $paccc_lcode ) ); ?>">
								<?php echo esc_html( $states[ $paccc_lcode ] ); ?>
								<span class="paccc-state-links-count">(<?php echo (int) $state_counts[ $paccc_lcode ]; ?>)</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'paccc_directory', 'paccc_md_shortcode' );

/**
 * The directory member-list markup as a string, so the shortcode can cache it.
 */
function paccc_md_render_members_list( $members ) {
	$countries = paccc_md_countries();
	ob_start();
	?>
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
						$cert_labels  = paccc_md_cert_labels();
						$country_code = $m->country ? $m->country : 'US';
						$is_intl      = ( 'US' !== $country_code && isset( $countries[ $country_code ] ) );
						// Region key groups the member in the "outside the U.S." dropdown.
						$region_key   = $is_intl ? paccc_md_member_region_key( $m ) : '';
						if ( $is_intl ) {
							// International location: City, Province, Country (no US state).
							$bits = array();
							if ( $m->city ) {
								$bits[] = $m->city;
							}
							$prov = trim( (string) $m->region );
							if ( $prov && 0 !== strcasecmp( $prov, (string) $m->city ) ) {
								$bits[] = $prov;
							}
							$bits[]   = $countries[ $country_code ];
							$location = implode( ', ', $bits );
						} else {
							$location = trim( $m->city . ( $m->city && $m->state ? ', ' : '' ) . $m->state );
						}
						// Bucket for the A-Z name filter: first letter of the business
						// name, uppercased; anything not A-Z (numbers, symbols) is "#".
						$name_first  = strtoupper( substr( remove_accents( trim( (string) $m->business_name ) ), 0, 1 ) );
						$name_letter = ( $name_first >= 'A' && $name_first <= 'Z' ) ? $name_first : '#';
						?>
						<article class="paccc-member" id="member-<?php echo esc_attr( $m->member_number ); ?>" data-state="<?php echo esc_attr( $m->state ); ?>" data-country="<?php echo esc_attr( $country_code ); ?>" data-region="<?php echo esc_attr( $region_key ); ?>" data-letter="<?php echo esc_attr( $name_letter ); ?>">
							<div class="paccc-member-summary">
								<?php
								/*
								 * Member logo / photo, shown only when the member has one (no monogram
								 * fallback on the listing). Decorative -- the business name sits beside it.
								 */
								?>
								<?php $paccc_logo = paccc_md_logo_img( $m->image_id ); ?>
								<?php if ( $paccc_logo ) : ?>
									<span class="paccc-member-logo" aria-hidden="true"><?php echo $paccc_logo; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<?php endif; ?>
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
								<?php $paccc_panel_img = paccc_md_member_image_url( $m->image_id, 'medium' ); ?>
								<?php if ( $paccc_panel_img ) : ?>
									<div class="paccc-member-panel-image"><img src="<?php echo esc_url( $paccc_panel_img ); ?>" alt="" loading="lazy" /></div>
								<?php endif; ?>
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
									<?php if ( $m->ceus ) : ?>
										<div>
											<dt>CEU(s)</dt>
											<dd><?php echo esc_html( implode( ', ', $m->ceus ) ); ?></dd>
										</div>
									<?php endif; ?>
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
	<?php
	return ob_get_clean();
}

/**
 * Canadian province/territory name => ISO 3166-2 code, matching the region
 * codes in assets/vendor/maps/canada.js. Used to highlight/link map regions.
 */
function paccc_md_ca_province_codes() {
	return array(
		'Alberta'                   => 'CA-AB',
		'British Columbia'          => 'CA-BC',
		'Manitoba'                  => 'CA-MB',
		'New Brunswick'             => 'CA-NB',
		'Newfoundland and Labrador' => 'CA-NL',
		'Northwest Territories'     => 'CA-NT',
		'Nova Scotia'               => 'CA-NS',
		'Nunavut'                   => 'CA-NU',
		'Ontario'                   => 'CA-ON',
		'Prince Edward Island'      => 'CA-PE',
		'Quebec'                    => 'CA-QC',
		'Saskatchewan'              => 'CA-SK',
		'Yukon'                     => 'CA-YT',
		'Yukon Territory'           => 'CA-YT',
	);
}

/**
 * A member logo <img> sized for the directory listing. Uses the dedicated
 * paccc-md-logo size when available (small file), else medium; always emits
 * width/height + lazy loading to avoid layout shift.
 */
function paccc_md_logo_img( $image_id, $sizes = '120px' ) {
	$image_id = (int) $image_id;
	if ( ! $image_id ) {
		return '';
	}
	$meta = wp_get_attachment_metadata( $image_id );
	$size = ( is_array( $meta ) && ! empty( $meta['sizes']['paccc-md-logo'] ) ) ? 'paccc-md-logo' : 'medium';
	return wp_get_attachment_image(
		$image_id,
		$size,
		false,
		array(
			'alt'      => '',
			'loading'  => 'lazy',
			'decoding' => 'async',
			'sizes'    => $sizes,
		)
	);
}

/**
 * A compact, uncropped logo size (applies to new uploads; regenerate
 * thumbnails to apply it to existing images).
 */
function paccc_md_register_image_sizes() {
	add_image_size( 'paccc-md-logo', 240, 240, false );
}
add_action( 'after_setup_theme', 'paccc_md_register_image_sizes' );

/**
 * Directory cache: cleared whenever a member changes so the cached member
 * list/schema rebuilds on the next directory view.
 */
function paccc_md_flush_directory_cache() {
	delete_transient( 'paccc_md_directory_cache' );
	delete_transient( 'paccc_md_stats' );
}
function paccc_md_flush_directory_cache_typed( $post_id ) {
	if ( PACCC_MD_CPT === get_post_type( $post_id ) ) {
		paccc_md_flush_directory_cache();
	}
}
add_action( 'save_post_' . PACCC_MD_CPT, 'paccc_md_flush_directory_cache' );
add_action( 'before_delete_post', 'paccc_md_flush_directory_cache_typed' );
add_action( 'trashed_post', 'paccc_md_flush_directory_cache_typed' );
add_action( 'untrashed_post', 'paccc_md_flush_directory_cache_typed' );

/**
 * Cached directory totals: total members, and the number of distinct
 * states + provinces (non-US regions) that have at least one member. Refreshed
 * by paccc_md_flush_directory_cache() whenever a member changes.
 */
function paccc_md_stats() {
	$stats = get_transient( 'paccc_md_stats' );
	if ( is_array( $stats ) && isset( $stats['members'], $stats['regions'] ) ) {
		return $stats;
	}

	$members   = paccc_md_get_members();
	$states    = array();
	$provinces = array();
	foreach ( $members as $m ) {
		$country = $m->country ? $m->country : 'US';
		if ( 'US' === $country ) {
			if ( $m->state ) {
				$states[ $m->state ] = true;
			}
		} else {
			// Province, falling back to city; namespaced by country so like-named
			// regions in different countries count separately.
			$rk = paccc_md_member_region_key( $m );
			if ( '' !== $rk ) {
				$provinces[ $country . '|' . $rk ] = true;
			}
		}
	}

	$stats = array(
		'members' => count( $members ),
		'regions' => count( $states ) + count( $provinces ),
	);
	set_transient( 'paccc_md_stats', $stats, DAY_IN_SECONDS );
	return $stats;
}

/** [paccc_member_count] — total published members. */
function paccc_md_member_count_shortcode() {
	$stats = paccc_md_stats();
	return esc_html( number_format_i18n( (int) $stats['members'] ) );
}
add_shortcode( 'paccc_member_count', 'paccc_md_member_count_shortcode' );

/** [paccc_state_count] — distinct states + provinces with members. */
function paccc_md_state_count_shortcode() {
	$stats = paccc_md_stats();
	return esc_html( number_format_i18n( (int) $stats['regions'] ) );
}
add_shortcode( 'paccc_state_count', 'paccc_md_state_count_shortcode' );

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
		<?php if ( $dir_link ) : ?>
			<?php
			/*
			 * Breadcrumb, above the card: the way back should be the first thing
			 * on the page, not something to hunt for below a tall map embed.
			 */
			?>
			<nav class="paccc-back-link" aria-label="Breadcrumb">
				<a href="<?php echo esc_url( $dir_link ); ?>">&laquo; Back to all members</a>
			</nav>
		<?php endif; ?>

		<div class="paccc-member-card">
			<?php $paccc_img = paccc_md_member_image_url( $m->image_id, 'large' ); ?>
			<?php if ( $paccc_img ) : ?>
				<div class="paccc-member-image">
					<img src="<?php echo esc_url( $paccc_img ); ?>" alt="<?php echo esc_attr( $m->business_name ); ?>" loading="lazy" />
				</div>
			<?php endif; ?>
			<?php
			/*
			 * On the built-in single template the business name is the page's
			 * H1, so it's dropped here to avoid repeating it ($show_business_name
			 * is false). The [paccc_member] shortcode has no such heading, so it
			 * passes true and the name shows above Member Name.
			 * Certification pills are shown below Member Name instead of as a row.
			 * Member Number stays last and de-emphasized rather than removed
			 * outright, since it's still useful for e.g. a member
			 * cross-checking their own certificate.
			 */
			?>
			<dl class="paccc-member-details">
				<?php if ( $show_business_name && '' !== trim( (string) $m->business_name ) ) : ?>
					<div class="paccc-member-business-name-row">
						<dt>Business Name</dt>
						<dd><?php echo esc_html( $m->business_name ); ?></dd>
					</div>
				<?php endif; ?>
				<div class="paccc-member-name-row">
					<dt>Member Name</dt>
					<dd><?php echo esc_html( $m->member_name ); ?></dd>
				</div>
				<?php if ( $m->certifications ) : ?>
					<div class="paccc-member-cert-row">
						<dt>Certification(s)</dt>
						<dd>
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
						</dd>
					</div>
				<?php endif; ?>
				<?php if ( $m->ceus ) : ?>
					<div class="paccc-member-ceu-row">
						<dt>CEU(s)</dt>
						<dd>
							<ul class="paccc-ceu-list">
								<?php foreach ( $m->ceus as $ceu ) : ?>
									<li class="paccc-ceu"><?php echo esc_html( $ceu ); ?></li>
								<?php endforeach; ?>
							</ul>
						</dd>
					</div>
				<?php endif; ?>
				<div class="paccc-member-address-row">
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
