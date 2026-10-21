# LinkCortex Landing Page Copy & Interactive Cost Calculator

> **Purpose:** Copy-paste ready landing page copy and an interactive Vanilla JS calculator widget for the official LinkCortex website.
> **Format:** Clean markdown for page builders/Gutenberg, with an embedded responsive HTML/CSS/JS calculator component.

---

# Part 1: High-Converting Landing Page Copy

## 1. Hero Section

* **Pre-headline Badge:** ⚡ Built for WordPress 7.0+ Core AI & Connectors
* **Main Headline (H1):** The Neural Network of WordPress SEO.
* **Sub-headline:** Stop wasting hours on manual spreadsheets or paying $100+/year for robotic keyword tools. LinkCortex uses native WordPress AI to discover semantic internal links, rescue orphan pages, and boost rankings—with 100% byte-safe edits and micro-cent pricing.
* **Primary CTA Button:** [Download Free on WordPress.org]
* **Secondary CTA Button:** [Calculate Your AI Savings ↓]
* **Micro-Copy Trust Bar:** 
  * ✓ 100% GPL & Open Source 
  * ✓ Zero API Key Clutter 
  * ✓ Byte-Safe Gutenberg Insertion 
  * ✓ No SaaS Subscriptions

---

## 2. Social Proof / Compatibility Bar
**Seamlessly integrates with your favorite WordPress SEO plugins:**
`[Yoast SEO Logo]  |  [Rank Math Logo]  |  [All in One SEO Logo]  |  [SEOPress Logo]  |  [WooCommerce Logo]`
*(LinkCortex automatically reads focus keywords without conflict or duplicate configuration).*

---

## 3. The Problem: Why Internal Linking in WordPress Is Broken Today

### Problem 1: Manual Linking Takes 15+ Hours a Month
Tracking internal links across hundreds of articles in Google Sheets is exhausting. As your site grows, older posts are forgotten, and high-value "money pages" starve for link equity.

### Problem 2: Legacy Plugins Are Stuck in 2018 (and Overcharge You)
Legacy tools charge $77 to $167 every single year. Worse, they rely on rigid keyword-matching rules that force awkward, robotic anchor text into your sentences—risking Google over-optimization penalties.

### Problem 3: Broken Gutenberg Blocks and HTML Corruption
Most link plugins use aggressive regex replacements that corrupt Gutenberg block markup (`<!-- wp:paragraph -->`) and scramble formatting, leading to disastrous "Invalid Block" errors.

---

## 4. The Solution: Meet LinkCortex

> *"Yoast optimizes what you say. LinkCortex connects what you know."*

LinkCortex brings enterprise-grade semantic search directly into WordPress. It analyzes your site locally, consults modern AI only for contextual sentence placement, and lets you review and apply internal links with complete peace of mind.

```
       Step 1: Scan               Step 2: Review             Step 3: Apply
┌─────────────────────────┐   ┌─────────────────────────┐   ┌─────────────────────────┐
│ Local BM25 shortlist +  │──►│ Inspect anchor in context│──►│ Surgical byte-safe edit │
│ AI semantic ranking     │   │ Edit, accept or reject  │   │ 1-Click batch revisions │
└─────────────────────────┘   └─────────────────────────┘   └─────────────────────────┘
```

---

## 5. Core Features That Put You in Total Control

### 🧠 Semantic AI Anchor Discovery (Not Just Keyword Matching)
LinkCortex doesn't just search for exact words. It reads paragraph context and crafts natural, descriptive 2-to-5 word anchors that fit smoothly into your sentences, signaling clear topical authority to search engines.

### 💰 Micro-Cent AI Architecture & Hard Budget Caps
Never get surprised by an AI bill. LinkCortex uses a **Local-First, AI-Last** pipeline: it indexes and shortlists pages locally for free, only calling AI to choose anchors. A full scan of 1,000 articles typically costs **under $1.00**. Set a monthly budget cap (e.g., $5.00), and LinkCortex automatically enforces a hard stop.

### 🛡️ 100% Byte-Safe Insertion & 1-Click Rollback
Powered by WordPress core's official `WP_HTML_Tag_Processor`, LinkCortex splices links directly into text nodes without re-serializing blocks or touching surrounding HTML. Every single link is recorded as a WordPress revision with a dedicated rollback record. Undo any link or batch with one click.

### 🏝️ Automated Orphan Page Rescue
Instantly discover every post and product on your site that has zero incoming internal links. LinkCortex’s smart **Quick Wins** algorithm pairs isolated pages with authoritative donor articles to restore lost PageRank immediately.

### 🔌 Native WordPress 7.0 Connectors API (Zero Stored Keys)
No sketchy third-party servers. No storing secret API keys in custom plugin tables. LinkCortex connects cleanly through WordPress 7.0’s native AI Client with official connectors for Anthropic (Claude), Google (Gemini), and OpenAI.

---

# Part 2: Interactive Cost Calculator (Embeddable Code)

Paste this clean, dependency-free HTML/CSS/JS block directly into a WordPress Custom HTML block or page builder template.

