# Regex Tutorial (RegexParser Edition)

This tutorial takes you from your first pattern to PCRE features used in production. It uses the RegexParser CLI and API throughout, so you learn regex and the parser at the same time.

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

## Tools We Use

### CLI (Explains and Diagrams)

```bash
bin/regex explain '/^cat.*dog$/'
```

```
Match:
  Anchor: start of string
  Literal: "cat"
  Any character, zero or more times
  Literal: "dog"
  Anchor: end of string
```

```bash
bin/regex diagram '/^cat.*dog$/'
```

```
RegexNode
+-- SequenceNode
    |-- AnchorNode("^")
    |-- LiteralNode("cat")
    |-- QuantifierNode("*")
    |   +-- DotNode(".")
    |-- LiteralNode("dog")
    +-- AnchorNode("$")
```

### PHP API

```php
use PhpRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/^cat.*dog$/');
$explanation = $regex->explain('/^cat.*dog$/');
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

## Tools You Will Use

### RegexParser CLI

Throughout this tutorial, use the CLI to visualize patterns:

```bash
# Explain a pattern in plain English
bin/regex explain '/\w+@\w+\.\w+/'

# Show pattern structure as a tree
bin/regex diagram '/\w+@\w+\.\w+/'

# Highlight syntax
bin/regex highlight '/\w+@\w+\.\w+/'

# Check for security issues
bin/regex analyze '/(a+)+$/'
```

### In Your PHP Code

```php
use PhpRegex\Toolkit\Regex;

$regex = Regex::create();

// Validate what you wrote
$result = $regex->validate('/your-pattern/');

// Get explanations
echo $regex->explain('/your-pattern/');

// Generate test data
$sample = $regex->generate('/your-pattern/');
```

---

## How to Use This Tutorial

### For Absolute Beginners

1. Read each chapter in order
2. Try every example in a PHP REPL or script
3. Use `bin/regex explain` to see what your pattern does
4. Work through the exercises at the end of each chapter

### For Those Who Know Regex

1. Skim chapters to find what you need
2. Focus on the "Good vs Bad" sections
3. Learn how RegexParser can validate and explain patterns
4. Pay special attention to the [Performance chapter](08-performance-redos.md)

---

## Quick Reference

### Most Common Patterns

| Pattern    | Matches                     |
|------------|-----------------------------|
| `/hello/`  | The word "hello"            |
| `/[0-9]/`  | Any single digit            |
| `/[a-z]/`  | Any lowercase letter        |
| `/\w+/`    | One or more word characters |
| `/^start/` | "start" at the beginning    |
| `/end$/`   | "end" at the end            |
| `/a?b/`    | "ab" or just "b"            |
| `/a*/`     | Zero or more "a"s           |
| `/a+/`     | One or more "a"s            |
| `/a{3}/`   | Exactly three "a"s          |
| `/a{2,4}/` | Two to four "a"s            |

### Special Characters (Need Escaping)

These characters have special meaning and must be escaped with `\` to match literally:

```
. ^ $ * + ? ( ) [ ] { } | \
```

To match a literal dot: `/\./`
To match a literal dollar sign: `/\$/`

---

## Start Here

**[Chapter 1: Regex Basics](01-basics.md)**

---

## If You Get Stuck

1. **Use the explain command**: `bin/regex explain '/your-pattern/'`
2. **Visualize it**: `bin/regex diagram '/your-pattern/'`
3. **Check for errors**: `bin/regex validate '/your-pattern/'`
4. **Read the FAQ**: [docs/reference/faq-glossary.md](reference/faq-glossary.md)
5. **Ask questions**: [GitHub Issues](https://github.com/php-regex/regex-parser/issues)

---

## Other Resources

- [Regex in PHP Guide](../guides/regex-in-php.md) - PHP-specific regex details
- [Cookbook](../COOKBOOK.md) - Ready-to-use patterns
- [ReDoS Guide](../REDOS_GUIDE.md) - Security and performance
- [Regex101](https://regex101.com) - Interactive regex tester

---


Previous: [Docs Home](../README.md) | Next: [Chapter 1: Basics](01-basics.md)