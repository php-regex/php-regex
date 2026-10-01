# Chapter 2: Character Classes and Escapes

> **Goal:** Match specific sets of characters like digits, letters, or any character except certain ones.

---

## What is a Character Class?

A **character class** is a way to say "match one character from this set." Think of it like a **menu** - you order one item from the available choices:

```
Pattern: /[aeiou]/
Meaning: Match any ONE vowel (a, e, i, o, or u)

Text: "hello"
Matches: "e", "o"  (two separate matches)
```

### Real-World Analogy

| Scenario              | Character Class | What It Matches      |
|-----------------------|-----------------|----------------------|
| Picking from a menu   | `[Cc]heese`     | "Cheese" or "cheese" |
| Rolling dice          | `[1-6]`         | Any number 1-6       |
| Password requirements | `[A-Za-z0-9]`   | Letters and numbers  |

---

## Basic Character Classes

### The Square Brackets [...]

Put characters inside `[]` to match any one of them:

```php
// Match any vowel
preg_match('/[aeiou]/', 'hello');  // Match: yes ('e' and 'o')

// Match any consonant
preg_match('/[bcdfghjklmnpqrstvwxyz]/', 'hello');  // Match: yes ('h', 'l', 'l')
```

### Character Ranges

Use `-` to specify a range of characters:

```php
/[0-9]/   // Any digit (0 through 9)
/[a-z]/   // Any lowercase letter
/[A-Z]/   // Any uppercase letter
/[a-zA-Z]/ // Any letter (case-insensitive without flag)
```

### Try It

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain('/[aeiou]/');
# Output: "Any one character in: a, e, i, o, u"

echo $regex->explain('/[0-9]/');
# Output: "Any digit from 0 to 9"

echo $regex->explain('/[a-zA-Z]/');
# Output: "Any letter from a-z or A-Z"
```

---

## Negated Character Classes

Use `^` inside `[]` to match anything **except** these characters:

```php
// Match any non-digit
preg_match('/[^0-9]/', 'A1B2');  // Match: yes ('A', 'B')

// Match any character except a, b, or c
preg_match('/[^abc]/', 'def');   // Match: yes ('d', 'e', 'f')
```

### Explanation

- `[^0-9]` matches any single character that is not a digit.
- In `"A1B2"`, the first match is `"A"`.

### Try It

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain('/[^0-9]/');
# Output: "Any character that is NOT a digit from 0 to 9"
```

---

## Shorthand Character Classes

These shortcuts save typing for common character sets:

| Shorthand | Equivalent       | Meaning                |
|-----------|------------------|------------------------|
| `\d`      | `[0-9]`          | Any digit              |
| `\D`      | `[^0-9]`         | Any non-digit          |
| `\w`      | `[a-zA-Z0-9_]`   | Any word character     |
| `\W`      | `[^a-zA-Z0-9_]`  | Any non-word character |
| `\s`      | `[ \t\r\n\f\v]`  | Any whitespace         |
| `\S`      | `[^ \t\r\n\f\v]` | Any non-whitespace     |
| `.`       | (any character)  | Any single character   |

### Examples

```php
// Match a digit
preg_match('/\d/', 'Phone123');  // Match: yes ('1', '2', '3')

// Match a word character (letter, number, underscore)
preg_match('/\w/', 'hello_world');  // Match: yes (all characters)

// Match whitespace
preg_match('/\s/', "hello world");  // Match: yes (the space)
```

### Try It

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain('/\d+/');
# Output: "One or more digits"

echo $regex->explain('/\w+/');
# Output: "One or more word characters (letters, digits, underscore)"

echo $regex->explain('/\s+/');
# Output: "One or more whitespace characters"
```

---

## Unicode Character Classes

Use `\p{...}` with the `u` flag to match Unicode characters:

```php
// Match any Unicode letter
preg_match('/^\p{L}+$/u', 'cafe');  // Match: yes (all letters)

// Match any Unicode number
preg_match('/^\p{N}+$/u', '123');   // Match: yes (all numbers)

// Match emoji (symbols)
preg_match('/\p{Emoji}/u', 'Hello 👋');  // Match: yes ('👋')
```

### Common Unicode Properties

| Property | Meaning               | Example             |
|----------|-----------------------|---------------------|
| `\p{L}`  | Any letter            | `a`, `Z`, `ç`, `Ω`  |
| `\p{N}`  | Any number            | `1`, `５`, `Ⅶ`       |
| `\{P}`   | Any punctuation       | `!`, `,`, `。`       |
| `\p{S}`  | Any symbol            | `$`, `€`, `©`       |
| `\p{Z}`  | Any separator         | space, tab, newline |
| `\p{C}`  | Any control character | NULL, BELL          |

### Try It

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

echo $regex->explain('/^\p{L}+$/u');
# Output: "Start of string, one or more Unicode letters, end of string"
```

