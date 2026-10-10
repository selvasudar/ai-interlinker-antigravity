# Manual test: real AI providers

The automated suite uses only the mock gateway (`AILSUG_MOCK_AI`). Run this checklist once per release with real keys. Use a staging site: it costs a few cents.

## Setup
1. Use WordPress 7.0 or newer **without** `AILSUG_MOCK_AI`. In the dev environment, remove it from `.wp-env.json` and run `npx wp-env start --update`.
2. Install one official provider plugin (*AI Provider for Anthropic*, *Google* or *OpenAI*) and add a key in **Settings → Connectors**.
3. Create or import 20–50 real posts. `bin/dev-seed.php` gives a quick gardening demo.
4. In **Internal Links → Settings → AI & Cost**, set the budget cap to **$0.50** and keep **strict budget mode** on.

## Checklist (repeat for each provider)
| # | Step | Expected |
|---|---|---|
| 1 | Settings → Status → "Check again" | ✓ Ready, the provider is listed, and a likely model is shown |
| 2 | Tools → Site Health | "An AI provider is available…" (good) |
| 3 | AI & Cost price table | The likely model has a built-in price; verify it on the provider's pricing page |
| 4 | Keywords → "Find missing keywords with AI…" | The dialog shows the page count, cost range and budget left. Run it. Keywords appear with the **AI** badge |
| 5 | AI & Cost → usage | A row per page. "Model that answered" is a real model ID. Estimated and actual cost are both filled in |
| 6 | Dashboard → Scan | Suggestions appear in Suggestions → To review. Anchors are verbatim, reasons are sensible |
| 7 | Accept 3 → Apply | Confirmation shows "3 links … pages". Links are visible on the front end; a revision exists |
| 8 | Applied → Undo whole batch | Content matches the revision taken before apply (compare in the revisions screen) |
| 9 | Quality → Balanced, then Re-generate one page | The usage row shows the Balanced model and a higher cost |
| 10 | "Use only this model" set to a model ID the provider does not offer | The run fails with "The model … is not available" and no spend |
| 11 | Delete the price of the model in use (add a custom price row for a fake ID, or use a model without a built-in price), with strict mode on | The scan is blocked with "price … unknown" before any request |
| 12 | Cap of $0.01, then Scan | The dialog shows "Only N of M pages fit"; the run stops with the budget status; nothing exceeds the cap |
| 13 | A plugin that returns `true` from `add_filter( 'wp_ai_client_prevent_prompt', '__return_true' )` | AI shows as not ready; runs are refused with an explanation |
| 14 | After 20+ calls | "Estimates improve automatically" shows 20+ samples; the calculator changes slightly |

## Record
Note the provider, the model that answered, the estimated vs actual cost for steps 4 and 6, and any surprises. Compare the usage log against the provider's own usage dashboard for the same hour; expect small differences, since the provider's invoice is the source of truth.
