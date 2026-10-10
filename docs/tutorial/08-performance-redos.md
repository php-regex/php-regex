---
layout: tutorial
description: "Understand catastrophic backtracking, measure it on real inputs, and fix it with simplification, atomic groups and possessive quantifiers — every verdict verified."
---

# Chapter 8: Performance and ReDoS

> **Goal:** Write fast, safe regex patterns and understand catastrophic backtracking.

> **Reference:** the [ReDoS guide](../guides/redos.md) is the canonical page
> for verdicts, guarantees, confirmed mode and fixes. This chapter teaches
> the intuition; when the two differ, the guide wins.

---

## What is ReDoS?

**ReDoS** (Regular Expression Denial of Service) happens when a pattern takes **exponentially long** to match certain inputs. Think of it like a **traffic jam** - the engine gets stuck trying all possible paths:

```
Safe Pattern: /a+b/
               "a" + "b" (one or more a's, then b)
               Linear time: O(n)

Dangerous Pattern: /(a+)+$/
                   Nested quantifiers!
                   Exponential time: O(2^n)
```

### Real-World Impact

Measured on PHP 8.4 with PCRE2 10.49. The attack input is `n` times `"a"` followed by `"!"`, which makes the `$` fail:

| Pattern    | Input                | Default settings (JIT on, backtrack limit 1M)         | JIT off, limits raised (2G)        |
|------------|----------------------|--------------------------------------------------------|------------------------------------|
| `/a+b/`    | 1000 × "a" + "b"     | 0.06 ms, match                                          | 0.00 ms                            |
| `/(a+)+$/` | 1000 × "a" + "!"     | 2.2 ms, returns `false` — **silent failure**            | 0.26 s at n=22, 4.10 s at n=26     |

In PHP, a vulnerable pattern rarely hangs: with the default limits it **fails silently**. `preg_match()` returns `false`, `preg_last_error_msg()` says "Backtrack limit exhausted", and code that treats the result as a boolean lets through, or rejects, whatever it is given. The exponential cost is still real — turn the JIT off and raise the limits, and the same pattern takes 4.1 seconds on just 26 characters, 16× slower than at 22; at n=30 the run was still going after 24.8 seconds, when it exhausted a 2-billion backtracks limit. Minutes arrive a few characters later.

---

## Catastrophic Backtracking Explained

### How Backtracking Works

PCRE uses **backtracking** - when a match fails, it tries different combinations:

```
Pattern: /(a+)+b/
Text:    "aaab"

Step 1: Outer (a+)+ matches "aaa"
Step 2: Try to match "b" - fails (next char is nothing!)
Step 3: Backtrack! Reduce outer (a+)+ to "aa"
Step 4: Try "b" - fails
Step 5: Backtrack! Reduce to "a"
Step 6: Try "b" - fails
Step 7: Backtrack! Reduce inner a+ to "aa"
Step 8: ...and so on...

Combinations grow exponentially!
```

### Backtracking intuition

For `/(a+)+b/` on `"aaab"`, the engine tries many ways to split the `a`s between the nested quantifiers. The number of paths grows quickly with input length, which is why nested quantifiers are risky.

---

## Common Risk Patterns

### 1. Nested Quantifiers

```php
// DANGEROUS: Nested + inside +
'/(a+)+$/'

// DANGEROUS: Nested * inside +
'/(a*)+$/'

// DANGEROUS: Quantifier inside quantifier
'/((a|b){2,})+$/'
```

PHPRegex proves all three exponential (critical, score 10).

### 2. Overlapping Alternations

```php
// DANGEROUS: Overlapping alternatives
'/(a|aa)+$/'

// Why dangerous? Engine tries "a" then "aa" in various combinations
```

Also critical (proven) — and as we will see below, reordering the alternatives does **not** defuse it.

### 3. Dot-Star Inside Repetition

```php
// DANGEROUS: .* inside +
'/(.*)+$/'

// DANGEROUS: Dot with an overlapping alternative
'/(.|a)+$/'
```

Both critical (proven). Beware of intuition here: the once-famous `/((.|\n)+)$/` is actually proven **safe** — PCRE optimizes `(.|\n)` into what is effectively an any-character class. This is why you measure instead of guessing.

---

## Safer Patterns

### 1. Atomic Groups `(?>...)`

Once inside, the engine **never backtracks**:

```php
// Risky: Can backtrack
'/(a+)+$/'

// Safe: Atomic group prevents backtracking
'/(?>a+)+$/'   // safe (proven)
```

