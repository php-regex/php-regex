---
description: "Embed PHPRegex in your own tool: configuration options, the exception surface, machine-readable lint JSON for CI gates, and the wrapper and visitor integration patterns."
redirect_from:
  - /MAINTAINERS_GUIDE.html
---
# Maintainers Guide

This guide is for framework maintainers, library maintainers, and tooling authors who want to integrate PHPRegex as a first-class analysis component. Whether you're building a PHPStan rule, a Symfony bundle, or a custom CLI tool, this guide covers everything you need.

The PHP snippets below use the `Regex` facade, which ships in `regex-toolkit`. It needs PHP 8.1 or later and the `mbstring` extension (PCRE ships with PHP); the [Quick Start](quick-start.md) covers the first steps if you are new here.

{% include install.html package="php-regex/regex-toolkit" %}

## Contributor Checklist

If you are new to the codebase, this short checklist helps you get oriented quickly:

- Read [docs/architecture.md](architecture.md) and [docs/extending.md](extending.md).
- Install everything with `task -t taskfile.dist.yaml install` — Composer dependencies plus the dev tools, each in its own vendor under `tools/` — then verify `composer phpunit` passes before changes (`composer test:parallel` runs the same suite across one process per CPU core).
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
]);

// 'runtime_pcre_validation' compiles with the running PHP, so it cannot be
// combined with a 'php_version' target such as '8.2': create() throws.
// To judge for another PHP, set 'php_version' and drop the runtime check.

// Now use $regex for parsing, validation, or analysis
$result = $regex->validate('/foo|bar/');
echo $result->isValid ? 'Valid' : 'Invalid';
```

### Common Configuration Pitfalls

```php
// PITFALL 1: Cache path not writable
// No error is raised: a directory that cannot be created, or that someone
// other than its owner may write to, is skipped silently and every parse
// misses the cache (getCacheStats() reports only misses)
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
// ERROR: "php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".
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
    - `LimitException`
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
Neither does it intercept a `LimitException`: a limit of the library is no
grammar error, and `validate()` reports it with the `limit` category.

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
    echo "Tokenization error at position {$e->position}: {$e->getMessage()}";
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
| `LimitException`              | A limit of the library    | Parent of the two below: no verdict         |
| `RecursionLimitException`     | Parser recursion too deep | Nested patterns exceed limit                |
| `ResourceLimitException`      | Resource limits exceeded  | Pattern too long                            |
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
    "parser_fallbacks": 0,
    "not_analyzed": 0
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
          "docs_url": "https://php-regex.com/reference/rules/#nested-quantifiers-redos-risk",
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
            "changes": ["Wrote [0-9] as \\d."]
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

permissions:
  contents: read

jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          persist-credentials: false
      - uses: shivammathur/setup-php@v2
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
// src/Validator/RegexConstraintValidator.php
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

        $result = $regex->validate($value);

        if (!$result->isValid) {
            $this->context->buildViolation($constraint->message)
                ->setParameter(':error', $result->error)
                ->addViolation();
            return;
        }

        // Check ReDoS safety
        $analysis = $regex->redos($value);
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

## Deprecations the suite fails on

A PHP deprecation — engine or user-level — whose frame lands under `src/` fails
the test run: this library deprecating itself is never silent. The rule lives
in `tests/Support/SourceDeprecationListener.php` and its two subscriber
classes, wired in `phpunit.xml.dist`: a subscriber throws, and PHPUnit reports
a third-party subscriber exception as a test runner warning that exits the run
non-zero on its own — no `failOn*` attribute carries this gate.

Every vendor or tool deprecation stays PHPUnit's display-only signal. The dev
locks are re-resolved weekly, and Symfony, Laravel, Psalm and Rector deprecate
for reasons that are not this library's; failing on them would be a chronic
red that trains people to ignore the signal, which is why PHPUnit's own
`failOnDeprecation` — unable to tell own deprecations from vendor ones — is
rejected here.

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
PHPRegex's shape refuses one; what PHPStan infers is recorded, never asserted. CI runs
it on each PHP of the bundled PCRE2 job, after the formatter corpus.

