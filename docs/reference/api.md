# API Reference

This reference documents the public API surface of RegexParser: entry points, configuration options, return objects, and the exception hierarchy.

## Entry Points

### Regex::create(array $options = []): Regex

Creates a configured Regex instance. This is the primary entry point for all library operations.

Factory steps:
- Validate options.
- Create a configured instance.
- Return a ready-to-use `Regex`.

**Example:**
```php
use RegexParser\Regex;

$regex = Regex::create([
    'cache' => '/var/cache/regex',
    'max_pattern_length' => 100_000,
    'max_lookbehind_length' => 255,
    'runtime_pcre_validation' => false,
    'redos_ignored_patterns' => [],
    'max_recursion_depth' => 1024,
    'php_version' => '8.2',
    'pcre_version' => '10.40',
]);

$result = $regex->validate('/foo|bar/');
echo $result->isValid() ? 'Valid' : 'Invalid';
```

---

### Regex::new(array $options = []): Regex

Alias for `Regex::create()`. Use whichever reads better in your code.

```php
// These are equivalent
$regex = Regex::create();
$regex = Regex::new();
```

---

### Regex::tokenize(string $regex, ?PcreTarget $target = null): TokenStream

Lexes a regex into a `TokenStream` with positional offsets. Useful for custom analysis or debugging.

```php
use RegexParser\Regex;

$stream = Regex::tokenize('/foo|bar/i');

foreach ($stream as $token) {
    echo "Type: {$token->type->value}, Value: '{$token->value}'\n";
    echo "Position: {$token->start} - {$token->end}\n";
}
```

---

### Regex::clearValidatorCaches(): void

Clears static caches used by the validator. Important for long-running processes to prevent memory growth.

```php
use RegexParser\Regex;

$regex = Regex::create();

// Process many patterns...
foreach ($patterns as $pattern) {
    $regex->validate($pattern);
}

// Clear caches periodically
$regex->clearValidatorCaches();
```

---

## Configuration Options

All options are validated. Unknown keys throw `InvalidRegexOptionException`.

| Option                    | Type                                   | Default           | Description                    | Performance Impact              |
|---------------------------|----------------------------------------|-------------------|--------------------------------|---------------------------------|
| `cache`                   | `null` \| `string` \| `CacheInterface` | `FilesystemCache` | Cache for parsed ASTs          | High - speeds repeated patterns |
| `max_pattern_length`      | `int`                                  | `100_000`         | Maximum pattern length         | Low - prevents abuse            |
| `max_lookbehind_length`   | `int`                                  | `255`             | Maximum length of a variable-length lookbehind; a fixed-length one is only limited by PCRE's 65535 | Low - PCRE compliance           |
| `runtime_pcre_validation` | `bool`                                 | `false`           | Compile-check via preg_match() | Medium - extra compile step     |
| `redos_ignored_patterns`  | `array<string>`                        | `[]`              | Patterns to skip ReDoS         | Low - reduces false positives   |
| `max_recursion_depth`     | `int`                                  | `1024`            | Parser recursion guard         | Low - prevents stack overflow   |
| `php_version`             | `string` \| `int`                      | the running PHP   | PHP version judged, with the PCRE2 it bundles | Low - feature validation        |
| `pcre_version`            | `string`                               | the linked PCRE2  | PCRE2 release judged, `"10.42"` | Low - feature validation        |