### 2. Possessive Quantifiers ++, *+, ?+

Once matched, characters are **never released**:

```php
// Risky: Can backtrack
'/(a+)+$/'

// Safe: Possessive quantifiers
'/(a++)+$/'   // safe (proven)
```

### 3. What Does NOT Fix It: Reordering Alternatives

A common myth says `/(a|aa)+$/` is fixed by putting the longer alternative first, giving `/(aa|a)+$/`. It is not. The order changes which paths the engine explores **first** — but on a failing match it explores every path anyway, whatever the order.

```php
// Both critical — measured with pcre.jit=0 on "a" x n + "!":
'/(a|aa)+$/'   // 0.0068 s at n=22, 0.0461 s at n=26 — critical (score 10)
'/(aa|a)+$/'   // 0.0067 s at n=22, 0.0457 s at n=26 — critical (score 10)
```

What actually fixes overlapping alternatives:

```php
// Fix 1: simplify — (a|aa)+ and a+ match exactly the same strings
'/a+$/'        // safe (proven)

// Fix 2: refuse to release — possessive or atomic units
'/(a|aa)++$/'  // 0.0000 s at n=26 (measured)
'/(?>a|aa)+$/' // 0.0000 s at n=26 (measured)
```

### 4. Simple Is Better

Often you can simplify:

```php
// Complex and risky
'/(a+)+$/'

// Simple and safe
'/a+$/'  // Same effect for most cases — and safe (proven)!
```

---

## Prevention Strategies

### Strategy 1: Validate with PHPRegex

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosSeverity;

$regex = Regex::create();

// Check a pattern
$analysis = $regex->redos('/(a+)+$/');

echo $analysis->severity->value;  // "critical"
echo $analysis->score;            // 10

// Block critical patterns
if ($analysis->exceedsThreshold(RedosSeverity::High)) {
    throw new InvalidArgumentException("Pattern is unsafe");
}
```

### Strategy 2: Use the CLI

```bash
# Analyze a pattern; --redos-mode=confirmed replays the attack input
# on the real engine, which is what prints the Confirmation section below
vendor/bin/regex debug '/(a+)+$/' --redos-mode=confirmed
```

```
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime   : PHP 8.4.26
Command   : Debug
PCRE      : 10.49 2026-09-28
PCRE JIT  : 1
Backtrack : 1000000
Recursion : 100000

  [1/3] Heatmap
  Pattern:    /(a+)+$/
                ^^
  Status:    Exponential backtracking (proven)
  Severity:  CRITICAL (score 10)
  Mode:      CONFIRMED
  Confidence: HIGH
  Culprit:    a+
  Trigger:    quantifier +
  Hotspots:   2
  Attack: "a" x n . "!"
  Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).
  Input:      "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!" (auto)

  [2/3] Confirmation
  Status:    CONFIRMED
  Evidence:  backtrack_limit
  Samples:   len=17 avg=1.20ms
  JIT:       0
  Backtrack: 100000
  Recursion: 10000

  [3/3] Findings
  - [MEDIUM] Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...).
      Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.
  - [CRITICAL] Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).
      Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).
```

The attack line is the input that triggers the blow-up: `str_repeat("a", $n) . "!"`.

### Strategy 3: Set Engine Limits

```php
// Set backtrack limit (PHP ini)
ini_set('pcre.backtrack_limit', '1000000');

