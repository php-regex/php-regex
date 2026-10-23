---
layout: tutorial
description: "Practical regex patterns for everyday tasks in PHP."
---

# Chapter 10: Real-World Patterns in PHP

> **Goal:** Put everything together with patterns you'll actually use.

---

## 1) Email (simple and practical)

For most applications, you don't need a perfect RFC email regex. A simple, anchored one is usually enough.

```php
$pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';

if (preg_match($pattern, 'user@example.com')) {
    echo "Looks valid";
}
```

**Notes:**
- `^` and `$` ensure the **entire** string matches
- Use [character classes](02-character-classes.md) to be explicit
- Add `i` flag if you want to be case-insensitive

```php
$pattern = '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i';
```

---

## 2) Dates (YYYY-MM-DD)

Validate the format with [anchors](03-anchors-boundaries.md) and [alternation](05-groups-alternation.md).

```php
$pattern = '/^(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

if (preg_match($pattern, '2025-10-23')) {
    echo "Valid format";
}
```

To check if it's a **real** calendar date, combine with PHP's `checkdate()`:

```php
if (preg_match('/^(?<y>(?:19|20)\d{2})-(?<m>0[1-9]|1[0-2])-(?<d>0[1-9]|[12]\d|3[01])$/', $input, $m)) {
    if (checkdate((int)$m['m'], (int)$m['d'], (int)$m['y'])) {
        echo "Valid date";
    }
}
```

---

## 3) URLs

A practical pattern for `http://` and `https://`.

```php
$pattern = '~^https?://[a-z0-9.-]+\.[a-z]{2,}(/[^\s]*)?$~i';

$urls = ['https://example.com', 'http://site.org/path?q=1'];
foreach ($urls as $url) {
    echo preg_match($pattern, $url) ? "OK\n" : "NO\n";
}
```

Notice we used `~` as [delimiter](01-basics.md#3-delimiters-wrapping-the-pattern) to avoid escaping `/`.

---

## 4) Phone Numbers (simple)

Covers common formats with spaces, dashes, or parentheses.

```php
$pattern = '/^\+?\d{1,3}[-.\s]?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}$/';

preg_match($pattern, '+1 (555) 123-4567'); // matches
preg_match($pattern, '555-123-4567');     // matches
```

This is flexible on purpose. If you need strict validation for one specific format, tighten it with [groups](05-groups-alternation.md).

---

## 5) Extracting Data

Regex shines at pulling parts out of strings.

### Extract hashtags

```php
$text = 'Learning #php and #regex today!';

preg_match_all('/#(\w+)/', $text, $matches);
print_r($matches[1]); // ["php", "regex"]
```

### Extract domain from email

```php
preg_match('/@(.+)$/', 'user@example.com', $m);
echo $m[1]; // "example.com"
```

### Extract numbers from a string

```php
preg_match_all('/-?\d+/', 'Price: $49, Tax: $5', $matches);
print_r($matches[0]); // ["49", "5"]
```

---

## 6) Replacing Text

Use `preg_replace()` to transform strings.

### Clean up whitespace

```php
$text = "Hello   world\n\nfrom   PHP";
$clean = preg_replace('/\s+/', ' ', $text);
echo $clean; // "Hello world from PHP"
```

### Mask credit card (keep last 4)

```php
$card = '1234567812345678';
$masked = preg_replace('/\d(?=\d{4})/', 'X', $card);
echo $masked; // "XXXXXXXXXXXX5678"
```

Here `(?=\d{4})` is a [lookahead](06-lookarounds.md) — it checks for 4 digits ahead without consuming them.

---

## 7) Validating Forms

A quick example combining what you've learned.

```php
function isValidUsername(string $u): bool {
    // 3-16 chars, letters, numbers, underscore
    return (bool) preg_match('/^[a-zA-Z0-9_]{3,16}$/', $u);
}

function isStrongPassword(string $p): bool {
    // Min 8, at least one letter and one number
    return (bool) preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*#?&]{8,}$/', $p);
}

var_dump(isValidUsername('john_doe')); // true
var_dump(isStrongPassword('pass1234')); // true
```

---

## 8) Tips for Production

- **Anchor your validation** - Use `^` and `$` when you want the *entire* string to match
- **Don't over-validate** - A complex email regex won't catch everything; sometimes a simple check is enough
- **Watch for ReDoS** - If patterns are user-generated or very complex, be mindful of [Chapter 8](08-performance-redos.md)
- **Prefer specific over broad** - `[0-9]` instead of `.`, `[a-z]+` instead of `.*`
- **Use built-in functions when possible** - Sometimes `filter_var()` with `FILTER_VALIDATE_EMAIL` is better than a regex
- **Test negative cases** - Make sure invalid inputs don't pass

---

## 9) Exercises

### Exercise 1: Slug
Create a pattern to convert spaces to hyphens and remove unwanted characters. Use `preg_replace()`.

<details markdown="1">
<summary>Solution</summary>

```php
$text = "Hello World! & PHP";
$slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text));
$slug = trim($slug, '-');
echo $slug; // "hello-world-php"
```

</details>

### Exercise 2: Extract all emails
Extract all email addresses from a text using `preg_match_all()`.

<details markdown="1">
<summary>Solution</summary>

```php
$text = 'Contact us at a@b.com or support@test.org';
preg_match_all('/[\w.-]+@[\w.-]+\.[a-z]{2,}/i', $text, $matches);
print_r($matches[0]);
```

</details>

### Exercise 3: Zip code (US)
Write a pattern for a 5-digit US zip code, optionally followed by a dash and 4 digits (`12345-6789`).

<details markdown="1">
<summary>Solution</summary>

```php
$pattern = '/^\d{5}(?:-\d{4})?$/';
```

</details>

### Exercise 4: Username validation
Create a function that validates a username: starts with a letter, 3-20 chars, only letters, numbers, underscore.

<details markdown="1">
<summary>Solution</summary>

```php
function validateUsername(string $u): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_]{2,19}$/i', $u);
}
```

</details>

---

## Key Takeaways

- **Anchoring is crucial** for validation (`^...$`)
- **`preg_match_all`** is perfect for extracting multiple matches
- **`preg_replace`** is great for cleaning and transforming
- **Lookaheads** are useful for masking/replacing without consuming
- **Combine regex with PHP** - sometimes `checkdate()` or `filter_var()` is better than going overboard

---

## Congratulations!

You've completed the entire **Regex Tutorial**. 

You now know how to:
- Write patterns from scratch
- Use character classes, anchors, quantifiers, groups, and lookarounds
- Handle backreferences and recursion
- Avoid ReDoS and write safe patterns
- Test and debug with confidence
- Apply regex to real PHP problems

Keep practicing. The best way to learn is to take patterns you need in real projects and build them step by step.

---

## Optional: Tools to Inspect Patterns

If you'd like to explain or visualize patterns, these tools are optional and not necessary to follow this chapter.

```bash
vendor/bin/regex explain '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i'
vendor/bin/regex diagram '/^(?=.*\d)[A-Za-z\d]{8,}$/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/\d{5}(?:-\d{4})?$/');
```

---

**End of Tutorial**
