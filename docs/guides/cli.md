# CLI Guide

This guide covers PHPRegex's command-line tool and the workflows it enables.

---

## Quick Start

### Installation

**Via Composer (recommended):**

```bash
# After installing the package
vendor/bin/regex --help
```

**Via PHAR (standalone):**

```bash
# Download the PHAR
curl -Ls https://github.com/php-regex/php-regex/releases/latest/download/regex.phar \
  -o ~/.local/bin/regex
chmod +x ~/.local/bin/regex

# Use it
regex --help
```

> **Note:** Replace `vendor/bin/regex` with `regex` in all examples below if using the PHAR.

---

## Command Overview

PHPRegex CLI provides these commands:

| Command       | Description                                              |
|---------------|----------------------------------------------------------|
| `parse`       | Parse and recompile a pattern                            |
| `analyze`     | Pattern analysis (validation + ReDoS + explanation)      |
| `debug`       | Detailed ReDoS analysis with heatmap                     |
| `diagram`     | Render AST diagram                                       |
| `highlight`   | Syntax highlighting (console or HTML)                    |
| `validate`    | Validate pattern syntax                                  |
| `lint`        | Lint entire codebase for regex issues                    |
| `self-update` | Update PHAR to latest version                            |
| `help`        | Show help message                                        |

### Global Options

| Option                | Description                       |
|-----------------------|-----------------------------------|
| `--ansi`              | Force ANSI colors                 |
| `--no-ansi`           | Disable ANSI colors               |
| `-q, --quiet`         | Suppress output                   |
| `--silent`            | Same as `--quiet`                 |
| `--php-version <ver>` | Target PHP version for validation |
| `--pcre-version <ver>` | Target PCRE2 release for validation, as `10.42` |
| `--help`              | Show help                         |

### Exit Codes

Every command exits with one of three codes:

| Code | Meaning                                                                   |
|------|---------------------------------------------------------------------------|
| `0`  | The command did what it was asked and found nothing wrong                 |
| `1`  | The patterns or the files it judged have a problem                        |
| `2`  | The command line or the configuration cannot be used; nothing was judged |

What each command counts as a problem (code 1):

| Command                                      | Exits with 1 when                                                                  |
|----------------------------------------------|------------------------------------------------------------------------------------|
| `lint`                                       | At least one error is found (warnings alone leave 0), or the files cannot be read |
| `validate`, `parse --validate`, `analyze`    | The pattern is invalid                                                             |
| `parse`, `explain`, `diagram`, `highlight`   | The pattern does not parse                                                         |
| `graph`                                      | The pattern does not parse, or cannot be drawn as an automaton                     |
| `transpile`                                  | The pattern does not parse, or cannot be written for the target                    |
| `debug`                                      | The pattern does not parse                                                         |
| `analyze`, `debug`                           | `--redos-mode=confirmed` confirms a ReDoS risk of high severity or more, at or above `--redos-threshold` |
| `compare`                                    | The answer is no: the patterns intersect, the first is not a subset of the second, or they differ; or they cannot be compared |
| `redos`                                      | PHP refuses to compile the pattern or the `--safe` one; a slow run alone leaves 0 |
| `self-update`                                | The update fails                                                                   |

A theoretical ReDoS finding is a warning, as it is for `lint`: it is printed
and leaves the code at 0.

Code 2 covers an unknown command or option, an option without its value or
with a value the command does not accept (an unknown `--format`, `--target`,
`--method` or `--redos-mode`, an invalid `--php-version` or `--pcre-version`),
a missing pattern, a removed option such as `--redos-no-jit`, an
`--input-file` that cannot be read, an `--output` file that cannot be written,
and a `regex.json` that cannot be read (`lint` and `debug`). `regex` run
without a command prints the help and exits with 2, as `regex help` with an
unknown command does.

Options may come before or after the pattern; `--` ends them, so that what
follows is read as the pattern even when it starts with `-`.

---

## Symfony Bundle Commands

When using the Symfony bundle, you also get these `bin/console` commands
(configuration and target: [the Symfony guide](symfony.md); the Laravel
commands: [the Laravel guide](laravel.md)):

