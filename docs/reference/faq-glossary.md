# FAQ and Glossary

Short answers to common questions plus quick definitions of core terms used throughout PHPRegex documentation.

## Frequently Asked Questions

### General Questions

#### Does PHPRegex execute regexes?

**No.** PHPRegex parses and analyzes patterns **statically**. It never actually runs the regex against input. Runtime validation is optional and uses a safe compile check with `preg_match()`.

```php
use PHPRegex\Toolkit\Regex;

// Static analysis - does NOT execute
$analysis = Regex::create()->redos('/(a+)+b/');
echo $analysis->severity->value;  // 'critical' - without running it!

// Runtime validation (optional, uses PCRE)
$regex = Regex::create(['runtime_pcre_validation' => true]);
$result = $regex->validate('/test/');
```

---

#### Is this PCRE2-only?

**Yes.** PHPRegex targets PHP's `preg_*` engine, which uses PCRE2. Patterns are validated against PCRE2 semantics.

```php
// PCRE2-specific features work
preg_match('/\p{L}/u', $text);  // Unicode properties

// PCRE1 patterns may not work
// preg_match('/\g{0}/', $text);  // Invalid in PCRE2
```

---

#### Does this guarantee ReDoS safety?

**No.** PHPRegex detects known risky structures and suggests safer alternatives, but safety depends on:
- Input patterns
- Flags used
- Runtime limits set by PHP or the application

```php
// PHPRegex will warn about this:
$analysis = Regex::create()->redos('/(a+)+b/');
// severity: 'critical'

// But you must still:
// 1. Apply runtime limits
// 2. Validate input length
// 3. Consider using atomic groups
```

---

#### What is tolerant parsing?

Tolerant parsing returns a partial AST plus errors, allowing tools to continue even when patterns are partially invalid.

```php
use PHPRegex\Toolkit\Regex;

// Strict parsing - throws on error
$ast = Regex::create()->parse('/[broken/');  // Throws ParserException

// Tolerant parsing - returns partial AST
$result = Regex::create()->parse('/[broken/', true);
echo $result->ast instanceof \PHPRegex\Parser\Node\RegexNode;  // true (partial)
echo count($result->errors);  // 1
```

---

#### Can I use this in CI?

**Yes.** PHPRegex is designed for CI/CD integration.

```bash
# CLI linting
vendor/bin/regex lint src/ --format=json > regex-issues.json

# Check for critical issues
if jq '[.results[] | .issues[] | select(.type == "error")] | length' regex-issues.json | grep -q 0; then
    echo "No critical regex issues found"
else
    echo "Critical issues found!"
    exit 1
fi
```

```yaml
# GitHub Actions example
- name: Run PHPRegex
  run: vendor/bin/regex lint src/ --format=json > regex-report.json
- name: Check report
  uses: dawidd6/action-json-to-coverage@v1
  with:
    report: regex-report.json
    min_coverage: 95
```

---

#### Why an AST?

The Abstract Syntax Tree provides:

| Benefit            | Description                                            |
|--------------------|--------------------------------------------------------|
| **Precision**      | Exact error locations, not just "somewhere in pattern" |
| **Analysis**       | Detect complex issues like ReDoS structurally          |
| **Transformation** | Refactor patterns safely without string hacking        |
| **Tooling**        | Support IDEs, linters, formatters                      |

```php
// String-based tools can only guess:
preg_match('/test/', $pattern);  // What if 'test' is escaped?

// AST knows the structure:
$ast = Regex::create()->parse('/test/');
$sequence = $ast->pattern;  // Exact structure known
```

---

### Usage Questions

#### How do I check if a pattern is safe from ReDoS?

```php
use PHPRegex\Toolkit\Regex;

$analysis = Regex::create()->redos('/(a+)+b/');

echo $analysis->severity->value;      // 'critical', 'safe', 'low', 'medium'
echo $analysis->confidence->value;    // 'high', 'medium', 'low'
echo $analysis->recommendations[0];   // Suggested fix
```

---

#### How do I optimize a pattern?

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->optimize('/[0-9]+/');

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'
echo $result->changes[0];  // 'Replaced [0-9] with \d'
```

---

#### How do I explain a pattern to users?

```php
use PHPRegex\Toolkit\Regex;

$explanation = Regex::create()->explain('/\d{3}-\d{4}/');
echo $explanation;
/*
Match exactly 3 digits, then hyphen, then exactly 4 digits.
*/
```

---

#### How do I generate a matching sample?

```php
use PHPRegex\Toolkit\Regex;

$sample = Regex::create()->generate('/[A-Z][a-z]{3,5}\d{2}/');
echo $sample;  // e.g., "Word12"
```

---

### Technical Questions

#### What's the difference between validate() and parse()?

| Method       | Returns          | On Error                              |
|--------------|------------------|---------------------------------------|
| `parse()`    | RegexNode (AST)  | Throws exception                      |
| `validate()` | ValidationResult | Returns result with `isValid = false` |

```php
use PHPRegex\Toolkit\Regex;

// parse() - throws
try {
    $ast = Regex::create()->parse('/[broken/');
} catch (\Exception $e) {
    echo "Parse failed: {$e->getMessage()}";
}

