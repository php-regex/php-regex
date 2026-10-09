# Chapter 10: Real-World Patterns in PHP

> **Goal:** Apply everything you've learned to common, practical use cases.

---

## Why Real-World Patterns Are Different

Tutorial patterns are simple. Production patterns must:
- Validate user input
- Handle edge cases
- Be secure (no ReDoS)
- Be maintainable
- Be documented

This chapter shows **battle-tested patterns** with explanations.

---

## Email Validation

### The Pattern

```php
$pattern = '/^[a-z0-9]+(?:[._%+-][a-z0-9]+)*+@[a-z0-9-]+(?:\.[a-z0-9-]+)*+$/i';
```

### Explanation

```
Start of string
  Local part:
    One or more alphanumeric characters
    Zero or more of:
      (literal . _ % + -) followed by one or more alphanumeric
  @
  Domain:
    One or more alphanumeric or hyphen
    Zero or more of:
      (literal .) followed by one or more alphanumeric or hyphen
End of string (case-insensitive)
```

### Structure summary

- Local part: `[a-z0-9]+`
- Separator: `@`
- Domain: `[a-z0-9-]+` with dot-separated segments
- TLD: `[a-z0-9-]+`

### Usage

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Validate
$result = $regex->validate($pattern);
if (!$result->isValid) {
    echo "Invalid email: " . $result->error;
    return;
}

// Check for potential ReDoS risk
$analysis = $regex->redos($pattern);
if ($analysis->severity->value !== 'safe') {
    throw new RuntimeException("Pattern has potential ReDoS risk");
}

// Use safely
if (preg_match($pattern, $userInput)) {
    echo "Valid email";
}
```

### Why This Pattern?

| Feature          | Benefit                             |
|------------------|-------------------------------------|
| `^...$`          | Anchored - validates entire string  |
| `*+`             | Possessive - no backtracking        |
| `[a-z0-9]`       | No special characters in local part |
| Case-insensitive | Accepts any case                    |

---

## Date Validation (YYYY-MM-DD)

### The Pattern

```php
$pattern = '/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12][0-9]|3[01])$/';
```

### Explanation

```
Start of string
  Year: 4 digits (named 'year')
  Literal: -
  Month: 01-12 (named 'month')
    0[1-9]  = 01-09
    |1[0-2] = 10-12
  Literal: -
  Day: 01-31 (named 'day')
    0[1-9]   = 01-09
    |[12][0-9] = 10-29
    |3[01]   = 30-31
End of string
```

### Usage

```php
preg_match($pattern, '2024-01-15', $matches);

$year = $matches['year'];   // "2024"
$month = $matches['month']; // "01"
$day = $matches['day'];     // "15"
```

### Note

This validates **format**, not **validity**. "2024-02-30" passes but isn't a real date. For real dates, combine with PHP's `checkdate()`:

```php
if (preg_match($pattern, $input, $m)) {
    if (checkdate((int)$m['month'], (int)$m['day'], (int)$m['year'])) {
        echo "Valid date!";
    } else {
        echo "Invalid calendar date";
    }
}
```

---

## Phone Numbers (US Format)

### The Pattern

```php
$pattern = '/^\+?1?\s*\(?([0-9]{3})\)?\s*-?[0-9]{3}\s*-?[0-9]{4}$/';
```

### Explanation

```
Start of string
  Optional: +1 (country code)
  Optional: whitespace
  Optional: (
  Area code: 3 digits
  Optional: )
  Optional: whitespace or hyphen
  Prefix: 3 digits
  Optional: whitespace or hyphen
  Line number: 4 digits