| Command                 | Description                                           |
|-------------------------|-------------------------------------------------------|
| `regex:lint`            | Lint regex patterns in your PHP code                  |
| `regex:compare`         | Compare two regex patterns via automata               |
| `regex:routes`          | Detect route conflicts and overlaps in your router    |
| `regex:security`        | Analyze access control ordering and firewall regexes  |
| `regex:analyze`         | Run Symfony bridge analyzers (routes + security)      |

Examples:

```bash
bin/console regex:routes
bin/console regex:routes --show-overlaps
bin/console regex:security
bin/console regex:security --show-overlaps
bin/console regex:analyze
bin/console regex:analyze --only=routes
bin/console regex:analyze --fail-on=any --format=json
```

These commands, like the Laravel ones, exit with the same codes as the
binary: `Command::INVALID` (2) for an option or a configuration they cannot
use, `Command::FAILURE` (1) for what they found, such as a route conflict or
an invalid pattern.

---

## Command Examples

### 1. Parse a Pattern

Parse and show the recompiled pattern:

```bash
# Basic parse
vendor/bin/regex parse '/^[a-z]+@[a-z]+\.[a-z]+$/i'

# Parse with validation
vendor/bin/regex parse '/^hello/' --validate
```

**Output:**
```
Pattern:    /^hello/
Recompiled: /^hello/
```

---

### 2. Analyze a Pattern

Detailed analysis including validation, ReDoS risk, and explanation:

```bash
# Analyze email pattern
vendor/bin/regex analyze '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i'
```

**Output:**
```
Analyze
  Pattern:    /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i
  Parse:      Validation: ReDoS:      SAFE (score 0)

Explanation
Start of string
  One or more characters from: a-z, 0-9, ., _, %, +, -
  Literal '@'
  One or more characters from: a-z, 0-9, ., -
  Literal '.'
  Two or more characters from: a-z
End of string (case-insensitive)
```

---

### 3. Debug (Deep ReDoS Analysis)

Show detailed ReDoS analysis with heatmap:

```bash
# Analyze dangerous pattern
vendor/bin/regex debug '/(a+)+$/'
```

**Output:**
```
Debug
  Pattern:    /(a+)+$/
  ReDoS:      CRITICAL (score 10)
  Culprit:    a+
  Trigger:    quantifier +
  Hotspots:   2
  Input:      "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!" (auto)

Heatmap:
  aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!
  ^^

Findings
  - [CRITICAL] Nested unbounded quantifiers detected.
    Pattern: /(a+)+$/
    This allows exponential backtracking.
    Suggested: Replace inner quantifiers with possessive variants or wrap in atomic groups.
```

---

### 4. Diagram (AST Visualization)

Render text diagram of pattern structure (default):

```bash
vendor/bin/regex diagram '/^[a-z]+@[a-z]+\.[a-z]+$/i'
```

Render SVG (prints XML to stdout):

```bash
vendor/bin/regex diagram '/^[a-z]+@[a-z]+\.[a-z]+$/i' --format=svg
```

Write SVG to a file:

```bash
vendor/bin/regex diagram '/^[a-z]+@[a-z]+\.[a-z]+$/i' --format=svg --output=graph.svg
```

**Output:**
```
Regex (flags: i)
\-- Sequence
    |-- Anchor (^)
    |-- Quantifier (+, greedy)
    |   \-- CharClass
    |       \-- Range
    |           |-- Literal ('a')
    |           \-- Literal ('z')
    |-- Literal ('@')
    |-- Quantifier (+, greedy)
    |   \-- CharClass
    |       \-- Range
    |           |-- Literal ('a')
    |           \-- Literal ('z')
    |-- Literal ('.')
    |-- Quantifier (+, greedy)
    |   \-- CharClass
    |       \-- Range
    |           |-- Literal ('a')
    |           \-- Literal ('z')
    \-- Anchor ($)
```

---

### 5. Highlight (Syntax Coloring)

**Console output:**

```bash
vendor/bin/regex highlight '/^[a-z]+@[a-z]+\.[a-z]+$/i'
```

**HTML output:**

```bash
vendor/bin/regex highlight '/^hello$/' --format=html
```

**Output (HTML):**
```html
<span class="regex-token regex-anchor">^</span><span class="regex-token regex-literal">hello</span><span class="regex-token regex-anchor">$</span>
```

