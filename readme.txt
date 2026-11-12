=== PACCC Member Directory ===
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later

Member directory for the Professional Animal Care Certification Council.

== Description ==

Admin side (Member Directory in the left sidebar):
* Sortable, searchable members table: State, Business Name, Member Name, Certification(s), Unique Link (member number + "Copy Unique Link" button).
* Edit / Delete row actions, "Add New" at the top.
* Add/Edit form: Member Number (auto-assigned, 7 digits, unique, editable), Business Name, Member Name, Certification(s) checklist (defaults: CPACP, CPACM, CPACO) with "+ Add a certification", Address 1, Address 2, City, State dropdown (all US states + DC), Zip.
* Directory Settings box: pick the frontend page unique links should point at (auto-detected from the shortcode if left alone), choose the map label font + weight, and set the member-state color.

Frontend:
* Add the [paccc_directory] shortcode to a page.
* US state map (jsVectorMap): white states, black outlines, black state labels; states with members are filled #ffe399. Hovering shows the state name + member count; clicking a highlighted state filters the list below.
* Directory list in "Business Name, Member Name, Certification(s)" format with a "View Member" accordion revealing Member Number, Business Name, Member Name, Certification(s), Address, and a Google map of the address (loaded only when opened).
* "Filter by state" dropdown lists all 50 states + DC (member counts shown in parentheses). Default is All States. Clicking a highlighted state on the map sets the dropdown to match.
* The list paginates at 20 members per page; choosing a state resets to page 1. Change the page size with the paccc_md_per_page filter.
* Unique links (?paccc_member=1000001) auto-open and scroll to that member.

== 2.37.2 — old-site CEUs shown with the member's CEU(s) ==

* On the member edit screen, the old-site CEU list moved from its own box
  (below Yoast) into the CEU(s) row of Member Details, under "From the old
  site". Still read-only, admins only, and still private on the front end.

== 2.37.1 — old-site CEUs are private ==

* CEUs imported from the old site are members' own free-text entries, some of
  them personal notes, so they no longer appear anywhere public: not on member
  pages, the directory (even with ceus="true") or [paccc_member_field
  key="ceus"]. They show only in the logged-in member's portal, and to admins
  on the member edit screen. Public pages show only the CEU(s) set by an admin.
* On the first admin page load after updating, the directory cache and WP
  Rocket's page cache are emptied once so no cached page still lists them.

== 2.37 — old-site CEUs + CSV exports ==

* PACCC Suite > Import Old CEUs: adds the CEUs members logged on the old site
  (wpDataTables "My CEUs" CSV + old users CSV) to their member profiles. Matches
  by email, then by first + last name for members with no email; a
  match-overrides CSV (old_user_id, member_post_id) settles the rest. Preview
  first; the import only adds, is keyed on the old wdt_ID, and is safe to re-run.
* Old-site CEUs are stored as entered (paccc_legacy_ceus member meta) apart from
  the admin CEU(s) checklist, and shown by name only, once per name, alongside
  it on the directory, member page and portal. A read-only box on the member
  edit screen lists them in full, with a "remove all" for wrong matches.
* "Export members (CSV)" and "Export CEUs (CSV)" buttons on the All Members and
  All CEUs screens.
* [paccc_member_field key="ceus"] outputs the member's CEU names.
* [paccc_directory] no longer lists CEUs unless you add ceus="true"
  (e.g. [paccc_directory ceus="true"]). Member pages and the portal still
  show them.

== 2.0 — member pages ==

Members are now a custom post type: each member has its own URL
(/paccc-certified-members/business-name/), its own title, meta description, and
LocalBusiness schema. This is the change that lets individual members rank in search.

* Existing members are migrated automatically from the old wp_paccc_members
  table the first time an admin page loads. The old table is NOT dropped — it
  stays as a backup. Migration is idempotent (tracked per row).
* Old unique links (?paccc_member=1000001) 301-redirect to the member's page,
  so links already shared keep working.
* The admin list lives under Member Directory, using WordPress's own list
  table (search, sorting, trash). Settings moved to Member Directory > Settings.
* Business Name is the post title. Everything else is in the Member Details box.
* The post type has no archive — an archive at the base would duplicate the
  shortcode directory page, which stays the hub linking to each member. Members
  share the directory page's base: /paccc-certified-members/business-name/.
* Permalink slug is filterable via paccc_md_permalink_slug.

== Updates via GitHub ==

The plugin self-updates from GitHub using the bundled Plugin Update Checker (lib/plugin-update-checker, MIT).

1. Create a GitHub repo and push this folder's CONTENTS to the repo root.
2. In paccc-member-directory.php, set your repo URL in two places: the Update URI header and the PucFactory::buildUpdateChecker() call.
3. To ship an update: bump the Version header and PACCC_MD_VERSION, note changes, commit, then publish a GitHub Release tagged v1.0.1 (etc.).
4. Sites see the update on the Plugins screen within ~12 hours; Dashboard → Updates → "Check again" forces it immediately.
5. Private repo: create a fine-grained personal access token (read-only Contents, this repo only) and add define( 'PACCC_MD_GITHUB_TOKEN', '...' ); to each site's wp-config.php.

== Implementation notes ==

* Data lives in {prefix}paccc_members. The state column stores indexed 2-letter codes, so the future interactive map can query:
  SELECT state, COUNT(*) AS members FROM wp_paccc_members GROUP BY state;
* Member numbers auto-increment from 1000001 (always 7 digits, no leading zeros to lose in exports).
* Certifications are stored in the paccc_certifications option; per-member selections are comma-separated in the certifications column.
* Map assets are self-hosted in /assets/vendor (jsvectormap 1.7.0 core + CSS, MIT, and the us-aea-en.js state map data). The US map data is not published to npm, so jsDelivr/CDN paths for it return 404 — keep these files bundled.
* The map is hidden at 1000px and below (51 full state names need width to stay legible); the state dropdown still filters on small screens. The map is built lazily the first time its container is visible, because getBBox() returns zeros inside a display:none container and would mis-place every label.
* State label positions: jsVectorMap anchors a label at the region's bounding-box center, which falls outside the state for panhandle shapes (OK, ID, FL, AK...). The offsets in assets/frontend.js were computed from the map's own path data as the "pole of inaccessibility" of each state's largest polygon minus that bbox center. Nudge any pair by hand if you want a different spot.
* The map label font list (includes/fonts.php) is a curated set of Google Fonts with each family's real published weights. Only the selected family + weight is loaded, from fonts.googleapis.com.
* The member-state color also sets the --paccc-accent CSS variable used by the View Member buttons and pagination. To pin those to #ffe399 instead, replace the three var(--paccc-accent, #ffe399) rules in assets/frontend.css.
* Address maps use Google's keyless embed (google.com/maps?q=...&output=embed). Swap in the official Maps Embed API + key later if traffic warrants it.
* Deleting the plugin (uninstall) drops the table and options. Deactivating keeps all data.
* Strings are not run through translation functions — wrap in __() if this ever needs localization.
