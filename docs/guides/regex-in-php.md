# Regex in PHP

This guide explains how PCRE works in PHP and how RegexParser interprets the same syntax. Examples use full regex literals (`/pattern/flags`).

---

## What Is PCRE?

PHP uses **PCRE2** (Perl Compatible Regular Expressions) for regex operations. This is the same engine used by Perl, and it is widely used and well-tested.

```
PCRE = Perl Compatible Regular Expressions
     = The engine that powers preg_* functions
```

---

## PHP Regex Functions

### The Core Functions

| Function           | What It Does            | Returns    |
|--------------------|-------------------------|------------|
| `preg_match()`     | Find first match        | `1` or `0` |
| `preg_match_all()` | Find all matches        | count      |
| `preg_replace()`   | Find and replace        | string     |
| `preg_split()`     | Split string            | array      |
| `preg_grep()`      | Filter array by pattern | array      |

### Quick Examples

```php
// Find first match
preg_match('/\d+/', 'Order 42', $matches);
echo $matches[0];  // "42"

// Find all matches
preg_match_all('/\d+/', 'Order 42, Price 99', $matches);
print_r($matches[0]);  // Array ( [0] => "42", [1] => "99" )

// Replace
preg_replace('/\s+/', '-', 'hello world');  // "hello-world"

// Split
preg_split('/,\s*/', 'a, b, c');  // Array ( [0] => "a", [1] => "b", [2] => "c" )

// Filter
$users = ['admin', 'user1', 'guest', 'moderator'];
preg_grep('/^admin|moderator/', $users);  // Array ( [0] => "admin", [3] => "moderator" )
```

---

## Delimiters: The / Characters

Every PHP regex pattern needs **delimiters** - characters that mark the start and end:

### Standard Delimiter

```php
$pattern = '/hello/i';  // / is the delimiter
```

### When Your Pattern Contains /

Use a different delimiter:

```php
// Problem: / appears in the pattern
$pattern = '/https://example.com/';  // Error: unescaped delimiter

// Solution: Use # as delimiter
$pattern = '#https://example\.com#';

// Or ~ or any non-alphanumeric character
$pattern = '~https://example\.com~';
$pattern = '%https://example\.com%';
```

### Valid Delimiters

```
/ # ~ % , ; : ! ' " ( ) [ ] { } < > |
```

### Delimiter Anatomy

```
Pattern: /^[a-z]+@[a-z]+\.[a-z]+$/i
Delimiter: /
Body: ^[a-z]+@[a-z]+\.[a-z]+$
Flags: i
```

---

## Pattern Modifiers (Flags)

Add flags after the closing delimiter to change behavior:

### Most Common Flags

| Flag | Name             | Effect                            |
|------|------------------|-----------------------------------|
| `i`  | Case-insensitive | `/hello/i` matches "HELLO"        |
| `m`  | Multiline        | `^` and `$` match line boundaries |
| `s`  | Dot-all          | `.` matches newlines too          |
| `u`  | Unicode          | Unicode support                   |
| `x`  | Extended         | Ignore whitespace, allow comments |

### Examples

```php
// Case-insensitive
preg_match('/hello/i', 'HELLO');  // Match: yes

// Multiline (^ and $ match each line)
preg_match('/^error/m', "line1\nerror here");  // Match: yes

// Dot-all (. includes newlines)
preg_match('/hello.world/s', "hello\nworld");  // Match: yes

// Unicode
preg_match('/^\p{L}+$/u', 'café');  // Match: yes (Unicode letters)

// Extended mode (ignores spaces, allows comments)
$pattern = '/(
    \d{3}    # Area code
    -        # Separator
    \d{4}    # Local number
)/x';
```

### Less Common Flags

| Flag | Effect                              |
|------|-------------------------------------|
| `A`  | Anchor to start (like `\A`)         |
| `D`  | Dollar matches end only (like `\z`) |
| `U`  | Ungreedy by default                 |
| `J`  | Allow duplicate names in groups     |

---