Without `php_version` nor `pcre_version`, patterns are judged for the running PHP
and the PCRE2 it links. `runtime_pcre_validation` compiles with the running PHP,
so it is refused with a target that is not that engine. See
[Which PHP and which PCRE2 judge a pattern](../concepts/pcre.md#which-php-and-which-pcre2-judge-a-pattern).

### Regex::target(): PcreTarget

The PHP version (`$phpVersionId`) and the PCRE2 release (`$pcreVersion`) the
instance judges for. `PcreTarget::runtime()` is the running engine,
`PcreTarget::bundledWith(80400)` a PHP version with its bundled PCRE2, and
`new PcreTarget(80400, '10.42')` any pair; `Lexer`, `Parser`,
`ValidatorNodeVisitor` and `Regex::cacheSeed()` take one.

---

## Parsing Methods

### parsePattern(string $pattern, string $flags = '', string $delimiter = '/'): RegexNode

Parses a pattern body plus flags/delimiter into a `RegexNode`. Use this when you have separate pattern components.

```php
use RegexParser\Regex;

$pattern = 'foo|bar';
$flags = 'i';
$delimiter = '/';

$ast = Regex::create()->parsePattern($pattern, $flags, $delimiter);

echo $ast->flags;      // 'i'
echo $ast->delimiter;  // '/'
echo $ast->pattern;    // SequenceNode or AlternationNode
```

---

### parse(string $regex, bool $tolerant = false): RegexNode|TolerantParseResult

Parses a full PCRE string (`/pattern/flags`).

```php
use RegexParser\Regex;

// Strict parsing (default)
$ast = Regex::create()->parse('/foo|bar/i');
echo $ast->flags;      // 'i'
echo $ast->delimiter;  // '/'

// Tolerant parsing - returns AST even with errors
$result = Regex::create()->parse('/[unclosed/i', true);

echo $result->ast;          // Partial AST
echo $result->errors[0]->getMessage();  // First error
```

---

## Validation and Analysis Methods

### validate(string $regex): ValidationResult

Returns a structured validation result without throwing exceptions.

```php
use RegexParser\Regex;

$result = Regex::create()->validate('/foo|bar/');

echo $result->isValid();           // true
echo $result->complexityScore;     // int
echo $result->category->value;     // ValidationErrorCategory enum
```

**ValidationResult Fields:**

| Field             | Type                    | Description              |
|-------------------|-------------------------|--------------------------|
| `isValid`         | bool                    | Whether pattern is valid |
| `error`           | string\|null            | Error message if invalid |
| `errorCode`       | string\|null            | Stable error code        |
| `offset`          | int\|null               | Byte offset of error     |
| `caretSnippet`    | string\|null            | Snippet with caret       |
| `hint`            | string\|null            | Fix suggestion           |
| `complexityScore` | int                     | Pattern complexity       |
| `category`        | ValidationErrorCategory | Error category           |

---

### analyze(string $regex): AnalysisReport

Aggregates validation, lint, ReDoS analysis, optimization, and explanation into a single report.

```php
use RegexParser\Regex;

$report = Regex::create()->analyze('/(a+)+b/');

echo $report->isValid;           // true/false
echo count($report->errors);      // Validation errors
echo count($report->lintIssues);  // Lint warnings
echo $report->redos->severity->value;  // 'critical', 'safe', etc.
echo $report->explain;            // Human explanation
echo $report->highlighted;        // Syntax-highlighted pattern
```

**AnalysisReport Fields:**

| Field           | Type          | Description                    |
|-----------------|---------------|--------------------------------|
| `isValid`       | bool          | Pattern is syntactically valid |
| `errors`        | array         | Validation errors              |
| `lintIssues`    | array         | Linting warnings               |
| `redos`         | ReDoSAnalysis | ReDoS analysis result          |
| `optimizations` | array         | Suggested optimizations        |
| `explain`       | string        | Human explanation              |
| `highlighted`   | string        | Highlighted pattern            |

---

### redos(string $regex, ?ReDoSSeverity $threshold = null, ReDoSMode $mode = ReDoSMode::THEORETICAL, ?ReDoSConfirmOptions $confirmOptions = null): ReDoSAnalysis

Analyzes ReDoS risk without an analysis report. Default mode is **theoretical** (structural). Use **confirmed** mode to attempt bounded evidence collection.

```php
use RegexParser\Regex;
use RegexParser\ReDoS\ReDoSMode;

$analysis = Regex::create()->redos('/(a+)+b/', mode: ReDoSMode::THEORETICAL);

echo $analysis->severity->value;       // 'critical', 'safe', etc.
echo $analysis->score;                 // int (0-10)
echo $analysis->confidenceLevel()->value; // 'high', 'medium', 'low'
echo $analysis->vulnerablePart;        // Subpattern causing risk
echo $analysis->recommendations[0];    // Suggested fix

// Optional: bounded confirmation
$confirmed = Regex::create()->redos('/(a+)+b/', mode: ReDoSMode::CONFIRMED);
echo $confirmed->isConfirmed() ? 'confirmed' : 'theoretical';
```

**ReDoSAnalysis Fields:**

| Field              | Type              | Description                              |
|--------------------|-------------------|------------------------------------------|
| `severity`         | ReDoSSeverity      | Risk level                                |
| `score`            | int               | Risk score (0-10)                         |
| `mode`             | ReDoSMode          | off, theoretical, or confirmed            |
| `confidence`       | Confidence         | Analysis confidence (use `confidenceLevel()`) |
| `confirmation`     | ReDoSConfirmation\|null | Bounded evidence details              |
| `vulnerablePart`   | string\|null       | Risky subpattern                          |
| `recommendations`  | array              | Suggested fixes (verify behavior)         |
| `hotspots`         | array              | Problem locations                         |
| `suggestedRewrite` | string\|null       | Suggested rewrite (verify behavior)       |

---

## Transform and Extract Methods

### optimize(string $regex, array $options = []): OptimizationResult

Applies safe optimizations to the pattern.

```php
use RegexParser\Regex;

$result = Regex::create()->optimize('/[0-9]+/', [
    'digits' => true,              // [0-9] -> \d
    'word' => true,                // [A-Za-z0-9_] -> \w
    'ranges' => true,              // Normalize ranges
    'canonicalizeCharClasses' => true, // Normalize character class order/dedup
    'autoPossessify' => false,     // Add possessive quantifiers
    'allowAlternationFactorization' => false,  // Factor common parts
    'minQuantifierCount' => 4,     // Use {n} only when repetition >= 4
    'verifyWithAutomata' => false, // Verify equivalence with the automata solver when possible
]);

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'
echo $result->changes[0];  // 'Optimized pattern.'
```

When `verifyWithAutomata` is enabled, RegexParser validates that the optimization is language-equivalent for the
supported regular subset. Unsupported patterns fall back to the original behavior.

---

### transpile(string $regex, string $target, ?TranspileOptions $options = null): TranspileResult

Transpiles a PCRE literal to another regex dialect (starting with JavaScript).

```php
use RegexParser\Regex;

$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'javascript');

echo $result->literal;     // '/(?<word>\\w+)/i'
echo $result->constructor; // 'new RegExp("(?<word>\\w+)", "i")'
print_r($result->warnings);
```

Notes:
- Unsupported PCRE constructs throw `TranspileException`.
- JavaScript targets may add `/u` when Unicode properties or code point escapes are used.
- `/x` is dropped after comments/whitespace are normalized.
- `TranspileOptions` lets you disable JS lookbehind support (`allowLookbehind: false`).
- Available targets: `javascript` (alias: `js`).

---

### literals(string $regex): LiteralExtractionResult

Extracts fixed literals and prefix/suffix data for fast prefilters or indexing. The literal set describes the text a
match consumes: every match starts with one of `literalSet->prefixes` and ends with one of `literalSet->suffixes`, and
an empty list says nothing about that end. Lookarounds add nothing, `(*ACCEPT)` drops the suffixes, and a set too large
to keep is dropped rather than cut.

```php
use RegexParser\Regex;

$result = Regex::create()->literals('/user-\d{4}/');

print_r($result->literals);                     // ['user-']
echo $result->patterns[0];                      // '^user\-'
echo $result->literalSet->getLongestPrefix();   // 'user-'
print_r($result->literalSet->suffixes);         // [] (the digits vary)
echo $result->confidence;                       // 'medium'
```

---

### generate(string $regex): string

Generates a sample string that matches the pattern. Useful for testing or documentation.

Each sample is checked against the running PHP, and generation is retried
until one matches: lookaheads, lookbehinds and assertions such as `\b` are
held where they stand. When none matches, `SampleGenerationException` is
thrown (error code `regex.generate.no_match`): the pattern matches nothing, as
`a(*FAIL)` or `a^b`, or its constraints are too tangled to guess. A pattern
the running PHP cannot compile gets a sample nothing checked.

```php
use RegexParser\Regex;

$sample = Regex::create()->generate('/[A-Z][a-z]{3,5}\d{2}/');
echo $sample;  // e.g., "Word12"
```

---

### explain(string $regex, string $format = 'text'): string

Generates a human-readable explanation of the pattern.

```php
use RegexParser\Regex;

// Plain text explanation
$text = Regex::create()->explain('/\d{3}-\d{4}/');
echo $text;
/*
Match exactly 3 digits, then hyphen, then exactly 4 digits.
*/

// HTML explanation for docs/UIs
$html = Regex::create()->explain('/\w+@\w+\.\w+/', 'html');
echo $html;
// <p>Match one or more word characters, then @, then...
```

---

### highlight(string $regex, string $format = 'console'): string

Generates syntax-highlighted output.

```php
use RegexParser\Regex;

// ANSI colors for console
$highlighted = Regex::create()->highlight('/\d+/', 'console');
echo $highlighted;  // "\033[38;2;78;201;176m\\d\033[0m\033[38;2;215;186;125m+\033[0m"

// HTML for web
$html = Regex::create()->highlight('/[a-z]+/', 'html');
echo $html;
// <span class="regex-token regex-literal">[a-z]</span>...
```

---

## Result Objects

### ValidationResult

Returned by `validate()`. Provides structured validation feedback.

```php
$result = Regex::create()->validate('/[unclosed/');

if (!$result->isValid()) {
    echo $result->error;         // "Unterminated character class"
    echo $result->errorCode;     // "regex.syntax.unterminated"
    echo $result->offset;        // 9
    echo $result->caretSnippet;  // "Pattern: [unclosed\n          ^"
    echo $result->hint;          // "Close the bracket: ]"
    echo $result->category->value;  // "syntax"
}
```

---

### TolerantParseResult

Returned by `parse($regex, true)`. Contains partial AST plus errors.

```php
$result = Regex::create()->parse('/[broken/i', true);

echo $result->ast instanceof \RegexParser\Node\RegexNode;  // true (partial)
echo count($result->errors);  // 1
echo $result->errors[0]->getMessage();  // "Unterminated character class"
```

---

### AnalysisReport

Returned by `analyze()`. Comprehensive pattern analysis.

```php
$report = Regex::create()->analyze('/(a+)+b/');

if (!$report->isValid) {
    // Handle validation errors
    foreach ($report->errors as $error) {
        echo $error['message'];
    }
}

// Check ReDoS safety
if ($report->redos->severity->value !== 'safe') {
    echo "Pattern may be vulnerable!";
    echo $report->redos->recommendations[0];
}

// Get explanation
echo $report->explain;
```

---

### OptimizationResult

Returned by `optimize()`. Shows what changed.

```php
$result = Regex::create()->optimize('/[0-9]+/');

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'

foreach ($result->changes as $change) {
    echo "- $change\n";
}
// Output:
// - Replaced [0-9] with \d
// - Saved 5 characters
```

---

### TranspileResult

Returned by `transpile()`. Includes JavaScript output and diagnostics.

```php
$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'javascript');

echo $result->pattern;     // '(?<word>\\w+)'
echo $result->flags;       // 'i'
echo $result->literal;     // '/(?<word>\\w+)/i'
echo $result->constructor; // 'new RegExp("(?<word>\\w+)", "i")'

foreach ($result->warnings as $warning) {
    echo "- $warning\n";
}
```

---

### LiteralExtractionResult

Returned by `literals()`. Extracts fixed content.

```php
$result = Regex::create()->literals('/user-\d{4}/');

echo $result->literalSet->getLongestPrefix();   // 'user-'
var_dump($result->literalSet->complete);        // bool(false)
echo $result->confidence;                       // 'medium'

foreach ($result->literals as $literal) {
    echo "Found literal: $literal\n";
}
// Output: Found literal: user-
```

---

## Exception Map

RegexParser uses a focused exception hierarchy for precise error handling:

Exception hierarchy (simplified):
- `RegexParserExceptionInterface`
  - `InvalidRegexOptionException` (invalid configuration option)
  - `LexerException` (tokenization failure)
  - `ParserException`
    - `SyntaxErrorException` (invalid syntax)
    - `SemanticErrorException` (semantic validation failure)
  - `RecursionLimitException` (max recursion depth)
  - `ResourceLimitException` (resource limits)
  - `RegexException` (base exception with position and error code)
  - `TranspileException` (unsupported target or feature during transpile)
  - `SampleGenerationException` (no sample the pattern matches was found)

**Usage Examples:**

```php
use RegexParser\Regex;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Exception\InvalidRegexOptionException;

try {
    $regex = Regex::create(['invalid_key' => 'value']);
} catch (InvalidRegexOptionException $e) {
    echo "Bad option: {$e->getMessage()}";
}

try {
    $ast = Regex::create()->parse('/[unclosed/');
} catch (LexerException $e) {
    echo "Tokenization failed: {$e->getMessage()}";
} catch (ParserException $e) {
    echo "Parse failed: {$e->getMessage()}";
}

// Catch-all for any library error
try {
    $result = Regex::create()->validate('/test/');
} catch (\RegexParser\Exception\RegexParserExceptionInterface $e) {
    echo "RegexParser error: {$e->getMessage()}";
}
```

---

## Quick Reference

| Method                  | Returns                 | Purpose           |
|-------------------------|-------------------------|-------------------|
| `create($options)`      | Regex                   | Factory method    |
| `parse($pattern)`       | RegexNode               | Parse to AST      |
| `parse($pattern, true)` | TolerantParseResult     | Parse with errors |
| `validate($regex)`      | ValidationResult        | Check validity    |
| `analyze($regex)`       | AnalysisReport          | Analysis report   |
| `redos($regex)`         | ReDoSAnalysis           | ReDoS check       |
| `optimize($regex)`      | OptimizationResult      | Optimize pattern  |
| `transpile($regex, $target)` | TranspileResult    | Convert dialects  |
| `explain($regex)`       | string                  | Human explanation |
| `highlight($regex)`     | string                  | Syntax highlight  |
| `generate($regex)`      | string                  | Generate sample   |
| `literals($regex)`      | LiteralExtractionResult | Extract literals  |

---

Previous: [Reference Index](README.md) | Next: [Diagnostics](diagnostics.md)
