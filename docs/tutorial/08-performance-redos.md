---
layout: tutorial
description: "Understand catastrophic backtracking, spot risky patterns, and write safer ones."
---

# Chapter 8: Performance & ReDoS

> **Goal:** Learn why some patterns are slow and how to avoid them.

---

## 1) What is ReDoS?

**ReDoS** (Regular Expression Denial of Service) happens when a seemingly harmless pattern takes **exponentially longer** to match certain inputs. 

The engine doesn't crash, it just gets stuck trying an enormous number of possible ways to match — called **catastrophic backtracking**.

```text
Safe:      /a+b/      matches "aaaaab" quickly
Dangerous: /(a+)+$/   can grind to a halt on "aaaaaaaa...!" 
```

In PHP with default settings, this often results in `preg_match()` returning `false` with "backtrack limit exhausted", rather than hanging forever. But it's still a real problem for user input.

---

## 2) How Catastrophic Backtracking Happens

Remember from [Chapter 4](04-quantifiers.md): when a match fails, the engine **backtracks** — it tries different splits by giving characters back.

With **nested quantifiers**, the number of possible splits grows exponentially.

```php
// /(a+)+$/ against lots of 'a' and a final '!': no split can ever match,
// so the engine tries them all — until the backtrack limit stops it
$pattern = '/(a+)+$/';
$subject = str_repeat('a', 24) . '!';

preg_match($pattern, $subject, $matches);
print_r($matches);          // nothing matched
echo preg_last_error_msg(); // Backtrack limit exhausted
```
{: data-run="" }

For `/(a+)+$/` against `"aaaa...!"` (lots of a's followed by `!`):
1. The inner `a+` and outer `(a+)+` can split the a's in many ways
2. None of them allow `$` to match because of `!`
3. The engine tries **every possible combination** before giving up
4. Each extra `a` roughly doubles the number of tries

---

## 3) Risky Patterns to Avoid

### Nested quantifiers

```php
// Risky
'/(a+)+$/'
'/(a*)+$/'
'/([a-z]+)+$/'
```

### Overlapping alternatives

```php
// Risky - "a" and "aa" overlap
'/(a|aa)+$/'
```

### Dot-star in repetition

```php
// Risky in some forms
'/(.*)+$/'
```

**Rule of thumb:** Be very cautious when you have a quantifier inside another quantifier, or alternatives that can match the same text.

---

## 4) Safer Alternatives

### Simplify

Often the fix is to remove the nesting entirely.

```php
// Risky
'/(a+)+$/'

// Safe - same result for valid matches
'/a+$/'
```

### Use atomic groups `(?>...)`

An **atomic group** matches once and never backtracks. Once `(?>...)` commits to what it matched, it's locked in.

```php
// Risky
'/(a+)+$/'

// Safer with atomic group
'/(?>a+)+$/'
```

### Use possessive quantifiers `++`, `*+`, `?+`

Possessive quantifiers are like greedy ones, but they never give back characters.

```php
// Risky
'/(a+)+$/'

// Safer with possessive quantifier
'/(a++)+$/'
```

### For overlapping alternatives, simplify or make possessive

```php
// Risky
'/(a|aa)+$/'

// Best fix - simplify
'/a+$/'

// Also works - make the unit possessive
'/(?:a|aa)++$/'
```

Reordering alternatives (`/(aa|a)+$/`) does **not** fix this — it only changes which path is tried first.

---

## 5) General Best Practices

1. **Keep it simple** - If you can write it with fewer quantifiers, do it
2. **Avoid nesting quantifiers** - `+`, `*`, `{n,m}` should rarely be inside each other
3. **Be specific** - `[a-z]+` is often safer than `.*`
4. **Anchor when possible** - `^...$` helps the engine fail faster in some cases, but won't fix catastrophic backtracking
5. **Test with worst-case inputs** - Think "what if this is all the same character followed by something that won't match?"
6. **Use atomic/possessive only when needed** - They change behavior, so make sure you still match what you intend

---

## 6) Exercises

Use chapters [1](01-basics.md)–[7](07-backreferences-recursion.md).

### Exercise 1: Spot the risk
Which of these are potentially dangerous?

1. `/\d{4}-\d{2}-\d{2}/`
2. `/(a+)+$/`
3. `/^[a-z]+$/`
4. `/(x|xx)+y/`

<details markdown="1">
<summary>Solution</summary>

1. Safe - no nested quantifiers
2. **Dangerous** - nested `+` inside `+`
3. Safe - single quantifier
4. **Dangerous** - overlapping alternatives

</details>

### Exercise 2: Fix it
Rewrite `/(a+)+$/` to be safe in two different ways.

<details markdown="1">
<summary>Solution</summary>

```php
// Option 1: Simplify
'/a+$/'

// Option 2: Atomic group
'/(?>a+)+$/'

// Option 3: Possessive
'/(a++)+$/'
```

</details>

### Exercise 3: Think
Why doesn't `/(aa|a)+$/` fix the issue from `/(a|aa)+$/`?

<details markdown="1">
<summary>Solution</summary>

Because on a **failing** match, the engine still tries all possible splits regardless of which alternative it tries first. Alternative order only matters when **multiple alternatives match** at the same position — it picks the first. On failure, it backtracks through everything.

</details>

---

## Key Takeaways

- **Catastrophic backtracking** happens with nested quantifiers or overlapping alternatives
- **Simplify first** — often the easiest and safest fix
- **Atomic groups** `(?>...)` and **possessive quantifiers** `++`/`*+`/`?+` prevent backtracking
- **Reordering alternatives** won't help on failing matches
- **Be skeptical** of patterns with quantifiers inside quantifiers

---

## Optional: Tools to Detect ReDoS

If you're working with user-provided patterns or want extra reassurance, tools can analyze them. These are **completely optional**.

```bash
vendor/bin/regex debug '/(a+)+$/' --redos-mode=confirmed
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$analysis = $regex->redos('/(a+)+$/');
// Check $analysis->severity
```

---

**Next:** [Chapter 9: Testing & Debugging](09-testing-debugging.md)
