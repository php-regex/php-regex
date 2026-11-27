---
description: "Fifteen copy-ready PHP regex recipes, one page each — email, URL, date, phone and more — each one proven safe from ReDoS, with matched and rejected examples."
head_title: "PHP regex cookbook: safe, proven patterns"
redirect_from:
  - /COOKBOOK.html
---
# Regex Cookbook: Practical Patterns for PHP

This cookbook collects patterns for common validation and parsing tasks, one page per recipe: the fifteen pages are listed below, and each answers one need. Every pattern in the Quick Reference is `safe (proven)`, and so is every variant its page shows: the ReDoS analyzer proved that no input makes one match attempt backtrack beyond a linear number of steps. The only unsafe patterns here are the bad examples in [Building Your Own Patterns](#building-your-own-patterns), each shown as the thing to avoid. Review and adapt each pattern for your context.
<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the cookbook</label>
  <input type="search" id="page-filter-input" data-items=".recipe-index > li" data-unit="recipes" placeholder="Filter by task — e.g. email, url, date, phone, slug" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>No recipe matches that filter — try a shorter fragment, or press Escape to see them all again.</p>

> These recipes include a short explanation and a quick validation call so you can use them in tooling or code reviews.
>
> Always validate and run `redos()` before accepting user-defined patterns.

{% include install.html package="php-regex/regex-toolkit" %}

Requires PHP 8.1 or later and the `mbstring` extension. The PHP examples that call PHPRegex use the `Regex` facade installed above; the [Quick Start](quick-start.md) walks through a first analysis.

## Quick Reference

One row per recipe: the task and verdict read on one line, then the regex sits alone on its own scrollable line. The name of the recipe opens its page.

<ul class="recipe-index">
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/email.md %}">Email</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/url.md %}">URL</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/uuid.md %}">UUID</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/ipv4.md %}">IPv4</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])){3}$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/ipv6.md %}">IPv6</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}$/i</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/date.md %}">ISO date</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/time.md %}">Time (24h)</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/datetime.md %}">DateTime</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z|[+-](?:[01]?[0-9]|2[0-3]):[0-5][0-9])?$/u</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/slug.md %}">Slug</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^[a-z0-9]+(?:-[a-z0-9]+)*$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/username.md %}">Username</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/password.md %}">Password</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/phone-number.md %}">Phone (E.164)</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^\+[1-9]\d{6,14}$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/credit-card.md %}">Credit card</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^[0-9]{13,19}$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/hex-color.md %}">Hex color</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^#(?:[0-9a-fA-F]{3}){1,2}$/</code></pre>
  </li>
  <li class="recipe-entry" data-copy="">
    <div class="recipe-entry-head">
      <a class="recipe-entry-name" href="{% link cookbook/semantic-version.md %}">SemVer</a>
      <span class="recipe-verdict"><span class="visually-hidden">Analyzer verdict: </span>safe (proven)</span>
    </div>
    <pre><code class="recipe-pattern">/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/</code></pre>
  </li>
</ul>

## How to Use This Cookbook

Each recipe has a page of its own, and each page includes:
- **The pattern** — ready to copy and use
- **What it matches** — examples of valid input
- **What it rejects** — examples of invalid input
- **Why it's safe** — ReDoS analysis notes
- **PHP example** — ready-to-run code, where the recipe needs one

---

## Building Your Own Patterns

### Guidelines for ReDoS-Safe Patterns

- Avoid nested quantifiers. Example: prefer `/a++b/` over `/(a+)+b/`.
- Avoid overlapping alternations. Example: simplify `/(a|aa)+b/` to `/a+b/` when possible.
- Use atomic groups or possessive quantifiers where appropriate (for example, `/a*+b/`).
- Prefer character classes over alternation for simple sets (for example, `/[abcd]/`).
- Validate input length before matching.
- Use lookaheads for flexible validation (for example, `/(?=.*[a-z])[a-z]+/`).

See the first guideline in the playground: [Open `/(a+)+b/` in the playground](/#pattern=%2F%28a%2B%29%2Bb%2F&subject=aaaaaaaaac) — on a subject that never delivers the `b`, the ReDoS tab shows the exponential backtracking — and [Open `/a++b/` in the playground](/#pattern=%2Fa%2B%2Bb%2F&subject=aaab) — the ReDoS tab proves the pattern linear.

### Validation Workflow

```php
use PHPRegex\Toolkit\Regex;

$pattern = '/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/';
$input = '2024-12-25';

$regex = Regex::create();

// Step 1: Require a pattern proven safe from ReDoS
$analysis = $regex->redos($pattern);
if (!$analysis->isProvenSafe()) {
    throw new \InvalidArgumentException('ReDoS: '.($analysis->hasQuadraticSearch() ? 'quadratic search' : $analysis->headline()));
}

// Step 2: Validate the pattern itself
$result = $regex->validate($pattern);
if (!$result->isValid) {
    throw new \InvalidArgumentException('Invalid pattern');
}

// Step 3: Validate input format
if (preg_match($pattern, $input) !== 1) {
    throw new \InvalidArgumentException('Invalid input format');
}

// Step 4: Apply business logic validation
// (e.g., checkdate(), luhnCheck(), etc.)
```

---

## Quick Reference: Pattern Elements

| Element          | Meaning                | ReDoS Risk |
|------------------|------------------------|------------|
| `[abc]`          | Character class        | Low        |
| `[^abc]`         | Negated class          | Low        |
| `\d`, `\w`, `\s` | Shorthand classes      | Low        |
| `{n,m}`          | Bounded quantifier     | Low        |
| `*`, `+`         | Unbounded quantifier   | Medium     |
| `*?`, `+?`       | Lazy quantifiers       | Medium     |
| `*+`, `++`       | Possessive quantifiers | Low        |
| `(?>...)`        | Atomic group           | Low        |
| `(?=...)`        | Lookahead              | Low        |
| `(?!...)`        | Negative lookahead     | Low        |
| `\|`             | Alternation            | Medium     |