---

## Good Patterns vs Bad Patterns

### Good: Clear and Specific

```php
// Match digits only
'/\d+/'

// Match letters only (ASCII)
'/^[A-Za-z]+$/'

// Match letters only (Unicode)
'/^\p{L}+$/u'

// Match word characters
'/\w+/'
```

### Bad: Common Mistakes

```php
// [A-z] includes characters between Z and a in ASCII!
// This includes [ \ ] ^ _ `
'/^[A-z]+$/'

// Correct: [A-Za-z]
'/^[A-Za-z]+$/'

// Using . when you mean literal dot
preg_match('/file.txt/', 'myfile.txt');  // Won't work as expected!

// Correct: Escape the dot
preg_match('/file\.txt/', 'myfile.txt');  // Match: yes ("file.txt")
```

---

## Pattern structure

Pattern: `/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i`

Structure:
- Start anchor `^`.
- Local part: `[a-z0-9._%+-]+`.
- Literal `@`.
- Domain: `[a-z0-9.-]+`.
- Literal dot `\.`.
- TLD: `[a-z]{2,}`.
- End anchor `$`.

This pattern matches email addresses like `user@example.com`.

---

## Exercises

### Exercise 1: Match Different Character Types

Create patterns that match:

1. Any single digit
2. Any lowercase letter
3. Any character that is NOT a letter
4. Any Unicode emoji

```php
// Solution 1
$pattern1 = '/\d/';
// or: '/[0-9]/'

// Solution 2
$pattern2 = '/[a-z]/';

// Solution 3
$pattern3 = '/[^A-Za-z]/';

// Solution 4
$pattern4 = '/\p{Emoji}/u';
```

### Exercise 2: Validate Your Patterns

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$tests = [
    '/\d/' => 'Match a digit',
    '/[a-z]/' => 'Match lowercase letter',
    '/[^A-Za-z]/' => 'Match non-letter',
    '/\p{Emoji}/u' => 'Match emoji',
];

foreach ($tests as $pattern => $description) {
    $result = $regex->validate($pattern);
    echo "$pattern: " . ($result->isValid() ? "Valid" : "Invalid") . " - $description\n";
}
```

### Exercise 3: Explain Each Pattern

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$patterns = ['/\d/', '/[a-z]/', '/[^0-9]/', '/\w/', '/\s/'];

foreach ($patterns as $pattern) {
    echo "\n$pattern\n";
    echo $regex->explain($pattern) . "\n";
}
```

---

## Key Takeaways

1. **Character classes** `[]` match one character from a set
2. **Ranges** `0-9`, `a-z` define consecutive characters
3. **Negation** `[^...]` matches anything except
4. **Shorthands** `\d`, `\w`, `\s` save time
5. **Unicode** `\p{...}` requires the `u` flag
6. **Escape special chars** like `.` with `\` to match literally

---

## Common Errors

### Error: Forgotten Flag for Unicode

```php
// Wrong: Unicode properties need the 'u' flag
preg_match('/^\p{L}+$/', 'café');  // May not work!

// Correct: Add the 'u' flag
preg_match('/^\p{L}+$/u', 'café');  // Works!
```

### Error: [A-z] Trap

```php
// Wrong: [A-z] includes special characters!
preg_match('/^[A-z]+$/', 'test_test');  // Match: yes (includes _)

// Correct: Be explicit
preg_match('/^[A-Za-z]+$/', 'test_test');  // Match: no (no _)
```

### Error: Forgetting to Escape Special Chars

```php
// Wrong: . matches any character
preg_match('/price.99/', 'priceX99');  // Match: yes (X instead of .)

// Correct: Escape the dot
preg_match('/price\.99/', 'priceX99');  // Match: no
preg_match('/price\.99/', 'price.99');  // Match: yes
```

---

## Recap

You now understand:
- Character classes and ranges
- Negated character classes
- Shorthand classes (\d, \w, \s)
- Unicode character properties
- Common pitfalls and how to avoid them

**Next:** [Chapter 3: Anchors and Boundaries](03-anchors-boundaries.md)

---


Previous: [Basics](01-basics.md) | Next: [Anchors & Boundaries](03-anchors-boundaries.md)