The time to read a shape from the pattern string, over that corpus and over the lint
corpus:

```bash
tools/phpbench/vendor/bin/phpbench run tests/Benchmark/CaptureShapeBench.php --report=default
```

## The Playground Bundle

The home page's playground runs PHPRegex inside a PHP compiled to
WebAssembly, in the visitor's browser. What that engine loads is one
committed file, `docs/assets/playground/bundle.json`: the core library (every
package under `src/` but the CLI, the language server and the framework
bridges) and the driver `docs/assets/playground/driver.php`, comments
stripped, with a manifest naming the version, the commit and the date of the
snapshot. The page captions that snapshot; between two regenerations the
playground runs the library as it was, not as `src/` is.

Regenerate it before a release, after any change to the driver, and whenever
a change under `src/` should reach the playground:

```bash
bin/playground-bundle
```

The script also writes `docs/_data/playground.json`, the answers the home
shows before a visitor asks for a run, judged for the default browser
engine: the analysis and the lint findings of the sample, and the answer
of each of its five tools (the ReDoS verdict, the JavaScript translation,
the samples drawn for the pattern, the layout at width 80, and the test
written from the subject and those samples). Each tool's answer is stored
beside the request it answers, under `default.tools`, in the page's own
words: the page holds the answer while its tab asks that very request,
and `bin/playground-check` asks the browser engine the same questions and
expects the same answers, word for word. The test's header carries the
day the sample was first written, kept from one regeneration to the next
so the stored file stays byte-stable. A change to the driver or to the
generators makes the stored data stale, so regenerate after one. The suite
holds the bundle's shape, runs the bundled driver against the bundled
library, and fails when the driver in the tree differs from the bundled
one.

The driver answers eleven requests, each one JSON in, one JSON document out:
`analyze`, `match`, `lint` and `describe` feed the syntax tree, the
diagnostics and the matches, the first three tabs of the results panel;
`format`, `redos`, `transpile`, `samples`, `check` and `export` feed its
tool tabs; `trace-input` hands the step trace the pattern body,
its modifiers and the body's offsets as the library's delimiter rules read
them, with the subject cut at the trace's own cap (1 000 bytes, on a
character boundary), and refuses a pattern with an explicit callout.
The ReDoS verdict is asked on every run, whatever tab is open,
for the badge its tab carries. `match` and `check` touch no class of the library,
so they run on PHP 8.0 too, where the library does not; the driver keeps to
PHP 8.0 syntax for that reason. The answers carry a protocol number
(`PHPREGEX_PLAYGROUND_PROTOCOL`, 3 today, read by the page as `PROTOCOL`):
a field added to an answer is additive, a field removed or renamed bumps
it, and the page refuses an answer whose number it does not know.
Every answer also carries `notices`: a notice, warning or deprecation the
library raised while answering, each once, collected instead of printed
(printed, it would break the envelope). The page shows them in its status
line when it has nothing else to say, once per run, so what the engine
raised is never hidden; the sample stored in `_data/playground.json` holds
none, and `bin/playground-check` prints the ones the browser engine raised.

What the suite cannot see is the browser engine itself: the extensions it
lacks, the way it runs a request. `bin/playground-check` runs the bundle
inside that engine under Node (php-wasm and its mbstring extension are
installed into a temporary directory for the run) and compares its answers
with the local driver's. The documentation workflow runs it on every change
to the docs; run it by hand before regenerating:

```bash
bin/playground-check
```

The engine and the extension are loaded from jsDelivr by version, without
subresource integrity, as the tutorial's Run buttons already do.

### The step debugger's module