End of string
```

### Usage

```php
preg_match($pattern, '(555) 123-4567', $matches);
$areaCode = $matches[1];  // "555"
```

---

## URLs (HTTP/HTTPS)

### The Pattern

```php
$pattern = '/^https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b(?:[-a-zA-Z0-9()@:%_\+.~#?&\/\/=]*)$/';
```

### Explanation

```
Start of string
  http:// or https://
  Optional: www.
  Domain: 1-256 characters (alphanumeric, @, %, _, ~, #, =)
  .
  TLD: 1-6 alphanumeric or parentheses
  Word boundary
  Optional path/query: any characters
End of string
```

### Usage

```php
if (preg_match($pattern, $url)) {
    echo "Valid URL format";
}
```

---

## Log Parsing

### The Pattern

```php
$pattern = '/^(?<level>INFO|WARN|ERROR|DEBUG)\s+(?<timestamp>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(?<message>.+)$/';
```

### Explanation

```
Start of string
  Level: INFO, WARN, ERROR, or DEBUG (named 'level')
  Whitespace
  Timestamp: YYYY-MM-DD HH:MM:SS (named 'timestamp')
  Whitespace
  Message: rest of line (named 'message')
End of string
```

### Usage

```php
$logLine = 'INFO 2024-01-15 10:30:45 User logged in successfully';

preg_match($pattern, $logLine, $matches);

echo $matches['level'];      // "INFO"
echo $matches['timestamp'];  // "2024-01-15 10:30:45"
echo $matches['message'];    // "User logged in successfully"
```

---

## Tags (HTML-like)

### The Pattern

```php
$pattern = '/^<([a-z][a-z0-9]*)([^>]*)>(.*?)<\/\1>$/i';
```

### Explanation

```
Start of string
  Opening tag:
    <letter followed by letters/numbers>
    Zero or more attributes (not >)
  >
  Content: any characters (lazy)
  Closing tag: </same opening tag>
End of string (case-insensitive)
```

### Usage

```php
preg_match($pattern, '<div class="container">Content</div>', $matches);

echo $matches[1];   // "div"
echo $matches[2];   // ' class="container"'
echo $matches[3];   // "Content"
```

---

## Password Strength

### The Pattern

```php
$pattern = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';
```

### Explanation

```
Start of string
  Lookahead: at least one uppercase
  Lookahead: at least one lowercase
  Lookahead: at least one digit
  Lookahead: at least one special char (@$!%*?&)
  Main: 8+ characters from allowed set
End of string
```

### Usage

```php
if (preg_match($pattern, $password)) {
    echo "Password is strong";
} else {
    echo "Password doesn't meet requirements";
}
```

---

## Comparison Table

| Use Case | Pattern                                                                                                           | Anchored          | ReDoS Safe | Named Groups |
|----------|-------------------------------------------------------------------------------------------------------------------|-------------------|------------|--------------|
| Email    | `/^[a-z0-9]+(?:[._%+-][a-z0-9]+)*+@[a-z0-9-]+(?:\.[a-z0-9-]+)*+$/i`                                               | Yes                 | Yes          | No            |
| Date     | `/^\d{4}-(?:0[1-9]\|1[0-2])-(?:0[1-9]\|[12]\d\|3[01])$/`                                                          | Yes                 | Yes          | No            |
| URL      | `/^https?:\/\/(?:www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b(?:[-a-zA-Z0-9()@:%_\+.~#?&\/\/=]*)$/` | Yes                 | Yes          | No            |
| Phone    | `/^\+?1?\s*\(?[0-9]{3}\)?\s*-?[0-9]{3}\s*-?[0-9]{4}$/`                                                            | Yes                 | Yes          | No            |
| Password | `/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/`                                          | Yes                 | Yes          | No            |

---

## Exercise: Build and Test a Pattern

### Challenge

Create a pattern to validate a **GitHub username**:
- Starts with letter or number
- Contains letters, numbers, hyphens
- Cannot start or end with hyphen
- Max 39 characters

### Solution

```php
$pattern = '/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,37}[a-zA-Z0-9])?$/';

// Explanation
// ^                Start of string
// [a-zA-Z0-9]     Must start with letter or number
// (?:...)?        Optional tail (allows a single character)
// [a-zA-Z0-9-]{0,37} Middle: 0-37 chars
// [a-zA-Z0-9]     Must end with letter or number
// $                End of string

// Test with PHPRegex
$regex = Regex::create();
echo $regex->explain($pattern);
```

---

## Key Takeaways

1. **Production patterns** need anchors (`^...$`) for exact matching
2. **Use named groups** for clarity and maintainability
3. **Validate format first**, then validate logic separately
4. **Always check ReDoS risk** before using patterns
5. **Lookaheads** are great for validation without consuming

---

## You're a Regex Master!

Tutorial summary:

1. Basics - Your first patterns
2. Character Classes - Matching sets
3. Anchors - Controlling position
4. Quantifiers - Controlling repetition
5. Groups - Structuring patterns
6. Lookarounds - Context matching
7. Backreferences - Self-reference
8. Performance - Avoiding ReDoS
9. Testing - Debugging patterns
10. Real-World - Practical patterns

### Next Steps

- **[Cookbook](../COOKBOOK.md)** - More pattern examples
- **[ReDoS Guide](../REDOS_GUIDE.md)** - Deep dive on risk and mitigation
- **[API Reference](../reference/api.md)** - API documentation
- Apply what you've learned in your own project

---

Tutorial finished.

---

Previous: [Testing & Debugging](09-testing-debugging.md) | Next: [Docs Home](/docs/)
