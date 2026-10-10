---
description: "Copy-ready PHP regex patterns for emails, URLs, dates, phones and more — each one proven safe against ReDoS by the analyzer, with matched and rejected examples."
redirect_from:
  - /COOKBOOK/
  - /COOKBOOK.html
---
# Regex Cookbook: Practical Patterns for PHP

This cookbook collects patterns for common validation and parsing tasks. Every pattern below is `safe (proven)`: the ReDoS analyzer proved that no input makes one match attempt backtrack beyond a linear number of steps. The only unsafe patterns here are the bad examples in [Building Your Own Patterns](#building-your-own-patterns), each shown as the thing to avoid. Review and adapt each pattern for your context.

> These recipes include a short explanation and a quick validation call so you can use them in tooling or code reviews.
>
> Always validate and run `redos()` before accepting user-defined patterns.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

Requires PHP 8.2 or later and the `mbstring` extension. The PHP examples use the `Regex` facade installed above; the [Quick Start](quick-start.md) walks through a first analysis.

## Quick Reference

One row per recipe: the pattern, its section, and the verdict `Regex::redos()` returns for it.

| Task | Pattern | Analyzer verdict |
|------|---------|------------------|
| [Email](#email-rfc-5322-simplified) | `/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i` | `safe (proven)` |
| [URL](#url) | `/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i` | `safe (proven)` |
| [UUID](#uuid-v1-v5) | `/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i` | `safe (proven)` |
| [IPv4](#ip-address) | `/^(?:25[0-5]\|2[0-4][0-9]\|1[0-9][0-9]\|[1-9][0-9]\|[0-9])(?:\.(?:25[0-5]\|2[0-4][0-9]\|1[0-9][0-9]\|[1-9][0-9]\|[0-9])){3}$/` | `safe (proven)` |
| [IPv6](#ip-address) | `/^(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}$/i` | `safe (proven)` |
| [ISO date](#date-formats) | `/^\d{4}-(?:0[1-9]\|1[0-2])-(?:0[1-9]\|[12][0-9]\|3[01])$/` | `safe (proven)` |
| [Time (24h)](#time-formats) | `/^(?:[01]?[0-9]\|2[0-3]):[0-5][0-9]$/` | `safe (proven)` |
| [DateTime](#datetime-iso-8601) | `/^\d{4}-(?:0[1-9]\|1[0-2])-(?:0[1-9]\|[12][0-9]\|3[01])T(?:[01]?[0-9]\|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z\|[+-](?:[01]?[0-9]\|2[0-3]):[0-5][0-9])?$/u` | `safe (proven)` |
| [Slug](#slug) | `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` | `safe (proven)` |
| [Username](#username) | `/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/` | `safe (proven)` |
| [Password](#password-strength) | `/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/` | `safe (proven)` |
| [Phone (E.164)](#phone-number) | `/^\+[1-9]\d{6,14}$/` | `safe (proven)` |
| [Credit card](#credit-card) | `/^[0-9]{13,19}$/` | `safe (proven)` |
| [Hex color](#hex-color) | `/^#(?:[0-9a-fA-F]{3}){1,2}$/` | `safe (proven)` |
| [SemVer](#semantic-versioning) | `/^(0\|[1-9]\d*)\.(0\|[1-9]\d*)\.(0\|[1-9]\d*)(?:-((?:0\|[1-9]\d*\|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0\|[1-9]\d*\|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/` | `safe (proven)` |

## How to Use This Cookbook

Each pattern includes:
- **The pattern** — ready to copy and use
- **What it matches** — examples of valid input
- **What it rejects** — examples of invalid input
- **Why it's safe** — ReDoS analysis notes
- **PHP example** — ready-to-run code, where the recipe needs one

---

## Email (RFC 5322 Simplified)

```
/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i
```

### What It Matches

```
✓ user@example.com
✓ user.name@example.com
✓ user+tag@example.com
✓ a@b.co
```

### What It Rejects

```
✗ @example.com           (missing local part)
✗ user@                  (missing domain)
✗ user@.com              (empty domain part)
✗ user@example..com      (consecutive dots)
✗ user@exam ple.com      (space in domain)
```

### Why It's Safe

- Local part starts with an alphanumeric and allows dots, underscores, percent signs, plus signs, and hyphens in the middle — so Gmail-style `user+tag` addresses pass.
- Domain starts with an alphanumeric and allows hyphenated parts.
- Requires at least one dot-separated TLD.
- Quantifiers are bounded by character classes; there are no nested or overlapping repeats.

### PHP Example

```php
use PHPRegex\Toolkit\Regex;

$email = 'user@example.com';
$pattern = '/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';

$regex = Regex::create();
$result = $regex->validate($pattern);
echo $result->isValid ? 'Valid pattern' : 'Invalid pattern';  // Valid pattern

$isMatch = preg_match($pattern, $email) === 1;
echo $isMatch ? 'Matches' : 'Does not match';                  // Matches

// ReDoS check
$analysis = Regex::create()->redos($pattern);
echo $analysis->headline();                                    // safe (proven)
```

---

## URL

```
/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i
```

### What It Matches

```
✓ https://example.com
✓ http://sub.domain.com/path
✓ https://example.com/path/to/page?query=value
✓ http://localhost:8080
```

### What It Rejects

```
✗ ftp://example.com       (not http/https)
✗ https://                (missing host)
✗ https://exam ple.com    (space in URL)
```

### Why It's Safe

- Accepts `http` or `https`, then `://`.
- Host starts with an alphanumeric and allows dots or hyphens; an optional port is bounded to at most five digits.
- Optional path is limited to non-space characters.
- Repeats are bounded by character classes and string anchors.

### PHP Example

```php
use PHPRegex\Toolkit\Regex;

$url = 'https://example.com/path?query=1';
$pattern = '/^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?(\/[^\s]*)?$/i';

$regex = Regex::create();
$result = $regex->validate($pattern);
echo $result->isValid ? 'Valid pattern' : 'Invalid pattern';  // Valid pattern

$isMatch = preg_match($pattern, $url) === 1;
echo $isMatch ? 'Matches' : 'Does not match';                 // Matches
```

---

## UUID (v1-v5)

```
/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
```

### What It Matches

```
✓ 550e8400-e29b-41d4-a716-446655440000  (v4)
✓ 6ba7b810-9dad-11d1-80b4-00c04fd430c8  (v1)
✓ 6ba7b811-9dad-11d1-80b4-00c04fd430c8  (v1)
```

### What It Rejects

```
✗ 550e8400-e29b-41d4-a716-44665544000   (too short)
✗ 550e8400-e29b-41d4-a716-446655440000g (invalid char)
✗ 550e8400-e29b-41d4-a716-4466554400    (missing group)
```

### Why It's Safe

- Fixed-length hex groups with exact bounds.
- Version and variant positions are constrained.
- No nested quantifiers or ambiguous overlaps.

---

## IP Address

### IPv4

```
/^(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])){3}$/
```

Each octet alternative is deterministic — one way to read it — which is what lets the analyzer prove the whole pattern linear.

### IPv6

```
/^(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}$/i
```

### What It Matches

```
✓ IPv4: 192.168.1.1, 10.0.0.1, 255.255.255.255
✓ IPv6: 2001:0db8:0000:0000:0000:0000:0000:0001, fe80:0000:0000:0000:0000:0000:0000:0001, 0000:0000:0000:0000:0000:0000:0000:0001
```

### What It Rejects

```
✗ IPv4: 256.1.1.1 (octet above 255), 1.2.3 (too few octets), 1.2.3.4.5 (too many), 01.2.3.4 (leading zero)
✗ IPv6: 2001:db8::1 (compressed form, see the note below), 12345::1, 1:2:3:4:5:6:7:8:9
```

### Note on Compressed IPv6

The pattern above only accepts the full eight-group form. Compressed addresses such as `2001:db8::1`, `::1` or `fe80::1` are valid IPv6 but need the full RFC 4291 grammar; for validation prefer PHP's own validator, which accepts both forms:

```php
$valid = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
```

---

## Date Formats

### ISO 8601 (YYYY-MM-DD)

```
/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/
```

### US Format (MM/DD/YYYY)

```
/^(?:0[1-9]|1[0-2])\/(?:0[1-9]|[12][0-9]|3[01])\/\d{4}$/
```

### European Format (DD.MM.YYYY)

```
/^(?:0[1-9]|[12][0-9]|3[01])\.(?:0[1-9]|1[0-2])\.\d{4}$/
```

### What It Matches

```
✓ 2024-12-25, 1999-01-01, 2050-12-31
✓ 12/25/2024, 01/01/1999
✓ 25.12.2024, 01.01.1999
```

### What It Rejects

```
✗ 2024-13-01      (invalid month)
✗ 2024-12-32      (invalid day)
✗ 2024/12/25      (wrong separator)
✗ 24-12-25        (2-digit year)
```

### Why It's Safe

- Year, month, and day are bounded with explicit ranges.
- Separators are literal and consistent.
- No nested quantifiers or ambiguous overlaps.

### Note on Calendar Correctness

These patterns validate **format**, not calendar validity. `2023-02-29` passes although 2023 is not a leap year, and `1900-02-29` passes although century years must be divisible by 400. Use PHP's `checkdate()` for true calendar validation:

```php
$date = '2023-02-29';
if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/', $date)) {
    [$y, $m, $d] = explode('-', $date);
    $valid = checkdate((int)$m, (int)$d, (int)$y);  // false: 2023 is not a leap year
}
```

---

## Time Formats

### 24-Hour (HH:MM)

```
/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/
```

### 24-Hour with Seconds (HH:MM:SS)

```
/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/
```

### 12-Hour with AM/PM (HH:MM AM/PM)

```
/^(?:0?[1-9]|1[0-2]):[0-5][0-9]\s*[AaPp][Mm]$/
```

---

## DateTime (ISO 8601)

```
/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z|[+-](?:[01]?[0-9]|2[0-3]):[0-5][0-9])?$/u
```

### What It Matches

```
✓ 2024-12-25T10:30:00Z
✓ 2024-12-25T10:30:00+05:00
✓ 2024-12-25T10:30:00
✓ 2024-12-25T10:30             (seconds are optional)
```

### What It Rejects

```
✗ 2024-12-25 10:30:00       (space instead of T)
✗ 2024-12-25T25:30:00       (invalid hour)
```

---

## Slug

```
/^[a-z0-9]+(?:-[a-z0-9]+)*$/
```

### What It Matches

```
✓ my-post
✓ hello-world-123
✓ a
```

### What It Rejects

```
✗ -my-post        (starts with hyphen)
✗ my-post-        (ends with hyphen)
✗ my--post        (consecutive hyphens)
✗ my_post         (underscore not allowed)
```

### Why It's Safe

- Requires at least one alphanumeric character.
- Allows additional segments separated by hyphens.
- Each segment is bounded by hyphens or end of string.

---

## Username

```
/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/
```

### What It Matches

```
✓ user123
✓ John_Doe
✓ admin
```

### What It Rejects

```
✗ 123user       (must start with letter)
✗ a             (too short)
✗ user$name     (invalid character)
```

### Common Patterns by System

| System  | Pattern                      | Max Length       |
|---------|------------------------------|------------------|
| Linux   | `/^[a-z_][a-z0-9_-]*$/i`     | 32               |
| GitHub  | `/^[a-zA-Z0-9](?:[a-zA-Z0-9]\|-(?!-)){0,38}$/` | 39 |
| Twitter | `/^[a-zA-Z0-9_]{1,15}$/`     | 15               |

---

## Password Strength

### Basic Strength

```
/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/
```

### Strong (Special Characters)

```
/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]).{12,}$/
```

### What It Matches

```
✓ Basic:  Password1,  MyPass123
✓ Strong: P@ssw0rd!#123,  MyStr0ng!P@ss
```

### What It Rejects

```
✗ password      (no uppercase, no number)
✗ PASSWORD      (no lowercase, no number)
✗ Passw0d       (too short - 8+ chars required)
✗ Password      (no digit)
```

### Pattern Breakdown

- `(?=.*[a-z])` requires at least one lowercase letter.
- `(?=.*[A-Z])` requires at least one uppercase letter.
- `(?=.*[0-9])` requires at least one digit.
- `(?=.*[!@#$%...])` requires at least one special character (strong pattern).
- `.{8,}` enforces minimum length.
- Lookaheads do not consume characters, but their bodies still backtrack: keep them free of nested quantifiers. The two patterns above are `safe (proven)`.

---

## Phone Number

### E.164 Format (International)

```
/^\+[1-9]\d{6,14}$/
```

### US/North America

```
/^\+?1?[2-9]\d{2}[2-9]\d{6}$/
```

### What It Matches

```
✓ E.164: +14155552671, +442071838750
✓ US: 4155552671, 14155552671
```

### What It Rejects

```
✗ 415555267            (too short)
✗ +04155552671         (country code can't start with 0)
✗ 415-555-2671         (separators not allowed)
```

### Note on Phone Numbers

Phone number validation is complex due to varying international formats. The patterns above are digits-only: strip separators before matching, or write a separator-aware pattern. Consider using a dedicated library like `libphonenumber-for-php` for production applications.

---

## Credit Card

### Generic Card Number (length only)

```
/^[0-9]{13,19}$/
```

This checks the length range, nothing else — a Luhn checksum is arithmetic a
pattern cannot carry; run the sum in PHP once the length has filtered the
input.

### Specific Card Types

| Card Type  | Pattern                       |
|------------|-------------------------------|
| Visa       | `/^4[0-9]{12}(?:[0-9]{3})?$/` |
| Mastercard | `/^5[1-5][0-9]{14}$/`         |
| Amex       | `/^3[47][0-9]{13}$/`          |
| Discover   | `/^6(?:011\|5[0-9]{2})[0-9]{12}$/` |

### What It Matches

```
✓ Visa: 4111111111111111
✓ Mastercard: 5555555555554444
✓ Amex: 378282246310005
```

### Validation Note

These patterns verify **format** and **length**. Always run the Luhn algorithm for actual validation:

```php
function luhnCheck(string $number): bool
{
    $sum = 0;
    $length = strlen($number);
    $parity = $length % 2;
    
    for ($i = $length - 1; $i >= 0; $i--) {
        $digit = (int)$number[$i];
        if ($i % 2 === $parity) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }
    
    return $sum % 10 === 0;
}
```

---

## Hex Color

```
/^#(?:[0-9a-fA-F]{3}){1,2}$/
```

### What It Matches

```
✓ #fff, #FFF, #ffffff, #FFFFFF
✓ #abc, #a1b2c3
```

### What It Rejects

```
✗ #fffff         (5 digits)
✗ #gggggg        (invalid hex)
✗ fff            (missing #)
```

---

## Semantic Versioning

```
/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/
```

### What It Matches

```
✓ 1.0.0, 2.10.5, 10.0.0
✓ 1.0.0-alpha, 1.0.0-alpha.1
✓ 1.0.0+build.123, 1.0.0-alpha+build.123
```

### What It Rejects

```
✗ 01.0.0          (leading zero)
✗ 1.0             (missing patch)
✗ 1.0.0-          (empty pre-release)
✗ 1.0.0-0.        (invalid pre-release segment)
```

---

## Building Your Own Patterns

### Guidelines for ReDoS-Safe Patterns

- Avoid nested quantifiers. Example: prefer `/a++b/` over `/(a+)+b/`.
- Avoid overlapping alternations. Example: simplify `/(a|aa)+b/` to `/a+b/` when possible.
- Use atomic groups or possessive quantifiers where appropriate (for example, `/a*+b/`).
- Prefer character classes over alternation for simple sets (for example, `/[abcd]/`).
- Validate input length before matching.
- Use lookaheads for flexible validation (for example, `/(?=.*[a-z])[a-z]+/`).

### Validation Workflow

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Step 1: Require a pattern proven safe from ReDoS
$analysis = $regex->redos($pattern);
if (!$analysis->isProvenSafe()) {
    throw new \InvalidArgumentException('ReDoS: '.$analysis->headline());
}

// Step 2: Validate the pattern itself
$result = $regex->validate($pattern);
if (!$result->isValid) {
    throw new \InvalidArgumentException('Invalid pattern');
}

// Step 3: Validate input format
if (preg_match($pattern, $input) !== 1) {
    throw new \InvalidArgumentException('Invalid input format');
}

// Step 4: Apply business logic validation
// (e.g., checkdate(), luhnCheck(), etc.)
```

---

## Quick Reference: Pattern Elements

| Element          | Meaning                | ReDoS Risk |
|------------------|------------------------|------------|
| `[abc]`          | Character class        | Low        |
| `[^abc]`         | Negated class          | Low        |
| `\d`, `\w`, `\s` | Shorthand classes      | Low        |
| `{n,m}`          | Bounded quantifier     | Low        |
| `*`, `+`         | Unbounded quantifier   | Medium     |
| `*?`, `+?`       | Lazy quantifiers       | Medium     |
| `*+`, `++`       | Possessive quantifiers | Low        |
| `(?>...)`        | Atomic group           | Low        |
| `(?=...)`        | Lookahead              | Low        |
| `(?!...)`        | Negative lookahead     | Low        |
| `\|`             | Alternation            | Medium     |