---

### 6. Validate Pattern

Check pattern syntax:

```bash
# Valid pattern
vendor/bin/regex validate '/^[a-z]+$/'

# Invalid pattern (unbounded lookbehind)
vendor/bin/regex validate '/(?<=a+)b/'
```

**Valid Output:**
```
/^[a-z]+$/
```

**Invalid Output:**
```
INVALID  /(?<=a+)b/
  Variable-length lookbehind is not supported in PCRE.
Line 1: (?<=a+)b
            ^
```

---

### 7. Lint Your Codebase

Scan PHP files for regex patterns and issues:

```bash
# Lint src directory
vendor/bin/regex lint src/

# Lint with verbose output
vendor/bin/regex lint src/ -v

# Lint with JSON output (CI/CD)
vendor/bin/regex lint src/ --format=json

# Lint with GitHub Actions format
vendor/bin/regex lint src/ --format=github

# Exclude directories
vendor/bin/regex lint src/ --exclude=vendor --exclude=tests
```

#### Patterns Behind a Wrapper

Not every pattern reaches PCRE through `preg_match()`. Codebases that route
them through composer/pcre, nette/utils or a helper of their own would
otherwise be reported as having no patterns at all.

`composer/pcre` is recognised out of the box; the other libraries are opt-in:

```bash
# Add nette/utils on top of the default composer/pcre support
vendor/bin/regex lint src/ --interop=composer-pcre,nette-utils

# Native preg_* calls only
vendor/bin/regex lint src/ --no-interop
```

| Preset          | Recognised calls                                                       |
|-----------------|------------------------------------------------------------------------|
| `composer-pcre` | `Composer\Pcre\Preg` and `Composer\Pcre\Regex` (enabled by default) |
| `nette-utils`   | `Nette\Utils\Strings::match`, `matchAll`, `split`, `replace`          |
| `spatie-regex`  | `Spatie\Regex\Regex::match`, `matchAll`, `replace`                    |
| `laravel-str`   | `Illuminate\Support\Str::match`, `matchAll`, `isMatch`, `replaceMatches` |

Wrappers are matched on their fully qualified name, using the file's `use`
statements: a class of your own named `Preg` is left alone.

A project's own helpers are declared as `function` or `Some\Class::method`.
Append `#<index>` when the pattern is not the first argument, and
`#<index>:keys` when that argument is an array whose keys hold the patterns:

```bash
vendor/bin/regex lint src/ --pattern-function='App\Support\Str::matches#1'
```

```json
{
  "extraction": {
    "interop": ["composer-pcre", "nette-utils"],
    "functions": ["App\\Support\\Str::matches#1"]
  }
}
```

Functions republished under another namespace with the same signature — as
`thecodingmachine/safe` does with `Safe\preg_match()` — are recognised
without any configuration.

**Console Output:**
```
PHPRegex 1.0.0 by Younes ENNAJI

Runtime       : PHP 8.2.30
Processes     : 10
Configuration : regex.dist.json

  [1/2] Collecting patterns
  [2/2] Analyzing patterns

  [PASS] No issues found, 0 optimizations available.
  Time: 0.08s | Memory: 10 MB | Cache: 0 hits, 0 misses | Processes: 10

  Found it useful? Consider starring: https://github.com/php-regex/php-regex
```

**With Issues:**
```
  [1/2] Collecting patterns
  [2/2] Analyzing patterns

  [1/1] src/Example.php:42

  INVALID  /(?<=a+)b/
    Variable-length lookbehind is not supported in PCRE.
    Line 1: (?<=a+)b
                ^

  [CRITICAL] src/Example.php:43
  /(a+)+$/ (ReDoS)
    Nested unbounded quantifiers detected.
```

---

## Configuration File

Create `regex.json` or `regex.dist.json` in your project root:

```json
{
  "$schema": "./vendor/php-regex/regex-linter/regex.schema.json",
  "format": "console",
  "jobs": 4,
  "exclude": ["vendor", "var", "tests"],
  "ide": "phpstorm",
  "phpVersion": "8.2",
  "checks": {
    "validation": true,
    "redos": {
      "enabled": true,
      "mode": "theoretical",
      "threshold": "high"
    },
    "optimizations": {
      "minSavings": 2,
      "options": {
        "digits": true,
        "word": true,
        "ranges": true,
        "canonicalizeCharClasses": true,
        "minQuantifierCount": 4,
        "verifyWithAutomata": true
      }
    }
  }
}
```

`regex.dist.json` is the file you commit; `regex.json` is read after it and
wins. Keys merge the way you would expect from a settings file: an object is
merged key by key, and anything else, a list included, is replaced whole. So
`{"exclude": ["build"]}` in `regex.json` replaces the whole `exclude` list of
`regex.dist.json`, and `{"exclude": []}` clears it.

### Configuration Options

| Option                          | Type             | Description                                              |
|---------------------------------|------------------|----------------------------------------------------------|
| `paths`                         | array or string  | Paths to scan (default: the working directory)           |
| `exclude`                       | array or string  | Paths to exclude (default: `vendor`)                     |
| `format`                        | string           | Output format (console, json, github, checkstyle, junit) |
| `jobs`                          | int              | Number of parallel workers (at least 1)                  |
| `ide`                           | string           | IDE for clickable links                                  |
| `phpVersion`                    | string or int    | PHP version the patterns are judged for: `"8.3"`, `80300`, or `"runtime"` for the PHP running the command |
| `pcreVersion`                   | string           | PCRE2 release the patterns are judged for: `"10.44"`     |
| `extraction.interop`            | array            | Wrapper libraries whose calls carry patterns (default `["composer-pcre"]`) |
| `extraction.functions`          | array            | Project helpers carrying patterns                        |
| `checks.validation`             | boolean          | Report patterns PCRE2 refuses (default `true`)           |
| `checks.redos`                  | object           | `enabled` (default `false`), `mode` (`theoretical` or `confirmed`), `threshold` (`low`, `medium`, `high`, `critical`) |
| `checks.optimizations`          | object           | `enabled` (default `true`), `minSavings`, `options`      |
| `checks.optimizations.options`  | object           | digits, word, ranges, canonicalizeCharClasses, possessive, factorize, minQuantifierCount, verifyWithAutomata |
| `checks.lint`                   | object           | `enabled` (default `true`) and `rules`, a map of rule id to `true` or `false` |

`checks.redos`, `checks.optimizations` and `checks.lint` are objects, and only
their `enabled` key switches a check on or off: setting `threshold`, `mode`,
`minSavings`, `options` or `rules` alone never enables it. `mode` and
`threshold` are read whatever their case.

The lint command refuses a file it cannot fully use. Every unknown key, every
unknown lint rule id and every value of the wrong kind is an error, and the
command lists all of them at once, each with the key it is about, then exits
with code 2 before scanning anything.

### Removed Keys

These 1.x keys are refused, with the message naming what replaced them:

| 1.x key                 | Use instead                                                  |
|-------------------------|--------------------------------------------------------------|
| `rules`                 | `checks` (`rules.optimization` is `checks.optimizations.enabled`) |
| `redosMode`             | `checks.redos.mode`                                          |
| `redosThreshold`        | `checks.redos.threshold`                                     |
| `redosNoJit`            | nothing: the ReDoS confirmation always runs without JIT      |
| `optimizations`         | `checks.optimizations.options`                               |
| `minSavings`            | `checks.optimizations.minSavings`                            |
| `checks.redos.noJit`    | nothing: the ReDoS confirmation always runs without JIT      |
| `checks.redos.mode: "off"` | `checks.redos.enabled: false`                             |
| `checks.redos: true` (and the other boolean forms) | `checks.redos: {"enabled": true}`  |

### Schema

`regex.schema.json`, shipped by `php-regex/regex-linter`, describes every key, so an
editor can complete and check the file: point `$schema` at it, as in the
example above. The lint command validates against the same definition, so
the editor and the command accept exactly the same files; the command is
only more lenient about the case of `mode` and `threshold`.

The file is generated. When a key changes, it is written again from the
definition with:

```bash
php tests/Tools/write_config_schema.php
```

### Target PHP and PCRE2

