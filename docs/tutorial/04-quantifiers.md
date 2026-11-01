---
layout: tutorial
description: "Control repetition with *, +, ?, and {n,m} — and understand greedy vs lazy matching."
---

# Chapter 4: Quantifiers & Greediness

> **Goal:** Say *how many times* something should match.

---

## 1) What are Quantifiers?

A **quantifier** applies to the element right before it. It tells the engine: *"match this thing this many times"*.

We've already seen them in passing, now we'll look at them properly.

| Quantifier | Meaning | Example |
|------------|---------|---------|
| `*` | Zero or more | `a*` matches "", "a", "aa", ... |
| `+` | One or more | `a+` matches "a", "aa", ... (at least one) |
| `?` | Zero or one | `a?` matches "" or "a" (optional) |
| `{n}` | Exactly `n` | `a{3}` matches "aaa" |
| `{n,}` | At least `n` | `a{2,}` matches "aa", "aaa", ... |
| `{n,m}` | Between `n` and `m` | `a{2,4}` matches "aa", "aaa", "aaaa" |

They work on a single character, a [character class](02-character-classes.md), or a [group](05-groups-alternation.md) (we'll see groups next chapter).

---

## 2) The Basics

### `*` — zero or more

```php
preg_match('/ab*/', 'a', $matches);    // matches "a" (zero 'b's)
print_r($matches[0]);
preg_match('/ab*/', 'ab', $matches);   // matches "ab"
print_r($matches[0]);
preg_match('/ab*/', 'abbb', $matches); // matches "abbb"
print_r($matches[0]);
```
{: data-run="" }

### `+` — one or more

```php
preg_match('/ab+/', 'a'); // no match (needs at least one 'b')
print_r(preg_match('/ab+/', 'a')); // 0
preg_match('/ab+/', 'ab', $matches);   // matches "ab"
print_r($matches[0]);
preg_match('/ab+/', 'abbb', $matches); // matches "abbb"
print_r($matches[0]);
```
{: data-run="" }

### `?` — zero or one (optional)

```php
preg_match('/ab?/', 'a', $matches);   // matches "a"
print_r($matches[0]);
preg_match('/ab?/', 'ab', $matches);  // matches "ab"
print_r($matches[0]);
preg_match('/ab?/', 'abb', $matches); // matches "ab" (only takes one 'b')
print_r($matches[0]);
```
{: data-run="" }

### Combining with character classes

This is where quantifiers become really useful.

```php
preg_match('/\d+/', 'Order 123', $matches);      // matches "123"
print_r($matches[0]);
preg_match('/[a-z]+/', 'Hello World', $matches); // matches "ello"
print_r($matches[0]);
preg_match('/\s*/', 'abc', $matches);            // matches "" (zero spaces)
print_r($matches[0]);
```
{: data-run="" }

---

## 3) Exact Ranges with `{}`

When you need precision, use curly braces.

```php
preg_match('/\d{3}/', '123', $matches);     // matches "123"
print_r($matches[0]);
preg_match('/\d{3}/', '12');                // no match
print_r(preg_match('/\d{3}/', '12'));       // 0
preg_match('/\d{3}/', '12345', $matches);   // matches "123" (first 3)
print_r($matches[0]);
preg_match('/\d{2,4}/', '12345', $matches); // matches "1234" (up to 4)
print_r($matches[0]);
preg_match('/\d{2,}/', '1');                // no match (needs at least 2)
print_r(preg_match('/\d{2,}/', '1'));       // 0
preg_match('/\d{2,}/', '123', $matches);    // matches "123"
print_r($matches[0]);
```
{: data-run="" }

A common example: a 3-digit code: `/^\d{3}$/` with [anchors](03-anchors-boundaries.md) to match the whole string.

---

## 4) Greedy by Default

By default, quantifiers are **greedy**. That means: *"take as much as possible while still allowing the rest of the pattern to match"*.

```php
$text = "<p>hello</p>";

preg_match('/<.+>/', $text, $matches);
echo $matches[0]; // Output: "<p>hello</p>"
```
{: data-run="" }

The `.+` could stop earlier, but greedy makes it go all the way to the last `>`.

### How does it actually work?

The engine tries to match greedily first. If later parts of the pattern fail, it **backtracks**: it gives back one character at a time and tries again.

This is normal behaviour. Backtracking only becomes problematic in very specific cases with nested quantifiers — we'll touch on that briefly at the end and go deeper in [Chapter 8](08-performance-redos.md).

---

## 5) Lazy (Non-Greedy) Matching

To make a quantifier **lazy**, add `?` after it: `*?`, `+?`, `??`, `{n,m}?`

A lazy quantifier does the opposite: *"take as little as possible"*.

```php
$text = "<p>hello</p>";

preg_match('/<.+?>/', $text, $matches);
echo $matches[0]; // Output: "<p>"
```
{: data-run="" }

### Greedy vs Lazy side by side

```php
$text = "<div>test</div>";

preg_match('/<.+>/',  $text, $m1);  // greedy
preg_match('/<.+?>/', $text, $m2);  // lazy

echo $m1[0]; // <div>test</div>
echo $m2[0]; // <div>
```
{: data-run="" }

### Extracting content between tags

To get just the inside of the first tag, lazy is perfect:

```php
$text = "<p>hello</p><p>world</p>";

preg_match('/<p>(.+?)<\/p>/', $text, $matches);
echo $matches[1]; // "hello"
```
{: data-run="" }

If you want **all** matches, use `preg_match_all()`.

```php
$text = "<p>hello</p><p>world</p>";

preg_match_all('/<p>(.+?)<\/p>/', $text, $matches);
print_r($matches[1]); // ["hello", "world"]
```
{: data-run="" }

---

## 6) A Note on Possessive Quantifiers

You might see `++`, `*+`, `?+`. These are **possessive** quantifiers: they never give back characters if something goes wrong. They can help in specific performance cases, but they're not something you need to reach for on day one.

For now, just know they exist. We'll cover when they matter in [Chapter 8: Performance & ReDoS](08-performance-redos.md).

---

## 7) Exercises

Use only what you've learned so far ([Chapters 1-3](01-basics.md)).

### Exercise 1: Zero or more
Write a pattern that matches `go`, `goo`, `gooo`, etc. using `*`.

<details markdown="1">
<summary>Solution</summary>

```php
'/go*/'
```

</details>

### Exercise 2: One or more
Write a pattern using `\d+` that matches one or more digits.

<details markdown="1">
<summary>Solution</summary>

```php
'/\d+/'
```

</details>

### Exercise 3: Optional letter
Write a pattern that matches `color` or `colour` (the `u` is optional).

<details markdown="1">
<summary>Solution</summary>

```php
'/colou?r/'
```

</details>

### Exercise 4: Exact count
Write a pattern that matches exactly 4 digits.

<details markdown="1">
<summary>Solution</summary>

```php
'/\d{4}/'
```

</details>

### Exercise 5: Greedy vs lazy
Given `<b>bold</b>`, write two patterns:
1. A greedy one that matches the whole thing
2. A lazy one that matches only `<b>`

<details markdown="1">
<summary>Solution</summary>

```php
// 1) Greedy
'/<.+>/' 

// 2) Lazy
'/<.+?>/'
```

</details>

---

## Key Takeaways

- **Quantifiers** control how many times to repeat: `*`, `+`, `?`, `{n,m}`
- `*` = 0 or more, `+` = 1 or more, `?` = 0 or 1
- Use `{n}`, `{n,}`, `{n,m}` for precise counts
- **Greedy** (default) = match as much as possible
- **Lazy** (`?` after quantifier) = match as little as possible
- Mix with [character classes](02-character-classes.md) and [anchors](03-anchors-boundaries.md) for powerful patterns

---

## Common Pitfalls

- **Forgetting `+` needs at least one** — `/\d+/` won't match an empty string
- **Greedy going too far** — use lazy `+?`/`*?` when you want the *first* match between delimiters
- **Not anchoring when validating** — `/\d+/` finds digits anywhere. If you want *only* digits, use `/^\d+$/`

---

## Optional: Tools to Inspect Patterns

If you'd like to visualize greedy vs lazy behavior, these tools are optional:

```bash
vendor/bin/regex explain '/<.+?>/'
vendor/bin/regex diagram '/\d{2,4}/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/a+?/');
```

---

**Next:** [Chapter 5: Groups & Alternation](05-groups-alternation.md)
