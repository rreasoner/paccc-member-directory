<?php
/**
 * Plugin Name:       PACCC Suite
 * Description:       Member directory, approved-CEU catalog, and member portal for the Professional Animal Care Certification Council. Each member gets its own indexable page, plus a frontend US map + directory via [paccc_directory] and a CEU directory via [paccc_ceu_directory].
 * Version:           2.30.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Nehmedia
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/rreasoner/paccc-member-directory
 * Text Domain:       paccc-member-directory
 */

defined( 'ABSPATH' ) || exit;

define( 'PACCC_MD_VERSION', '2.30.0' );
define( 'PACCC_MD_FILE', __FILE__ );
define( 'PACCC_MD_DIR', plugin_dir_path( __FILE__ ) );
define( 'PACCC_MD_URL', plugin_dir_url( __FILE__ ) );

/** Post type slug for members. */
define( 'PACCC_MD_CPT', 'paccc_member' );

/**
 * Legacy members table (pre-2.0). Kept only for the one-time migration --
 * the table is NOT dropped, so it remains available as a backup.
 */
function paccc_md_table() {
	global $wpdb;
	return $wpdb->prefix . 'paccc_members';
}

/**
 * All US states (+ DC), keyed by the 2-letter code stored in the `state` column.
 * The 2-letter code is what you'll query against when building the interactive map.
 */
function paccc_md_states() {
	return array(
		'AL' => 'Alabama',
		'AK' => 'Alaska',
		'AZ' => 'Arizona',
		'AR' => 'Arkansas',
		'CA' => 'California',
		'CO' => 'Colorado',
		'CT' => 'Connecticut',
		'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',
		'GA' => 'Georgia',
		'HI' => 'Hawaii',
		'ID' => 'Idaho',
		'IL' => 'Illinois',
		'IN' => 'Indiana',
		'IA' => 'Iowa',
		'KS' => 'Kansas',
		'KY' => 'Kentucky',
		'LA' => 'Louisiana',
		'ME' => 'Maine',
		'MD' => 'Maryland',
		'MA' => 'Massachusetts',
		'MI' => 'Michigan',
		'MN' => 'Minnesota',
		'MS' => 'Mississippi',
		'MO' => 'Missouri',
		'MT' => 'Montana',
		'NE' => 'Nebraska',
		'NV' => 'Nevada',
		'NH' => 'New Hampshire',
		'NJ' => 'New Jersey',
		'NM' => 'New Mexico',
		'NY' => 'New York',
		'NC' => 'North Carolina',
		'ND' => 'North Dakota',
		'OH' => 'Ohio',
		'OK' => 'Oklahoma',
		'OR' => 'Oregon',
		'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island',
		'SC' => 'South Carolina',
		'SD' => 'South Dakota',
		'TN' => 'Tennessee',
		'TX' => 'Texas',
		'UT' => 'Utah',
		'VT' => 'Vermont',
		'VA' => 'Virginia',
		'WA' => 'Washington',
		'WV' => 'West Virginia',
		'WI' => 'Wisconsin',
		'WY' => 'Wyoming',
	);
}

/**
 * Countries (ISO 3166-1 alpha-2 => name), for the member Country field. Members
 * default to 'US'; only non-US members surface as chips on the directory.
 */
function paccc_md_countries() {
	return array(
		'US' => 'United States',
		'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola',
		'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
		'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
		'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
		'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'BN' => 'Brunei',
		'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon',
		'CA' => 'Canada', 'CV' => 'Cape Verde', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
		'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (DRC)',
		'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus',
		'CZ' => 'Czechia', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
		'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea',
		'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland',
		'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany',
		'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea',
		'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HN' => 'Honduras', 'HU' => 'Hungary',
		'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
		'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan',
		'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KW' => 'Kuwait',
		'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho',
		'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
		'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali',
		'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MX' => 'Mexico',
		'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro',
		'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru',
		'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger',
		'NG' => 'Nigeria', 'MK' => 'North Macedonia', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan',
		'PW' => 'Palau', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru',
		'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania',
		'RU' => 'Russia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia',
		'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe',
		'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone',
		'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia',
		'ZA' => 'South Africa', 'KR' => 'South Korea', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka',
		'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
		'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste',
		'TG' => 'Togo', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye',
		'TM' => 'Turkmenistan', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
		'GB' => 'United Kingdom', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela',
		'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
	);
}