Whether a pattern compiles, and how it behaves, depends on the PCRE2 release
and, for a few rules, on the PHP version. The lint command judges every
pattern for one target, chosen in this order:

1. `--php-version` and `--pcre-version` on the command line;
2. `phpVersion` and `pcreVersion` in `regex.json`;
3. `composer.json` in the working directory: `config.platform.php` if set,
   else the lowest version `require.php` allows (`^8.2 || ^8.3` is PHP 8.2).
   The `COMPOSER` environment variable names another file, as it does for
   Composer;
4. the PHP running the command.

`runtime`, given to `--php-version` or as `phpVersion`, names the PHP running
the command and the PCRE2 it links, as PHPStan's `phpVersion` does: a project
that lints for its floor can still ask what the engine at hand says.

Each version is chosen on its own. Without a PCRE2 release, the command uses
the one the target PHP bundles (10.40 for PHP 8.2, 10.42 for 8.3, 10.44 for
8.4 and 8.5), or the PCRE2 of the running PHP when the target is the running
PHP.

The lowest PHP is the one that matters because a library, or an application
deployed on several servers, runs on every version its constraint allows: a
pattern that only compiles on a newer PCRE2 fails on the oldest one. A floor
below PHP 8.2 is judged as PHP 8.2, the oldest this library supports, and the
command says so. A constraint the command cannot read (`*`, `<9`, a branch
name) falls back to the running PHP, with a note naming it; reading
`composer.json` never stops a run.

The console, GitHub, Checkstyle and JUnit formats print the target on stderr,
so that the report on stdout stays unchanged:

```text
Target: PHP 8.2, PCRE2 10.40 (composer.json require.php)
```

