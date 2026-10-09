---
layout: tutorial
permalink: /tutorial/
description: "A ten-chapter hands-on regex course for PHP developers — from first patterns to recursion and ReDoS, every example explained and verified with the PHPRegex toolkit."
---

# Regex Tutorial (PHPRegex Edition)

This tutorial takes you from your first pattern to PCRE features used in production. It uses the PHPRegex CLI and API throughout, so you learn regex and the parser at the same time.

## What You'll Learn

| Chapter | Topic                                            | What You Can Do After           |
|---------|--------------------------------------------------|---------------------------------|
| 1       | [Basics](01-basics.md)                           | Write simple literal patterns   |
| 2       | [Character Classes](02-character-classes.md)     | Match sets of characters        |
| 3       | [Anchors & Boundaries](03-anchors-boundaries.md) | Control where matches occur     |
| 4       | [Quantifiers](04-quantifiers.md)                 | Control repetition              |
| 5       | [Groups & Alternation](05-groups-alternation.md) | Capture and choose              |
| 6       | [Lookarounds](06-lookarounds.md)                 | Match context without consuming |
| 7       | [Backreferences](07-backreferences-recursion.md) | Match repeated patterns         |
| 8       | [Performance & ReDoS](08-performance-redos.md)   | Write safe, fast patterns       |
| 9       | [Testing & Debugging](09-testing-debugging.md)   | Find and fix issues             |
| 10      | [Real-World PHP](10-real-world-php.md)           | Common patterns explained       |

---

## Before You Start

Every chapter mixes two things: regex concepts you can use with `preg_match()` today, and the PHPRegex tools that explain and validate them. Install the library once — the `regex` CLI and the `Regex` facade used throughout both come with it.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

### Prerequisites

- PHP 8.2 or newer
- the `mbstring` extension
- nothing else: the regex engine (PCRE2) ships inside PHP itself

Under the pre-release monorepo install, the `vendor/bin/regex` binary is available immediately; in the split layout it ships as the `php-regex/regex-cli` package.

---

## The Tools You Will Use

### The regex CLI

Every chapter asks you to run the CLI to see what a pattern does:

```bash
vendor/bin/regex explain '/^cat.*dog$/'
```

```
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime : PHP 8.4.26
Command : explain
Format  : text

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
Format  : text

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
