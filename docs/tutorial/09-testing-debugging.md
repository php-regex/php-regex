# Chapter 9: Testing and Debugging with PHPRegex

> **Goal:** Use PHPRegex to understand, validate, and test your patterns.

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
Start of string
  Named group 'user':
    One or more word characters
  Literal '@'
  Named group 'host':
    One or more word characters
End of string
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
Regex
└── Sequence
    ├── Anchor (^)
    ├── Group (named: user)
    │   └── Sequence
    │       └── QuantifierNode (+)
    │           └── CharTypeNode (\w)
    ├── Literal (@)
    ├── Group (named: host)
    │   └── Sequence
    │       └── QuantifierNode (+)
    │           └── CharTypeNode (\w)
    └── Anchor ($)
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
echo $sample;  // Example output: "abc12"
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
Start of string
  One or more characters from: a-z, 0-9, ., _, %, +, -
  Literal '@'
  One or more characters from: a-z, 0-9, ., -
  Literal '.'
  Two or more characters from: a-z
End of string (case-insensitive)
```

### Step 3: Check for ReDoS Risk (Theoretical)

```php
$analysis = $regex->redos($pattern);

echo "Severity: " . $analysis->severity->value . "\n";
echo "Score: " . $analysis->score . "\n";

if ($analysis->severity->value === 'safe') {
    echo "No structural ReDoS risk detected.\n";
}
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
// "One or more digits from 0-9, from start to end"

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
// Output: Attack: "a" x n . "!", the input that triggers it

echo "Suggestion (verify behavior): " . $analysis->recommendations[0] . "\n";
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
3. **Check documentation** - `docs/guides/regex-in-php.md`
4. **Ask for help** - [GitHub Issues](https://github.com/php-regex/php-regex/issues)

---

## Recap

Topics covered:

- Pattern basics and structure
- Character classes and escapes
- Anchors and boundaries
- Quantifiers and greediness
- Groups and alternation
- Lookarounds and assertions
- Backreferences and recursion
- Performance and ReDoS prevention
- Testing and debugging

**Next:** [Chapter 10: Real-World Patterns in PHP](10-real-world-php.md)
