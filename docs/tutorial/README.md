---
layout: tutorial
permalink: /tutorial/
description: "Learn regular expressions from scratch, step by step — with clear examples, exercises, and PHP `preg_match()` along the way."
---

# Regex Tutorial

Learn regular expressions from the ground up. This tutorial assumes **no prior knowledge** and introduces one concept at a time, building on what you just learned.

Most examples use plain PHP's `preg_match()` to show matches, but the concepts you'll learn apply to almost any regex flavor that uses PCRE.

## What You'll Learn

{% assign c1 = site.pages | where: "url", "/tutorial/01-basics/" | first %}
{% assign m1 = c1.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c2 = site.pages | where: "url", "/tutorial/02-character-classes/" | first %}
{% assign m2 = c2.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c3 = site.pages | where: "url", "/tutorial/03-anchors-boundaries/" | first %}
{% assign m3 = c3.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c4 = site.pages | where: "url", "/tutorial/04-quantifiers/" | first %}
{% assign m4 = c4.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c5 = site.pages | where: "url", "/tutorial/05-groups-alternation/" | first %}
{% assign m5 = c5.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c6 = site.pages | where: "url", "/tutorial/06-lookarounds/" | first %}
{% assign m6 = c6.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c7 = site.pages | where: "url", "/tutorial/07-backreferences-recursion/" | first %}
{% assign m7 = c7.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c8 = site.pages | where: "url", "/tutorial/08-performance-redos/" | first %}
{% assign m8 = c8.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c9 = site.pages | where: "url", "/tutorial/09-testing-debugging/" | first %}
{% assign m9 = c9.content | number_of_words | divided_by: 200 | at_least: 1 %}
{% assign c10 = site.pages | where: "url", "/tutorial/10-real-world-php/" | first %}
{% assign m10 = c10.content | number_of_words | divided_by: 200 | at_least: 1 %}

<div class="guide-grid">

<div class="guide-entry">
<h3><a href="/tutorial/01-basics/">1 &middot; Regex basics</a></h3>
<p>Write your first literal patterns.</p>
<p class="guide-meta">{{ m1 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/02-character-classes/">2 &middot; Character classes &amp; escapes</a></h3>
<p>Match sets of characters.</p>
<p class="guide-meta">{{ m2 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/03-anchors-boundaries/">3 &middot; Anchors &amp; boundaries</a></h3>
<p>Control where matches occur.</p>
<p class="guide-meta">{{ m3 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/04-quantifiers/">4 &middot; Quantifiers &amp; greediness</a></h3>
<p>Control repetition.</p>
<p class="guide-meta">{{ m4 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/05-groups-alternation/">5 &middot; Groups &amp; alternation</a></h3>
<p>Capture and choose.</p>
<p class="guide-meta">{{ m5 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/06-lookarounds/">6 &middot; Lookarounds &amp; assertions</a></h3>
<p>Match context without consuming.</p>
<p class="guide-meta">{{ m6 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/07-backreferences-recursion/">7 &middot; Backreferences &amp; recursion</a></h3>
<p>Match repeated or nested patterns.</p>
<p class="guide-meta">{{ m7 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/08-performance-redos/">8 &middot; Performance &amp; ReDoS</a></h3>
<p>Write safe, fast patterns.</p>
<p class="guide-meta">{{ m8 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/09-testing-debugging/">9 &middot; Testing &amp; debugging</a></h3>
<p>Find and fix issues.</p>
<p class="guide-meta">{{ m9 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="/tutorial/10-real-world-php/">10 &middot; Real-world PHP</a></h3>
<p>Apply regex in practical PHP code.</p>
<p class="guide-meta">{{ m10 }} min</p>
</div>

</div>

---

## Before You Start

### Prerequisites

- **PHP 8.1 or newer** — PCRE2 is built into PHP, so you already have everything you need for the engine
- **The `mbstring` extension** — only needed for a few Unicode examples later on

You don't need to install any extra packages to follow chapters 1–7. Just read, think, and test with `preg_match()` in your editor or REPL.

### How to Use This Tutorial

1. **Go in order.** Each chapter builds on the previous one.
2. **Read actively.** Focus on understanding *why*, not just memorizing.
3. **Do the exercises.** They're short on purpose and use only what you've just learned.
4. **Look up as you go.** If a term is mentioned, follow its link to learn it at the right time.
5. **Test for yourself.** Copy an example and tweak it slightly. That's often the fastest way to learn.

---

## Start Here

**[Chapter 1: Regex Basics](01-basics.md)**

---

## Optional: Tools to Inspect Patterns

If you'd like a second opinion on a pattern you're working on, you can use tools to explain or visualize it. These are **completely optional** — you can safely skip them and come back later.

### PHPRegex CLI

```bash
vendor/bin/regex explain '/cat/i'
vendor/bin/regex diagram '/cat/i'
vendor/bin/regex validate '/cat/i'
```

### PHPRegex API

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$result  = $regex->validate('/cat/i');
$explain = $regex->explain('/cat/i');
```