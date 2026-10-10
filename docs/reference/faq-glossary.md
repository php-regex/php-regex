---
description: "Short answers to common PHPRegex questions — CI integration, caching, exceptions — plus a glossary of the terms used across the documentation."
---

# FAQ and Glossary

Short answers to common questions plus quick definitions of core terms used throughout PHPRegex documentation.

## Frequently Asked Questions

### General Questions

#### Does PHPRegex execute regexes?

**No — until you ask it to.** PHPRegex parses and analyzes patterns **statically**: the default verdicts never run the regex against input. The `confirmed` ReDoS mode is the exception by design — it replays the attack input through `preg_match()` under engine limits, to turn a theoretical verdict into a proven one.

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

**Within limits.** For the patterns its backtracking model covers, PHPRegex proves the cost of one match attempt: `safe (proven)` means no input makes that attempt backtrack beyond a linear number of steps. The guarantee is per match attempt (the retries of an unanchored search and `preg_match_all()` are not counted), about the pattern as analysed (see `abstractions`), and for the PCRE2 release it ran with. Patterns with backreferences, conditionals or recursion are judged by heuristics, and say so. The [ReDoS guide](../guides/redos.md#the-guarantee) lists every limit.

```php
$analysis = Regex::create()->redos('/(a+)+b/');
echo $analysis->headline();        // 'Exponential backtracking (proven)'
echo $analysis->witness->render(); // '"a" x n . "!b"': the input that triggers it

var_dump(Regex::create()->redos('/a+b/')->isProvenSafe()); // bool(true)

// You should still:
// 1. Check preg_* results for false
// 2. Validate input length
// 3. Prefer atomic groups and possessive quantifiers
```

---

#### What is tolerant parsing?

Tolerant parsing returns a partial AST plus errors, allowing tools to continue even when patterns are partially invalid.

```php
use PHPRegex\Toolkit\Regex;

// Strict parsing - throws on error
$ast = Regex::create()->parse('/[broken/');  // Throws LexerException (extends RegexException)

// Tolerant parsing - returns partial AST
$result = Regex::create()->parseTolerant('/[broken/');
echo $result->ast instanceof \PHPRegex\Parser\Node\RegexNode;  // true (partial)
echo count($result->errors);  // 1
```

---

#### Can I use this in CI?

**Yes.** PHPRegex is designed for CI/CD integration.

```bash
# CLI linting; --redos adds the ReDoS findings the lint does not run by default
vendor/bin/regex lint src/ --redos --format=json > regex-issues.json

# Fail on any error-severity issue
if [ "$(jq '[.results[] | .issues[] | select(.severity == "error")] | length' regex-issues.json)" -eq 0 ]; then
    echo "No error-severity regex issues found"
else
    echo "Error-severity regex issues found!"
    exit 1
fi
```

A ReDoS finding is a `warning` in the default theoretical mode; it turns into the
`error` the gate above fails on when `--redos-mode=confirmed` replays the attack
on the engine and reproduces a `high`-or-above verdict.

```yaml
# GitHub Actions example
- name: Run PHPRegex
  run: vendor/bin/regex lint src/ --redos --format=github
```

The exit code fails the job: `0` clean, `1` errors found, `2` unusable configuration or command line. See
[the CLI guide](../guides/cli.md) for the report formats and the exit codes.

---

#### Why an AST?

The Abstract Syntax Tree provides:

| Benefit            | Description                                            |
|--------------------|--------------------------------------------------------|
| **Precision**      | Exact error locations, not just "somewhere in pattern" |
| **Analysis**       | Detect complex issues like ReDoS, proven on a model of PCRE |
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

echo $analysis->severity->value;          // 'critical' ('safe', 'low', 'medium', 'unknown', 'high', 'critical')
echo $analysis->headline();               // 'Exponential backtracking (proven)'
echo $analysis->confidenceLevel()->value; // 'medium' ('high' once replayed on PCRE)
echo $analysis->recommendations[0];       // Suggested fix

$analysis->isProvenSafe();                // false: true only for 'safe (proven)'
```

---

#### How do I optimize a pattern?

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->optimize('/[0-9]+/');

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'
var_export($result->changes);
// array (
//   0 => 'Optimized pattern.',
// )
```

---

#### How do I explain a pattern to users?

```php
use PHPRegex\Toolkit\Regex;

$explanation = Regex::create()->explain('/\d{3}-\d{4}/');
echo $explanation;
/*
Regex matches
    Character Type: A digit: [0-9] (exactly 3 times)
  '-'
    Character Type: A digit: [0-9] (exactly 4 times)
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
} catch (\PHPRegex\Parser\Exception\ExceptionInterface $e) {
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
| **Branch reset**          | `(?\|...)` - resets capture numbering per branch |
| **Subroutine**            | `(?1)` or `(?&name)` - reuses a group definition               |
| **Lexer**                 | Tokenizes the pattern string into tokens                       |
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

The regex basics — from `.` and `\d` to possessive quantifiers — are taught by the
[tutorial](../tutorial/README.md), which starts at [the atoms of a pattern](../tutorial/01-basics.md).
