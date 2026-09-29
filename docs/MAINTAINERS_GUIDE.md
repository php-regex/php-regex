# Maintainers Guide

This guide is for framework maintainers, library maintainers, and tooling authors who want to integrate RegexParser as a first-class analysis component. Whether you're building a PHPStan rule, a Symfony bundle, or a custom CLI tool, this guide covers everything you need.

## Contributor Checklist

If you are new to the codebase, this short checklist helps you get oriented quickly:

- Read [docs/ARCHITECTURE.md](ARCHITECTURE.md) and [docs/EXTENDING_GUIDE.md](EXTENDING_GUIDE.md).
- Run `composer install` and verify `composer phpunit` passes before changes.
- When touching Lexer/Parser or AST nodes, update relevant visitors and add tests.
- Preserve byte offsets in diagnostics and update [docs/reference/diagnostics.md](reference/diagnostics.md) for new codes.
- Keep docs in sync with behavior changes, especially [docs/reference.md](reference.md).

## Where to Start

For first-time contributors, this is a good entry path:

- Skim `src/Regex.php` to understand the public API and options flow.
- Read `src/Lexer.php`, `src/Parser.php`, and `src/NodeVisitor/*` for the core pipeline.
- Use `tests/Fixtures/*` and `tests/Unit/*` to see real patterns and expected behavior.
- Scan `tests/Fixtures/pcre_patterns.php` for real-world patterns to reuse in examples.
- Run `bin/regex parse '/^hello$/'` and `bin/regex analyze '/(a+)+$/'` to connect CLI output with AST behavior.

## The Integration Landscape

**RegexParser** is typically embedded in:

- PHPStan rules and custom static analyzers
- Symfony bundles and validators
- CLI tools and CI pipelines

Integration flow:

```
Your app -> RegexParser -> AST + visitors -> results
```

---

## Configuration Reference (Regex::create($options))

`Regex::create()` accepts a validated array of options. Invalid keys raise `InvalidRegexOptionException`.

### Available Options

| Option                    | Purpose                                   |
|---------------------------|-------------------------------------------|
| `cache`                   | Configure caching behavior                |
| `max_pattern_length`      | Set maximum pattern length                |
| `max_lookbehind_length`   | Limit variable-length lookbehinds         |
| `runtime_pcre_validation` | Enable runtime PCRE checks                |
| `redos_ignored_patterns`  | Skip ReDoS analysis for specific patterns |
| `max_recursion_depth`     | Set parser recursion limit                |
| `php_version`             | Target PHP version for validation         |
| `pcre_version`            | Target PCRE2 release for validation       |

