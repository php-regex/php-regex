---
layout: tutorial
description: "Test and debug regex patterns with PHPRegex: explain them in plain English, validate syntax, get proven ReDoS verdicts, generate samples and diagram the AST."
---

# Chapter 9: Testing and Debugging with PHPRegex

> **Goal:** Use PHPRegex to understand, validate, and test your patterns.

---

## Prerequisites

This chapter uses the `Regex` facade and the `vendor/bin/regex` binary. Both come from one install (the binary ships as `php-regex/regex-cli` in the split layout). You need PHP 8.2 or newer with the `mbstring` extension. The [Quick Start](../quick-start.md) walks through the same setup in full.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

---

## Why Use PHPRegex for Testing?

PHPRegex turns cryptic patterns into **readable explanations** and helps you find issues **before** they reach production:

```
Pattern: /^(?<user>\w+)@(?<host>\w+)$/

Without PHPRegex:
  - Stare at the pattern
  - Guess what it does
  - Hope it's correct

With PHPRegex:
  - See plain English explanation
  - Validate syntax automatically
  - Detect potential ReDoS risk
  - Generate test strings
```

---

## Core Features for Testing

### 1. Explain Patterns in Plain English

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain('/^(?<user>\w+)@(?<host>\w+)$/');
```

**Output:**
```
Regex matches
  Anchor: the beginning of a line
  Capturing group (named: 'user')
        Character Type: A word character: [a-zA-Z_0-9] (one or more times)
  End group
  '@'
  Capturing group (named: 'host')
        Character Type: A word character: [a-zA-Z_0-9] (one or more times)
  End group
  Anchor: the end of a line
```

### 2. Validate Syntax

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$result = $regex->validate('/(?<=a+)b/');

if (!$result->isValid) {
    echo "Error: " . $result->error . "\n";
    echo "Hint: " . $result->getHint() . "\n";
    echo "Snippet:\n" . $result->getCaretSnippet() . "\n";
}
```

**Output:**
```
Error: Lookbehind is unbounded. PCRE requires a bounded maximum length.
Hint: Use a bounded quantifier instead of "+".
Snippet:
Line 1: (?<=a+)b
        ^
```

### 3. Visualize Pattern Structure

```bash
# CLI: Show AST diagram
vendor/bin/regex diagram '/^(?<user>\w+)@(?<host>\w+)$/'
```

**Output:**
```
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime : PHP 8.4.26
Command : diagram
Format  : text

  [1/1] Rendering diagram
  Pattern
      → /^(?<user>\w+)@(?<host>\w+)$/

Regex
\-- Sequence
    |-- Anchor (^)
    |-- Group (named) name="user"
    |   \-- Quantifier (+, greedy)
    |       \-- CharType (\w)
    |-- Literal ('@')
    |-- Group (named) name="host"
    |   \-- Quantifier (+, greedy)
    |       \-- CharType (\w)
    \-- Anchor ($)
```

### 4. Syntax Highlighting

```bash
# CLI: Colorized output
vendor/bin/regex highlight '/^(?<user>\w+)@(?<host>\w+)$/'
```

### 5. Generate Test Strings

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Generate sample that matches pattern
$sample = $regex->generate('/[a-z]{3}\d{2}/');
echo $sample;  // Example output: "bjv69" (random — yours will differ)
```

---

## Testing Workflow

### Step 1: Write Your Pattern

```php
// You want to validate email addresses
$pattern = '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i';
```

### Step 2: Explain It

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain($pattern);
```

**Output:**
```
Regex matches (with flags: i)
  Anchor: the beginning of a line
    Character Class: any character in [   Range: from 'a' to 'z',   Range: from '0' to '9',   '.',   '_',   '%',   '+',   '-' ] (one or more times)
  '@'
    Character Class: any character in [   Range: from 'a' to 'z',   Range: from '0' to '9',   '.',   '-' ] (one or more times)
  '.'
    Character Class: any character in [   Range: from 'a' to 'z' ] (at least 2 times)
  Anchor: the end of a line
```

### Step 3: Check for ReDoS Risk

```php
$analysis = $regex->redos($pattern);

echo "Severity: " . $analysis->severity->value . "\n";
echo "Score: " . $analysis->score . "\n";

if ($analysis->severity->value === 'safe') {
    echo "No structural ReDoS risk detected.\n";
}
```

**Output:**
```
Severity: safe
Score: 0
No structural ReDoS risk detected.
```

### Step 4: Generate Test Cases

```php
// Generate matching samples
$validSamples = [
    $regex->generate($pattern),
    $regex->generate($pattern),
    $regex->generate($pattern),
];

print_r($validSamples);
```

**Output** (the generator is random — yours will differ):
```
Array
(
    [0] => t@.6-h.mcvcc
    [1] => %.%e@.d.zpdz
    [2] => _@7-.jmj
)
```

Every sample satisfies the pattern — which is exactly the point: it gives you concrete inputs your test suite can assert on.

### Step 5: Validate in PHP

```php
$testCases = [
    'test@example.com',
    'user.name@domain.org',
    'admin@sub.domain.co.uk',
    'invalid-email',      // Should NOT match
    '@missing-local.com', // Should NOT match
];

foreach ($testCases as $email) {
    $result = preg_match($pattern, $email) ? 'VALID' : 'INVALID';
    echo "$email: $result\n";
}
```

**Output:**
```
test@example.com: VALID
user.name@domain.org: VALID
admin@sub.domain.co.uk: VALID
invalid-email: INVALID
@missing-local.com: INVALID
```

