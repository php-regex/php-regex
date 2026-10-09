# Chapter 8: Performance and ReDoS

> **Goal:** Write fast, safe regex patterns and understand catastrophic backtracking.

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

| Pattern    | Input                  | Time to Match |
|------------|------------------------|---------------|
| `/a+b/`    | "aaa...aab" (1000 a's) | ~1ms          |
| `/(a+)+$/` | "aaa...aab" (1000 a's) | ~MINUTES!     |

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

### 2. Overlapping Alternations

```php
// DANGEROUS: Overlapping alternatives
'/(a|aa)+$/'

// Why dangerous? Engine tries "a" then "aa" in various combinations
```

### 3. Dot-Star Inside Repetition

```php
// DANGEROUS: .* inside +
'/(.*)+$/'

// DANGEROUS: Dot-star with alternation
'/((.|\n)+)$/'
```

---

## Safer Patterns

### 1. Atomic Groups `(?>...)`

Once inside, the engine **never backtracks**:

```php
// Risky: Can backtrack
'/(a+)+$/'

// Safe: Atomic group prevents backtracking
'/(?>a+)+$/'
```

### 2. Possessive Quantifiers ++, *+, ?+

Once matched, characters are **never released**:

```php
// Risky: Can backtrack
'/(a+)+$/'

// Safe: Possessive quantifiers
'/(a++)+$/'
```

### 3. Mutual Exclusion

Make alternatives **non-overlapping**:

```php
// Risky: Overlapping (a|aa)
'/(a|aa)+$/'

// Better: Put longer patterns first
'/(aa|a)+$/'
```

### 4. Simple Is Better

Often you can simplify:

```php
// Complex and risky
'/(a+)+$/'

// Simple and safe
'/a+$/'  // Same effect for most cases!
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
# Analyze a pattern
vendor/bin/regex debug '/(a+)+$/'

# Output (excerpt):
#   Status:    Exponential backtracking (proven)
#   Severity:  CRITICAL (score 10)
#   Mode:      THEORETICAL
#   Confidence: MEDIUM
#   Culprit:    a+
#   Trigger:    quantifier +
#   Hotspots:   2
#   Attack: "a" x n . "!"
#
# The attack is the input that triggers the blow-up: str_repeat("a", $n) . "!".
```

### Strategy 3: Set Engine Limits

```php
// Set backtrack limit (PHP ini)
ini_set('pcre.backtrack_limit', '1000000');

// Set recursion limit
ini_set('pcre.recursion_limit', '100000');
```

---

## Pattern Comparison Table

| Pattern       | Risk        | Time (1000 chars) | Safe Alternative        |
|---------------|-------------|-------------------|-------------------------|
| `/a+/`        | None        | ~0ms              | -                       |
| `/a*$/`       | None        | ~0ms              | -                       |
| `/(a+)+$/`    | CRITICAL    | Minutes!          | `/(?>a+)+$/` or `/a+$/` |
| `/a{1,100}$/` | None        | ~0ms              | -                       |
| `/(a\|b)+$/`   | LOW        | ~1ms              | `/(?:a\|b)+$/`          |
| `/((a\|b){2,})+$/` | HIGH    | Seconds!          | `/(?:a{2,}\|(?:ab){2,})+$/` |

---

## Exercises

### Exercise 1: Identify Dangerous Patterns

Which patterns are dangerous?

1. `/\d+/`
2. `/(a+)+$/`
3. `/[a-z]+$/`
4. `/((a|aa)+)$/`

```php
// Answers:
// 1. No - Safe
// 2. Yes - CRITICAL - nested quantifiers
// 3. No - Safe
// 4. Yes - HIGH - overlapping alternations
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

// Solution 2: Put longer patterns first
$safe2 = '/(aa|a)+$/';
```

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

---

## Key Takeaways

1. **ReDoS** = exponential backtracking = DoS vulnerability
2. **Nested quantifiers** are the main risk
3. **Atomic groups** `(?>...)` prevent backtracking
4. **Possessive quantifiers** `++`, `*+` prevent backtracking
5. **Longer alternatives first** reduces backtracking
6. **Validate patterns** with PHPRegex before production

---

## Common Errors

### Error: Thinking Short Patterns Are Safe

```php
// Looks harmless but is dangerous!
'/((a+)+)+$/'

// Even nested once can be problematic
'/(a+){2,}/'  // Much safer than /(a+)+/
```

### Error: Forgetting Alternation Order

```php
// Shorter first = more backtracking
'/(a|aa)+$/'

// Longer first = less backtracking
'/(aa|a)+$/'
```

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

---


Previous: [Backreferences](07-backreferences-recursion.md) | Next: [Testing & Debugging](09-testing-debugging.md)