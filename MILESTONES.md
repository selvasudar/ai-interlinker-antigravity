# AI Internal Link Suggester: milestone log

Reference record of what each milestone delivered, how it was verified, and any decisions that deviate from or interpret `spec.md`.

- **Plugin folder:** `ai-internal-link-suggester/`
- **Dev environment:** `@wordpress/env` (WordPress 7.1.3, PHP 8.2), run from the repo root.
- **Commits:** local only. Nothing has been pushed.

| Milestone | Commit | Status |
|---|---|---|
| M0 Plan and research | (plan only) | Done |
| M1 Scaffold | `b106843` | Done |
| M2 Index | `c39a61f` | Done |
| M3 AI gateway and cost engine | `a06cc96` | Done |
| M4 Suggestion engine | `b9d9f40` | Done |
| M5 Review and apply | `af97b6c` | Done |
| M6 Polish | `507992b` | Done |
| M7 Release prep | `d58ebb3` | Done |

---

## M0: Plan and research (verified 2026-10-10)

### WordPress
- 7.0 "Armstrong" was released on 2026-05-20. The current version is 7.1.3.
- Minimum PHP is **7.4**. Plugin header: `Requires at least: 7.0`, `Requires PHP: 7.4`. Readme: `Tested up to: 7.1`.

### AI Client (`wp-includes/ai-client.php`, php-ai-client 1.3.1)
- **Entry point and builder:** `wp_ai_client_prompt( $text )` returns a `WP_AI_Client_Prompt_Builder` with snake_case methods:
  - `using_system_instruction()`, `using_temperature()`, `using_max_tokens()`, `as_json_response( $schema )`;
  - `using_model_preference( ...ids )`, `using_provider()`, `using_model( ModelInterface )`;
  - `is_supported_for_text_generation()`, `generate_text_result()`, which returns a result or a `WP_Error`.
- **Result object** (camelCase methods):
  - `getTokenUsage()->getPromptTokens()` and `getCompletionTokens()` (completion includes thinking tokens);
  - `getProviderMetadata()->getId()` and `getModelMetadata()->getId()`.
- **Blocking a prompt:** filter `wp_ai_client_prevent_prompt( bool, $builder_clone )`. When it returns true, the call yields `WP_Error 'prompt_prevented'`.
- **Requiring one model:** `using_model( AiClient::defaultRegistry()->getProviderModel( p, m ) )`. That registry call throws, so wrap it.
- **Detecting providers:** `AiClient::defaultRegistry()->getRegisteredProviderIds()` and `isProviderConfigured()`. The Connectors screen is at `admin_url( 'options-connectors.php' )`.

### Where the docs and the spec differ
1. The method is `using_system_instruction`, not `with_system_instruction`.
2. Token getters are named prompt and completion, not input and output.
3. The model core will use can't be read before a call (`getConfiguredModel()` is private). Estimates therefore use the likely model plus a fallback range, and always reprice from the model that actually answered.
4. Discovering models makes a list-models network call, which core caches for 24 hours. It only runs on the AI & Cost tab, the onboarding check and Site Health, behind a 12-hour transient.

### SEO plugin storage (checked against source)
| Plugin | Focus keyword | Noindex |
|---|---|---|
| Yoast | `_yoast_wpseo_focuskw` | `_yoast_wpseo_meta-robots-noindex=1`, or the post-type default in `wpseo_titles` |
| Rank Math | `rank_math_focus_keyword` (comma list; first is primary) | `rank_math_robots` contains `noindex` |
| AIOSEO | `{prefix}aioseo_posts.keyphrases` JSON, `focus.keyphrase` | `robots_default=0 AND robots_noindex=1` |
| SEOPress | `_seopress_analysis_target_kw` (comma list) | `_seopress_robots_index='yes'` |

