---
layout: tutorial
permalink: /tutorial/
description: "A ten-chapter hands-on regex course for PHP developers — from first patterns to recursion and ReDoS, every example explained and verified with the PHPRegex toolkit."
---

# Regex Tutorial (PHPRegex Edition)

This tutorial takes you from your first pattern to PCRE features used in production. It uses the PHPRegex CLI and API throughout, so you learn regex and the parser at the same time.

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
<h3><a href="01-basics.md">1 &middot; Regex basics</a></h3>
<p>Write simple literal patterns.</p>
<p class="guide-meta">{{ m1 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="02-character-classes.md">2 &middot; Character classes &amp; escapes</a></h3>
<p>Match sets of characters.</p>
<p class="guide-meta">{{ m2 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="03-anchors-boundaries.md">3 &middot; Anchors &amp; boundaries</a></h3>
<p>Control where matches occur.</p>
<p class="guide-meta">{{ m3 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="04-quantifiers.md">4 &middot; Quantifiers &amp; greediness</a></h3>
<p>Control repetition.</p>
<p class="guide-meta">{{ m4 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="05-groups-alternation.md">5 &middot; Groups &amp; alternation</a></h3>
<p>Capture and choose.</p>
<p class="guide-meta">{{ m5 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="06-lookarounds.md">6 &middot; Lookarounds &amp; assertions</a></h3>
<p>Match context without consuming.</p>
<p class="guide-meta">{{ m6 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="07-backreferences-recursion.md">7 &middot; Backreferences &amp; recursion</a></h3>
<p>Match repeated patterns.</p>
<p class="guide-meta">{{ m7 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="08-performance-redos.md">8 &middot; Performance &amp; ReDoS</a></h3>
<p>Write safe, fast patterns.</p>
<p class="guide-meta">{{ m8 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="09-testing-debugging.md">9 &middot; Testing &amp; debugging</a></h3>
<p>Find and fix issues.</p>
<p class="guide-meta">{{ m9 }} min</p>
</div>

<div class="guide-entry">
<h3><a href="10-real-world-php.md">10 &middot; Real-world PHP</a></h3>
<p>Common patterns explained.</p>
<p class="guide-meta">{{ m10 }} min</p>
</div>

</div>

---

## Before You Start

Every chapter mixes two things: regex concepts you can use with `preg_match()` today, and the PHPRegex tools that explain and validate them. Install the library once — the `regex` CLI and the `Regex` facade used throughout both come with it.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

### Prerequisites

- PHP 8.1 or newer
- the `mbstring` extension
- nothing else: the regex engine (PCRE2) ships inside PHP itself

The API examples use `php-regex/regex-toolkit`. The terminal examples use `php-regex/regex-cli`; install it as well when you reach those chapters.

---

## The Tools You Will Use

### The regex CLI

From chapter 8 on, every chapter asks you to run the CLI to see what a
pattern does:

```bash
vendor/bin/regex explain '/^cat.*dog$/'
```

```
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime : PHP 8.4.26
Command : explain
Format  : console

  [1/2] Pattern
  Pattern
      → /^cat.*dog$/

  [2/2] Explanation
Regex matches
  Anchor: the beginning of a line
  'c'
  'a'
  't'
    Wildcard: any character (may or may not match line terminators) (zero or more times)
  'd'
  'o'
  'g'
  Anchor: the end of a line
```

```bash
vendor/bin/regex diagram '/^cat.*dog$/'
```

```
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime : PHP 8.4.26
Command : diagram
Format  : console

  [1/1] Rendering diagram
  Pattern
      → /^cat.*dog$/

Regex
\-- Sequence
    |-- Anchor (^)
    |-- Literal ('c')
    |-- Literal ('a')
    |-- Literal ('t')
    |-- Quantifier (*, greedy)
    |   \-- Dot (.)
    |-- Literal ('d')
    |-- Literal ('o')
    |-- Literal ('g')
    \-- Anchor ($)
```

Two more commands show up later in the track — `highlight` for colorized output, and `analyze` for [ReDoS](../guides/redos.md) risk checks:

```bash
vendor/bin/regex analyze '/(a+)+$/'
```

### The PHP API

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Validate what you wrote
$result = $regex->validate('/your-pattern/');

// Get explanations
echo $regex->explain('/your-pattern/');

// Generate test data
$sample = $regex->generate('/your-pattern/');
```

---

## What Is a Regular Expression?

A **regular expression** (regex) is a pattern that describes text. Think of it like a recipe for matching strings:

```
Recipe: "Start with 'cat', then any characters, end with 'dog'"
Regex:  /^cat.*dog$/

Recipe: "An '@' symbol between two words"
Regex:  /\w+@\w+/
```

### Analogy: Finding a Book

Imagine you're in a library looking for a specific book:

| Task                           | Without Regex        | With Regex |
|--------------------------------|----------------------|------------|
| Find all books by "King"       | Read every spine     | `/King/`   |
| Find books with 4-digit years  | Check dates manually | `/\d{4}/`  |
| Find books starting with "The" | Look at every title  | `/^The.*/` |

### Real-World Examples

| Use Case         | Regex                          | What It Matches   |
|------------------|--------------------------------|-------------------|
| Email validation | `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` | email@example.com |
| Phone numbers    | `/^\+?[\d\s-]{10,}$/`          | +1 555-123-4567   |
| Dates            | `/\d{4}-\d{2}-\d{2}/`          | 2024-01-15        |
| Extract prices   | `/\$\d+\.\d{2}/`               | $99.99            |

---

## How to Use This Tutorial

### For Absolute Beginners

1. Read each chapter in order
2. Try every example in a PHP REPL or script
3. Use `vendor/bin/regex explain` to see what your pattern does
4. Work through the exercises at the end of each chapter

### For Those Who Know Regex

1. Skim chapters to find what you need
2. Focus on the "Good vs Bad" sections
3. Learn how PHPRegex can validate and explain patterns
4. Pay special attention to the [Performance chapter](08-performance-redos.md)

For a compact list of everyday patterns and the special characters that need escaping, see [Chapter 1](01-basics.md#escaping-special-characters) and the [diagnostics cheatsheet](../reference/diagnostics-cheatsheet.md).

---

## Start Here

**[Chapter 1: Regex Basics](01-basics.md)**

---

## If You Get Stuck

1. **Use the explain command**: `vendor/bin/regex explain '/your-pattern/'`
2. **Visualize it**: `vendor/bin/regex diagram '/your-pattern/'`
3. **Check for errors**: `vendor/bin/regex validate '/your-pattern/'`
4. **Read the FAQ**: [FAQ & glossary](../reference/faq-glossary.md)
5. **Ask questions**: [GitHub Issues](https://github.com/php-regex/php-regex/issues)

---

## Other Resources

- [Regex in PHP Guide](../guides/regex-in-php.md) - PHP-specific regex details
- [Cookbook](../cookbook.md) - Ready-to-use patterns
- [ReDoS Guide](../guides/redos.md) - Security and performance
- [Regex101](https://regex101.com) - Interactive regex tester
