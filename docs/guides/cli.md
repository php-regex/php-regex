# CLI Guide

This guide covers RegexParser's command-line tool and the workflows it enables.

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
curl -Ls https://github.com/php-regex/regex-parser/releases/latest/download/regex.phar \
  -o ~/.local/bin/regex
chmod +x ~/.local/bin/regex

# Use it
regex --help
```

> **Note:** Replace `vendor/bin/regex` with `regex` in all examples below if using the PHAR.

---

## Command Overview

RegexParser CLI provides these commands:

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
| `--help`              | Show help                         |

---

## Symfony Bundle Commands

When using the Symfony bundle, you also get these `bin/console` commands:

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
RegexParser 1.0.0 by Younes ENNAJI

Runtime       : PHP 8.2.30
Processes     : 10
Configuration : regex.dist.json

  [1/2] Collecting patterns
  [2/2] Analyzing patterns

  [PASS] No issues found, 0 optimizations available.
  Time: 0.08s | Memory: 10 MB | Cache: 0 hits, 0 misses | Processes: 10

  Found it useful? Consider starring: https://github.com/php-regex/regex-parser
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
  "format": "console",
  "jobs": 4,
  "exclude": ["vendor", "var", "tests"],
  "ide": "phpstorm",
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

### Configuration Options

| Option                          | Type           | Description                                              |
|---------------------------------|----------------|----------------------------------------------------------|
| `format`                        | string         | Output format (console, json, github, checkstyle, junit) |
| `jobs`                          | int            | Number of parallel workers                               |
| `exclude`                       | array          | Paths to exclude                                         |
| `extraction.interop`            | array          | Wrapper libraries whose calls carry patterns (default `["composer-pcre"]`) |
| `extraction.functions`          | array          | Project helpers carrying patterns                        |
| `ide`                           | string         | IDE for clickable links                                  |
| `checks`                        | object         | Enable or configure lint checks (validation, redos, optimizations) |
| `checks.redos`                  | boolean or object | ReDoS analysis toggle or settings (mode, threshold, noJit) |
| `checks.optimizations`          | boolean or object | Optimization suggestions toggle or settings (minSavings, options) |
| `checks.optimizations.options`  | object         | Optimization options (digits/word/ranges/canonicalizeCharClasses/possessive/factorize/minQuantifierCount/verifyWithAutomata) |

Legacy keys (`rules`, `redosMode`, `redosThreshold`, `redosNoJit`, `optimizations`, `minSavings`) are still supported but deprecated.

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
| `--redos`           | Enable ReDoS analysis (disabled by default)        |
| `--no-redos`        | Explicitly disable ReDoS analysis                  |
| `--no-validate`     | Skip validation                                    |
| `--no-optimize`     | Disable optimization suggestions                   |
| `--interop <presets>` | Wrapper libraries to read patterns from (comma separated, `none` to disable) |
| `--no-interop`      | Read patterns from native `preg_*` calls only      |
| `--pattern-function <spec>` | Extra call carrying a pattern (repeatable) |
| `-v, --verbose`     | Detailed output                                    |
| `--debug`           | Debug information                                  |

> **Note:** ReDoS analysis is disabled by default for performance. Enable it with `--redos` or via configuration.

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
