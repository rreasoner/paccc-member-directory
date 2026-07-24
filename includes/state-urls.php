<?php
/**
 * PACCC Member Directory -- per-state URLs.
 *
 * Adds crawlable, shareable URLs for each state (e.g. /paccc-certified-members/texas/)
 * that resolve to the SAME directory page the [paccc_directory] shortcode
 * renders -- no separate archive, no reload. A rewrite rule routes the state
 * segment to a query var; the shortcode pre-selects that state server-side;
 * and assets/frontend.js keeps the URL in sync with the dropdown/map via the
 * History API. SEO title / description / canonical are set per state so each
 * URL is a distinct, indexable landing page (Yoast-aware).
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * State <-> slug mapping
 * ------------------------------------------------------------------------ */

/**
 * Map of 2-letter state code => URL slug (e.g. 'TX' => 'texas',
 * 'NY' => 'new-york'). Derived from the display names in paccc_md_states().
 */
function paccc_md_state_slugs() {
	static $slugs = null;
	if ( null !== $slugs ) {
		return $slugs;
	}
	$slugs = array();
	foreach ( paccc_md_states() as $code => $name ) {
		$slugs[ $code ] = sanitize_title( $name );
	}
	return $slugs;
}

/**
 * Slug for a state code, or '' if unknown.
 */
function paccc_md_state_slug( $code ) {
	$slugs = paccc_md_state_slugs();
	return isset( $slugs[ $code ] ) ? $slugs[ $code ] : '';
}

/**
 * State code for a URL slug, or '' if it doesn't match a real state.
 */
function paccc_md_state_from_slug( $slug ) {
	$code = array_search( sanitize_title( $slug ), paccc_md_state_slugs(), true );
	return $code ? $code : '';
}

/* ---------------------------------------------------------------------------
 * Directory page location
 * ------------------------------------------------------------------------ */

/**
 * The page holding the [paccc_directory] shortcode (0 if not set yet).
 */
function paccc_md_directory_page_id() {
	return (int) get_option( 'paccc_directory_page_id' );
}

/**
 * The directory page's path relative to the site root, e.g. 'paccc-certified-members'
 * (or 'parent/paccc-certified-members' for a nested page). '' if there's no page.
 */
function paccc_md_directory_path() {
	$id = paccc_md_directory_page_id();
	if ( ! $id ) {
		return '';
	}
	$uri = get_page_uri( $id );
	return $uri ? $uri : '';
}

/**
 * Full URL for a state's directory view, e.g.
 * https://example.com/paccc-certified-members/texas/. '' if unavailable.
 */
function paccc_md_state_url( $code ) {
	$path = paccc_md_directory_path();
	$slug = paccc_md_state_slug( $code );
	if ( '' === $path || '' === $slug ) {
		return '';
	}
	return home_url( user_trailingslashit( $path . '/' . $slug ) );
}

/* ---------------------------------------------------------------------------
 * Rewrite rule + query var
 * ------------------------------------------------------------------------ */

/**
 * Route {directory-path}/{state-slug}/ to the directory page, carrying the
 * state slug in the paccc_state query var. Registered on every load; the
 * matching page still renders normally, just with the query var available.
 */
function paccc_md_register_state_rewrite() {
	$id   = paccc_md_directory_page_id();
	$path = paccc_md_directory_path();
	if ( ! $id || '' === $path ) {
		return;
	}
	// Match only real state slugs, so a genuine child page of the directory
	// (e.g. /paccc-certified-members/about/) still resolves normally instead of being
	// swallowed by this rule. State slugs are [a-z0-9-], safe in the pattern.
	$pattern = implode( '|', array_values( paccc_md_state_slugs() ) );
	add_rewrite_rule(
		'^' . $path . '/(' . $pattern . ')/?$',
		'index.php?page_id=' . $id . '&paccc_state=$matches[1]',
		'top'
	);
}
add_action( 'init', 'paccc_md_register_state_rewrite' );

function paccc_md_register_query_var( $vars ) {
	$vars[] = 'paccc_state';
	return $vars;
}
add_filter( 'query_vars', 'paccc_md_register_query_var' );