// validate() - returns result
$result = Regex::create()->validate('/[broken/');
echo $result->isValid ? 'Valid' : "Invalid: {$result->error}";
```

---

#### How does caching work?

```php
use PHPRegex\Toolkit\Regex;

// Default: the latest 1024 trees in memory, nothing on disk
$regex = Regex::create();

// Custom cache location
$regex = Regex::create(['cache' => '/my/app/cache']);

// Disable cache
$regex = Regex::create(['cache' => null]);

// Long-running processes should clear cache
$regex->clearCaches();
```

---

#### What PHP versions are supported?

- **PHP 8.2** and above
- Uses modern PHP features (readonly classes, enums, etc.)

```php
// Requires PHP 8.2+
$regex = Regex::create([
    'php_version' => '8.2',  // Target version
]);
```

---

## Glossary

| Term                      | Definition                                                     |
|---------------------------|----------------------------------------------------------------|
| **AST**                   | Abstract Syntax Tree - structured representation of the regex  |
| **Node**                  | Single element in the AST (literal, group, quantifier, etc.)   |
| **Visitor**               | Algorithm that traverses the AST (compile, explain, lint)      |
| **PCRE2**                 | Perl Compatible Regular Expressions - the engine PHP uses      |
| **ReDoS**                 | Regular Expression Denial of Service - catastrophic backtracking |
| **Backtracking**          | Engine behavior that retries alternative paths on failure      |
| **Lookaround**            | Zero-width assertion like `(?=...)` or `(?<=...)`              |
| **Atomic group**          | `(?>...)` - prevents backtracking inside the group             |
| **Possessive quantifier** | `*+`, `++`, `{m,n}+` - no backtracking                         |
| **Branch reset**          | `(?|...)` - resets capture numbering per branch |
| **Subroutine**            | `(?1)` or `(?&uses a group definition                          |
| **Lexer**                 | Tokenizesname)` - re the pattern string into tokens            |
| **Parser**                | Builds the AST from tokens                                     |
| **Tokenizer**             | Same as Lexer                                                  |
| **Delimiter**             | Character marking pattern boundaries (e.g., `/` in `/pattern/`) |
| **Flag**                  | Modifier like `i` (case-insensitive) or `s` (dotall)           |
| **Quantifier**            | `*`, `+`, `?`, `{m,n}` - specifies repetition                  |
| **Greedy**                | Default quantifier behavior - matches as much as possible      |
| **Lazy**                  | `*?`, `+?`, `??` - matches as little as possible               |
| **Capturing group**       | `(...)` - captures matched text                                |
| **Non-capturing group**   | `(?:...)` - groups without capture                             |
| **Named group**           | `(?<name>...)` - captures with a name                          |
| **Backreference**         | `\1`, `\k<name>` - refers to previous capture                  |
| **Escape sequence**       | `\d`, `\w`, `\x{...}` - special character representation       |
| **Character class**       | `[...]` - matches one character from a set                     |
| **Negated class**         | `[^...]` - matches any character NOT in the set                |
| **Shorthand class**       | `\d`, `\w`, `\s` - common character classes                    |
| **Anchor**                | `^`, `$`, `\A`, `\z` - matches position, not characters        |
| **Assertion**             | Zero-width check like `\b` or `(?=...)`                        |
| **Word boundary**         | `\b` - transition between word and non-word characters         |
| **Unicode property**      | `\p{L}`, `\p{N}` - characters matching Unicode properties      |

---

## Pattern Quick Reference

| Pattern    | Meaning                      | Example      |
|------------|------------------------------|--------------|
| `.`        | Any character except newline | `/.+/`       |
| `\d`       | Digit [0-9]                  | `/\d{3}/`    |
| `\w`       | Word character [a-zA-Z0-9_]  | `/\w+/`      |
| `\s`       | Whitespace                   | `/\s*/`      |
| `\b`       | Word boundary                | `/\bword\b/` |
| `^`        | Start of string              | `/^start/`   |
| `$`        | End of string                | `/end$/`     |
| `*`        | 0 or more                    | `/a*/`       |
| `+`        | 1 or more                    | `/a+/`       |
| `?`        | 0 or 1                       | `/a?/`       |
| `{n,m}`    | Between n and m              | `/a{2,4}/`   |
| `[abc]`    | Any of a, b, c               | `/[abc]/`    |
| `[^abc]`   | Not a, b, or c               | `/[^abc]/`   |
| `(...)`    | Capturing group              | `/(\w+)/`    |
| `(?:...)`  | Non-capturing                | `/(?:foo)/`  |
| `(?=...)`  | Positive lookahead           | `/(?=\d)/`   |
| `(?!...)`  | Negative lookahead           | `/(?!\d)/`   |
| `(?<=...)` | Positive lookbehind          | `/(?<=\d)/`  |
| `(?<!...)` | Negative lookbehind          | `/(?<!\d)/`  |
| `\|`       | Alternation                  | `/a\|b/`     |
| `\1`       | Backreference                | `/(\w+)\1/`  |
| `(?>...)`  | Atomic group                 | `/(?>a+)/`   |
| `*+`       | Possessive quantifier        | `/a*+/`      |

---

Previous: [Diagnostics](diagnostics.md) | Next: [Diagnostics Cheat Sheet](diagnostics-cheatsheet.md)