For a full list of options, types, and default values, see the [API Reference](reference/api.md#configuration-options).

### Configuration flow

```
Regex::create([options])
  -> validate keys/types/values
  -> build Regex instance
```

### Example

```php
use RegexParser\Regex;
use RegexParser\Cache\FilesystemCache;

$regex = Regex::create([
    'cache' => new FilesystemCache('/var/cache/regex-parser'),
    'max_pattern_length' => 100_000,
    'max_lookbehind_length' => 255,
    'runtime_pcre_validation' => true,
    'redos_ignored_patterns' => [
        '/^([0-9]{4}-[0-9]{2}-[0-9]{2})$/',  // Trusted date pattern
        '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',  // Safe email
    ],
    'max_recursion_depth' => 1024,
    'php_version' => '8.2',
]);

// Now use $regex for parsing, validation, or analysis
$result = $regex->validate('/foo|bar/');
echo $result->isValid() ? 'Valid' : 'Invalid';
```

### Common Configuration Pitfalls

```php
// PITFALL 1: Cache path not writable
// ERROR: Cache directory must exist and be writable
$regex = Regex::create([
    'cache' => '/nonexistent/path/cache',  // WRONG
]);

// FIX: Use null to disable cache, or ensure path exists
$regex = Regex::create(['cache' => null]);  // $regex = Regex::create(['cache' => '/tmp/regex-cache']);  // if writable

// PITFALL 2: max_lookbehind too high
// ERROR: Unbounded lookbehinds are invalid in PCRE
$regex = Regex::create([
    'max_lookbehind_length' => 1000000,  // Way too high
]);

// FIX: Use the default 255, or lower for stricter validation
$regex = Regex::create([
    'max_lookbehind_length' => 100,  // Stricter
]);

// PITFALL 3: Invalid PHP version
// ERROR: Version must be valid semver or PHP_VERSION_ID
$regex = Regex::create([
    'php_version' => 'invalid-version',  // WRONG
]);

// FIX: Use valid version string or integer
$regex = Regex::create(['php_version' => '8.2']);   // $regex = Regex::create(['php_version' => 80200]);   // (PHP_VERSION_ID)
```

---

## Exception Hierarchy

RegexParser exposes a stable exception surface for precise error handling:

Exception hierarchy (simplified):
- `Throwable`
  - `RegexException`
    - `LexerException`
    - `ParserException`
      - `SyntaxErrorException`
      - `SemanticErrorException`
    - `RecursionLimitException`
    - `ResourceLimitException`
- `RegexParserExceptionInterface` (implemented by parser/lexer exceptions)

Catch-all:
- `RegexParserExceptionInterface`

Specific catches:
- `LexerException`
- `ParserException`

### Exception Handling Examples

```php
use RegexParser\Regex;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Exception\RegexParserExceptionInterface;
use RegexParser\Exception\InvalidRegexOptionException;

try {
    $ast = Regex::create()->parse('/[a-z]+/');
} catch (InvalidRegexOptionException $e) {
    // Configuration error - check your options
    echo "Invalid configuration: " . $e->getMessage();
} catch (LexerException $e) {
    // Tokenization failed - malformed pattern
    echo "Tokenization error at position {$e->getPosition()}: {$e->getMessage()}";
} catch (ParserException $e) {
    // Grammar failed - invalid structure
    echo "Parse error: {$e->getMessage()}";
} catch (RegexParserExceptionInterface $e) {
    // Any other parser/lexer error
    echo "RegexParser error: {$e->getMessage()}";
}

// Handling specific error codes
try {
    $result = Regex::create()->validate('/(?<=a+)b/');
    if (!$result->isValid()) {
        echo "Error {$result->errorCode}: {$result->error}";
        echo "Hint: {$result->hint}";
    }
} catch (\Throwable $e) {
    echo "Unexpected error: {$e->getMessage()}";
}
```

### Exception Reference Table

| Exception                     | When It's Thrown          | Common Cause                                |
|-------------------------------|---------------------------|---------------------------------------------|
| `LexerException`              | Tokenization fails        | Invalid escape, malformed character class   |
| `ParserException`             | AST construction fails    | Missing delimiter, unbalanced groups        |
| `SyntaxErrorException`        | Invalid syntax            | Unrecognized token                          |
| `SemanticErrorException`      | Invalid semantics         | Unbounded lookbehind, invalid backreference |
| `RecursionLimitException`     | Parser recursion too deep | Nested patterns exceed limit                |
| `ResourceLimitException`      | Resource limits exceeded  | Pattern too long, too many tokens           |
| `InvalidRegexOptionException` | Invalid `create()` option | Unknown key, wrong type                     |

---

## JSON Output Schema (CLI Linting)

Use `vendor/bin/regex lint --format=json` for machine-readable output suitable for CI/CD pipelines, IDEs, and custom tooling.

### Output Structure Overview

```json
{
  "stats": {
    "errors": 0,
    "warnings": 1,
    "optimizations": 1
  },
  "results": [
    {
      "file": "src/Service/EmailValidator.php",
      "line": 42,
      "source": "php",
      "pattern": "/^[a-z0-9._%+-]+@[a-z0-9-]+(?:\\.[a-z0-9-]+)+$/i",
      "location": null,
      "issues": [
        {
          "type": "warning",
          "message": "Nested quantifiers detected...",
          "file": "src/Service/EmailValidator.php",
          "line": 42,
          "column": 9,
          "issueId": "regex.lint.quantifier.nested",
          "hint": "Use atomic groups...",
          "source": "php"
        }
      ],
      "optimizations": [
        {
          "file": "src/Service/EmailValidator.php",
          "line": 42,
          "optimization": {
            "original": "/[0-9]+/",
            "optimized": "/\\d+/",
            "changes": ["Optimized pattern."]
          },
          "savings": 2,
          "source": "php"
        }
      ]
    }
  ]
}
```

Notes:
- `stats` contains the run summary.
- `results` contains one entry per pattern occurrence.
- `issues` and `optimizations` are present only when applicable.

### Field Reference

| Field                       | Type   | Description                    | Always Present    |
|-----------------------------|--------|--------------------------------|-------------------|
| `stats.errors`              | int    | Total error count              | Yes               |
| `stats.warnings`            | int    | Total warning count            | Yes               |
| `stats.optimizations`       | int    | Total optimization suggestions | Yes               |
| `results[].file`            | string | Source file path               | Yes               |
| `results[].line`            | int    | Line number                    | Yes               |
| `results[].column`          | int    | Column number                  | Sometimes         |
| `results[].source`          | string | Source language                | Yes               |
| `results[].pattern`         | string | The pattern being analyzed     | Yes               |
| `results[].issues[]`        | array  | Diagnostic issues              | When issues found |
| `results[].optimizations[]` | array  | Optimization suggestions       | When applicable   |

### CLI Examples

```bash
# Lint a single pattern
vendor/bin/regex lint --pattern '/(a+)+b/'

# Lint with JSON output
vendor/bin/regex lint --format=json src/

# Lint with minimum savings threshold
vendor/bin/regex lint --min-savings 5 src/

# Lint with optimization suggestions
vendor/bin/regex lint --optimize src/

# Lint without optimization
vendor/bin/regex lint --no-optimize src/
```

### Integrating with CI/CD

```yaml
# .github/workflows/regex-lint.yml
name: Regex Lint

on: [push, pull_request]

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/cache-extensions@v1
        with:
          php-version: '8.2'
      - name: Install dependencies
        run: composer install --no-progress
      - name: Run RegexParser linter
        run: vendor/bin/regex lint src/ --format=json > regex-issues.json
      - name: Check for critical issues
        run: |
          ERRORS=$(jq '[.results[] | .issues[] | select(.type == "error")] | length' regex-issues.json)
          if [ "$ERRORS" -gt 0 ]; then
            echo "Found $ERRORS critical regex issues"
            cat regex-issues.json | jq '.results[] | select(.issues | length > 0) | {file, line, issues: [.issues[].message]}'
            exit 1
          fi
```

---

## Building Custom Integrations

### Integration Pattern 1: Simple Wrapper

```php
namespace MyApp\Regex;

use RegexParser\Regex;

class RegexValidator
{
    private Regex $regex;

    public function __construct()
    {
        $this->regex = Regex::create([
            'runtime_pcre_validation' => true,
            'max_pattern_length' => 10000,
        ]);
    }

    public function validatePattern(string $pattern): bool
    {
        $result = $this->regex->validate($pattern);
        return $result->isValid();
    }

    public function checkReDoS(string $pattern): array
    {
        $analysis = $this->regex->redos($pattern);
        return [
            'severity' => $analysis->severity->value,
            'confidence' => $analysis->confidence->value,
        ];
    }
}
```

### Integration Pattern 2: Custom Visitor

```php
namespace MyApp\Regex;

use RegexParser\Regex;
use RegexParser\NodeVisitor\AbstractNodeVisitor;
use RegexParser\Node;

class LiteralCollector extends AbstractNodeVisitor
{
    private array $literals = [];

    public function visitLiteral(Node\LiteralNode $node): void
    {
        $this->literals[] = $node->value;
    }

    public function getLiterals(): array
    {
        return $this->literals;
    }
}

class PatternAnalyzer
{
    public function extractLiterals(string $pattern): array
    {
        $ast = Regex::create()->parse($pattern);
        $visitor = new LiteralCollector();
        $ast->accept($visitor);
        return $visitor->getLiterals();
    }
}
```

### Integration Pattern 3: Symfony Integration

```php
// src/Validator/RegexValidator.php
namespace App\Validator;

use RegexParser\Regex;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class RegexConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $regex = Regex::create([
            'runtime_pcre_validation' => true,
        ]);

        $result = $regex->validate($constraint->pattern);

        if (!$result->isValid()) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ error }}', $result->error)
                ->addViolation();
            return;
        }

        // Check ReDoS safety
        $analysis = $regex->redos($constraint->pattern);
        if ($analysis->severity->value !== 'safe') {
            $this->context->buildViolation($constraint->redosMessage)
                ->setParameter('{{ severity }}', $analysis->severity->value)
                ->addViolation();
        }
    }
}
```

---

## Best Practices for Integrations

- Always configure `max_pattern_length` for user-supplied patterns.
- Use `runtime_pcre_validation` for critical paths.
- Cache ASTs for repeated pattern analysis.
- Handle exceptions at the right level.
- Clear validator caches in long-running processes.
- Prefer `ValidationResult` over exceptions for validation.
- Use `redos()` for ReDoS checks before storing patterns.
- Log diagnostics for debugging integration issues.

---

## Memory Management for Long-Running Processes

For long-running processes (daemons, workers), manage memory carefully:

```php
use RegexParser\Regex;

class RegexProcessor
{
    private Regex $regex;

    public function __construct()
    {
        $this->regex = Regex::create([
            'cache' => new FilesystemCache('/tmp/regex-cache'),
        ]);
    }

    public function processPatterns(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            // Process pattern...
            $result = $this->regex->validate($pattern);

            // Periodically clear caches
            static $counter = 0;
            if (++$counter % 100 === 0) {
                $this->regex->clearValidatorCaches();
            }
        }
    }
}
```

---

## Related Documentation

| Topic           | File                                       |
|-----------------|--------------------------------------------|
| API Reference   | [api.md](reference/api.md)                 |
| Diagnostics     | [diagnostics.md](reference/diagnostics.md) |
| ReDoS Guide     | [REDOS_GUIDE.md](../REDOS_GUIDE.md)        |
| Architecture    | [ARCHITECTURE.md](../ARCHITECTURE.md)      |
| Extending Guide | [EXTENDING_GUIDE.md](EXTENDING_GUIDE.md)   |

---

## Summary

| Topic         | Key Points                                            |
|---------------|-------------------------------------------------------|
| Configuration | Use `Regex::create()` with validated options          |
| Exceptions    | Catch specific types for precise error handling       |
| JSON Output   | Schema includes stats, results, issues, optimizations |
| Integration   | Build wrappers, visitors, or Symfony integrations     |
| Memory        | Clear caches in long-running processes                |

---

Previous: [Architecture](ARCHITECTURE.md) | Next: [Extending Guide](EXTENDING_GUIDE.md)