/**
 * The rewrite rule depends on the directory page's path, so whenever that
 * page changes, flag a rewrite flush for the next load.
 */
function paccc_md_flag_rewrite_flush() {
	update_option( 'paccc_md_flush_rewrites', 1 );
}
add_action( 'add_option_paccc_directory_page_id', 'paccc_md_flag_rewrite_flush' );
add_action( 'update_option_paccc_directory_page_id', 'paccc_md_flag_rewrite_flush' );

/**
 * Flush once, after the rewrite rule has been registered for this request.
 */
function paccc_md_maybe_flush_rewrites() {
	if ( get_option( 'paccc_md_flush_rewrites' ) ) {
		flush_rewrite_rules();
		delete_option( 'paccc_md_flush_rewrites' );
	}
}
add_action( 'init', 'paccc_md_maybe_flush_rewrites', 20 );

/* ---------------------------------------------------------------------------
 * Active-state resolution
 * ------------------------------------------------------------------------ */

/**
 * The state code from the current URL's paccc_state query var, validated
 * against the real state list. '' when there's no (valid) state segment.
 */
function paccc_md_current_state_code() {
	$slug = get_query_var( 'paccc_state' );
	if ( '' === $slug || null === $slug ) {
		return '';
	}
	return paccc_md_state_from_slug( $slug );
}

/**
 * Like paccc_md_current_state_code(), but only on the directory page -- so a
 * stray ?paccc_state= elsewhere can't trigger the per-state SEO output.
 */
function paccc_md_page_state_code() {
	if ( ! is_page( paccc_md_directory_page_id() ) ) {
		return '';
	}
	return paccc_md_current_state_code();
}

/* ---------------------------------------------------------------------------
 * Per-state SEO (title / description / canonical), Yoast-aware
 * ------------------------------------------------------------------------ */

function paccc_md_state_title_text( $code ) {
	$states = paccc_md_states();
	return sprintf( 'PACCC Certified Members in %s', $states[ $code ] );
}

function paccc_md_state_desc_text( $code ) {
	$states = paccc_md_states();
	return sprintf(
		'Find PACCC-certified members in %s -- professional animal care businesses certified by the Professional Animal Care Certification Council.',
		$states[ $code ]
	);
}

/**
 * Core / classic-theme document title.
 */
function paccc_md_state_document_title( $parts ) {
	$code = paccc_md_page_state_code();
	if ( $code ) {
		$parts['title'] = paccc_md_state_title_text( $code );
	}
	return $parts;
}
add_filter( 'document_title_parts', 'paccc_md_state_document_title' );

/**
 * Yoast title (Yoast bypasses document_title_parts).
 */
function paccc_md_state_wpseo_title( $title ) {
	$code = paccc_md_page_state_code();
	if ( ! $code ) {
		return $title;
	}
	return paccc_md_state_title_text( $code ) . ' - ' . get_bloginfo( 'name' );
}
add_filter( 'wpseo_title', 'paccc_md_state_wpseo_title' );

/**
 * Yoast meta description.
 */
function paccc_md_state_wpseo_metadesc( $desc ) {
	$code = paccc_md_page_state_code();
	return $code ? paccc_md_state_desc_text( $code ) : $desc;
}
add_filter( 'wpseo_metadesc', 'paccc_md_state_wpseo_metadesc' );

/**
 * Self-referencing canonical for each state URL. Without this, WordPress (and
 * Yoast) would canonicalize the state URL back to the bare directory page,
 * telling search engines the state pages are duplicates -- so they'd never
 * rank. Applies to core canonical, Yoast canonical, and the OpenGraph URL.
 */
function paccc_md_state_canonical( $url ) {
	$code = paccc_md_page_state_code();
	if ( ! $code ) {
		return $url;
	}
	$state_url = paccc_md_state_url( $code );
	return $state_url ? $state_url : $url;
}
add_filter( 'get_canonical_url', 'paccc_md_state_canonical' );
add_filter( 'wpseo_canonical', 'paccc_md_state_canonical' );
add_filter( 'wpseo_opengraph_url', 'paccc_md_state_canonical' );

