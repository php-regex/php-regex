# PCRE vs Other Engines

**PCRE** (Perl Compatible Regular Expressions) is the regex engine used by PHP's `preg_*` functions. Understanding PCRE helps you write better patterns and avoid compatibility issues.

## What is PCRE?

PCRE is a regular expression engine that:

- Powers PHP's `preg_match()`, `preg_replace()`, etc.
- Is based on Perl's regex syntax
- Supports advanced features like lookarounds, recursion, and Unicode
- Uses backtracking for pattern matching

## PCRE in PHP

### PHP's Regex Functions

```php
// PCRE functions in PHP
preg_match('/pattern/', $subject);      // Match pattern
preg_replace('/pattern/', 'replacement', $subject); // Replace
preg_split('/pattern/', $subject);      // Split by pattern
preg_match_all('/pattern/', $subject, $matches); // Find all matches
```

### PCRE Version in PHP

```php
// Check your PCRE version
echo PCRE_VERSION; // e.g., "10.42 2022-12-11"
```

Some syntax depends on that release. PHP 8.2 and 8.3 bundle PCRE2 10.40
and 10.42; PHP 8.4 bundles 10.44, which reads PCRE2 10.43's additions:

| Syntax | Example | Needs |
|---|---|---|
| Variable-length lookbehind | `(?<=ab?)` | PCRE2 10.43, PHP 8.4 |
| Caseless restrict option | `(?r)` | PCRE2 10.43, PHP 8.4 |
| ASCII options: `a` alone, or with one of `D`, `S`, `W`, `P`, `T` | `(?aD)` | PCRE2 10.43, PHP 8.4 |
| Spaces inside braced escapes and references | `\x{ 41 }`, `\g{ 1 }`, `\k{ name }` | PCRE2 10.43, PHP 8.4 |
| Open minimum and spaces in a repeat count (literal text before) | `a{,2}`, `a{ 2 }` | PCRE2 10.43, PHP 8.4 |
| Unicode 15 script names | `\p{Kawi}`, `\p{Nag_Mundari}` | PCRE2 10.43, PHP 8.4 |
| Unicode 16 and 17 script names, and the binary properties PCRE2 10.45 added | `\p{Garay}`, `\p{IDS_Unary_Operator}` | PCRE2 10.45, bundled by no PHP release yet |
| `^` after spaces in a property | `\p{ ^Lu}` | PCRE2 10.45, bundled by no PHP release yet |
| Casing settings at the start of the pattern | `(*CASELESS_RESTRICT)`, `(*TURKISH_CASING)` | PCRE2 10.45, bundled by no PHP release yet |
| `\k` read as the letter inside a class | `[\k]` | PCRE2 10.45, bundled by no PHP release yet |
| `\K` inside a lookaround | `(?=a\K)` | allowed up to PHP 8.4; PHP 8.5 compiles without `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` and refuses it |

The validator judges a pattern for the PHP version it targets (`php_version`),
or, without one, for the PCRE2 the running PHP links. That PCRE2 is not always
the one PHP bundles: the PHP 8.4 packages of Ubuntu 24.04, for one, link its
PCRE2 10.42, and refuse what 10.43 added. `PCRE_VERSION` says which one runs.

Error offsets follow the same rule. PCRE2 10.47 reports most syntax errors past
the character at fault rather than on it (`/+/` at offset 1 rather than 0,
`\y` at 2 rather than 1), and 10.45 moved a few (an unknown POSIX class is
reported past its end). `ValidationResult::$offset` is the offset the PCRE2 of
the targeted PHP version reports, or, without a target, the one the running
PHP links.

## PCRE vs other regex engines

| Feature               | PCRE (PHP) | JavaScript | Python | .NET |
|-----------------------|------------|------------|--------|------|
| Lookaheads            | Yes        | Yes        | Yes    | Yes  |
| Lookbehinds           | Yes        | No         | Yes    | Yes  |
| Variable-length lookbehind | PCRE2 10.43+, up to 255 per branch | No | No | Yes |
| Recursion             | Yes        | No         | No     | Yes  |
| Atomic groups         | Yes        | No         | Yes    | Yes  |
| Possessive quantifiers| Yes        | No         | No     | Yes  |
| Unicode properties    | Yes        | Yes        | Yes    | Yes  |
| Named groups          | Yes        | Yes        | Yes    | Yes  |
| Branch reset          | Yes        | No         | No     | No   |

## PCRE-specific features

### 1. Recursion

```php
// Match balanced parentheses
$pattern = '/\((?:[^()]++|(?R))*\)/';
preg_match($pattern, '(a(b)c)', $matches);
```

### 2. Branch Reset

```php
// Reset capture numbering per branch
$pattern = '/(?|(a)|(b)|(c))+/';
preg_match($pattern, 'abc', $matches);
```

### 3. Atomic Groups

```php
// Prevent backtracking
$pattern = '/(?>a+)b/';
preg_match($pattern, 'aaaa!', $matches); // Fails quickly
```

### 4. Possessive Quantifiers

```php
// No backtracking
$pattern = '/a++b/';
preg_match($pattern, 'aaaa!', $matches); // Fails quickly
```

## PCRE best practices

### 1. Use Delimiters

```php
// Always include delimiters
$pattern = '/^hello$/i'; // Good
$pattern = '^hello$';     // Bad - missing delimiters
```

### 2. Specify Flags

```php
// Common flags
$pattern = '/hello/i';  // Case-insensitive
$pattern = '/hello/s';  // Dot matches newline
$pattern = '/hello/m';  // Multiline mode
$pattern = '/hello/u';  // Unicode mode
$pattern = '/hello/x';  // Extended (ignore whitespace)
```

### 3. Escape Special Characters

```php
// Escape regex metacharacters
$literal = preg_quote('user@input.com', '/');
$pattern = '/' . $literal . '/';
```

### 4. Use Raw Patterns

```php
// Use single quotes to avoid escaping
$pattern = '/\d{3}-\d{4}/'; // Good
$pattern = "/\d{3}-\d{4}/"; // Also works but harder to read
```

## Related concepts

- **[ReDoS Deep Dive](redos.md)** - PCRE's backtracking vulnerabilities
- **[Architecture](../ARCHITECTURE.md)** - How RegexParser handles PCRE
- **[Regex in PHP Guide](../guides/regex-in-php.md)** - PHP-specific regex details

## Further reading

- [PCRE Documentation](https://www.pcre.org/) - Official PCRE docs
- [PHP Regex Functions](https://www.php.net/manual/en/book.pcre.php) - PHP manual
- [Regex101 PCRE Reference](https://regex101.com/) - Interactive tester

---

Previous: [ReDoS Deep Dive](redos.md) | Next: [Concepts Home](README.md)