/**
 * Certification list (stored as a wp_option so new ones can be added from the UI).
 */
function paccc_md_certifications() {
	$certs = get_option( 'paccc_certifications', array() );
	if ( ! is_array( $certs ) || empty( $certs ) ) {
		$certs = array( 'CPACP', 'CPACM', 'CPACO' );
	}
	return array_values( array_unique( array_filter( array_map( 'trim', $certs ) ) ) );
}

/**
 * CEU (Continuing Education Unit) list -- a wp_option of free-text CEU names,
 * added and edited from the member edit screen. Unlike certifications there is
 * no default set; it starts empty.
 */
function paccc_md_ceus() {
	$ceus = get_option( 'paccc_ceus', array() );
	if ( ! is_array( $ceus ) ) {
		$ceus = array();
	}
	return array_values( array_unique( array_filter( array_map( 'trim', $ceus ) ) ) );
}

/**
 * Register the member post type. The business name is the post title, so each
 * member gets a real permalink such as /paccc-certified-members/pet-resort-marketing/,
 * nested under the same base as the directory page.
 */
function paccc_md_register_cpt() {
	register_post_type(
		PACCC_MD_CPT,
		array(
			'labels'             => array(
				'name'               => 'Member Directory',
				'singular_name'      => 'Member',
				'add_new'            => 'Add New',
				'add_new_item'       => 'Add New Member',
				'edit_item'          => 'Edit Member',
				'new_item'           => 'New Member',
				'view_item'          => 'View Member',
				'search_items'       => 'Search Members',
				'not_found'          => 'No members found.',
				'not_found_in_trash' => 'No members found in Trash.',
				'all_items'          => 'All Members',
				'menu_name'          => 'PACCC Suite',
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => false,
			'menu_icon'          => 'dashicons-pets',
			// Position 3 sits it directly below Dashboard, above the first
			// separator and Posts (5), so the client finds it immediately.
			'menu_position'      => 3,
			/*
			 * No post type archive: an archive at the base would duplicate the
			 * shortcode directory page. The directory page is the hub that links
			 * out. Members share the directory page's base slug so a member URL
			 * reads /paccc-certified-members/{business}/.
			 */
			'has_archive'        => false,
			'rewrite'            => array(
				'slug'       => apply_filters( 'paccc_md_permalink_slug', 'paccc-certified-members' ),
				'with_front' => false,
			),
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		)
	);
}
add_action( 'init', 'paccc_md_register_cpt' );

/**
 * A member's fields as a plain object, matching the shape the rest of the
 * plugin expects.
 */
function paccc_md_get_member( $post ) {
	$post = get_post( $post );
	if ( ! $post || PACCC_MD_CPT !== $post->post_type ) {
		return null;
	}

	$certs = get_post_meta( $post->ID, 'paccc_certifications', true );
	if ( ! is_array( $certs ) ) {
		$certs = array_filter( array_map( 'trim', explode( ',', (string) $certs ) ) );
	}

	$ceus = get_post_meta( $post->ID, 'paccc_member_ceus', true );
	if ( ! is_array( $ceus ) ) {
		$ceus = array_filter( array_map( 'trim', explode( ',', (string) $ceus ) ) );
	}

	return (object) array(
		'ID'             => $post->ID,
		'business_name'  => $post->post_title,
		'member_number'  => (string) get_post_meta( $post->ID, 'paccc_member_number', true ),
		'member_name'    => (string) get_post_meta( $post->ID, 'paccc_member_name', true ),
		'certifications' => array_values( $certs ),
		'ceus'           => array_values( $ceus ),
		'image_id'       => (int) get_post_meta( $post->ID, 'paccc_image_id', true ),
		'address1'       => (string) get_post_meta( $post->ID, 'paccc_address1', true ),
		'address2'       => (string) get_post_meta( $post->ID, 'paccc_address2', true ),
		'city'           => (string) get_post_meta( $post->ID, 'paccc_city', true ),
		'state'          => (string) get_post_meta( $post->ID, 'paccc_state', true ),
		'zip'            => (string) get_post_meta( $post->ID, 'paccc_zip', true ),
		'country'        => ( $c = (string) get_post_meta( $post->ID, 'paccc_country', true ) ) ? $c : 'US',
		'region'         => (string) get_post_meta( $post->ID, 'paccc_region', true ),
		'website'        => (string) get_post_meta( $post->ID, 'paccc_website', true ),
		'email'          => (string) get_post_meta( $post->ID, 'paccc_email', true ),
		'permalink'      => get_permalink( $post ),
	);
}

/**
 * The grouping key for a non-US member in the "Browse outside the U.S."
 * dropdown: its province/region, falling back to its city. Empty if neither
 * is set (the member then only shows under "All of {country}").
 */
function paccc_md_member_region_key( $m ) {
	$region = trim( (string) ( isset( $m->region ) ? $m->region : '' ) );
	if ( '' === $region ) {
		$region = trim( (string) $m->city );
	}
	return $region;
}

/**
 * All published members, ordered by business name.
 */
function paccc_md_get_members() {
	$posts = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	$members = array();
	foreach ( $posts as $p ) {
		$m = paccc_md_get_member( $p );
		if ( $m ) {
			$members[] = $m;
		}
	}
	return $members;
}

/**
 * Next auto-assigned member number. Starts at 1000001 so numbers are always
 * 7 digits with no leading zeros (which spreadsheets strip).
 */
function paccc_md_next_member_number() {
	global $wpdb;
	$max  = $wpdb->get_var( "SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = 'paccc_member_number'" );
	$next = $max ? ( (int) $max + 1 ) : 1000001;
	return str_pad( (string) $next, 7, '0', STR_PAD_LEFT );
}

/**
 * Find a member post ID by member number.
 */
function paccc_md_find_by_number( $number ) {
	$posts = get_posts(
		array(
			'post_type'   => PACCC_MD_CPT,
			'post_status' => 'publish',
			'numberposts' => 1,
			'meta_key'    => 'paccc_member_number',
			'meta_value'  => (string) $number,
			'fields'      => 'ids',
		)
	);
	return $posts ? (int) $posts[0] : 0;
}

/**
 * A member's unique link is now simply its permalink.
 */
function paccc_md_member_link( $post_id ) {
	return apply_filters( 'paccc_md_member_link', get_permalink( $post_id ), $post_id );
}

/**
 * Map style settings, with defaults.
 */
function paccc_md_map_settings() {
	$fonts  = paccc_md_google_fonts();
	$family = (string) get_option( 'paccc_md_map_font', '' );
	if ( ! isset( $fonts[ $family ] ) ) {
		$family = '';
	}

	$weight = (string) get_option( 'paccc_md_map_font_weight', '400' );
	if ( $family && ! in_array( $weight, $fonts[ $family ], true ) ) {
		$weight = $fonts[ $family ][0];
	}

	$color = sanitize_hex_color( (string) get_option( 'paccc_md_map_highlight', '#ffe399' ) );

	return array(
		'font'      => $family,
		'weight'    => $weight ? $weight : '400',
		'highlight' => $color ? $color : '#ffe399',
	);
}

/**
 * One-time migration from the pre-2.0 custom table into the post type.
 *
 * The old table is left in place as a backup and is never dropped here. Rows
 * already migrated are skipped via _paccc_legacy_id, so this is safe to rerun.
 */
function paccc_md_maybe_migrate() {
	if ( get_option( 'paccc_md_migrated_to_cpt' ) ) {
		return;
	}

	global $wpdb;
	$table = paccc_md_table();

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		update_option( 'paccc_md_migrated_to_cpt', 1 );
		return;
	}

	$rows = $wpdb->get_results( "SELECT * FROM $table" ); // phpcs:ignore WordPress.DB
	$done = 0;

	foreach ( $rows as $row ) {
		$existing = get_posts(
			array(
				'post_type'   => PACCC_MD_CPT,
				'post_status' => 'any',
				'numberposts' => 1,
				'meta_key'    => '_paccc_legacy_id',
				'meta_value'  => (string) $row->id,
				'fields'      => 'ids',
			)
		);
		if ( $existing ) {
			continue;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => PACCC_MD_CPT,
				'post_status' => 'publish',
				'post_title'  => $row->business_name,
				'post_date'   => $row->created_at ? $row->created_at : current_time( 'mysql' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		$certs = array_values( array_filter( array_map( 'trim', explode( ',', (string) $row->certifications ) ) ) );

		update_post_meta( $post_id, 'paccc_member_number', $row->member_number );
		update_post_meta( $post_id, 'paccc_member_name', $row->member_name );
		update_post_meta( $post_id, 'paccc_certifications', $certs );
		update_post_meta( $post_id, 'paccc_address1', $row->address1 );
		update_post_meta( $post_id, 'paccc_address2', $row->address2 );
		update_post_meta( $post_id, 'paccc_city', $row->city );
		update_post_meta( $post_id, 'paccc_state', $row->state );
		update_post_meta( $post_id, 'paccc_zip', $row->zip );
		update_post_meta( $post_id, '_paccc_legacy_id', (string) $row->id );
		$done++;
	}

	update_option( 'paccc_md_migrated_to_cpt', 1 );
	update_option( 'paccc_md_migrated_count', $done );
	if ( $done ) {
		flush_rewrite_rules();
	}
}
add_action( 'admin_init', 'paccc_md_maybe_migrate' );

/**
 * Old unique links (?paccc_member=1000001) pointed at the directory page.
 * Redirect them to the member's own URL so links already shared -- and any
 * search engine that indexed them -- land in the right place.
 */
function paccc_md_legacy_link_redirect() {
	if ( empty( $_GET['paccc_member'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$number  = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_GET['paccc_member'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$post_id = $number ? paccc_md_find_by_number( $number ) : 0;
	if ( $post_id ) {
		wp_safe_redirect( get_permalink( $post_id ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'paccc_md_legacy_link_redirect' );

/**
 * Activation: register the post type, then flush rewrite rules so member
 * permalinks resolve immediately.
 */
function paccc_md_activate() {
	paccc_md_register_cpt();
	paccc_md_register_state_rewrite();
	paccc_md_register_member_role();
	paccc_ceu_register_cpt();

	add_option( 'paccc_certifications', array( 'CPACP', 'CPACM', 'CPACO' ) );
	add_option( 'paccc_directory_page_id', 0 );
	update_option( 'paccc_md_db_version', PACCC_MD_VERSION );

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'paccc_md_activate' );

function paccc_md_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'paccc_md_deactivate' );

/* Load modules. */
require_once PACCC_MD_DIR . 'includes/fonts.php';
require_once PACCC_MD_DIR . 'includes/state-urls.php';
require_once PACCC_MD_DIR . 'includes/frontend.php';
require_once PACCC_MD_DIR . 'includes/portal.php';
require_once PACCC_MD_DIR . 'includes/ceu.php';
if ( is_admin() ) {
	require_once PACCC_MD_DIR . 'includes/admin.php';
	require_once PACCC_MD_DIR . 'includes/import.php';
	require_once PACCC_MD_DIR . 'includes/ceu-import.php';
}

/*
 * GitHub-powered updates (Plugin Update Checker 5.6, bundled in /lib).
 *
 * Release workflow: bump Version above + PACCC_MD_VERSION, commit, publish a
 * GitHub Release tagged e.g. v2.0.1.
 */
if ( file_exists( PACCC_MD_DIR . 'lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once PACCC_MD_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

	/*
	 * 4th argument is the check period in HOURS. PUC has no setCheckPeriod()
	 * method -- the period must be passed here, at construction.
	 * Default is 12; 1 makes releases appear on client sites within the hour.
	 * Checks run on WP-Cron, so they fire on the first page visit after the
	 * interval elapses.
	 */
	$paccc_md_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/rreasoner/paccc-member-directory/',
		__FILE__,
		'paccc-member-directory',
		1
	);

	$paccc_md_updater->setBranch( 'main' );

	// Private repo? Define this in wp-config.php with a fine-grained PAT
	// (read-only Contents access to this one repo):
	// define( 'PACCC_MD_GITHUB_TOKEN', 'github_pat_XXXX' );
	if ( defined( 'PACCC_MD_GITHUB_TOKEN' ) && PACCC_MD_GITHUB_TOKEN ) {
		$paccc_md_updater->setAuthentication( PACCC_MD_GITHUB_TOKEN );
	}
}
