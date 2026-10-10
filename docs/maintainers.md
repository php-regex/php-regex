---
description: "Embed PHPRegex in your own tool: configuration options, the exception surface, machine-readable lint JSON for CI gates, and the wrapper and visitor integration patterns."
redirect_from:
  - /MAINTAINERS_GUIDE/
  - /MAINTAINERS_GUIDE.html
---
# Maintainers Guide

This guide is for framework maintainers, library maintainers, and tooling authors who want to integrate PHPRegex as a first-class analysis component. Whether you're building a PHPStan rule, a Symfony bundle, or a custom CLI tool, this guide covers everything you need.

The PHP snippets below use the `Regex` facade, which ships in `regex-toolkit`. It needs PHP 8.2 or later and the `mbstring` extension (PCRE ships with PHP); the [Quick Start](quick-start.md) covers the first steps if you are new here.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

## Contributor Checklist

If you are new to the codebase, this short checklist helps you get oriented quickly:

- Read [docs/architecture.md](architecture.md) and [docs/extending.md](extending.md).
- Install everything with `task -t taskfile.dist.yaml install` — Composer dependencies plus the dev tools, each in its own vendor under `tools/` — then verify `composer phpunit` passes before changes.
- When touching Lexer/Parser or AST nodes, update relevant visitors and add tests.
- Preserve byte offsets in diagnostics and update [docs/reference/diagnostics.md](reference/diagnostics.md) for new codes.
- Keep docs in sync with behavior changes, especially [docs/reference/rules.md](reference/rules.md).

## Where to Start

For first-time contributors, this is a good entry path:

- Skim `src/Toolkit/Regex.php` to understand the public API and options flow.
- Read `src/Parser/Lexer.php`, `src/Parser/Syntax/TokenParser.php`, and `src/Parser/Validation/Validator.php` for the core pipeline.
- Use `tests/Fixtures/*` and `tests/Unit/*` to see real patterns and expected behavior.
- Scan `tests/Fixtures/pcre_patterns.php` for real-world patterns to reuse in examples.
- Run `php src/Cli/bin/regex parse '/^hello$/'` and `php src/Cli/bin/regex analyze '/(a+)+$/'` from the repository root to connect CLI output with AST behavior (a Composer-installed dependency links the same binary as `vendor/bin/regex`).

## The Integration Landscape

**PHPRegex** is typically embedded in:

- PHPStan rules and custom static analyzers
- Symfony bundles and validators
- CLI tools and CI pipelines

Integration flow:

```
Your app -> PHPRegex -> AST + visitors -> results
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
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Cache\FilesystemCache;

$regex = Regex::create([
    'cache' => new FilesystemCache('/var/cache/php-regex'),
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
echo $result->isValid ? 'Valid' : 'Invalid';
```

### Common Configuration Pitfalls

```php
// PITFALL 1: Cache path not writable
// ERROR: the cache directory must be creatable, and only its owner may write to it
$regex = Regex::create([
    'cache' => '/nonexistent/path/cache',  // WRONG
]);

// FIX: Use null to disable cache, or ensure path exists
$regex = Regex::create(['cache' => null]);  // or a directory of the project: ['cache' => __DIR__.'/var/cache/regex']

// PITFALL 2: max_lookbehind_length caps the span of a variable-length
// lookbehind — raising it lifts the cap, it does not validate unbounded ones
// (a 1,000,000 ceiling is accepted silently; (?<=a+) stays invalid anyway)
$regex = Regex::create([
    'max_lookbehind_length' => 1000000,
]);

// By default the cap is 255: '/(?<=a{1,300})x/' is refused with
// "Lookbehind exceeds the maximum length of 255 (max=300)"; lower the
// option for stricter validation, raise it to accept wider spans
$regex = Regex::create([
    'max_lookbehind_length' => 100,  // Stricter
]);

// PITFALL 3: Invalid PHP version
// ERROR: Version must be valid semver or PHP_VERSION_ID
$regex = Regex::create([
    'php_version' => 'invalid-version',  // WRONG
]);

// FIX: Use valid version string or integer
$regex = Regex::create(['php_version' => '8.2']);
// or a PHP_VERSION_ID integer:
$regex = Regex::create(['php_version' => 80200]);
```