/**
 * Meta description for sites without Yoast (Yoast outputs its own).
 */
function paccc_md_state_meta_description() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return;
	}
	$code = paccc_md_page_state_code();
	if ( ! $code ) {
		return;
	}
	echo '<meta name="description" content="' . esc_attr( paccc_md_state_desc_text( $code ) ) . '">' . "\n";
}
add_action( 'wp_head', 'paccc_md_state_meta_description', 1 );

/* ---------------------------------------------------------------------------
 * Member counts + intro copy
 * ------------------------------------------------------------------------ */

/**
 * Published member count per state code, computed once per request.
 */
function paccc_md_state_counts() {
	static $counts = null;
	if ( null !== $counts ) {
		return $counts;
	}
	$counts = array();
	foreach ( paccc_md_get_members() as $m ) {
		if ( $m->state ) {
			$counts[ $m->state ] = isset( $counts[ $m->state ] ) ? $counts[ $m->state ] + 1 : 1;
		}
	}
	return $counts;
}

/**
 * State codes that have at least one member, in the canonical state order.
 * These are the only states worth linking / listing in the sitemap -- an empty
 * state page would be thin content.
 */
function paccc_md_states_with_members() {
	$counts = paccc_md_state_counts();
	$codes  = array();
	foreach ( array_keys( paccc_md_states() ) as $code ) {
		if ( ! empty( $counts[ $code ] ) ) {
			$codes[] = $code;
		}
	}
	return $codes;
}

/**
 * A natural-language sentence for a state page ("There are 3 PACCC-certified
 * ... in Texas."). Server-rendered so AI answer engines and search snippets
 * have a clear, quotable summary of what the page is about.
 */
function paccc_md_state_intro_text( $code, $count = null ) {
	$states = paccc_md_states();
	$name   = isset( $states[ $code ] ) ? $states[ $code ] : $code;

	if ( null === $count ) {
		$counts = paccc_md_state_counts();
		$count  = isset( $counts[ $code ] ) ? (int) $counts[ $code ] : 0;
	}

	if ( $count < 1 ) {
		return sprintf( 'There are no PACCC-certified members in %s yet.', $name );
	}

	return sprintf(
		/* translators: 1: member count, 2: state name */
		_n(
			'There is %1$d PACCC-certified member in %2$s.',
			'There are %1$d PACCC-certified members in %2$s.',
			$count,
			'paccc-member-directory'
		),
		$count,
		$name
	);
}

/* ---------------------------------------------------------------------------
 * Current-state shortcodes -- for building a custom state-aware header in a
 * page builder / Beaver Themer layout (a Heading module can't read the URL's
 * state on its own). All resolve the state from the current URL and fall back
 * to the optional default="" when there's no state (the unfiltered page).
 *
 * Note: these render server-side from the URL, so they're correct on load but
 * won't change if the visitor filters client-side. The directory shortcode's
 * own built-in heading does live-update; use heading="0" on [paccc_directory]
 * to hide it when you build your own with these.
 * ------------------------------------------------------------------------ */

/**
 * [paccc_current_state] -- the current state's name, e.g. "Texas".
 */
function paccc_md_current_state_shortcode( $atts ) {
	$atts   = shortcode_atts( array( 'default' => '' ), $atts, 'paccc_current_state' );
	$code   = paccc_md_current_state_code();
	$states = paccc_md_states();
	if ( ! $code || ! isset( $states[ $code ] ) ) {
		return esc_html( $atts['default'] );
	}
	return esc_html( $states[ $code ] );
}
add_shortcode( 'paccc_current_state', 'paccc_md_current_state_shortcode' );

/**
 * [paccc_current_state_title] -- "PACCC Certified Members in Texas".
 */
function paccc_md_current_state_title_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'default' => 'PACCC Certified Members' ), $atts, 'paccc_current_state_title' );
	$code = paccc_md_current_state_code();
	if ( ! $code ) {
		return esc_html( $atts['default'] );
	}
	return esc_html( paccc_md_state_title_text( $code ) );
}
add_shortcode( 'paccc_current_state_title', 'paccc_md_current_state_title_shortcode' );