---

## Debugging Common Issues

### Issue 1: Pattern Not Matching Expected Input

```php
// Your pattern
$pattern = '/^[0-9]+$/';
$input = '123abc';

preg_match($pattern, $input, $matches);
echo count($matches) > 0 ? "Match" : "No match";  // "No match"
```

**Debug with PHPRegex:**

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain($pattern);
// Regex matches
//   Anchor: the beginning of a line
//     Character Class: any character in [   Range: from '0' to '9' ] (one or more times)
//   Anchor: the end of a line

echo "Input: '$input'\n";
echo "The pattern requires ALL characters to be digits.\n";
echo "'123abc' contains non-digit characters.\n";
```

**Solution:**
```php
// Match string containing digits (not just digits)
$pattern = '/[0-9]+/';  // Remove anchors
```

### Issue 2: Potential ReDoS Risk

```php
// Suspicious pattern
$pattern = '/(a+)+$/';

// Test with PHPRegex
$analysis = $regex->redos($pattern);

echo "Severity: " . $analysis->severity->value . "\n";
// Output: "critical"

echo $analysis->headline() . "\n";
// Output: "Exponential backtracking (proven)"

echo "Attack: " . $analysis->witness->render() . "\n";
// Output: Attack: "a" x n . "!"

echo $analysis->recommendations[1] . "\n";
// Output: Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++). Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).
```

**Fix:**
```php
$safePattern = '/a+$/';  // Simplify!
```

### Issue 3: Variable-Length Lookbehind

```php
// Invalid in PCRE
$pattern = '/(?<=a+)b/';

$result = $regex->validate($pattern);

echo $result->error . "\n";
echo $result->getHint() . "\n";
```

**Output:**
```
Lookbehind is unbounded. PCRE requires a bounded maximum length.
Use a bounded quantifier instead of "+".
```

---

## Testing Checklist

Before using a pattern in production:

- [ ] **Explain** - Can you understand what it does?
- [ ] **Validate** - Does PHPRegex report any errors?
- [ ] **Security** - Does ReDoS analysis show "safe"?
- [ ] **Coverage** - Does it match all expected cases?
- [ ] **Edge cases** - Does it handle empty strings, special characters?
- [ ] **Performance** - Test with long inputs

---

## Exercise: Testing Workflow

### Your Task

Test this pattern for password validation:

```php
$pattern = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)[A-Za-z\d]{8,}$/';
```

### Solution

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// 1. Explain the pattern
echo "=== Pattern Explanation ===\n";
echo $regex->explain($pattern) . "\n\n";

// 2. Validate syntax
echo "=== Syntax Validation ===\n";
$result = $regex->validate($pattern);
echo $result->isValid ? "Valid\n\n" : "Invalid: " . $result->error . "\n\n";

// 3. Check for ReDoS
echo "=== ReDoS Analysis ===\n";
$analysis = $regex->redos($pattern);
echo "Severity: " . $analysis->severity->value . "\n";
echo "Score: " . $analysis->score . "\n\n";

// 4. Generate test cases
echo "=== Sample Matching Strings ===\n";
for ($i = 0; $i < 3; $i++) {
    $sample = $regex->generate($pattern);
    echo "- $sample\n";
}
```

Output (the samples are random — yours will differ):

```
=== Pattern Explanation ===
Regex matches
  Anchor: the beginning of a line
  Positive lookahead
        Wildcard: any character (may or may not match line terminators) (zero or more times)
    Character Class: any character in [     Range: from 'A' to 'Z' ]
  End group
  Positive lookahead
        Wildcard: any character (may or may not match line terminators) (zero or more times)
    Character Class: any character in [     Range: from 'a' to 'z' ]
  End group
  Positive lookahead
        Wildcard: any character (may or may not match line terminators) (zero or more times)
    Character Type: A digit: [0-9]
  End group
    Character Class: any character in [   Range: from 'A' to 'Z',   Range: from 'a' to 'z',   Character Type: A digit: [0-9] ] (at least 8 times)
  Anchor: the end of a line

=== Syntax Validation ===
Valid

=== ReDoS Analysis ===
Severity: safe
Score: 0

=== Sample Matching Strings ===
- 9u7iUao9
- Aj0Ek4sT5
- FV0Fo5q6jC
```

---

## Key Takeaways

1. **Always explain** patterns to ensure understanding
2. **Validate early** - catch syntax errors before testing
3. **Check ReDoS** - prevent catastrophic backtracking
4. **Generate samples** - create test data automatically
5. **Visualize structure** - see pattern as a tree

---

## When You Get Stuck

1. **Use the CLI** - `vendor/bin/regex explain <pattern>`
2. **Try diagram** - `vendor/bin/regex diagram <pattern>`
3. **Check documentation** - [Regex in PHP guide](../guides/regex-in-php.md)
4. **Ask for help** - [GitHub Issues](https://github.com/php-regex/php-regex/issues)

---

## Recap

In this chapter you used every testing tool the library offers:

- **explain()** and the `explain` command — plain-English walkthroughs
- **validate()** — syntax errors with hints and caret snippets
- **redos()** and the `debug` command — proven risk verdicts with attack inputs
- **generate()** — sample strings your tests can assert on
- **diagram** — the AST as a tree

Combined with the testing checklist above, that is the full loop: explain, validate, analyze, generate, assert.

**Next:** [Chapter 10: Real-World Patterns in PHP](10-real-world-php.md)
