---
layout: tutorial
description: "Group patterns with (...), use alternation with |, and learn capturing, non-capturing, and named groups."
---

# Chapter 5: Groups & Alternation

> **Goal:** Treat parts of a pattern as a unit and choose between alternatives.

---

## 1) What are Groups?

**Groups** let you apply [quantifiers](04-quantifiers.md), [alternation](#3-alternation-the-pipe), or just structure to **more than one character at once**. Think of them like parentheses in math.

```text
Pattern: /ab+/
Matches: "ab", "abb", "abbb" ("b" repeated)

Pattern: /(ab)+/
Matches: "ab", "abab", "ab" in "cabd"
```

With `(ab)+`, the whole pair `ab` is repeated, not just the `b`.

---

## 2) Capturing, Non-Capturing, and Named Groups

### Capturing groups `(...)`

By default, parentheses **capture** what they match. In PHP, you can access those captures with `preg_match()`.

```php
preg_match('/^(\w+) (\w+)$/', 'hello world', $matches);

echo $matches[0]; // "hello world" (full match)
echo $matches[1]; // "hello" (first group)
echo $matches[2]; // "world" (second group)
```

### Non-capturing groups `(?:...)`

If you only need grouping for structure (not to extract), use `(?:...)`. It's cleaner and avoids creating extra captures.

```php
preg_match('/(?:https?):\/\/(\w+)/', 'https://example.com', $matches);

echo $matches[0]; // "https://example"
echo $matches[1]; // "example" (only the domain group is captured)
```

### Named groups `(?<name>...)`

You can give a group a name to make extraction clearer.

```php
preg_match('/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/', '2024-01-15', $matches);

echo $matches['year'];  // "2024"
echo $matches['month']; // "01"
echo $matches['day'];   // "15"
```

You can also use `'name'` or `P<name>` in some flavors, but `(?<name>...)` is common and clear in PCRE/PHP.

---

## 3) Alternation: The Pipe `|`

The `|` means **or**. It tries alternatives from **left to right** and takes the **first** one that matches at that position.

```php
preg_match('/cat|dog/', 'I have a dog', $matches);
echo $matches[0]; // "dog"
```

### Watch your scope

Without grouping, `|` has very wide scope.

```php
// Matches "jpg" OR "png.gif"
preg_match('/jpg|png.gif/', 'image.png.gif', $matches);
echo $matches[0]; // "png.gif"
```

Group to limit what alternation applies to:

```php
// Matches "jpg" OR "png" followed by ".gif"
preg_match('/(?:jpg|png)\.gif/', 'image.png.gif', $matches);
echo $matches[0]; // "png.gif"
```

---

## 4) Combining Groups, Quantifiers, and Alternation

```php
// Match "yes" or "no" possibly with punctuation
preg_match('/^(?:yes|no)[!?.]?$/i', 'Yes!', $matches); // matches
preg_match('/^(?:yes|no)[!?.]?$/i', 'maybe', $matches); // no match

// Repeat a group
preg_match('/^(?:ha)+$/', 'hahahaha', $matches); // matches

// Capture just the extension
preg_match('/\.([a-z]+)$/i', 'photo.jpg', $matches);
echo $matches[1]; // "jpg"
```

---

## 5) Exercises

Use only chapters [1](01-basics.md)–[4](04-quantifiers.md).

### Exercise 1: Group a pair
Write a pattern that matches `"abab"` using `(ab)+`.

<details markdown="1">
<summary>Solution</summary>

```php
'/(ab)+/'
```

</details>

### Exercise 2: Choose between options
Write a pattern that matches either `jpg`, `png`, or `gif` (case-insensitive), using a non-capturing group.

<details markdown="1">
<summary>Solution</summary>

```php
'/(?:jpg|png|gif)/i'
```

</details>

### Exercise 3: Capture parts of a date
Using named groups, capture `year`, `month`, and `day` from `2025-10-23`.

<details markdown="1">
<summary>Solution</summary>

```php
'/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/'
```

</details>

### Exercise 4: Optional suffix
Write a pattern that matches `cat` or `cats` using grouping and `?`.

<details markdown="1">
<summary>Solution</summary>

```php
'/cats?/'
```

Or explicitly grouped: `/(?:cat)s?/`

</details>

### Exercise 5: Scope matters
Fix this so it matches `file.jpg` or `file.png` (not just `jpg` or `png.file`).

```php
/file\.jpg|png/
```

<details markdown="1">
<summary>Solution</summary>

```php
'/file\.(?:jpg|png)/'
```

</details>

---

## Key Takeaways

- **Groups** `(...)` let you treat multiple tokens as one unit
- **Capturing** `(...)` saves matches; **non-capturing** `(?:...)` groups without saving
- **Named groups** `(?<name>...)` make captures easier to read
- **Alternation** `|` picks the **first** matching alternative from left to right
- **Always group** when `|` should apply to a subset of your pattern
- Apply [quantifiers](04-quantifiers.md) to whole groups, not just single characters

---

## Common Pitfalls

- **Forgetting grouping with `|`** — `foo|barbaz` is very different from `(?:foo|bar)baz`
- **Capturing unnecessarily** — use `(?:...)` unless you actually need to extract the value
- **Relying on "longest match" from alternation** — PCRE/PCRE2 uses **left-to-right, first match wins** for alternatives, not "prefer the longest alternative"

---

## Optional: Tools to Inspect Patterns

```bash
vendor/bin/regex explain '/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/'
vendor/bin/regex diagram '/(?:jpg|png|gif)/i'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/(cat|dog)/');
```

---

**Next:** [Chapter 6: Lookarounds & Assertions](06-lookarounds.md)
