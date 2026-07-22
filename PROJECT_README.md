# PACCC Member Directory — Dev Project

Git repo initialized from the delivered plugin zip (initial commit: "Initial
import: PACCC Member Directory plugin (as delivered)"). Use this as the base
for any edits — branch off `main` per change.

## Structure

```
paccc-member-directory/
├── paccc-member-directory.php   # Plugin bootstrap — hooks, CPT registration, shortcodes
├── readme.txt                   # Standard WP plugin readme (name, description, changelog)
├── uninstall.php                # Cleanup on plugin removal
├── includes/
│   ├── admin.php                 # Admin-side logic (member CRUD/import UI)
│   ├── frontend.php              # Public-facing directory rendering (state filtering)
│   └── fonts.php                 # Font handling
├── templates/
│   └── single-paccc_member.php   # Single-member profile template
├── assets/
│   ├── admin.js / admin.css
│   ├── frontend.js / frontend.css
│   ├── single.js
│   └── vendor/
│       └── jsvectormap*, us-aea-en.js   # Interactive US map library (state-by-state UI)
└── lib/
    └── plugin-update-checker/    # Self-hosted update mechanism (YahnisElsts), not from WP.org
```

## Local dev notes

- This is a standard WordPress plugin — drop the `paccc-member-directory/`
  folder into `wp-content/plugins/` on a local WP install (e.g. via
  [LocalWP](https://localwp.com/), `wp-env`, or Docker) to run it.
- No build step / package.json detected — assets appear to be hand-authored,
  not compiled from a bundler.
- The bundled Plugin Update Checker library suggests self-hosted updates
  (likely GitHub-based, given `Vcs/GitHubApi.php` is present) rather than
  distribution through the WordPress.org plugin repository.

## Suggested next steps

- Wire up a local WP environment to actually run/test the plugin
- Review `includes/frontend.php` for the state-directory markup and confirm
  it's indexable (no JS-only rendering) and has clean, semantic HTML for
  GEO/AEO purposes
- Check for structured data (schema.org `Person`/`Organization` markup) on
  `templates/single-paccc_member.php`
- Audit accessibility of the jsvectormap-based state map (keyboard nav,
  ARIA labels, non-map fallback for screen readers)