## Common Use Cases with Code

### 1. Validate Email

```php
$pattern = '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i';

if (preg_match($pattern, $email)) {
    echo "Valid email format";
}
```

### 2. Extract Data

```php
// Get all email addresses from text
$text = "Contact us at hello@example.com or support@test.org";
preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $matches);
print_r($matches[0]);

// Extract date parts
preg_match('/(\d{4})-(\d{2})-(\d{2})/', '2024-01-15', $matches);
$year = $matches[1];  // "2024"
$month = $matches[2]; // "01"
$day = $matches[3];   // "15"
```

### 3. Find and Replace

```php
// Replace multiple spaces with single space
preg_replace('/\s+/', ' ', 'hello    world');  // "hello world"

// Convert dates from YYYY-MM-DD to DD/MM/YYYY
preg_replace('/(\d{4})-(\d{2})-(\d{2})/', '$3/$2/$1', '2024-01-15');  // "15/01/2024"
```

### 4. Input Validation

```php
// Validate US phone number
$pattern = '/^\(?[0-9]{3}\)?[-. ]?[0-9]{3}[-. ]?[0-9]{4}$/';

if (preg_match($pattern, $phone)) {
    echo "Valid phone number";
}

// Validate password strength
$pattern = '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/';
```

---

## Important: preg_quote()

When inserting user input into patterns, **always use `preg_quote()`**:

```php
// DANGEROUS: User input directly in pattern!
$userInput = 'test+';  // + is a regex special character!
$pattern = "/$userInput/";  // Could fail or match unexpected things

// SAFE: Escape user input
$userInput = 'test+';
$pattern = '/'.preg_quote($userInput, '/').'/';
echo $pattern;  // "/test\+/"
```

### Syntax

```php
preg_quote(string $pattern, string $delimiter = null): string
```

---

## Error Handling

### Check for Errors

```php
preg_match('/invalid[/', 'test', $matches);

// Always check the result!
if (preg_last_error() === PREG_NO_ERROR) {
    echo "Match attempted successfully";
} else {
    echo "Error: " . preg_last_error_msg();
}
```

### Error Codes

| Constant                     | Meaning                    |
|------------------------------|----------------------------|
| `PREG_NO_ERROR`              | No error                   |
| `PREG_INTERNAL_ERROR`        | PCRE internal error        |
| `PREG_BACKTRACK_LIMIT_ERROR` | Backtrack limit reached    |
| `PREG_RECURSION_LIMIT_ERROR` | Recursion limit reached    |
| `PREG_BAD_UTF8_ERROR`        | Malformed UTF-8            |
| `PREG_BAD_UTF8_OFFSET_ERROR` | UTF-8 offset doesn't match |

---

## Performance: PCRE Limits

For untrusted input, set limits to prevent runaway patterns:

```php
// Set limits (php.ini or runtime)
ini_set('pcre.backtrack_limit', '1000000');  // 1 million
ini_set('pcre.recursion_limit', '100000');   // 100 thousand

// What happens when limits are reached?
$result = preg_match('/(a+)+$/m', str_repeat('a', 1000000) . '!');

if ($result === false) {
    echo "Pattern too complex or input too long";
    echo "Error: " . preg_last_error_msg();
}
```

### Why Limits Matter

Without limits, malicious input can cause:
- **CPU exhaustion** (exponential backtracking)
- **Memory exhaustion** (deep recursion)
- **Service denial** (application hangs)

---

## Common Mistakes and How to Fix Them

### Mistake 1: Forgetting Anchors

```php
// Unanchored pattern matches inside longer text
preg_match('/bar/', 'foobar');  // Match: yes (not desired)

// Anchors require a full-string match
preg_match('/^bar$/', 'bar');  // Match: yes
preg_match('/^bar$/', 'foobar');  // Match: no
```

### Mistake 2: Using [A-z]

```php
// [A-z] includes characters between Z and a: [ \ ] ^ _ `
// This matches underscores!
preg_match('/^[A-z]+$/', 'hello_world');  // Match: yes (unexpected)