---

## Exception Hierarchy

PHPRegex exposes a stable exception surface for precise error handling:

Exception hierarchy (simplified):
- `Throwable`
  - `RegexException`
    - `LexerException`
    - `ParserException`
      - `SyntaxErrorException`
      - `RecursionLimitException`
      - `ResourceLimitException`
    - `SemanticErrorException`
- `InvalidRegexOptionException` extends `\InvalidArgumentException`, and `CacheException` extends `\RuntimeException` — both outside `RegexException`, both implementing `ExceptionInterface`

Every exception above implements `ExceptionInterface`, the catch-all.

Catch-all:
- `ExceptionInterface`

Specific catches:
- `LexerException`
- `ParserException`

Note that `SemanticErrorException` sits beside `ParserException`, directly
under `RegexException`: a `catch (ParserException)` block does not intercept it.

### Exception Handling Examples

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;

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
} catch (ExceptionInterface $e) {
    // Any other parser/lexer error
    echo "PHPRegex error: {$e->getMessage()}";
}

// Handling specific error codes
try {
    $result = Regex::create()->validate('/(?<=a+)b/');
    if (!$result->isValid) {
        echo "Error {$result->errorCode?->value}: {$result->error}";
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
  "target": {
    "php": "8.4.26",
    "pcre": "10.49",
    "source": "running PHP",
    "range": [{"php": "8.4.26", "pcre": "10.49"}]
  },
  "stats": {
    "errors": 0,
    "warnings": 1,
    "optimizations": 1,
    "redos_errors": 0,
    "infos": 0,
    "lint_errors": 0,
    "parser_fallbacks": 0
  },
  "results": [
    {
      "file": "app/Service/Validator.php",
      "line": 9,
      "column": 33,
      "file_offset": 147,
      "source": "preg_match()",
      "pattern": "/^(?:[0-9]+\\s?)+$/",
      "location": null,
      "issues": [
        {
          "severity": "warning",
          "file": "app/Service/Validator.php",
          "line": 9,
          "column": 33,
          "file_offset": 147,
          "position": 1,
          "issue_id": "regex.lint.quantifier.nested",
          "message": "Nested quantifiers can cause catastrophic backtracking.",
          "hint": "Consider atomic groups (?>...) or possessive quantifiers ...",
          "tip": null,
          "source": "preg_match()",
          "validation": null,
          "analysis": null,
          "target": null
        }
      ],
      "optimizations": [
        {
          "file": "app/Service/Validator.php",
          "line": 9,
          "column": 33,
          "file_offset": 147,
          "optimization": {
            "original": "/^(?:[0-9]+\\s?)+$/",
            "optimized": "/^(?:\\d+\\s?)+$/",
            "changes": ["Optimized pattern."]
          },
          "savings": 3,
          "source": "preg_match()"
        }
      ]
    }
  ]
}
```

Notes:
- `target` names the PHP and PCRE2 release the patterns were judged for; `target.range` lists every PHP and PCRE2 they were validated at, the floor first.
- `stats` contains the run summary; every key is always present, `0` when there is none.
- `results` contains one entry per pattern with something to report, sorted by file, line and column.
- `issues` and `optimizations` are always present, empty when there is none; every issue carries every key, `null` when it does not apply.

### Field Reference

The [JSON output reference](reference/json-output.md) lists every key of this
report, with its type and meaning, and the JSON of `analyze`, `debug`, `redos`
and `transpile`. It also gives the units of each position, the error envelope
printed when a run fails, and what a minor release may add.

### CLI Examples

```bash
# Analyze a single pattern
vendor/bin/regex analyze '/(a+)+b/'

# Lint with JSON output
vendor/bin/regex lint --format=json src/

# Lint with minimum savings threshold
vendor/bin/regex lint --min-savings 5 src/

# Lint without optimization suggestions (they are on by default)
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
      - name: Run PHPRegex linter
        run: vendor/bin/regex lint src/ --format=json > regex-issues.json
      - name: Check for critical issues
        run: |
          # "critical" and "error" both surface with severity "error" in the JSON
          ERRORS=$(jq '[.results[] | .issues[] | select(.severity == "error")] | length' regex-issues.json)
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

use PHPRegex\Toolkit\Regex;

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
        return $result->isValid;
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

The full walkthrough of a typical visitor — the `LiteralCollector` class, what
extending `AbstractTraversingVisitor` buys you, how to skip one subtree — lives
in the [visitor reference](visitors/README.md#abstracttraversingvisitor). From
an integration, the shape is:

```php
namespace MyApp\Regex;

use PHPRegex\Toolkit\Regex;

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

use PHPRegex\Toolkit\Regex;
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

        if (!$result->isValid) {
            $this->context->buildViolation($constraint->message)
                ->setParameter(':error', $result->error)
                ->addViolation();
            return;
        }

        // Check ReDoS safety
        $analysis = $regex->redos($constraint->pattern);
        if ($analysis->severity->value !== 'safe') {
            $this->context->buildViolation($constraint->redosMessage)
                ->setParameter(':severity', $analysis->severity->value)
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
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Cache\FilesystemCache;

class RegexProcessor
{
    private Regex $regex;

    public function __construct()
    {
        $this->regex = Regex::create([
            'cache' => new FilesystemCache(__DIR__.'/var/cache/regex'),
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
                $this->regex->clearCaches();
            }
        }
    }
}
```

---

## Capture Shapes Next to PHPStan

The default test run replays the capture shapes on the engine over a corpus of
`preg_match()` cases, `tests/Fixtures/CaptureShapeParity/cases.php`: for each pattern
and each flag set, the shape must hold every `$matches` PHP writes, key by key.

A report puts the type PHPStan infers for `$matches` next to the shape PHPRegex writes,
for every row of that corpus. It is left out of the default run; run it alone, and it
writes `var/capture-shape-parity.md`:

```bash
tools/phpunit/vendor/bin/phpunit --group capture-shape-parity
```

The report lists the rows where either type refuses a `$matches` the engine writes,
and compares the two types (equal, narrower, wider or incomparable). It fails only when
PHPRegex's shape refuses one; what PHPStan infers is recorded, never asserted.

The time to read a shape from the pattern string, over that corpus and over the lint
corpus:

```bash
tools/phpbench/vendor/bin/phpbench run tests/Benchmark/CaptureShapeBench.php --report=default
```

## Benchmarks

`tests/Benchmark/` measures time and memory with PHPBench, one group per
subsystem:

| group | measures |
|---|---|
| `lexer`, `parser`, `validate` | tokenizing, parsing and validating |
| `lint` | the lint run over a fixed set of files, one process |
| `redos` | the theoretical ReDoS analysis |
| `redos-corpus` | the same analysis once per pattern of the lint corpus, ASTs cached (the longest group) |
| `automata` | NFA construction, determinization, minimization and the language solver |
| `optimizer` | the optimizer, with its default options and with every rewrite verified by automata (the PHPStan extension's options) |
| `capture-shape`, `formatter` | reading a capture shape, writing lint reports |

```bash
composer bench -- --group=automata
```

Three kinds of input:

- **The lint corpus** (`tests/Fixtures/Corpus/lint-expectations.json`): one
  subject runs every pattern of it, so the number is the cost of real code.
- **One case per file** under `tests/Benchmark/data/<group>/<slug>.php`, each
  returning its `pattern`, its `origin` (`synthetic`, `issue #N` or
  `corpus:<where>`) and a `note`. A case of `redos` or `automata` also
  declares the outcome it measures in `expect`: `proven`, `heuristic` or
  `budget_exceeded` for `redos`, `complete` or `guard` for `automata`; no
  other group takes it. A change that moves the case to another outcome
  fails the check below, as the timing would no longer measure the same
  work. A pattern PCRE refuses, kept to time the error path, says so with
  `'invalid' => true`. A pattern that was slow, or that someone reported as
  slow, gets a file here in the same commit as its fix, and the file stays:
  the case is measured from then on. The slug is the case's name in every
  comparison: choose it once.
- **Growth series** (`automata`, `redos`): the same pattern family at growing
  sizes (`a{80}`, `a{160}`, `a{320}`, `a{640}`). How the time grows from one
  size to the next shows the complexity class; every point stays under the
  default resource limits, so a series measures work, not a limit tripping.

Most subjects are cold: the caches are emptied before each measurement,
which is what a linter pays when it sees a pattern once. Subjects ending in
`Warm` keep the caches, as a long-running process does; a subject run over
several revolutions finds the process-wide caches filled after its first
one, and its docblock says so. `benchNoop` in each
class gives the memory floor of the process; subtract it to read a subject's
own memory, and read that figure as coarse.

`phpbench.json` pins the settings that matter (PCRE JIT, OPcache, memory
limit, and Xdebug and PCOV off, which would otherwise skew every number) for
every benchmark process. Where the local `php.ini` loads extensions that
make each PHP process slow to start, `--php-disable-ini` runs the processes
without it, provided `php -n -m` still lists `mbstring`. Compare numbers from
the same machine only.

`tests/Unit/Benchmark/BenchmarkDataTest.php` checks every case file: the real
PCRE engine compiles each pattern (or refuses it, for a case marked
`'invalid' => true`), the library parses it, and every growth series stays
under the limits.

## Pattern Info Next to pcre2test

The exact facts of `PatternInfo` (capture count, names, max back reference,
`\C`, the limits, the newline and `\R` conventions) are checked against what
PCRE2 itself reports. `tests/Fixtures/PatternInfo/pcre2test.out` holds the
`/I` output of `pcre2test` for every pattern of
`tests/Fixtures/PatternInfo/patterns.txt`, and
`tests/Integration/PatternInfo/PatternInfoPcre2ParityTest.php` reads it,
block by block, in pattern order.

After adding a pattern to `patterns.txt`, or to check a newer PCRE2,
regenerate the output with a `pcre2test` binary:

```bash
php tests/Fixtures/PatternInfo/generate.php /opt/homebrew/bin/pcre2test
```

The argument is the binary to run, `pcre2test` on the `PATH` when it is left
out. The committed output was written by PCRE2 10.49. The script turns each
PHP modifier into the one PHP passes to PCRE2 (`u` is `utf,ucp`; `S` and `X`
pass nothing) and compiles every pattern with `allow_lookaround_bsk`, as PHP
8.2 to 8.4 do. It exits with 1 when a pattern has a modifier it cannot
convert or when `pcre2test` fails; a pattern PCRE2 refuses shows as a
`Failed:` line, which the test rejects. Every pattern of the file must compile
on PHP 8.4. Commit `patterns.txt` and `pcre2test.out` together: the test
fails when their counts or their order differ.

---

## Related Documentation

| Topic           | File                                       |
|-----------------|--------------------------------------------|
| API Reference   | [api.md](reference/api.md)                 |
| Diagnostics     | [diagnostics.md](reference/diagnostics.md) |
| ReDoS Guide     | [guides/redos.md](guides/redos.md)           |
| Architecture    | [architecture.md](architecture.md)         |
| Extending Guide | [extending.md](extending.md)   |

---

## Summary

| Topic         | Key Points                                            |
|---------------|-------------------------------------------------------|
| Configuration | Use `Regex::create()` with validated options          |
| Exceptions    | Catch specific types for precise error handling       |
| JSON Output   | Schema includes stats, results, issues, optimizations |
| Integration   | Build wrappers, visitors, or Symfony integrations     |
| Memory        | Clear caches in long-running processes                |