// Set recursion limit
ini_set('pcre.recursion_limit', '100000');
```

Limits are a tourniquet, not a cure: they turn a hang into a silent `false`. Keep them, but fix the pattern.

---

## Pattern Comparison Table

Verdicts below come straight from `$regex->redos()`; timings were measured with `pcre.jit=0` on each pattern's attack input (limits raised).

| Pattern             | PHPRegex verdict              | Measured (JIT off)                    | Safe alternative                          |
|---------------------|-------------------------------|----------------------------------------|-------------------------------------------|
| `/a+$/`             | safe (proven), score 0        | 0.00 s at n=100                        | — none needed                             |
| `/(a|b)+$/`         | safe (proven), score 0        | 0.017 s at n=1000                      | — none needed; `(?:...)` changes nothing  |
| `/a{1,100}$/`       | safe (proven), score 0        | 0.0001 s at n=1000                     | —                                         |
| `/(a+)+$/`          | critical (proven), score 10   | 0.26 s at n=22, 4.10 s at n=26         | `/a+$/`, `/(a++)+$/`, `/(?>a+)+$/` — all safe (proven) |
| `/((a\|b){2,})+$/`  | critical (proven), score 10   | 44 s on 40 chars of its attack input   | `/[ab]+$/` — safe (proven), 0.005 s at n=1000 |

Note the `/((a|b){2,})+$/` row: its attack input is not plain `a`s but `"aaaa"` repeated — a reminder to generate attacks from the analysis, not from intuition. The proposed fix `/(?:a{2,}|(?:ab){2,})+$/` found in some checklists is itself critical (proven): the `a{2,}` still nests inside the outer `+`.

---

## Exercises

### Exercise 1: Identify Dangerous Patterns

Which patterns are dangerous?

1. `/\d+/`
2. `/(a+)+$/`
3. `/[a-z]+$/`
4. `/((a|aa)+)$/`

```php
// Answers (PHPRegex verdicts):
// 1. No - safe (proven)
// 2. Yes - CRITICAL - nested quantifiers
// 3. No - safe (proven)
// 4. Yes - CRITICAL - overlapping alternations
```

### Exercise 2: Fix Dangerous Patterns

Make these safe:

1. `/(a+)+$/`
2. `/((a|aa)+)$/`

```php
// Solution 1a: Use atomic group
$safe1 = '/(?>a+)+$/';

// Solution 1b: Simplify
$safe1b = '/a+$/';

// Solution 2a: Simplify — (a|aa)+ matches the same strings as a+
$safe2 = '/a+$/';

// Solution 2b: Refuse to release — possessive units
$safe2b = '/(a|aa)++$/';
```

Reordering is not a solution here — see [What Does NOT Fix It](#3-what-does-not-fix-it-reordering-alternatives) above: `/(aa|a)+$/` stays critical.

### Exercise 3: Test with PHPRegex

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$patterns = [
    '/\d+/',
    '/(a+)+$/',
    '/[a-z]+$/',
    '/(aa|a)+$/',
];

foreach ($patterns as $pattern) {
    $analysis = $regex->redos($pattern);
    echo sprintf("%-15s %-10s (score: %d)\n",
        $pattern,
        $analysis->severity->value,
        $analysis->score
    );
}
```

Output:

```
/\d+/           safe       (score: 0)
/(a+)+$/        critical   (score: 10)
/[a-z]+$/       safe       (score: 0)
/(aa|a)+$/      critical   (score: 10)
```

Both alternative orders come out critical — the measurement confirms what the analyzer proves.

---

## Key Takeaways

1. **ReDoS** = exponential backtracking = DoS vulnerability
2. **Nested quantifiers** are the main risk
3. **Atomic groups** `(?>...)` prevent backtracking
4. **Possessive quantifiers** `++`, `*+` prevent backtracking
5. **Reordering alternatives fixes nothing** on failing matches — simplify, or refuse to release
6. **Validate patterns** with PHPRegex before production

---

## Common Errors

### Error: Thinking Short Patterns Are Safe

```php
// Looks harmless but is dangerous!
'/((a+)+)+$/'
```

Shorter is not safer either — a lower bound or an outer maximum does not change the class:

```php
// Still critical (proven): 0.13 s at n=22, 2.31 s at n=26 (measured, JIT off)
'/^(a+){2,}$/'

// Still critical (proven): the outer {1,100} does not cap the ways to split
'/(a+){1,100}$/'

// Safe (proven): no inner quantifier to backtrack
'/(a++){2,}$/'

// Safe (proven): the inner a+ cannot backtrack
'/(?>a+){2,}$/'
```

### Error: Trusting Alternative Order

```php
// The myth: "shorter first = more backtracking, longer first = less"
'/(a|aa)+$/'   // critical (proven)
'/(aa|a)+$/'   // critical (proven) — same paths, explored in a different order
```

On a match that succeeds, order decides which alternative wins (Chapter 5). On a match that fails, every order explores every path. Fix the overlap instead — simplify or make the units possessive.

### Error: Using .* When You Mean Something Specific

```php
// .* can match anything, including too much
'/.*tag/'

// Be specific
'/[a-z]*tag/'
```

---

## Recap

You now understand:
- What ReDoS is and why it's dangerous
- Common risk patterns
- How to write safe patterns
- Using PHPRegex to detect issues

**Next:** [Chapter 9: Testing and Debugging](09-testing-debugging.md)