The JSON report carries it as its `target` key (see [JSON](#json) below).
Single-pattern commands, such as `analyze` or `validate`, judge for the
running PHP unless `--php-version` or `--pcre-version` is given.

### Errors and Exit Codes

`lint` exits with the codes every command uses (see
[Exit Codes](#exit-codes)): 1 when at least one error is found, 2 when the
configuration or the command line cannot be used, in which case nothing is
scanned.

With `--format=json`, a configuration or command-line error is printed on
stdout as `{"error": "..."}`, so that stdout always holds one JSON document;
with the other formats it is printed on stderr.

### IDE Integration

Enable clickable file links in lint output:

```json
{
  "ide": "phpstorm"
}
```

**Supported IDEs:**
- `"phpstorm"` - phpstorm://open?file=%f&line=%l
- `"vscode"` - vscode://file/%f:%l
- `"textmate"` - txmt://open?url=file://%f&line=%l
- `"sublime"` - subl://open?url=file://%f&line=%l
- `"emacs"` - emacs://open?url=file://%f&line=%l
- `"atom"` - atom://core/open/file?filename=%f&line=%l
- `"macvim"` - mvim://open?url=file://%f&line=%l
- `""` - Disable clickable links

---

## Ignoring Patterns

### Inline Comments

```php
preg_match('/pattern/', $input); // @regex-ignore-next-line
```

### Config Exclude

In `regex.json`:
```json
{
  "exclude": ["src/Legacy", "src/Deprecated"]
}
```

---

## Output Formats

### Console (Default)

Human-readable colored output for terminal.

### JSON

```bash
vendor/bin/regex lint src/ --format=json
```

**Output:**
```json
{
  "target": {
    "php": "8.2",
    "pcre": "10.40",
    "source": "composer.json require.php"
  },
  "stats": {
    "errors": 1,
    "warnings": 0,
    "optimizations": 0
  },
  "results": [
    {
      "file": "src/Example.php",
      "line": 42,
      "pattern": "/(?<=a+)b/",
      "issues": [
        {
          "type": "validation",
          "severity": "error",
          "message": "Variable-length lookbehind is not supported"
        }
      ]
    }
  ]
}
```

### GitHub Actions

```bash
vendor/bin/regex lint src/ --format=github
```

**Output:**
```
::error file=src/Example.php,line=42::Variable-length lookbehind is not supported
```

### Checkstyle (for CI)

```bash
vendor/bin/regex lint src/ --format=checkstyle --output=checkstyle.xml
```

### JUnit

```bash
vendor/bin/regex lint src/ --format=junit --output=junit.xml
```

---

## Lint Options

| Option              | Description                                        |
|---------------------|----------------------------------------------------|
| `--exclude <path>`  | Exclude path (repeatable)                          |
| `--min-savings <n>` | Minimum optimization savings                       |
| `--jobs <n>`        | Parallel workers                                   |
| `--format <format>` | Output format (console, json, github, checkstyle, junit) |
| `--output <file>`   | Also write the report to a file                    |
| `--redos`           | Enable ReDoS analysis (disabled by default)        |
| `--no-redos`        | Explicitly disable ReDoS analysis                  |
| `--redos-mode <mode>` | `theoretical` or `confirmed`                     |
| `--redos-threshold <sev>` | Lowest severity reported: low, medium, high, critical |
| `--no-validate`     | Skip validation                                    |
| `--no-optimize`     | Disable optimization suggestions                   |
| `--interop <presets>` | Wrapper libraries to read patterns from (comma separated, `none` to disable) |
| `--no-interop`      | Read patterns from native `preg_*` calls only      |
| `--pattern-function <spec>` | Extra call carrying a pattern (repeatable) |
| `-v, --verbose`     | Detailed output                                    |
| `--debug`           | Debug information                                  |

> **Note:** ReDoS analysis is disabled by default for performance. Enable it with `--redos` or via configuration.

`--redos-mode=off` and `--redos-no-jit` were removed from `lint` in 2.0: use
`--no-redos` to skip the analysis; the confirmation always runs without JIT.
Either one is now a usage error (exit code 2), as is an unknown `--format`.
`analyze` and `debug` refuse `--redos-no-jit` too, for the same reason.

---

## CI/CD Integration

### GitHub Actions

```yaml
name: regex-lint
on: [pull_request]

jobs:
  regex:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --no-interaction --no-progress
      - run: vendor/bin/regex lint src/ --format=github
```

### GitLab CI

```yaml
regex-lint:
  image: php:8.2
  script:
    - composer install
    - vendor/bin/regex lint src/ --format=json > report.json
  artifacts:
    reports:
      json: report.json
```

### Jenkins

```bash
vendor/bin/regex lint src/ --format=checkstyle --output=regex-checkstyle.xml
```

---

## Tips and Tricks

### Quick Pattern Test

```bash
# Test a pattern inline
vendor/bin/regex explain '/^[a-z]+$/'

# Test multiple patterns
for pattern in '/^test$/' '/^hello$/i' '/\d+/'; do
  echo "Pattern: $pattern"
  vendor/bin/regex validate "$pattern"
done
```

### Debug ReDoS Issues

```bash
# Find all ReDoS issues in your code
vendor/bin/regex lint src/ --no-validate --no-optimize

# Get detailed analysis
vendor/bin/regex debug '/your-pattern/'
```

### Generate HTML for Documentation

```bash
vendor/bin/regex highlight '/^your-pattern$/' --format=html
```

---

## Common Issues

### "Unknown command"

Make sure you're using the correct command name:
```bash
# Wrong
vendor/bin/regex explain '/test/'

# Correct
vendor/bin/regex analyze '/test/'
```

### "Pattern not found"

The CLI expects a pattern in a specific format:
```bash
# Wrong (missing delimiters)
vendor/bin/regex validate 'test'

# Correct
vendor/bin/regex validate '/test/'
vendor/bin/regex validate '#test#'
```

### Colors Not Showing

Force ANSI output:
```bash
vendor/bin/regex highlight '/test/' --ansi
```

---

## Learn More

- **[LSP Integration](lsp.md)** - IDE integration via Language Server Protocol
- **[Regex Tutorial](../tutorial/README.md)** - Learn regex from scratch
- **[Regex in PHP](regex-in-php.md)** - PHP regex fundamentals
- **[ReDoS Guide](../REDOS_GUIDE.md)** - Preventing catastrophic backtracking
- **[Cookbook](../COOKBOOK.md)** - Ready-to-use patterns

---

## Self-Update (PHAR Only)

If using the PHAR, update to the latest version:

```bash
regex self-update
```

---

End of CLI guide.

---

Previous: [Regex in PHP](regex-in-php.md) | Next: [Diagnostics](../reference/diagnostics.md)