/**
 * [paccc_current_state_intro] -- the state's intro sentence.
 */
function paccc_md_current_state_intro_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'default' => '' ), $atts, 'paccc_current_state_intro' );
	$code = paccc_md_current_state_code();
	if ( ! $code ) {
		return esc_html( $atts['default'] );
	}
	return esc_html( paccc_md_state_intro_text( $code ) );
}
add_shortcode( 'paccc_current_state_intro', 'paccc_md_current_state_intro_shortcode' );

/* ---------------------------------------------------------------------------
 * XML sitemap: list the per-state URLs so search engines discover them
 * (they're virtual routes, not posts/terms, so they aren't included
 * automatically). Yoast path when Yoast is active; WordPress core sitemaps
 * otherwise.
 * ------------------------------------------------------------------------ */

/**
 * Yoast: register a dedicated /paccc_states-sitemap.xml and add it to the
 * sitemap index. Guarded so a fatal is impossible if the API shifts.
 */
function paccc_md_register_yoast_sitemap() {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		return;
	}
	global $wpseo_sitemaps;
	if ( empty( $wpseo_sitemaps ) || ! is_object( $wpseo_sitemaps ) || ! method_exists( $wpseo_sitemaps, 'register_sitemap' ) ) {
		return;
	}
	$wpseo_sitemaps->register_sitemap( 'paccc_states', 'paccc_md_build_yoast_state_sitemap' );
	// Only advertise the sitemap in the index once we know registration ran,
	// so we never point search engines at a URL that 404s.
	add_filter( 'wpseo_sitemap_index', 'paccc_md_yoast_sitemap_index_entry' );
}
add_action( 'init', 'paccc_md_register_yoast_sitemap', 99 );

function paccc_md_yoast_sitemap_index_entry( $index ) {
	if ( ! paccc_md_states_with_members() ) {
		return $index;
	}
	$index .= '<sitemap><loc>' . esc_url( home_url( '/paccc_states-sitemap.xml' ) ) . '</loc>'
		. '<lastmod>' . esc_html( gmdate( 'c' ) ) . '</lastmod></sitemap>';
	return $index;
}

function paccc_md_build_yoast_state_sitemap() {
	global $wpseo_sitemaps;
	$urls = '';
	foreach ( paccc_md_states_with_members() as $code ) {
		$url = paccc_md_state_url( $code );
		if ( $url ) {
			$urls .= '<url><loc>' . esc_url( $url ) . '</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>' . "\n";
		}
	}
	$sitemap = '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $urls . '</urlset>';
	$wpseo_sitemaps->set_sitemap( $sitemap );
}

/**
 * WordPress core sitemaps (only when Yoast isn't active -- Yoast disables
 * core sitemaps). The provider class is defined lazily because its parent,
 * WP_Sitemaps_Provider, isn't loaded until WordPress boots the sitemaps API.
 */
function paccc_md_register_core_sitemap() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return;
	}
	if ( ! class_exists( 'WP_Sitemaps_Provider' ) || ! function_exists( 'wp_register_sitemap_provider' ) ) {
		return;
	}

	if ( ! class_exists( 'PACCC_MD_State_Sitemap_Provider' ) ) {
		class PACCC_MD_State_Sitemap_Provider extends WP_Sitemaps_Provider {
			public function __construct() {
				$this->name        = 'paccc_states';
				$this->object_type = 'paccc_state';
			}

			public function get_url_list( $page_num, $object_subtype = '' ) {
				$urls = array();
				foreach ( paccc_md_states_with_members() as $code ) {
					$url = paccc_md_state_url( $code );
					if ( $url ) {
						$urls[] = array( 'loc' => $url );
					}
				}
				return $urls;
			}

			public function get_max_num_pages( $object_subtype = '' ) {
				return paccc_md_states_with_members() ? 1 : 0;
			}
		}
	}

	wp_register_sitemap_provider( 'paccc_states', new PACCC_MD_State_Sitemap_Provider() );
}
add_action( 'init', 'paccc_md_register_core_sitemap' );