The Tools panel's "Step through" tab shows the match as PCRE2 ran it. The
engine that ran it is not the browser's PHP: php-wasm exposes no trace, so
the tab runs the same PCRE2 release a second time, compiled to WebAssembly
from the upstream sources, unmodified, with `PCRE2_AUTO_CALLOUT` set and a
callout recording every item the matcher reaches. The module today is
PCRE2 10.44, the release php-src bundles at 8.4.1 and 8.5.2 — the two
engines the page's captions report it for — built 8-bit, Unicode on, no
JIT, no DFA, with the limits of php-src's bundled copy asserted by the build
script. The trace is one `pcre2_match()`: the first match, with the options
PHP's wrapper would pass at that exact PHP version.

The build runs inside one Emscripten image, pinned by digest, so the bytes
are the same on every machine:

```bash
docker run --rm --platform linux/amd64 -v "$PWD:/work" -w /work \
  emscripten/emsdk@sha256:27bc6267cb285223b8aebb7627bfebae7cb3ad2aaa0d5923b8aa5321793033e8 \
  wasm/pcre2-trace/build.sh --write-manifest
```

`wasm/pcre2-trace/manifest.json` records the build: the PCRE2 tag and its
commit, the image and its digest, the module's name, size and sha256
(`pcre2-10.44.wasm`, about 290 KB, `78e68fff…` for Emscripten 4.0.15), its
exports and its one import (Emscripten's memory-growth notice, which the
page answers with a no-op; the memory grows on demand up to 1 GB, enough
for PHP's own recursion limit over hundreds of groups). The script refuses to run outside the
container and writes the module to `wasm/pcre2-trace/build/`, which git
ignores: the manifest is the only tracked trace of the binary. Run the
build twice; the sha256 must not move. `wasm/README.md` has the rest.

The module reaches the site inside `bundle.json`: `bin/playground-bundle`
reads it from `wasm/pcre2-trace/build/` and embeds it, base64, under
`modules` keyed by release (`pcre2-10.44`, with the release and the sha256
of the bytes beside them), the way it embeds the library's sources — and refuses to run when the module is not
there, so a bundle without a trace module cannot be regenerated by accident.
The trace worker checks the sha256 again before instantiating the bytes.
The weight is the module compressed as text: about 79 KB gzip on its own,
about 106 KB once base64 and gzip, on top of the roughly 500 KB the bundle weighed
before it. The bytes arrive with the bundle, on the playground's first run;
the trace worker starts, checks and instantiates them only when the tab is
opened.

CI keeps the three in step. The documentation workflow's `pcre2-module` job
rebuilds the module in the pinned container (`build.sh --check`), which
fails by name when the sha256 is not the manifest's; the documentation job
then downloads those bytes into `wasm/pcre2-trace/build/`, and
`bin/playground-check` compares them with the ones `bundle.json` embeds,
runs the module under Node against the pinned `pcre2test` traces of 10.44
(`tests/Documentation/Fixtures/pcre2-trace/10.44.txt`, item by item), and
runs the probe's four witnesses on the module and on php-wasm, as the page
does once per engine.

Those traces are pinned per PCRE2 release by `pin-traces.sh`, from a
`pcre2test` of that release at hand; the script refuses a binary of another
release:

```bash
wasm/pcre2-trace/pin-traces.sh 10.45 /path/to/pcre2test
```

After adding a case to `tests/Documentation/Fixtures/pcre2-trace/cases.txt`,
re-pin every release listed in `wasm/README.md` and add the case to the
shim test's table.

The options PHP passes are a table, `docs/assets/js/php-pcre-options.js`,
one row per exact PHP version the page's engines report, each read from
`ext/pcre/php_pcre.c` at the version's tag and naming the commit. The
table holds one row per engine, never two for the same minor:
`bin/playground-check` boots each engine and requires its exact version.
When php-wasm moves an engine (8.4.1 to 8.4.2), that engine's tag is
replaced in the command; a new minor adds its tag. The generator writes
only the tags it is given, in that order:

```bash
php wasm/pcre2-trace/php-pcre-options/generate.php /path/to/php-src php-8.1.28 php-8.2.11 php-8.3.11 php-8.4.1 php-8.5.2
```

The row's excerpt of `php_pcre.c` goes beside the others under
`tests/Documentation/Fixtures/php-pcre/<version>.txt`, headed by the tag
and the commit, and the version takes its place in the list in
`PhpPcreOptionsTableTest`, the versions the page offers; an upgraded
engine's old excerpt and list entry go. The test fails, version named,
until the excerpt and the row agree.

The rule behind all of it: the tab serves no trace it cannot vouch for. A
php-wasm upgrade whose engine reports a PHP version without a row makes the
tab say "No trace for PHP x.y.z yet"; an engine whose PCRE2 release has no
module, "No trace module for PCRE2 x.y yet"; a witness the module and the
engine answer differently disables the tab on that engine with the witness
named. Each is a sentence in the tab, never a trace of another release
passed off as the engine's.

## The Corpus

The linter and the ReDoS analyzer are run over a corpus of real PHP projects
to check that their rules produce sensible output on real code. This run is
not part of the automated CI differential tests; its lint results are in
`var/log/corpus.log`.

The corpus checkouts themselves are not committed. `corpus.json`, at the root
next to `composer.json`, lists every repository with its URL, branch and the
commit it was pinned to, much like a lock file. `bin/corpus` manages the
checkouts, composer-style:

```bash
php bin/corpus install                 # rebuild corpus/ exactly at the pinned commits
php bin/corpus update                  # prune, clone what is missing, then pull everything,
                                       # and write the new commits back to corpus.json
php bin/corpus update --no-prune       # keep checkouts that are no longer listed
php bin/corpus update --add https://github.com/vendor/repo.git # add a repository to corpus.json
php bin/corpus update --write-manifest # rewrite corpus.json from what is on disk
```

Every `corpus.json` key is a `vendor/name` path — a repository added without
`--as` is checked out under the `vendor/name` derived from its URL, and a URL
that offers no vendor segment is refused rather than guessed at. `--add` also
takes `--as <path>`, the path under `corpus/`, and `--branch <name>`, a branch
other than the remote's default.

`install` never writes to `corpus.json`: delete `corpus/` at any time and one
command rebuilds it, repository by repository, at the exact commits the
manifest pins. `update` is the only command that moves the pins. A listed
checkout with local changes is never reset unless `--force` is given. A
checkout no longer listed is removed only if it has no local changes:
`update --force` alone removes one that has them, and `install --force` keeps
it. Local changes are what `git status --porcelain` reports, so ignored files
(an installed `vendor/`, a `.env`) do not keep a checkout and are deleted with
it: copy out what you need before pruning. A clean repository cloned by hand is removed on the next run unless it is
added with `--add` or listed with `--write-manifest` first.

Regenerate `var/log/corpus.log` after updating the corpus, sweeping it
repository by repository:

```bash
php bin/corpus-lint
```

One lint run per repository, the target read from its own `composer.json`:
`config.platform.php` when it declares a plain version, else the lowest PHP
`require.php` allows, with the PCRE2 release `config.platform.lib-pcre` pins,
else the running one for the running PHP's minor, else the one bundled with
that PHP. A release
older than the library knows in full is judged with the oldest rules it does
— the notice the linter prints is carried into the log — and a repository
that declares neither `config.platform.php` nor `require.php` falls back to
`--php` (`8.1` by default). That fallback run does not read the repository's
`composer.json` at all, so a `config.platform.lib-pcre` it pins is ignored:
the PCRE2 is the one the working directory's `composer.json` pins, else the
running or bundled one for that PHP. Every run also reads `regex.dist.json`
and `regex.json` from the working directory, and they come before any
`composer.json`: a `pcreVersion` there judges every repository with that
PCRE2, its own `config.platform.lib-pcre` included, and a `phpVersion` there
replaces the PHP each repository requires. The `regex.dist.json` at the root
sets neither, so keep them out of a local `regex.json` before a sweep. PHP 7.2 and
earlier ship PCRE1, which the linter never judges: a `require.php` that also
allows PHP 7.3 or later is judged from the lowest such PHP, with the note
saying so in the log, and a `composer.json` that allows nothing from 7.3 on
is refused: the section names no target (`Target: none — exit 2`) and
carries the refusal, and the sweep counts it as a failure, never as a clean
repository. Each run carries the ReDoS analysis in confirmed mode
(`--redos --redos-mode=confirmed`): a pattern the analysis proves linear
loses the warnings that only guess at backtracking, and an exponential
verdict is replayed on the running PCRE: when the replay reproduces it, the
log says at which length `preg_match()` gives up. A polynomial verdict is not
replayed, and an exponential one the replay does not reproduce, or cannot run
because the limits cannot be set, carries no length (see
[Confirmed mode](guides/redos.md#confirmed-mode)). The merged
`var/log/corpus.log` keeps the issue format of a single run and gains one
`###` header per repository; each repository's own
log stays under `var/log/corpus-parts/`. `--only` and `--limit` sweep a slice
of the corpus, and the merged log renders the same whether the command runs
in a terminal or through a pipe, and whatever `COLUMNS` the shell exports:
every run folds its lines at 120 columns.

The merged log is rebuilt from every part the sweep has ever recorded, not
only from the repositories under `corpus/` today: a repository removed from
the corpus keeps its section, even after a full sweep. To drop it, delete
`var/log/corpus-parts/` and `var/log/corpus.log` together (the sweep reseeds
its list from the old log when the parts are gone), then run
`php bin/corpus-lint` over the whole corpus.

To judge the whole tree for one target instead, the PHP running the command
and the PCRE2 it links, without touching the tracked log:

```bash
php bin/regex lint corpus/ --php-version=runtime
```

## Findings

The playground's second list, and the pages under `/findings/`, show what
the linter reports on the corpus: every FAIL and WARN on the repositories
under `corpus/`, each judged for the PHP its own composer.json asks for
(its require.php floor, or the platform PHP it pins; PHP 8.1 when it names
none).
INFO-level advice is left out. Run daily:

```bash
php bin/corpus update --force    # clone or reset every repository
php bin/corpus-lint              # the log, and one JSON part per repository
php bin/findings-report          # the site's findings, from the JSON parts
```

`bin/corpus-lint` lints each repository twice under the same target: once
for `var/log/corpus.log`, once with `--format=json` into
`var/log/corpus-parts/<vendor>__<repo>.json` (`--no-json` skips it).
`bin/findings-report` reads those parts and the clones, and writes:

- `docs/assets/findings/index.json`: each repository with findings, its
  owner, the PHP and PCRE2 it was judged for, its clone's commit, its
  counts and the rules it met; fetched when the Findings list opens;
- `docs/assets/findings/repos/<vendor>__<repo>.json`: its findings, each
  with the linter's words and rule links, the optimizer's rewrite when it
  printed one, the code around it at the clone's commit, and for a proven
  ReDoS the witness and the length at which the replay gave up; fetched
  when the repository opens;
- `docs/_findings/<vendor>__<repo>.md`: its page, `/findings/<vendor>/<repo>/`
  (in the site's file names a dot of the repository's name is spelled `~`:
  `scrivo/highlight.php` is `scrivo__highlight~php`);
- `docs/assets/findings/avatars/<owner>.png`: the owner's avatar, downloaded
  once (`--offline` refuses a missing one instead).

Each JSON part names the commit it judged (`head`); the report refuses to
publish a part whose commit is not the one its clone stands on (run
`bin/corpus-lint` again), and refuses a `--corpus` that holds none of the
parts' repositories, so a wrong directory never empties the site. A
repository without a FAIL or a WARN, or gone from `corpus/`, gets nothing,
and the files of the day before are removed, avatars of owners gone
included. Avatars are kept at 48 pixels, rescaled when GitHub sends
another size. A finding's id
(`#f-…` on its repository's page) hashes its file and its pattern, so a
shared link survives a line added above it. The output is sorted and
dated by each clone's commit only: a day the corpus did not move writes
the same bytes.

A witness goes into the text field with four times the pumps the replay
needed under the library's backtrack limit, so PHP's default limit gives
up on it too; the suite runs each one through `preg_match()` to hold that.

## Benchmarks

`tests/Benchmark/` measures time and memory with PHPBench, one group per
subsystem:

| group | measures |
|---|---|
| `lexer`, `parser`, `validate` | tokenizing, parsing and validating |
| `lint` | the lint run over a fixed set of files, one process; the default rules alone over the lint corpus and over the cases of `data/lint/` |
| `redos` | the theoretical ReDoS analysis |
| `redos-corpus` | the same analysis once per pattern of the lint corpus, ASTs cached (the longest group), and once over the whole corpus in one measurement, for the table |
| `automata` | NFA construction, determinization, minimization and the language solver |
| `optimizer` | the optimizer, with its default options and with every rewrite verified by automata (the PHPStan extension's options) |
| `capture-shape`, `formatter` | reading a capture shape, writing lint reports |
| `table` | the corpus subjects of the groups above, with the noop floor of each class: what CI compares on a pull request (below) |

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

CI runs every subject once, each variant for one revolution, and fails on a
subject that throws or on a run past its time limit: that job is the gate.
The same run, locally:

```bash
tools/phpbench/vendor/bin/phpbench run --iterations=1 --revs=1 --progress=none --stop-on-error
```

A second job measures the `table` group and never gates. On a pull request
that changes `src/` or `tests/Benchmark/`, it measures the base commit
first, then the pull request, on the same runner, and writes the table to
the job summary with the difference per subject; a subject taking 50 ms or
more in the base and more than 30 % longer in the pull request is marked.
The mark advises: the two passes run one after the other on a shared
runner, which still varies between them, and nothing is required of the
author. On a push and on the Monday run the group is measured once and the
numbers are kept as an artifact for ninety days. The same comparison,
locally, from the base commit and then from the change:

```bash
tools/phpbench/vendor/bin/phpbench run --group=table --tag=base
tools/phpbench/vendor/bin/phpbench run --group=table --ref=base --report=table
```

`tests/Unit/Benchmark/BenchmarkDataTest.php` checks every case file: the real
PCRE engine compiles each pattern (or refuses it, for a case marked
`'invalid' => true`), the library parses it, and every growth series stays
under the limits.

### Comparing two revisions on more patterns

A change meant to make the library faster must not change what it says.
Two scripts compare two revisions on as many patterns as you can gather:

```bash
# the distinct patterns of a tree of PHP code: the preg_* calls, or (--literals)
# every string literal that reads as a delimited pattern, the tests' included
php tests/Tools/harvest_patterns.php --out=var/patterns.json path/to/projects
php tests/Tools/harvest_patterns.php --literals --out=var/test-patterns.json tests

# per pattern and per target (the running engine, each PHP version boundary,
# PHP 8.4 on the later PCRE2 releases): tokens, tree, tolerant tree and its
# errors, every field of the validation result
php tests/Tools/behaviour_fingerprint.php --out=var/after.txt fixture var/patterns.json var/test-patterns.json
php tests/Tools/behaviour_fingerprint.php --autoload=../other-checkout/vendor/autoload.php \
    --out=var/before.txt fixture var/patterns.json var/test-patterns.json
diff var/before.txt var/after.txt
```

The harvest reads directories in a fixed order and keeps each pattern once,
where it is first met, so the same code gives the same list on every machine;
`--sample=N --seed=S` draws a reproducible subset. The patterns of
third-party code are not committed: only the corpus fixture is.

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