```html
<!-- LinkCortex Interactive AI Cost Calculator -->
<div id="linkcortex-calc" style="max-width: 680px; margin: 40px auto; padding: 32px; background: #0f172a; border-radius: 16px; border: 1px solid #334155; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #f8fafc; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);">
  
  <div style="text-align: center; margin-bottom: 24px;">
    <span style="background: #3b82f6; color: #ffffff; font-size: 12px; font-weight: 700; text-transform: uppercase; padding: 4px 12px; border-radius: 9999px; letter-spacing: 0.05em;">Interactive Estimator</span>
    <h3 style="font-size: 26px; font-weight: 800; margin: 12px 0 6px; color: #ffffff;">Calculate Your Internal Linking Costs</h3>
    <p style="font-size: 15px; color: #94a3b8; margin: 0;">See how much you save using native WordPress 7.0 AI vs. legacy SaaS tools.</p>
  </div>

  <div style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
      <label for="calc-posts" style="font-size: 14px; font-weight: 600; color: #cbd5e1;">Number of Published Posts:</label>
      <span id="calc-posts-val" style="font-size: 18px; font-weight: 800; color: #38bdf8;">500 posts</span>
    </div>
    <input type="range" id="calc-posts" min="50" max="3000" step="50" value="500" style="width: 100%; accent-color: #38bdf8; cursor: pointer;">
  </div>

  <div style="margin-bottom: 28px;">
    <label for="calc-tier" style="display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #cbd5e1;">Select AI Quality Tier:</label>
    <select id="calc-tier" style="width: 100%; padding: 12px; background: #1e293b; border: 1px solid #475569; border-radius: 8px; color: #f8fafc; font-size: 14px; font-weight: 500; outline: none; cursor: pointer;">
      <option value="economy" selected>Economy (Recommended: Gemini 2.0 Flash / Claude 3.5 Haiku) - ~$0.0007 / post</option>
      <option value="balanced">Balanced (GPT-4o-mini) - ~$0.0015 / post</option>
      <option value="premium">Best Quality (Claude 3.5 Sonnet / GPT-4o) - ~$0.0080 / post</option>
    </select>
  </div>

  <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
    <div style="background: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; text-align: center;">
      <div style="font-size: 12px; font-weight: 600; color: #94a3b8; text-transform: uppercase;">LinkCortex AI Cost</div>
      <div id="calc-linkcortex-cost" style="font-size: 32px; font-weight: 800; color: #10b981; margin: 8px 0 4px;">$0.35</div>
      <div style="font-size: 12px; color: #64748b;">Direct API cost (Pay as you go)</div>
    </div>
    <div style="background: #1e293b; padding: 20px; border-radius: 12px; border: 1px solid #334155; text-align: center;">
      <div style="font-size: 12px; font-weight: 600; color: #94a3b8; text-transform: uppercase;">Legacy SaaS (Link Whisper)</div>
      <div style="font-size: 32px; font-weight: 800; color: #f43f5e; margin: 8px 0 4px;">$77.00</div>
      <div style="font-size: 12px; color: #64748b;">Recurring annual license</div>
    </div>
  </div>

  <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; padding: 16px; text-align: center;">
    <div style="font-size: 14px; color: #e2e8f0;">
      Your Estimated Savings with LinkCortex:
      <strong id="calc-savings" style="color: #34d399; font-size: 18px; margin-left: 6px;">$76.65 / year (99% Less)</strong>
    </div>
  </div>

  <div style="text-align: center; margin-top: 24px;">
    <a href="https://wordpress.org/plugins/ai-internal-link-suggester/" target="_blank" rel="noopener noreferrer" style="display: inline-block; background: #3b82f6; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 16px; padding: 14px 28px; border-radius: 8px; transition: background 0.2s ease;">
      Get Started with LinkCortex Free →
    </a>
  </div>
</div>

<script>
(function() {
  const postsInput = document.getElementById('calc-posts');
  const postsVal = document.getElementById('calc-posts-val');
  const tierInput = document.getElementById('calc-tier');
  const costEl = document.getElementById('calc-linkcortex-cost');
  const savingsEl = document.getElementById('calc-savings');

  const rates = {
    economy: 0.0007,
    balanced: 0.0015,
    premium: 0.0080
  };

  function updateCalc() {
    const posts = parseInt(postsInput.value, 10);
    const tier = tierInput.value;
    postsVal.textContent = posts.toLocaleString() + ' posts';

    const cost = posts * rates[tier];
    const displayCost = cost < 0.01 ? '< $0.01' : '$' + cost.toFixed(2);
    costEl.textContent = displayCost;

    const saasCost = 77.00;
    const savings = saasCost - cost;
    const pct = ((savings / saasCost) * 100).toFixed(0);
    savingsEl.textContent = '$' + savings.toFixed(2) + ' / year (' + pct + '% Less)';
  }

  postsInput.addEventListener('input', updateCalc);
  tierInput.addEventListener('change', updateCalc);
  updateCalc();
})();
</script>
```

---

# Part 3: Landing Page FAQs

### Do I need to pay for an API key before I can use LinkCortex?
No. The core features of LinkCortex—including the complete page index, link graph analysis, orphan page detection, and focus keyword reader—are **100% free and work with zero external API calls**. When you choose to run AI-powered link ranking, it connects directly through the native WordPress 7.0 Connectors API to your existing provider account.

### Will applying links break my layout or Gutenberg blocks?
Never. LinkCortex does not use regex string replacement or re-serialize block JSON. It uses WordPress core’s official `WP_HTML_Tag_Processor` to splice anchor tags into verified text nodes, leaving every other character on your page untouched.

### Can I undo internal links if I change my mind?
Yes! Every single link inserted by LinkCortex is tracked with a dedicated edit record and native WordPress revision. You can revert individual links or entire batches of 100 links with a single click.

### Does LinkCortex work with WooCommerce?
Yes. LinkCortex supports all public custom post types, including WooCommerce products, documentation posts, portfolio items, and standard blog posts.
