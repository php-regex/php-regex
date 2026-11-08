---
layout: tutorial
description: "Match context without consuming it with lookaheads and lookbehinds — positive and negative."
---

# Chapter 6: Lookarounds & Assertions

> **Goal:** Check what's before or after a match, without including it in the result.

---

## 1) What are Lookarounds?

A **lookaround** is a **zero-width** assertion. "Zero-width" means it consumes no characters: it only checks *"Is there X before/after this position?"* and never adds anything to the match.

If the check passes, the engine continues at the same position. If not, it fails.

This is perfect when you want to **filter by context** without capturing the context itself.

---

## 2) Lookaheads: Check What's Ahead

### Positive lookahead `(?=...)`

Match only if what follows matches `...`

```php
preg_match('/\d+(?=USD)/', 'Price: 100USD', $matches);
echo $matches[0]; // "100" (USD is not included)
```
{: data-run="" }

### Negative lookahead `(?!...)`

Match only if what follows **does not** match `...`

```php
preg_match('/\d+(?!USD)/', 'Price: 100EUR', $matches);
echo $matches[0]; // "100"
```
{: data-run="" }

**Watch out with greedy quantifiers here.** You might expect `\d+(?!USD)` to fail on `"100USD"`, but it won't. Walk through it step by step:

1. `\d+` is greedy, so it first takes the maximum: all three digits, `"100"`.
2. The lookahead `(?!USD)` is checked right after `"100"` — and fails, because `"USD"` does start there.
3. The engine backtracks: `\d+` gives back one digit and now holds `"10"`.
4. The lookahead is checked again, right after `"10"` — the text there is `"0USD"`, which does not start with `"USD"`, so the negative lookahead passes.

The match is `"10"`:

```php
preg_match('/\d+(?!USD)/', 'Price: 100USD', $matches);
echo $matches[0]; // "10" — not "no match"!
```
{: data-run="" }

To truly reject `"100USD"`, anchor the pattern or add context so there's nowhere to backtrack to:

```php
preg_match('/\b\d+\b(?!USD)/', 'Price: 100USD'); // no match — see below
echo preg_match('/\b\d+\b(?!USD)/', 'Price: 100USD'), PHP_EOL; // 0
```
{: data-run="" }

Why does this reject the whole number? The second `\b` demands a word boundary right after the digits. Inside `100USD` every character is a word character (`0`, `U`, `S`, `D` are all `\w`), so `\b` can never match between them — it fails before the lookahead is ever reached, at every length `\d+` tries.

### Greedy + lookahead matters

Remember [quantifiers](04-quantifiers.md) are greedy by default. A greedy quantifier first takes the maximum it can, the lookahead is checked at that position, and if it fails the engine gives back one character at a time and checks again after each give-back. A lazy quantifier does the opposite: it takes the minimum first and only takes more when the lookahead fails there.

```php
// Greedy \w+ takes as much as it can
preg_match('/\w+(?=\d)/', 'test123', $matches);
echo $matches[0]; // "test12"

// Lazy \w+? takes as little as possible
preg_match('/\w+?(?=\d)/', 'test123', $matches);
echo $matches[0]; // "test"
```
{: data-run="" }

Both pass the lookahead — the difference is *where* they stop.

---

## 3) Lookbehinds: Check What's Behind

### Positive lookbehind `(?<=...)`

Match only if what **precedes** matches `...`

```php
preg_match('/(?<=\$)\d+/', 'Total: $49', $matches);
echo $matches[0]; // "49" ($ is not included)
```
{: data-run="" }

### Negative lookbehind `(?<!...)`

Match only if what **precedes does not** match `...`

```php
preg_match('/(?<!\$)\d+/', 'Total: 49', $matches);
echo $matches[0]; // "49"
```
{: data-run="" }

**The same backtracking pitfall applies here.** You might expect `(?<!\$)\d+` to fail on `"$49"`, but `\d+` can start at the `"9"` — which is preceded by `"4"`, not `"$"` — so it matches `"9"`:

```php
preg_match('/(?<!\$)\d+/', 'Total: $49', $matches);
echo $matches[0]; // "9" — not "no match"!
```
{: data-run="" }

To reject the entire number, anchor it so the engine can't skip the first digit:

```php
preg_match('/(?<!\$)\b\d+/', 'Total: $49'); // no match (word boundary forces start at "4")
echo preg_match('/(?<!\$)\b\d+/', 'Total: $49'), PHP_EOL; // 0
```
{: data-run="" }

---

## 4) Practical Examples

### Get filename without extension

```php
preg_match('/^.+?(?=\.[a-z]+$)/i', 'document.pdf', $matches);
echo $matches[0]; // "document"
```
{: data-run="" }

### Match word only if not followed by "ing"

```php
preg_match('/\bwalk(?!ing)\b/', 'I walk to work', $matches); // matches
echo $matches[0], PHP_EOL;
preg_match('/\bwalk(?!ing)\b/', 'I am walking');             // no match
echo preg_match('/\bwalk(?!ing)\b/', 'I am walking'), PHP_EOL;    // 0
```
{: data-run="" }

### Simple password-like check

You can chain lookaheads at the start to assert things exist, without consuming them:

```php
// Must be at least 8 chars and contain at least one digit
$pattern = '/^(?=.*\d).{8,}$/';
preg_match($pattern, 'abcdefg1', $matches); // matches
echo $matches[0], PHP_EOL;
preg_match($pattern, 'abcdefg');            // no match
echo preg_match($pattern, 'abcdefg'), PHP_EOL;   // 0
```
{: data-run="" }

Here `(?=.*\d)` scans ahead from the start to see whether any digit exists anywhere in the subject. Because a lookahead consumes nothing, the engine then returns to position 0 (right after the `^`), and `.{8,}` does the actual consuming from there — which is why the reported match is the whole string, not just the tail after some digit.

---

## 5) Lookbehind Constraints in PCRE

This is important to know in PHP/PCRE:

- **Fixed-length lookbehinds** always work, whatever the PCRE2 version: `(?<=\$)`, `(?<=abc)`, `(?<=\d{3})`
- **Bounded variable-length lookbehinds** — a known minimum *and* maximum, like `(?<=a{1,5})` — need PCRE2 10.43 or newer. PHP 8.4 and newer bundle PCRE2 10.44, so they accept them; a PHP linked against a system PCRE2 depends on that library's version instead. By default the maximum is 255 characters: `(?<=a{1,256})` does not compile.
- **Unbounded lookbehinds** like `(?<=a+)` are refused by every PCRE2 release — the engine insists on a maximum length up front.

If you're ever unsure, test with a fixed length, or restructure with a lookahead instead.

```php
// Unbounded — every engine refuses it
preg_match('/(?<=a+)b/', 'aaab');
var_dump(preg_match('/(?<=a+)b/', 'aaab')); // bool(false), with a compile warning

// Bounded — recent engines accept it (PCRE2 10.43, so PHP 8.4+)
preg_match('/(?<=a{1,5})b/', 'aaab');
var_dump(preg_match('/(?<=a{1,5})b/', 'aaab')); // int(1); bool(false) with the same warning on older engines
```
{: data-run="" }

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