// Correct: [A-Za-z]
preg_match('/^[A-Za-z]+$/', 'hello_world');  // Match: no
```

### Mistake 3: Not Escaping Special Characters

```php
// . matches any character!
preg_match('/file.txt/', 'fileXtxt');  // Match: yes (incorrect)

// Escape special characters
preg_match('/file\.txt/', 'file.txt');  // Match: yes
preg_match('/file\.txt/', 'fileXtxt');  // Match: no
```

### Mistake 4: Greedy Matching

```php
$html = '<p>Hello</p><p>World</p>';

// Greedy: matches everything to the last >
preg_match('/<p>.*<\/p>/', $html, $m);
echo $m[0];  // "<p>Hello</p><p>World</p>"

// Lazy: matches to the first >
preg_match('/<p>.*?<\/p>/', $html, $m);
echo $m[0];  // "<p>Hello</p>"
```

---

## Where RegexParser Helps

### 1. Validate Patterns Before Use

```php
use PhpRegex\Toolkit\Regex;

$regex = Regex::create();

$result = $regex->validate($userPattern);

if (!$result->isValid()) {
    echo "Invalid pattern: " . $result->getErrorMessage();
    echo "Hint: " . $result->getHint();
    return;
}
```

### 2. Explain Complex Patterns

```php
echo $regex->explain('/^(?<email>[^@]+)@(?<domain>[^@]+)$/');
```

**Output:**
```
Start of string
  Named group 'email':
    One or more characters that are not @
  Literal @
  Named group 'domain':
    One or more characters that are not @
End of string
```

### 3. Detect ReDoS Vulnerabilities

```php
$analysis = $regex->redos('/(a+)+$/');

if ($analysis->severity->value === 'critical') {
    echo "DANGEROUS: This pattern can cause exponential backtracking!";
    echo "Try: /a+$/ instead";
}
```

### 4. Generate Test Data

```php
// Generate a valid email for testing
$email = $regex->generate('/^[a-z]+@[a-z]+\.[a-z]+$/i');
echo $email;  // Example: "user@example.com"
```

---

## Quick Reference

### Regex to PCRE Translation

| Common Regex    | PCRE Syntax |
|-----------------|-------------|
| Start of string | `^`         |
| End of string   | `$`         |
| Any character   | `.`         |
| Word character  | `\w`        |
| Digit           | `\d`        |
| Whitespace      | `\s`        |
| One or more     | `+`         |
| Zero or more    | `*`         |
| Optional        | `?`         |
| Group           | `(...)`     |
| Alternation     | `           |` |

### Common Patterns

| Task       | Pattern                                  |
|------------|------------------------------------------|
| Email      | `/^[^\s@]+@[^\s@]+\.[^\s@]+$/`           |
| URL        | `/^https?:\/\/[^\s]+$/`                  |
| Phone      | `/^\d{3}-?\d{3}-?\d{4}$/`                |
| Date       | `/^\d{4}-\d{2}-\d{2}$/`                  |
| IP Address | `/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/` |

---

## Learn More

- **[Regex Tutorial](../tutorial/README.md)** - Step-by-step guide
- **[Quick Start](../QUICK_START.md)** - Get productive in 5 minutes
- **[ReDoS Guide](../REDOS_GUIDE.md)** - Prevent catastrophic backtracking
- **[Cookbook](../COOKBOOK.md)** - Ready-to-use patterns

---

## Troubleshooting

| Problem                  | Solution                           |
|--------------------------|------------------------------------|
| Pattern not matching     | Add `^` and `$` anchors            |
| Partial matches          | Use anchors to match entire string |
| Error "unknown modifier" | Check your delimiters              |
| Unicode not working      | Add `u` flag                       |
| Performance issues       | Check for ReDoS, set limits        |
| Complex pattern          | Use RegexParser to explain it      |

---

**Next:** [CLI Guide](cli.md)

---

Previous: [Docs Home](../README.md) | Next: [CLI Guide](cli.md)
