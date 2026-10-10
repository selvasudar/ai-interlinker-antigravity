# WordPress.org submission notes: AI Internal Link Suggester 1.0.0

## Before submitting (manual, needs you)
1. **Contributors:** replace `ailsug` in `readme.txt` with your real WordPress.org username(s).
2. **Author:** the plugin header currently says "AI Internal Link Suggester contributors". Set your name or company (and optionally `Author URI`).
3. **Name and slug:** check that `ai-internal-link-suggester` is free on WordPress.org. The name doesn't start with or consist of a trademark; providers are mentioned only descriptively.
4. **Real-provider test:** run `ai-internal-link-suggester/tests/MANUAL-real-providers.md` with at least one provider.
5. **Prices:** re-check `data/pricing.json` against the official pricing pages and update `as_of` if anything changed.
6. **Screenshots (optional):** add `assets/screenshot-1.png` … in the SVN `/assets` folder and a `== Screenshots ==` section to the readme. Suggested shots: dashboard, suggestions review, cost dialog, AI & Cost tab, editor panel.

## Build
```bash
cd ai-internal-link-suggester
npm install && npm run build      # only if src/ changed; build/ is committed
bash bin/build-zip.sh             # → ../dist/ai-internal-link-suggester.zip (about 180 KB, 88 files)
```
The zip excludes everything in `.distignore`: tests, `bin`, `vendor`, `node_modules`, composer/phpunit/phpcs configs and the lock file. It **includes** `src/` and `package.json` (the source of the built JS), as the guidelines require.

## Verified for this release (2026-10-10)
| Check | Result |
|---|---|
| Plugin Check 2.1.0 on the **installed zip** (clean WP 7.1, PHP 7.4), including experimental checks | No errors, no warnings |
| PHPCS (`WordPress`, `WordPress-Extra`, `PHPCompatibilityWP` 7.4+) | Clean |
| PHPUnit (WP test suite, mock gateway) | 104 tests passing, including the byte-identical apply→revert corpus |
| Concurrency (30 parallel processes) | Budget cap never exceeded; no job claimed twice |
| Zip install/activate on PHP 7.4 | 9 tables, no notices, empty `debug.log`, all screens return 200 |
| No provider connected | Guidance shown, AI refused, index, link map and orphans work |
| Uninstall without opt-in | Data kept |
| Uninstall with opt-in | 0 tables, options, post meta, user meta or cron events left |

## Reviewer-facing notes (answers to typical review questions)
- **External services:** the plugin makes no HTTP requests of its own. AI requests go only through core's `wp_ai_client_prompt()` to the provider the site owner connected in Settings → Connectors, and only after an explicit user action (or a schedule/auto-update the owner turned on). This is documented in the readme's `== External services ==` section with links to each provider's terms and privacy policy.
- **No API keys:** none are collected or stored. Keys are managed by core Connectors.
- **No calls on activation, no telemetry, no remote assets, no upsells or locked features.**
- **Direct database queries:** all are on the plugin's own `{prefix}ailsug_*` tables (plus read-only lookups in AIOSEO's `aioseo_posts` table). Every query with variables uses `$wpdb->prepare()`; where SQL fragments are assembled (filters, `IN` lists), only placeholders are concatenated, and each case carries a justified `phpcs:ignore`.
- **`content_save_pre` / `pre_post_content` are detached for one update** when the plugin inserts or removes a link. This keeps every byte outside the link unchanged: core's `wp_targeted_link_rel` would otherwise rewrite unrelated links. The inserted markup is only `<a href="esc_url(...)">`. The filters are restored in a `finally` block, and the saved content is compared with what was intended.
- **Capabilities:** settings and AI runs need `manage_options` (runs can be changed with the `ailsug_run_capability` filter). Editing keywords and applying or undoing links check `edit_post` for each post. The site-wide views need `edit_others_posts`. Every REST route has a `permission_callback`.
- **Bundled JS:** `build/index.js` is built with `@wordpress/scripts` from `src/editor/index.js`. It depends only on core `wp-*` packages, and no third-party code is bundled.
- **Mock gateway** (`includes/AI/MockGateway.php`) is only active when a site owner defines `AILSUG_MOCK_AI`. It is used for demos and tests and never contacts the network.

## Known limitations (documented, not bugs)
- An anchor must be inside a single text node, so "the <em>best</em> guide" cannot become one link.
- Page-builder content (Elementor, Divi and others) is view only in v1.
- The pre-scan estimate is deliberately conservative, because it can't know which pages will need no AI call. Calibration adjusts token sizes after 20 calls.
- The budget cap limits *estimated* spend and cannot guarantee the provider's invoice. This is stated in the UI and readme.