### Pricing (USD per 1M tokens, as of 2026-10-10)
| Tier | Anthropic | Google | OpenAI |
|---|---|---|---|
| Economy | `claude-haiku-5-5` 0.10/0.50 | `gemini-3.1-flash-lite` 0.25/1.50, `gemini-3.5-flash-lite` 0.30/2.50 | `gpt-6-luna` 0.10/0.50 |
| Balanced | `claude-sonnet-5-5` 2/10 | `gemini-3.8-flash` 0.75/3.75 (1.50/7.50 from 2027-01-01) | `gpt-6.1-sol` 2/10 |
| Best | `claude-opus-5-5` 4/20 | `gemini-3.1-pro-preview` 2/12 | `gpt-6-astra` 10/50 |

`claude-fable-5-1` (10/50) is also in the table.

---

## M1: Scaffold (`b106843`)

**Delivered**
- Bootstrap with a PSR-4-style autoloader. The prefixes are `AILSUG\` and `ailsug_`.
- Requirement checks for WordPress 7.0 and PHP 7.4.
- Activator using `dbDelta`. Settings live in one option that doesn't autoload.
- `Settings` class with defaults and a merge-safe sanitizer; money is stored as integer micro-dollars.
- Settings screen with five tabs (General, Linking rules, AI & Cost, Advanced, Status), plus export and import.
- `AI\Availability`: reports missing core support, AI disabled, no provider, or a blocked prompt, and links to Connectors and the three official provider plugins.
- `AI\Gateway` (transport through `wp_ai_client_prompt()` only) and `AI\MockGateway` (switched on with `AILSUG_MOCK_AI`).
- `AI\ModelResolver`: copies core's model-selection order to predict the likely model.
- `uninstall.php` deletes data only when you've opted in. A readme skeleton was added.
- Tooling: wp-env, composer, PHPCS (`WordPress`, `WordPress-Extra`, `PHPCompatibilityWP`) and PHPUnit.

**Verified:** clean activation, all tables created, no notices, 10 tests passing, Plugin Check with no code errors.

**Decisions**
- Extra tables beyond the spec: `ailsug_links` (the link graph), `ailsug_runs` (run history), `ailsug_reservations` (budget holds).
- The default budget cap is $10 a month, so strict mode is active from the start.
- Mock AI is on in the dev environment through `.wp-env.json`.

---

## M2: Index (`c39a61f`)

**Delivered**
- **Collector:** content hash, Unicode-aware word count, headings and internal links, read from raw content with `WP_HTML_Tag_Processor`.
- **LinkGraph:** links in and out, and orphan pages.
- **Eligibility:** post types, statuses and exclusions. Password-protected pages are skipped, Polylang/WPML languages recorded, and page builders (Elementor, Divi, Beaver Builder, WPBakery, Bricks, Oxygen) detected.
- **SeoPluginReader:** four version-guarded, read-only adapters with silent fallthrough, an on/off setting and the `ailsug_seo_keyword_sources` filter.
- **KeywordResolver:** your own keyword, then the SEO plugin's, then AI. AI is re-run only when the page changed.
- **Queue and worker:** atomic claims with `claim_token` and a lock timeout, retries up to 3, holds, pause/resume/cancel, and WP-Cron ticks. "Run now" works through a REST loop.
- **Saving a post** only queues a re-index at shutdown; no work happens inside `save_post`.
- **Keywords screen:** views (no keyword, orphans, few links in), filters, inline keyword editing and CSV export with formula-injection protection.

**Verified:** 30 tests passing, the index run tested end to end over REST on 12 demo posts, CSV export, inline editing and re-indexing on save.

**Decisions:** the Keywords screen requires `edit_others_posts`, because it lists every page on the site. "Links in" counts distinct linking pages.

---

## M3: AI gateway and cost engine (`a06cc96`)

**Delivered**
- **`Gateway::run`:** checks availability and strict mode, reserves the worst-case cost, calls core with a JSON schema at temperature 0.2 with a max-tokens limit, prices the call from the model that actually answered, reconciles, and logs. Bad JSON is retried once. A blocked prompt releases its reservation.
- **Budget:**
  - Reservations are made with one conditional `UPDATE … WHERE spent+reserved+r <= cap`.
  - Each reservation is closed exactly once.
  - Network and upstream errors hold the reservation for 15 minutes, and a cleanup job then releases it.
  - Parked jobs resume when the cap is raised, prices are entered, or a new month starts.
- **Strict-budget mode:** if any model that might answer has an unknown price, the call is refused. If the model that answered has an unknown price, it's charged at the worst-case amount and paid runs pause until you enter its price.
- **Pricing:** `data/pricing.json` (verified prices, `effective_from` changes, matching for dated snapshots and `models/` prefixes), user overrides and the `ailsug_pricing_table` filter.
- **Estimator:** characters per token by script (Latin 4, other 2, CJK 1), low–high ranges across the models that might answer, integer micros throughout. **Calibrator:** a rolling correction factor that switches on after 20 calls.
- **AI & Cost tab:**
  - spend meter and usage by task, model and day;
  - estimated vs actual per run, CSV export, reset with confirmation;
  - price table with links to verify on the provider sites;
  - live calculator with "How is this calculated?";
  - tips and an optional second currency.
- **Pre-run dialog:** pages, tokens explained in plain words, cost range, likely model and budget left, with "Run first N that fit" and an "I understand" tick above your threshold. The server refuses paid runs that weren't confirmed.
- **Stage A:** "Find missing keywords with AI…".

**Verified**
- 55 tests passing.
- **Real concurrency test** (`npm run test:concurrency`): 30 parallel processes never pushed committed spend over the cap, and 60 jobs were each claimed exactly once.
- Real browser: the calculator, the dialog, and a mock run (13 pages, $0.0007 actual against a $0.0009–$0.0015 estimate).

**Notes:** the dialog always appears before a paid run, and the threshold setting adds the required tick. Prompts and schemas were built here rather than in M4. Fixed: WordPress's button style overrode the `hidden` attribute.

---

## M4: Suggestion engine (`b9d9f40`)

**Delivered**
- **TextMap:** maps the eligible text in raw content with exact byte offsets, through a `WP_HTML_Tag_Processor` subclass that reads the protected bookmark spans.
  - Skipped: links, headings, code and pre, captions, buttons, form controls, scripts, `[shortcodes]`, and blocks such as Custom HTML, Code, Shortcode, Buttons, Navigation and Embed.
  - Entities map back to their exact raw bytes, and matches respect Unicode word boundaries.
  - Classic paragraphs are split on blank lines. Chunk IDs are `b{block}p{n}`.
- **Shortlister:** MySQL FULLTEXT over the source's description plus its body text, with a PHP IDF fallback. It applies every exclusion (self, unpublished, noindex, already linked, other language) and boosts priority pages ×1.5.
- **AnchorFinder:** exact, then lightly stemmed, then partial matches; sends at most 6 chunks. **If no paragraph mentions any candidate, no AI call is made.**
- **Validator:** drops hallucinated targets and chunks, anchors not verbatim in an eligible text node of that chunk, generic anchors and pages already linked. It then applies one suggestion per target, no overlaps, spacing and the maximum count.
- **Store:** replaces pending and stale suggestions. Accepted, applied and rejected targets are remembered, so a rejected idea isn't suggested again.
- **Ranker and runs:** Scan only re-ranks changed pages (`rank_hash`). Regenerate forces a re-rank. Auto-update and the daily/weekly schedule are budget-aware.

**Verified:** 67 tests passing; on the dev site the FULLTEXT path works, and a mock scan of 14 pages produced 9 valid suggestions.

**Decisions**
- The shortlist query includes the source's body text, so pages whose topics the source mentions are found.
- An anchor must sit inside a single text node, which keeps the byte-exact apply and revert safe.
- Pre-scan estimates are conservative, about 4–6× on the demo.

---

## M5: Review and apply (`af97b6c`)

**Delivered**
- **Applier:** a surgical splice of `<a href>…</a>` into the raw content.
  - It rechecks the hash, the anchor, that the target is published, the current permalink, duplicates, the per-apply limit and the spacing.
  - It uses the occurrence in the suggested chunk, otherwise the first eligible one, and records which one.
  - It skips locked posts, page builders (view only) and posts you can't edit.
  - It saves a pre-apply revision, makes one save per page, and keeps an edit record (exact markup plus about 40 bytes of context, normalized by unwrapping our other links).
  - The page's other open suggestions are kept if they still validate.
- **Reverter:** removes only our exact markup, and only when the context still matches. Otherwise it stops and offers the revision. Undo works per link and per batch.
- **Suggestions screen:**
  - status tabs and filters (type, category, minimum relevance, links to orphan pages);
  - accept, reject, "accept all ≥ N", inline edit of the anchor and target;
  - Preview, per-page Re-generate, and "Compare revisions";
  - a confirmation screen showing how many pages will change;
  - CSV export of suggestions and applied links.
  
  Authors only see their own pages.
- **Editor panel:** source in `src/editor`, build in `build/`. It shows the keyword, links in and out, and suggestions with Accept, Apply and Dismiss, plus "Find link suggestions for this post…" with a cost estimate. Apply is disabled while there are unsaved edits, and the editor reloads after applying. A classic metabox is the fallback.
- **REST:** `posts/{id}/links`, `suggestions/{id}` (edit), `suggestions/{id}/preview`, `suggestions/apply-preview`, `suggestions/apply` (requires `confirmed`), `applied/revert`.

**Verified**
- **Corpus proof:** 9 fixtures (Gutenberg, classic with CRLF, mixed, RTL/emoji/CJK, entities, repeated anchors, attribute traps, nested inline tags, malformed HTML) plus a 400-paragraph post. All 40 links applied and every revert was **byte-identical**, both as a batch and as separate batches reverted oldest-first. Each link is checked to be a real `<a>` element.
- Bulk apply is on by default; setting `AILSUG_BULK_APPLY=false` turns it off.
- 101 tests passing.
- On the dev site, accept → confirm → apply → undo left the pages byte-identical to their pre-apply hashes.
- Real browser: the Suggestions screen, and applying from the editor panel.
- Plugin Check and PHPCS clean.

**Decisions**
- The page is saved with `content_save_pre` / `pre_post_content` detached for that one update and then restored. Otherwise core's `wp_targeted_link_rel` would rewrite unrelated links. The saved bytes are checked afterwards.
- Fixed: target staleness now uses `text_hash` (title plus plain text), so adding or removing links on a target doesn't mark suggestions to it as out of date. DB v4.
- Re-generating is admin-only by default, through the `ailsug_run_capability` filter.

---

## M6: Polish

**Delivered**
- **Dashboard:**
  - a "Scan → Review → Apply" strip;
  - KPI cards (pages indexed, orphan pages, suggestions to review, links applied this month, estimated AI spend against the cap), each linking to the matching filtered view;
  - a large Scan button that opens the cost dialog;
  - budget warnings;
  - a guided path when AI isn't connected, including a free "Update page index" button.
- **Quick wins:** the top 20 pending suggestions ranked by `score × max(1, average links in + 1 − target's links in)`, so orphan and under-linked targets come first, each with a Review button.
- **Onboarding checklist** (admins only, dismissible per user): ① AI connected ② content chosen ③ costs seen ④ first scan run. Each step shows ✓/✗ with a plain-language hint and a link, and the official provider plugins are linked when no AI is connected.
- **Site Health:** a direct test "AI provider for internal link suggestions" (good / recommended) and a debug-info section (versions, AI status, last model, queue, spend and cap, cron).
- **Advanced tools:** reset the index (confirmed; keeps your own keywords and applied links), and a debug-log viewer with a Clear button, visible only when the debug log is on.
- **Microcopy and accessibility:**
  - the word "token" is always explained;
  - the progress bar has an accessible name and progress text is announced;
  - status is never shown by colour alone (✓/✗ plus screen-reader text, badges with text);
  - all dialogs are labelled.
- **RTL:** the CSS uses only logical properties, and an `admin-rtl.css` / `editor-rtl.css` copy is registered for each stylesheet.
- **i18n:** `languages/ai-internal-link-suggester.pot` (453 strings, including JS) and a `composer make-pot` script.

**Verified:** 104 tests passing; the dashboard checked in a real browser (an orphan target ranks first in Quick wins); no notices on any screen; Plugin Check reports no errors; PHPCS clean.

## M7: Release prep

**Delivered**
- **Version 1.0.0** in the plugin header, the constant, `readme.txt` and `package.json`.
- **Final `readme.txt`:**
  - a 141-character short description and 5 tags;
  - `Requires at least: 7.0`, `Tested up to: 7.1`, `Requires PHP: 7.4`;
  - a plain-language description, installation steps, **External services** (what is sent, when, to whom, with terms and privacy links for each provider), **Privacy**, a cost and budget FAQ, a third-party libraries section (none), the changelog and an upgrade notice.
- **`bin/build-zip.sh`** (also `composer build-zip`): turns `.distignore` into rsync excludes and refuses to build if tests, vendor, `node_modules` or dev configs would ship. It produces `dist/ai-internal-link-suggester.zip`: about 178 KB, 88 files, including `src/` and `package.json` as required.
- **`uninstall.php` completed:** it now also removes the price-block, budget-period and debug-log options, the plugin's short-lived per-user transients, and every onboarding user meta key.
- **`tests/MANUAL-real-providers.md`:** a 14-step checklist for testing with real providers.
- **`SUBMISSION-NOTES.md`** at the repo root: steps you need to do yourself before submitting, the build command, a verification table, reviewer-facing explanations and known limitations.

**Verified on a separate clean site** (WordPress 7.1, **PHP 7.4**, only Plugin Check installed, zip installed with `wp plugin install`)
- **Plugin Check 2.1.0 on the installed zip, including experimental checks: no errors and no warnings.**
- Activation: 9 tables, DB v4, an empty `debug.log`, and all 9 admin screens plus the editor return 200 with no notices.
- **No provider connected** (acceptance criterion 4): the reason is shown, scans are blocked, the gateway refuses (`ailsug_unavailable`), and the index, link graph and orphans work.
- **Uninstall without opt-in:** data kept. **With opt-in:** 0 tables, options, post meta, user meta or cron events left.
- Dev environment: PHPCS clean, **104 PHPUnit tests passing**, and the concurrency race passes (cap never exceeded, 60 of 60 jobs claimed once each).
- Final zip SHA-256: `e643968ecbc038ed14a4b714c3b6b82484e6f6bc8d3dce79507af38bdc46d66d`.

**Needs you before submitting** (see `SUBMISSION-NOTES.md`): your real WordPress.org username in `Contributors`, the Author in the plugin header, a check that the slug is available, a run of the real-provider checklist, a re-check of prices, and optional screenshots.

---

## Acceptance criteria (spec §13): status

| # | Criterion | Evidence |
|---|---|---|
| 1 | Scan → Review → Apply without docs | Onboarding checklist, dashboard Scan → Suggestions review → confirmation → applied; browser-tested with mock AI |
| 2 | Estimate vs actual visible, close after calibration | Usage table "Estimated before / Actual"; calibrator switches on after 20 samples; mock runs land within or just under the range (real-provider check in the manual doc) |
| 3 | No new AI job once the cap is reached; strict mode blocks unknown prices | `GatewayTest`, `CostTest`, the 30-process concurrency script, `PaidRunTest` |
| 4 | No provider: clean activation, guidance, link graph and orphans | Verified on a clean PHP 7.4 site (above) |
| 5 | Byte-identical apply→revert; edited posts never overwritten | 9-fixture corpus plus a 400-paragraph post; `ApplyTest` revert cases; dev-site hash check |
| 6 | Plugin Check and PHPCS clean; the zip installs and uninstalls cleanly | Verified on the clean site (above) |
| 7 | Every logged call stores and is priced from the model that answered | `GatewayTest::test_actual_model_differs_from_requested`; `usage.model` and `requested_model` columns |
| 8 | No custom provider or HTTP fallback | All AI traffic goes through `Gateway::transport()` → `wp_ai_client_prompt()`; no other HTTP calls in the code |

---

## How to run things

```bash
npx wp-env start                 # WordPress at http://localhost:8888 (admin / password)
npm test                         # PHPUnit in the tests container
npm run lint                     # PHPCS
npm run plugin-check             # Plugin Check
npm run test:concurrency         # 30 parallel processes: budget and queue race test
npx wp-env run cli wp eval-file wp-content/plugins/ai-internal-link-suggester/bin/dev-seed.php   # demo content
cd ai-internal-link-suggester && npm run build   # rebuild the editor panel
```
