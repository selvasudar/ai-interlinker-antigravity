# LinkCortex Website Content & Launch Blueprint

This folder contains the complete, copy-paste ready content assets for the **LinkCortex** official website and blog. Each document is fully formatted in markdown with complete SEO metadata, heading hierarchy, comparison tables, and callouts.

---

## Content Inventory

| File | Type | Target URL Slug | Primary Keyword | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **[`01-definitive-guide-to-wordpress-internal-linking.md`](file:///Users/selvakumarduraipandian/Documents/Projects/interlink-ai-plugin/website-content/01-definitive-guide-to-wordpress-internal-linking.md)** | Pillar / Cornerstone | `/wordpress-internal-linking-guide/` | `WordPress internal linking` | Massive educational asset to rank on Google and earn backlinks as the authoritative reference guide. |
| **[`02-linkcortex-vs-link-whisper-comparison.md`](file:///Users/selvakumarduraipandian/Documents/Projects/interlink-ai-plugin/website-content/02-linkcortex-vs-link-whisper-comparison.md)** | High-Intent Comparison (BOFU) | `/linkcortex-vs-link-whisper/` | `LinkCortex vs Link Whisper` | Directly targets searchers looking for alternatives to expensive or legacy internal link tools. |
| **[`03-how-to-find-and-fix-orphan-pages-in-wordpress.md`](file:///Users/selvakumarduraipandian/Documents/Projects/interlink-ai-plugin/website-content/03-how-to-find-and-fix-orphan-pages-in-wordpress.md)** | Problem-Solving Guide (MOFU) | `/how-to-find-and-fix-orphan-pages-wordpress/` | `orphan pages WordPress` | Captures high-volume search traffic of users diagnosing and fixing broken internal architectures. |
| **[`04-landing-page-copy-and-interactive-calculator.md`](file:///Users/selvakumarduraipandian/Documents/Projects/interlink-ai-plugin/website-content/04-landing-page-copy-and-interactive-calculator.md)** | Homepage / Landing Page + Widget | `/` (Homepage) or `/features/` | `AI internal link WordPress` | High-converting homepage copy plus a plug-and-play interactive HTML/CSS/JS cost calculator. |

---

## Publishing Instructions for WordPress

### 1. Publishing the Blog Articles (Gutenberg)
1. In your WordPress admin, go to **Posts → Add New Post**.
2. Set the Title, URL slug, and Category (e.g., `Guides` or `Comparisons`).
3. Copy the markdown content from the file. You can paste markdown directly into the Gutenberg block editor; WordPress will automatically convert headings, lists, tables, and blockquotes into native blocks.
4. Set the Meta Title and Meta Description in your SEO plugin (Yoast SEO or Rank Math) using the metadata provided at the top of each document.

### 2. Publishing the Interactive Cost Calculator
Inside [`04-landing-page-copy-and-interactive-calculator.md`](file:///Users/selvakumarduraipandian/Documents/Projects/interlink-ai-plugin/website-content/04-landing-page-copy-and-interactive-calculator.md), Part 2 contains an embeddable HTML/JS component.
* **In Gutenberg:** Add a **Custom HTML** block and paste the entire `<div id="linkcortex-calc">...</div><script>...</script>` snippet.
* **In Elementor / Divi:** Add an **HTML** widget and paste the snippet.
* The calculator is 100% self-contained: it requires no external libraries, has zero layout shift, is fully responsive on mobile, and works offline.

---

## Strategic Internal Linking Map Between These 4 Assets

To practice what LinkCortex preaches, configure these internal links when publishing:

```
                  ┌──────────────────────────────────────────────┐
                  │          Homepage / Landing Page             │
                  │ (04-landing-page-copy-and-calculator.md)     │
                  └──────────────┬───────────────────────────────┘
                                 │ Links to guides
                                 ▼
┌────────────────────────────────────────────────────────────────────────┐
│           Cornerstone: The Definitive Guide to Internal Linking        │
│          (01-definitive-guide-to-wordpress-internal-linking.md)        │
└──────────────┬──────────────────────────────────────────┬──────────────┘
               │ Contextually links to                    │ Contextually links to
               ▼                                          ▼
┌──────────────────────────────┐          ┌──────────────────────────────┐
│  Link Whisper Comparison     │◄────────►│   Orphan Pages Guide         │
│  (02-linkcortex-vs-...)      │          │   (03-how-to-find-and-fix...)│
└──────────────────────────────┘          └──────────────────────────────┘
               │                                          │
               └──────────────► CTA Links Back ───────────┘
                                       │
                                       ▼
                         [Download LinkCortex CTA]
```
