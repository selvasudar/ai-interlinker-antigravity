=== AI Internal Link Suggester ===
Contributors: ailsug
Tags: internal links, seo, links, orphan pages, keywords
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds each page's main keyword and suggests internal links you review, apply and undo in bulk, with clear AI cost estimates and a budget cap.

== Description ==

AI Internal Link Suggester removes the manual work of internal linking. It follows a simple three-step flow: **Scan → Review → Apply**.

* Finds the primary keyword of every page (from your own entry, your SEO plugin, or AI).
* Suggests the best internal links with the exact anchor text and a short reason.
* Lets you accept, reject and apply suggestions in bulk, with one-click undo.
* Shows what AI costs before, during and after each run, with a calculator and an estimated monthly budget cap.

Works without AI too: the page index, link map, orphan page report and keyword editing never need an AI provider.

= AI features need a connected provider =

This plugin uses the AI features built into WordPress 7.0. It does not store or ask for API keys. To use AI features, connect a provider in **Settings → Connectors** (for example with the official AI Provider plugins for Anthropic, Google or OpenAI). Without a connected provider, AI features are switched off with an explanation and everything else keeps working.

== External services ==

This plugin itself does not contact any external service. When you click a Scan, Run or Re-generate button (or turn on a scheduled scan), it asks WordPress to send page titles, headings and short text excerpts of your site to the AI provider *you* connected in Settings → Connectors (for example Anthropic, Google or OpenAI). Nothing is sent on activation or on page load. Which provider is used, and its terms, is your choice:

* Anthropic: [Terms of Service](https://www.anthropic.com/legal/commercial-terms), [Privacy Policy](https://www.anthropic.com/legal/privacy)
* Google Gemini API: [Terms of Service](https://ai.google.dev/gemini-api/terms), [Privacy Policy](https://policies.google.com/privacy)
* OpenAI: [Terms of Use](https://openai.com/policies/terms-of-use/), [Privacy Policy](https://openai.com/policies/privacy-policy/)

== Privacy ==

The plugin stores keywords, link suggestions, an edit record for every link it adds, and an AI usage log in your own database. It sends no telemetry and loads no remote assets. Text excerpts are only sent to your connected AI provider when you start an AI run.

== Frequently Asked Questions ==

= How much does it cost? =

Settings → Internal Links → AI & Cost has a calculator based on your own site. On the Economy setting a typical page costs a fraction of a cent to analyze. Every run shows an estimate first and asks you to confirm.

= What is the budget cap? =

A monthly cap on estimated spend. No new AI work starts once the estimated spend reaches the cap. It is based on the usage your provider reports, so it limits overspend but cannot guarantee your provider's invoice. Your provider's invoice is the source of truth.

= Can I undo links? =

Yes. Every applied link can be reverted on its own or by batch. If a page was edited after a link was added, the plugin only removes its own link, or stops and points you to the saved revision so newer edits are never lost.

== Changelog ==

= 0.1.0 =
* First version.
