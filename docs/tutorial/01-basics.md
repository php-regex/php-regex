---
layout: tutorial
description: "Write your first PHP regex patterns — delimiters, flags and escaping — and validate and explain every one of them with PHPRegex before you run it."
---

# Chapter 1: Regex Basics

> **Goal:** Write your first patterns and understand how regex works.

---

## What is a Pattern?

A **regex pattern** is a search template. It describes what text looks like:

```
Text: "The cat sat on the mat"
Pattern: /cat/
Match: "cat" (at position 4)
```

Think of a pattern like a **wanted poster** for text. You describe what you're looking for, and the regex engine finds matches.

### Real-World Analogy

| Scenario                               | Pattern     | What It Finds         |
|----------------------------------------|-------------|-----------------------|
| Looking for "Alice" in a list          | `/Alice/`   | Anyone named Alice    |
| Looking for any 3-digit number         | `/\d{3}/`   | 123, 456, 999...      |
| Looking for words starting with "test" | `/test\w*/` | test, testing, tested |

---

## Your First Pattern

The examples below use the PHPRegex facade. If you have not installed it yet, see [Before You Start](README.md#installation) — one `composer require` is enough. From here on, **every PHP block in this chapter assumes this import** at the top of your script:

```php
use PHPRegex\Toolkit\Regex;
```

### The Simplest Pattern: Literal Text

```php
$pattern = '/hello/';  // Match the word "hello"
```

The pattern `/hello/` will match:
- Yes "**hello** world"
- Yes "say **hello**"
- No "HELLO" (case-sensitive by default)
- No "hell" (missing "o")

### Try It

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Test a pattern
$result = $regex->validate('/hello/');

if ($result->isValid) {
    echo "Pattern is valid!\n";

    // See what it means
    echo $regex->explain('/hello/') . "\n";
}

// Output:
// Pattern is valid!
// Regex matches
//   'h'
//   'e'
//   'l'
//   'l'
//   'o'
```

The explanation walks the pattern piece by piece — here, five literal characters, one per line.

### Using in PHP

```php
$pattern = '/hello/';
$text = 'Hello world, hello PHP!';

if (preg_match($pattern, $text)) {
    echo "Found 'hello'!";
}
```

**But wait** — the match is case-sensitive: this finds the lower-case `hello`,
not `Hello`. Let's make it match both.

---

## Pattern Modifiers (Flags)

Add flags after the closing `/` to change behavior:

### Common Flags

| Flag | Name             | Effect                            |
|------|------------------|-----------------------------------|
| `i`  | Case-insensitive | `/hello/i` matches "HELLO"        |
| `m`  | Multiline        | `^` and `$` match line boundaries |
| `s`  | Dot-all          | `.` matches newlines              |
| `u`  | Unicode          | Unicode support                   |
| `x`  | Extended         | Ignore whitespace, allow comments |

### Example: Case-Insensitive Match

```php
$pattern = '/hello/i';

preg_match($pattern, 'HELLO world');  // Match: yes
preg_match($pattern, 'Hello PHP');    // Match: yes
preg_match($pattern, 'heLLo');        // Match: yes
```

### Try It

```php
$regex = Regex::create();

echo $regex->explain('/hello/i');
// Output:
// Regex matches (with flags: i)
//   'h'
//   'e'
//   'l'
//   'l'
//   'o'
```

The flags in use are announced on the first line — here the `i`.

---

## Delimiters: The / Characters

Every PHP regex pattern needs **delimiters** - characters that mark the beginning and end:

```php
// Standard: use / as delimiter
'/pattern/'

// When pattern contains /, use a different delimiter
'#https?://#'   // Example match: http:// or https://
'~email: \S+~'  // Example match: "email: something@something"
```

### Valid Delimiters

Any character that is **not alphanumeric, not a backslash and not whitespace** can serve as a delimiter. The most common choices:

```
/ # ~ % , ; : ! ' " ( ) [ ] { } < > |
```

but `+ - . = @ * ?` and others work just as well.

**Rules:**
- Delimiter cannot be alphanumeric
- Delimiter cannot be a backslash `\`
- Delimiter cannot be whitespace
- Opening and closing delimiters are the same character — unless the opening one is a bracket pair `()`, `[]`, `{}` or `<>`, where the closing one is its match: `(pattern)` works, `(pattern]` does not

```php
// Wrong: spaces cannot delimit — this is not a pattern
preg_match(' hello ', 'hello');  // false ("Internal error")

// Right: a real delimiter
preg_match('/hello/', 'hello');  // 1
```

### Example: URL Pattern

```php
// Problem: / appears in the pattern
'/https://example.com/'

// Solution: Use different delimiter
'#https://example\.com#'
// Note: We escape the . with \.
```

### Try It

```php
$regex = Regex::create();

echo $regex->explain('#https://example\.com#');
// Output:
// Regex matches
//   'h'
//   't'
//   't'
//   'p'
//   's'
//   ':'
//   '/'
//   '/'
//   'e'
//   'x'
//   'a'
//   'm'
//   'p'
//   'l'
//   'e'
//   '.'
//   'c'
//   'o'
//   'm'
```

The escaped `\.` appears in the explanation as a plain `'.'` — it matched a literal dot, not "any character".

---

## Escaping Special Characters

Some characters have special meaning in regex. To match them literally, use `\`:

### Special Characters (Must Escape)

```
. ^ $ * + ? ( ) [ ] { } | \
```

### Examples

| Pattern | What It Matches                 |
|---------|---------------------------------|
| `/\./`  | A literal dot (`.`)             |
| `/\$/`  | A literal dollar sign (`$`)     |
| `/\[/`  | A literal opening bracket (`[`) |
| `/\\/`  | A literal backslash (`\`)       |

### Without Escaping (Special Meaning)

| Pattern | Meaning                              |
|---------|--------------------------------------|
| `/./`   | **Any single character**             |
| `/^/`   | Start of string                      |
| `/$/`   | End of string                        |
| `/a*/`  | Zero or more `a`s (the `*` quantifier) |

The quantifiers need something to repeat: `a*` is "zero or more `a`s". The pattern `/*/` is not that — `\*` is the *escaped* form from the table above, a literal star.

### Try It

```php
$regex = Regex::create();

// Literal dot vs any character
echo $regex->explain('/\./');
// Output:
// Regex matches
//   '.'

echo $regex->explain('/./');
// Output:
// Regex matches
//   Wildcard: any character (may or may not match line terminators)
```

---

## Your First Example

### Validate an Email (Simple Version)

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// A simple email pattern
$pattern = '/^[a-z]+@[a-z]+\.[a-z]+$/';

$result = $regex->validate($pattern);

if ($result->isValid) {
    echo "Valid email pattern!\n";
    echo "Explanation: " . $regex->explain($pattern) . "\n";
} else {
    echo "Error: " . $result->error . "\n";
}
```

### What the Pattern Does

Pattern components:
- `/` delimiters
- `^` start of string
- `[a-z]+` one or more letters (local part)
- `@` literal at sign
- `[a-z]+` one or more letters (domain)
- `\.` literal dot
- `[a-z]+` one or more letters (TLD)
- `$` end of string

---

## Good Patterns vs Bad Patterns

### Good: Clear and Specific

```php
// Match exactly "error" at the start of a line
'/^error/'

// Match email addresses (basic)
'/^[a-z]+@[a-z]+\.[a-z]+$/'

// Match phone numbers (US format)
'/^\d{3}-\d{4}$/'
```

### Bad: Too Broad or Wrong

```php
// Too broad - matches almost anything
'/.*/'

// No anchors - matches partial emails
'/[a-z]+@[a-z]+\.[a-z]+/'

// Using [A-z] instead of [A-Za-z]
# [A-z] includes characters between Z and a in ASCII!
'/^[A-z]+$/'
```

---

## Exercises

### Exercise 1: Basic Matching

Create patterns that match:

1. The word "PHP" (case-insensitive)
2. Any 3-digit number
3. A dollar amount like "$99.99"

The last two need the character classes and quantifiers of chapters 2 and 4 —
meet them there and come back, or read the solutions as a preview.

```php
// Solution 1
$pattern1 = '/PHP/i';

// Solution 2
$pattern2 = '/\d{3}/';

// Solution 3
$pattern3 = '/\$\d+\.\d{2}/';
```

### Exercise 2: Validate Your Patterns

```php
$regex = Regex::create();

$patterns = [
    '/PHP/i',
    '/\d{3}/',
    '/\$\d+\.\d{2}/',
];

foreach ($patterns as $pattern) {
    $result = $regex->validate($pattern);
    echo "$pattern: " . ($result->isValid ? "Valid" : "Invalid") . "\n";
}
```

### Exercise 3: Explain Your Patterns

```php
$regex = Regex::create();

echo $regex->explain('/PHP/i');
echo $regex->explain('/\d{3}/');
echo $regex->explain('/\$\d+\.\d{2}/');
```

---

## Key Takeaways

1. **Patterns** describe text you want to match
2. **Delimiters** (`/`) mark the start and end
3. **Flags** (`i`, `m`, etc.) modify behavior
4. **Escape** special characters with `\` to match literally
5. **Anchors** (`^` and `$`) control where matches occur
6. **Use PHPRegex** to validate and explain your patterns!

---

## Common Errors

### Error: Unknown regex flag(s) found: "a"

```php
// Wrong: 'a' after the closing / is read as a flag — and no such flag exists
$result = $regex->validate('/hello/a');
echo $result->error;
// Output: Unknown regex flag(s) found: "a"

// Right: Put real flags after the closing /
$result = $regex->validate('/hello/i');
```

### Error: Expected ) at end of input (found eof)

```php
// Wrong: Unclosed parenthesis
$result = $regex->validate('/(hello/');
echo $result->error;
// Output: Expected ) at end of input (found eof)

// Right: Matching parentheses
$result = $regex->validate('/(hello)/');
```

---

## Recap

You now understand:
- What regex patterns are
- How to write basic patterns
- Flags and delimiters
- Escaping special characters

**Next:** [Chapter 2: Character Classes](02-character-classes.md)
