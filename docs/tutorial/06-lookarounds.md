---
layout: tutorial
description: "Match context without consuming it with lookaheads and lookbehinds — positive and negative."
---

# Chapter 6: Lookarounds & Assertions

> **Goal:** Check what's before or after a match, without including it in the result.

---

## 1) What are Lookarounds?

A **lookaround** is a zero-width assertion. It doesn't match any characters, it just checks: *"Is there X before/after this position?"*

If the check passes, the engine continues. If not, it fails.

This is perfect when you want to **filter by context** without capturing the context itself.

---

## 2) Lookaheads: Check What's Ahead

### Positive lookahead `(?=...)`

Match only if what follows matches `...`

```php
preg_match('/\d+(?=USD)/', 'Price: 100USD', $matches);
echo $matches[0]; // "100" (USD is not included)
```

### Negative lookahead `(?!...)`

Match only if what follows **does not** match `...`

```php
preg_match('/\d+(?!USD)/', 'Price: 100EUR', $matches);
echo $matches[0]; // "100"

preg_match('/\d+(?!USD)/', 'Price: 100USD', $matches); // no match
```

### Greedy + lookahead matters

Remember [quantifiers](04-quantifiers.md) are greedy by default. The lookahead is checked at the position where the quantifier **stops**.

```php
// Greedy \w+ takes as much as it can
preg_match('/\w+(?=\d)/', 'test123', $matches);
echo $matches[0]; // "test12"

// Lazy \w+? takes as little as possible
preg_match('/\w+?(?=\d)/', 'test123', $matches);
echo $matches[0]; // "test"
```

Both pass the lookahead — the difference is *where* they stop.

---

## 3) Lookbehinds: Check What's Behind

### Positive lookbehind `(?<=...)`

Match only if what **precedes** matches `...`

```php
preg_match('/(?<=\$)\d+/', 'Total: $49', $matches);
echo $matches[0]; // "49" ($ is not included)
```

### Negative lookbehind `(?<!...)`

Match only if what **precedes does not** match `...`

```php
preg_match('/(?<!\$)\d+/', 'Total: 49', $matches);
echo $matches[0]; // "49"

preg_match('/(?<!\$)\d+/', 'Total: $49', $matches); // no match
```

---

## 4) Practical Examples

### Get filename without extension

```php
preg_match('/^.+?(?=\.[a-z]+$)/i', 'document.pdf', $matches);
echo $matches[0]; // "document"
```

### Match word only if not followed by "ing"

```php
preg_match('/\bwalk(?!ing)\b/', 'I walk to work', $matches);   // matches
preg_match('/\bwalk(?!ing)\b/', 'I am walking', $matches);    // no match
```

### Simple password-like check

You can chain lookaheads at the start to assert things exist, without consuming them:

```php
// Must be at least 8 chars and contain at least one digit
$pattern = '/^(?=.*\d).{8,}$/';
preg_match($pattern, 'abcdefg1', $matches); // matches
preg_match($pattern, 'abcdefg',  $matches); // no match
```

Here `(?=.*\d)` scans ahead from the start to see if any digit exists. It doesn't add to the match, `.{8,}` does the actual consuming.

---

## 5) Lookbehind Constraints in PCRE

This is important to know in PHP/PCRE:

- **Fixed-length lookbehinds** are always fine: `(?<=\$)`, `(?<=abc)`, `(?<=\d{3})`
- **Variable-length lookbehinds** must have a **known maximum length**. `(?<=a{1,3})` is allowed in modern PCRE2. `(?<=a+)` (unbounded) is **not** allowed.
- Support for arbitrary variable-length lookbehinds depends on PCRE2 version. PHP ships with PCRE2, but older PHP versions may be stricter.

If you're ever unsure, test with a fixed length, or restructure with a lookahead instead.

```php
// Unbounded — usually invalid
preg_match('/(?<=a+)b/', 'aaab'); // may error

// Bounded — safer
preg_match('/(?<=a{1,5})b/', 'aaab'); // more likely to work
```

---

## 6) Exercises

Use chapters [1](01-basics.md)–[5](05-groups-alternation.md).

### Exercise 1: Price without symbol
Match the number only from `$199` using a positive lookbehind.

<details markdown="1">
<summary>Solution</summary>

```php
'/(?<=\$)\d+/'
```

</details>

### Exercise 2: File extension ahead
Match `readme` only if it's followed by `.txt`.

<details markdown="1">
<summary>Solution</summary>

```php
'/readme(?=\.txt)/'
```

</details>

### Exercise 3: Not followed by comma
Match `hello` only if it's **not** followed by a comma.

<details markdown="1">
<summary>Solution</summary>

```php
'/hello(?!,)/'
```

</details>

### Exercise 4: Not preceded by hash
Match digits only if they're **not** preceded by `#`.

<details markdown="1">
<summary>Solution</summary>

```php
'/(?<!#)\d+/'
```

</details>

### Exercise 5: Extract domain from email
Extract just the domain from `user@example.com` using lookarounds (no capturing groups needed, just match the domain).

<details markdown="1">
<summary>Solution</summary>

```php
'/(?<=@)[^@]+/'
```

</details>

---

## Key Takeaways

- **Lookaheads** `(?=...)` / `(?!...)` check ahead, **lookbehinds** `(?<=...)` / `(?<!...)` check behind
- Lookarounds are **zero-width** — they don't consume characters
- Use them to match **context without including context**
- **Lookbehinds** must be bounded in PCRE
- Combine with [groups](05-groups-alternation.md) and [quantifiers](04-quantifiers.md) for flexible checks

---

## Common Pitfalls

- **Forgetting zero-width** — `/(?=\d)\d/` matches a single digit; the lookahead doesn't "add" it
- **Unbounded lookbehind** — avoid `+`, `*` inside lookbehind unless bounded
- **Using lookbehind when lookahead reads better** — often `/X(?=Y)/` is clearer than trying to reverse things
- **Greedy conflicts** — check the position where your quantified part actually stops

---

## Optional: Tools to Inspect Patterns

```bash
vendor/bin/regex explain '/(?<=\$)\d+/'
vendor/bin/regex diagram '/^(?=.*\d).{8,}$/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/\d+(?=USD)/');
```

---

**Next:** [Chapter 7: Backreferences & Recursion](07-backreferences-recursion.md)
