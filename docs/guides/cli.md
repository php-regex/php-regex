---
description: "Install the PHPRegex CLI and drive it end to end: parse, explain, analyze and lint patterns, with every option, exit code, output format and CI recipe."
---

# CLI Guide

This guide covers PHPRegex's command-line tool and the workflows it enables.
The binary requires PHP 8.1 or later.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the CLI guide</label>
  <input type="search" id="page-filter-input" data-unit="commands" placeholder="Filter by command, flag or output — e.g. lint, explain, diagram, json" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status">Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

{% include install-prerelease.html package="php-regex/regex-cli" dev=true %}

Once installed, the binary is `vendor/bin/regex`:

```bash
vendor/bin/regex --help
```

The overview lists the commands and the options they all take;
`vendor/bin/regex help <command>` shows the options, notes and examples of one
command.

**Via PHAR (standalone):**

The PHAR needs no PHP project and no Composer:

```bash
# Download the PHAR
curl -Ls https://github.com/php-regex/php-regex/releases/latest/download/regex.phar \
  -o ~/.local/bin/regex
chmod +x ~/.local/bin/regex

# Use it
regex --help
```
{: data-copy="" }

Every release's PHAR is built from its tag by a GitHub Actions workflow, which
attests where it was built. With the [GitHub CLI](https://cli.github.com),
check that the file you downloaded is that build:

```bash
gh attestation verify ~/.local/bin/regex --repo php-regex/php-regex
```
{: data-copy="" }

The release also carries `regex.phar.sha256`, which `regex self-update`
checks before it replaces the PHAR.

> **Note:** Replace `vendor/bin/regex` with `regex` in all examples below if using the PHAR.

---

## Command Overview

PHPRegex CLI provides these commands:

| Command       | Description                                              |
|---------------|----------------------------------------------------------|
| `parse`       | Parse and recompile a pattern                            |
| `analyze`     | Pattern analysis (validation + ReDoS + explanation)      |
| `compare`     | Compare two regex patterns using automata logic          |
| `explain`     | Explain a regex pattern in plain language                |
| `debug`       | Detailed ReDoS analysis with heatmap                     |
| `redos`       | Benchmark regex patterns for ReDoS behavior              |
| `diagram`     | Render AST diagram                                       |
| `graph`       | Generate a graph diagram (DOT/Mermaid) of the NFA        |
| `highlight`   | Syntax highlighting (console or HTML)                    |
| `validate`    | Validate pattern syntax                                  |
| `transpile`   | Transpile a PCRE regex to JavaScript (`js`), an HTML `pattern` attribute (`html`) or Python (`py`) |
| `format`      | Lay a pattern out over lines under `x`, or `--compact` it to one line |
| `lint`        | Lint entire codebase for regex issues                    |
| `version`     | Display version information                              |
| `self-update` | Update PHAR to latest version                            |
| `help`        | Show help message                                        |

### Global Options

| Option                | Description                       |
|-----------------------|-----------------------------------|
| `--ansi`              | Force ANSI colors                 |
| `--no-ansi`           | Disable ANSI colors               |
| `-q, --quiet`         | Suppress output; a JSON, GitHub, Checkstyle or JUnit report is still printed |
| `--silent`            | Same as `--quiet`                 |
| `--php-version <ver>` | Target PHP version for validation |
| `--pcre-version <ver>` | Target PCRE2 release for validation, as `10.42` |
| `--no-visuals`         | Disable banner and section visuals (aliases: `--no-art`, `--no-splash`) |
| `--help`              | Show help                         |

Options may come before or after the pattern; `--` ends them, so that what
follows is read as the pattern even when it starts with `-`.

`parse`, `validate` and `compare` print one rendering and take no
`--format`: giving them one is a usage error. The other commands take it and,
but for `graph`, call the terminal rendering `console`; the other values
depend on the command: `json` for `analyze`, `debug`, `redos`, `transpile` and
`lint` (with `github`, `checkstyle` and `junit` for `lint`), `html` for
`explain` and `highlight` (`auto`, the default of `highlight`, picks `console`
in a terminal), `svg` for `diagram`, and `dot` (the default) or `mermaid` for
`graph`. An unknown value is a usage error that names the values the command
takes.

### Exit Codes

Every command exits with one of three codes:

| Code | Meaning                                                                   |
|------|---------------------------------------------------------------------------|
| `0`  | The command did what it was asked and found nothing wrong                 |
| `1`  | The patterns or the files it judged have a problem                        |
| `2`  | The command line or the configuration cannot be used; nothing was judged |

What each command counts as a problem, and everything code 2 covers, is
detailed in [Errors and Exit Codes](#errors-and-exit-codes).

---

## Command Examples

### 1. Parse a Pattern

Parse and show the recompiled pattern:

```bash
# Basic parse
vendor/bin/regex parse '/^[a-z]+@[a-z]+\.[a-z]+$/i'

# Parse with validation
vendor/bin/regex parse '/^hello/' --validate --no-visuals
```

**Output:**
```
  Pattern: /^hello/
  Parse: OK
  Recompiled: /^hello/
  Status: OK
```

---

### 2. Analyze a Pattern

Detailed analysis including validation, ReDoS verdict, and explanation:

```bash
# Analyze email pattern
vendor/bin/regex analyze '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i'
```

**Output:**
```
  [1/4] Parsing pattern
  Pattern
      → /^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i
  Parse : OK

  [2/4] Validation
  Status : OK

  [3/4] ReDoS analysis
  Status     : safe (proven)
  Severity   : SAFE (score 0)
  Mode       : THEORETICAL
  Confidence : HIGH

  [4/4] Explanation
Regex matches (with flags: i)
  Anchor: the beginning of a line
    Character Class: any character in [   Range: from 'a' to 'z',   Range: from '0' to '9',   '.',   '_',   '%',   '+',   '-' ] (one or more times)
  '@'
  ...
```

`Status` is the verdict's headline: `safe (proven)`, `Exponential
backtracking (proven)`, `Polynomial backtracking, degree N (proven)`,
`Potential backtracking (heuristic)`, `no risk found (heuristic)`, `not
analyzed (budget exceeded)` or `not analyzed (analysis error)` (see
[the ReDoS guide](redos.md#what-the-verdict-means)). Up to three lines
follow it:

- `Model:` what the analysis read differently from the pattern as written, as
  a bounded repeat above 16 analysed as unbounded; the proof is about that
  model;
- `Note: analysis budget exceeded, the heuristics decided`;
- `Attack:` the input that drives the worst case, as PHP: `"a" x n . "!"` is
  `str_repeat("a", $n) . "!"`.

```bash
vendor/bin/regex analyze '/(a{1,20})+$/'
```

```
  [3/4] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : THEORETICAL
  Confidence : MEDIUM
  Model: {1,20} at offset 1 analysed as {1,}
  Attack: "a" x n . "!"
  Hotspot:   0-10
```

`--redos-mode=confirmed` replays an exponential attack on the running PCRE,
without the JIT, and says at which length `preg_match()` gives up:

```bash
vendor/bin/regex analyze '/(a+)+$/' --redos-mode=confirmed   # exits 1
```

```
  [3/5] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : CONFIRMED
  Confidence : HIGH
  Attack: "a" x n . "!"
  Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).
  Hotspot:   1-3

  [4/5] Confirmation
  Status:    CONFIRMED
  Evidence:  backtrack_limit
  Samples:   len=17 avg=1.25ms
  JIT:       0
  Backtrack: 100000
  Recursion: 10000
```

The confirmation adds a step, so the steps are numbered out of five. The
evidence line names the limit PCRE hit and the JIT setting it ran under; only
an exhausted backtrack limit counts as reproduced. When the engine does not
fail, the line reads `Not reproduced on PCRE2 10.49 (PCRE's optimisations
defuse it).`, the confidence stays `MEDIUM`, and the command exits with 0. A
polynomial verdict is never replayed.

`--format=json` prints the same verdict under `redos`, with `complexity`,
`degree`, `proof`, `witness`, `replayed`, `abstractions`, `pcre_version`,
`php_version` and `analysis_version` next to `severity`, `score` and the other keys (every key is in the
[JSON output reference](../reference/json-output.md#redos-analysis-redos_analysis)):

```bash
vendor/bin/regex analyze '/(a+)+$/' --format=json
```

```json
"redos": {
    "severity": "critical",
    "score": 10,
    "mode": "theoretical",
    "confirmed": false,
    "confidence": "medium",
    ...
    "confirmation": null,
    "complexity": "exponential",
    "degree": null,
    "proof": "proven",
    "witness": {
        "prefix": "",
        "pump": "a",
        "suffix": "!"
    },
    "replayed": null,
    "abstractions": [],
    "pcre_version": "10.49",
    "php_version": "8.4.26",
    "analysis_version": "1"
},
```

---

### 3. Debug (Deep ReDoS Analysis)

Show detailed ReDoS analysis with heatmap. `debug` reads the ReDoS mode and
threshold of `regex.json` when there is one; the options win:

```bash
# Analyze dangerous pattern
vendor/bin/regex debug '/(a+)+$/'
```

**Output:**
```
  [1/2] Heatmap
  Pattern:    /(a+)+$/
                ^^
  Status:    Exponential backtracking (proven)
  Severity:  CRITICAL (score 10)
  Mode:      THEORETICAL
  Confidence: MEDIUM
  Culprit:    a+
  Trigger:    quantifier +
  Hotspots:   2
  Attack: "a" x n . "!"
  Input:      "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!" (auto)

  [2/2] Findings
  - [MEDIUM] Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...).
      Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.
  - [CRITICAL] Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).
      Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).
```

The carets under the pattern mark the hotspot. A pattern proven safe, as
`/a+/`, gets no carets, no `Hotspots:`, `Culprit:` or `Trigger:` line and no
generated `Input:` (one given with `--input` is still shown), and its findings
are listed under `Heuristic hints`: the proof overrides them. With
`--redos-mode=confirmed`, the `Replayed on …` or `Not reproduced on …` line
follows the attack, a `[2/3] Confirmation` step follows the heatmap, and the
exit code follows the same rule as `analyze`.

---

### 4. Diagram (AST Visualization)

Render a text diagram of the pattern structure (`--format=console`, the default):

```bash
vendor/bin/regex diagram '/^[a-z]+@[a-z]+\.[a-z]+$/i' --no-visuals
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

Render SVG (prints XML to stdout):

```bash
vendor/bin/regex diagram '/^[a-z]+@[a-z]+\.[a-z]+$/i' --format=svg
```

Write SVG to a file:

```bash
vendor/bin/regex diagram '/^[a-z]+@[a-z]+\.[a-z]+$/i' --format=svg --output=graph.svg
```

Nothing is printed: the SVG goes to the file.

---

### 5. Highlight (Syntax Coloring)

**Console output:**

```bash
vendor/bin/regex highlight '/^[a-z]+@[a-z]+\.[a-z]+$/i'
```

**HTML output:**

```bash
vendor/bin/regex highlight '/^hello$/' --format=html --no-visuals
```

**Output (HTML):**
```html
<span class="regex-token regex-anchor">^</span><span class="regex-token regex-literal">h</span><span class="regex-token regex-literal">e</span><span class="regex-token regex-literal">l</span><span class="regex-token regex-literal">l</span><span class="regex-token regex-literal">o</span><span class="regex-token regex-anchor">$</span>
```

---

### 6. Validate Pattern

Check pattern syntax:

```bash
# Valid pattern
vendor/bin/regex validate '/^[a-z]+$/' --no-visuals

# Invalid pattern (unbounded lookbehind)
vendor/bin/regex validate '/(?<=a+)b/' --no-visuals
```

**Valid Output:**
```
  Pattern: /^[a-z]+$/
  Status: OK
```

**Invalid Output:**
```
  Pattern: /(?<=a+)b/
  Status: INVALID
  Lookbehind is unbounded. PCRE requires a bounded maximum length.
Line 1: (?<=a+)b
        ^
```

**Not analyzed** (a pattern past one of the library's own limits, which PCRE
may compile: no verdict, exit code 1):
```
  Status: NOT ANALYZED
  Regex pattern exceeds maximum length of 100000 characters.
  A limit of this library, not of PCRE: raise the "max_pattern_length" option to analyze the pattern.
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
them through composer/pcre, nette/utils, an assertion library or a helper of
their own would otherwise be reported as having fewer patterns than they hold,
or none at all.

Besides the `preg_*` functions, the lint always reads the other native calls
that compile a delimited pattern: `new RegexIterator($iterator, $pattern)` and
`new RecursiveRegexIterator(...)` (the pattern second), and `filter_var()` or
`filter_input()` with `FILTER_VALIDATE_REGEXP`, whose `regexp` option is read
when the filter is that constant and the options are an array written in the
call.

The libraries are presets, named after their Composer package. Every preset is
read by default:

| Preset               | Recognised calls                                                                                                          |
|----------------------|---------------------------------------------------------------------------------------------------------------------------|
| `composer-pcre`      | the static methods of `Composer\Pcre\Preg` and `Composer\Pcre\Regex`                                                     |
| `nette-utils`        | `Nette\Utils\Strings::match`, `matchAll`, `split`, `replace` (the pattern second; a `utf8` of `true`, by name or in its place, adds `u`)  |
| `spatie-regex`       | `Spatie\Regex\Regex::match`, `matchAll`, `replace`                                                                        |
| `illuminate-support` | `Illuminate\Support\Str::match`, `matchAll`, `isMatch`, `replaceMatches`                                                  |
| `phpunit`            | `assertMatchesRegularExpression`, `assertDoesNotMatchRegularExpression`, `matchesRegularExpression`, `expectOutputRegex`, `expectExceptionMessageMatches`, `expectUserDeprecationMessageMatches`, and PHPUnit 9's `assertRegExp`, `assertNotRegExp` |
| `webmozart-assert`   | `Webmozart\Assert\Assert::regex`, `notRegex`, and their `nullOr` and `all` forms (the pattern second)                       |
| `beberlei-assert`    | `Assert\Assertion::regex`, `notRegex`, and their `nullOr` and `all` forms (the pattern second)                              |
| `symfony-validator`  | `new Regex(...)` and `#[Regex(...)]` of `Symfony\Component\Validator\Constraints`: `pattern`, never `htmlPattern`         |

```bash
# Every preset, the default
vendor/bin/regex lint src/

# Exactly these presets
vendor/bin/regex lint src/ --interop=composer-pcre,phpunit

# Native calls only (preg_*, RegexIterator, filter_var())
vendor/bin/regex lint src/ --no-interop
```

A list, on the command line or in `extraction.interop`, means exactly that
list: a preset a later release adds stays off for a project that lists its
presets, and there is no way to say "every preset but one" other than listing
the others. `[]` (or `--no-interop`) reads the native calls only.

A name that is no preset is a configuration error, exit code 2, that names the
preset it most likely means: left out, it would leave a list that reads fewer
calls than meant, the native ones only when it was the only one.

```
Error: Unknown extraction preset "laravel-str" in --interop: did you mean "illuminate-support"?
Error: Invalid "extraction.interop" in /app/regex.json: unknown preset "laravel-str"; did you mean "illuminate-support"?
```

`regex debug` reads `regex.json` too, and stops on the same error.

Wrappers are matched on their fully qualified name, using the file's `use`
statements: a class of your own named `Preg` is left alone, and so is a
subclass of an assertion class (`class Assert extends \Webmozart\Assert\Assert`
called as `App\Assert::regex()`). A class is never looked up in the global
namespace as a fallback: in `namespace App`, `new RegexIterator(...)` without a
`use` or a leading `\` is not read, as PHP would not find the class either.
PHPUnit's methods are the exception: their names are PHPUnit's own, so they
are read by name, on `$this->`, `self::`, `static::` and `parent::`, on
`PHPUnit\Framework\Assert::` and `PHPUnit\Framework\TestCase::`, and as the
functions of `PHPUnit\Framework`, in any class, a test case or not. A named
argument is read under the name the library gives the parameter, case
included, as PHP compares it: `expectOutputRegex(expectedRegex: '/.../')`,
`Assert::regex(pattern: '/.../', value: $v)`; `preg_match(PATTERN: '/.../')`
is not read. nette/utils' `utf8` is read by name or in its place (sixth for
`match()` and `split()`, seventh for `matchAll()` and `replace()`): `true`
adds `u`, `false` adds nothing, and anything else, a spread included, leaves
the pattern's flags unknown, so the pattern is not read.

##### Patterns held in a constant, a variable or a property

A pattern argument that names a value the same file proves is read:

```php
final class Slug
{
    private const PATTERN = '/^[a-z0-9-]+$/i';

    public function isValid(string $slug): bool
    {
        return 1 === preg_match(self::PATTERN, $slug);
    }

    public function split(string $text): array
    {
        $separator = '/(\s+)+$/';

        return preg_split($separator, $text);
    }
}
```

- **Constants**: `self::X`, and `ClassName::X` of a class declared in the
  same file, its name resolved through the `use` imports; `static::X` only when
  the class or the constant is `final`, as a subclass may redeclare it; a
  namespace `const X`, or a `define('X', value)` with a literal name and these
  two arguments only (a `define()` written otherwise, with named arguments, a
  third argument or a name that is no quoted literal, is not read); an
  unqualified name tried in the namespace, then globally. A constant built
  from other constants (`'/'.self::PART.'/'`) is folded, up to 32 constants
  deep; a cycle reads nothing, and neither does a value folded past 64 KiB. A
  constant array feeds `preg_replace()` its values and
  `preg_replace_callback_array()` its keys, and Symfony's `Regex` its
  `pattern` option alone. A `define()` or a global `const` of a name PHP
  already defines, `PHP_EOL` say, is not read: PHP keeps its own value.
- **Local variables**: a variable of the function body whose every assignment
  that may reach the call is a literal, a concatenation of literals or a
  constant the file proves; each of those literals is a pattern the call may
  receive. An assignment written after the variable's last read as a pattern
  never reaches it, unless a loop (`for`, `foreach`, `while`, `do`) holds
  both or the body has a `goto`: `$type = 'int'` after `preg_match($type,
  ...)` is not read. A `for`, `foreach` or `while` whose body is a control
  structure without braces counts as holding the whole function body; a `do`
  ends at its `while`, whatever its body is. The variable is
  not read when it may hold anything else: a parameter, a reference (`=&`),
  a compound assignment (`.=`, `??=`), `global`, `static`, a destructuring,
  a `foreach` or `catch` target, `unset()`, `++` or `--`, a `yield` in a
  generator that returns by reference (`function &gen()`), or the variable
  passed to any other call, as `preg_match()`'s `$matches`. A function body
  that writes `$$name`, or calls `extract()`, `compact()`, `eval()` or
  `include`, has none of its variables read. A variable does not cross into a closure,
  and one at file scope is not read.
- **Properties**: `$this->p` with a literal default, `private` (in a `final`
  class, or a `final` property) or `protected` (in a `final` class that
  extends none), in a class using no trait the file does not declare,
  neither static nor promoted nor
  hooked, and whose name is never written in the file, on any object; a
  by-reference `foreach` over `$this->p`, or `$x->p`, writes it too. Nor is
  any property of a class whose body may write them all at once: a
  by-reference `foreach` over `$this`, a variable or any other expression but
  a literal array or a named property, or `$this` passed to a call; and no
  property of any class of the file when `array_walk()` or
  `array_walk_recursive()` walks anything but a literal array. A public
  property can be given another value where this file cannot see it, a
  protected one by a subclass or a parent class, and any one by a trait of
  another file; a private one of a class that may be extended is left out as
  well, a rule deliberately stricter than PHP, which lets no subclass write it.
  In a closure or an arrow function, which `Closure::bind()` may give another
  class, neither `$this->p` nor `self::X` nor `static::X` is read.
  Writes the file does not show are not seen: those of another file, of
  Reflection, of `Closure::bind()`, or of `array_walk()` on the object from
  another scope. The default is still linted, as one of the values the
  property holds.

A pattern read through a name is reported once, where its literal is written,
whatever the number of calls that use it: `regex lint` points at the line of
`private const PATTERN`, and the JSON report gives the first call as `source`
and the way it reached the literal as `location`, `via self::PATTERN` (see the
[JSON output reference](../reference/json-output.md#result-lintresults)). The
console shows it under the pattern:

```
  app/Slug.php:14:22
      → /(\s+)+$/
     ↳ via $separator
```

A pattern assembled at the call, `preg_match('/'.self::PART.'/', $s)`, is
reported at the call. To silence a pattern read through a name,
put `@regex-ignore` on the line of its declaration: a marker on the call does
not reach it.

Not read: a constant of another file, `self::` inside a trait, a ternary or a
`match` over literals, a variable captured by a closure's `use` or an arrow
function, an array of patterns held in a local variable, a concatenation with
`preg_quote()` (its text is not known), a method called on an object
(`$str->matches()`: Laravel's `Stringable`, Symfony String, Finder's
`->name()`, Pest's `->toMatch()`, beberlei's `Assert::that($v)->regex()`), and
`mb_ereg()` and `mb_split()`, whose patterns are not PCRE.
[Extraction coverage](../reference/extraction-coverage.md) lists what each
tool reads.

##### Your own helpers

A project's own helpers are declared as `function` or `Some\Class::method`.
Append `#<index>` when the pattern is not the first argument, and
`#<index>:keys` when that argument is an array whose keys hold the patterns:

```bash
vendor/bin/regex lint src/ --pattern-function='App\Support\Str::matches#1'
```

A call that passes the pattern by name is read under `pattern:`, `patterns:`
or `regex:`. When the helper names its parameter otherwise, append
`$<name>` to the index: `App\grep#1$expression` reads `grep($list,
expression: '/.../')`, and that name alone. A spec the lint cannot read,
`x#a` or `x#1$` say, is left out with a warning on stderr, once per spec,
which `-q` silences: `Malformed pattern function "x#a", ignored.`

```json
{
  "extraction": {
    "interop": ["composer-pcre", "nette-utils"],
    "functions": ["App\\Support\\Str::matches#1"]
  }
}
```

Or mark the parameter at the source, with the attribute
`PHPRegex\Parser\Attribute\RegexPattern`, or with PhpStorm's
`#[Language('RegExp')]` (`jetbrains/phpstorm-attributes`) if the code already
carries it: the functions and static methods that declare one are read first,
and their calls are then read as if they were configured. The declarations
are read project-wide, whatever paths are linted: in the linted paths, in the
configured `paths` and in `vendor/` of the working directory; with no `paths`
configured, in the linted paths and `vendor/`. Below a linted path they are
read as the lint reads the files: what `exclude` and `--exclude` keep out of
the lint there is not read, so a plain `regex lint`, which lints `.` with
`"exclude": ["var", "vendor"]`, reads nothing under `var/cache/`. A configured
path the run does not lint, and `vendor/`, are read whatever `exclude` says:
a helper declared there, or in a library, is still known. With `paths`
configured, `regex lint $(git diff --name-only)`
still knows a helper declared in a project file it does not lint. The
`vendor/` and the `regex.json` used are those of the directory the lint runs
from. A declaration file or directory that cannot be read, or a file too
large for `memory_limit`, is skipped silently; a package Composer links into
`vendor/` from a path repository is read through its symlink.

When several declarations name one function, a configured spec
(`--pattern-function`, `extraction.functions`) always wins over a declaration
the lint finds; a project declaration (in the linted or configured paths)
wins over a copy in `vendor/`; two project declarations marking different
parameters are both read, whatever the order of the paths or the number of
jobs; two that mark one position under different parameter names read a
named argument under either name.

A call written unqualified in the function's own namespace, `grep()` in
`namespace App`, is read, as PHP calls `App\grep()` first; and when the
namespace declares its own `grep()`, marked or not, a global `grep()` marked
in a library or named with `--pattern-function` or `extraction.functions` does
not capture the call. A static method is read when the call names its class,
`Str::matches(...)`, not through `self::`, `static::` or `parent::`. An
instance call (`$str->matches(...)`, `$this->matches(...)`), a method marked
`#[RegexPattern]` included, names no class the linter can know, and is not
read; the PHPStan extension,
which knows the type of `$str`, reads it (see
[the PHPStan guide](phpstan.md#pattern-parameters)).

```php
use PHPRegex\Parser\Attribute\RegexPattern;

final class Str
{
    public static function matches(string $subject, #[RegexPattern] string $regex): bool
    {
        return 1 === preg_match($regex, $subject);
    }
}

Str::matches($input, '/(a+)+$/'); // linted as preg_match('/(a+)+$/', ...)
```

A function or method that marks several parameters is read at each of them,
by position and by name: in `between(#[RegexPattern] string $from, string
$subject, #[RegexPattern] string $to)`, both `$from` and `$to` are linted.

Functions republished under another namespace with the same signature — as
`thecodingmachine/safe` does with `Safe\preg_match()` — are recognised
without any configuration.

**Console Output:**
```
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime       : PHP 8.4.26, PCRE2 10.49
Target        : PHP 8.2, PCRE2 10.40 (composer.json require.php)
Processes     : 10
PCRE JIT      : 1
Backtrack     : 1000000
Recursion     : 100000
Configuration : regex.dist.json

  [1/3] Scanning files
  Scanned 1 files, found 1 patterns.

  [2/3] Analyzing patterns
  [3/3] Suggesting optimizations

  PASS No issues found, 0 optimizations available.
  Time: 0.02s  Memory: 8 MB  Processes: 1
  If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex
```

**With Issues:**

```bash
vendor/bin/regex lint src/ --redos
```

```
  [1/3] Scanning files
  Scanned 1 files, found 2 patterns.

  [2/3] Analyzing patterns
  [3/3] Suggesting optimizations
  src/Example.php:3:12
      → /(?<=a+)b/
    FAIL Lookbehind is unbounded. PCRE requires a bounded maximum length.
         ↳         (?<=a+)b
                   ^

  src/Example.php:4:12
      → /^(a+)+$/
    WARN Exponential backtracking (proven). Severity: CRITICAL, confidence: MEDIUM.
         ↳ Attack: "a" x n . "!" Unbounded quantifier detected. May cause backtracking on non-matching input. ...
    INFO Quantified capturing group "(...)" with "+": only the last iteration's capture is retained.
         ↳ Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.
    WARN Nested quantifiers can cause catastrophic backtracking.
         ↳ Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.

  FAIL 1 invalid patterns, 2 warnings, 1 infos found, 0 optimizations.
```

The summary counts infos only when there are some: a run with infos and
nothing worse ends with `PASS 0 warnings, 2 infos found, 0 optimizations
available.`, and exits with 0.

The ReDoS issue, `regex.lint.redos`, carries the verdict's headline, then
`Severity: …, confidence: …`, and in its hint the attack. It is a warning in
theoretical mode. With `--redos-mode=confirmed`, a verdict the running PCRE
reproduced at `high` or above, or a proven one it cannot replay because `ini_set()` is disabled, is an error, and makes the command exit with 1;
its hint adds the replay, and the summary counts it apart from the invalid
patterns:

```bash
vendor/bin/regex lint src/ --redos --redos-mode=confirmed
```

```
  src/Example.php:4:12
      → /^(a+)+$/
    ...
    FAIL Exponential backtracking (proven). Severity: CRITICAL, confidence: HIGH.
         ↳ Attack: "a" x n . "!" Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off). Unbounded quantifier detected. ...

  FAIL 1 invalid patterns, 1 ReDoS errors, 1 warnings, 1 infos found, 0 optimizations.
```

An exponential verdict the engine did not reproduce is dropped, and a
polynomial one, never replayed, stays a warning:

```bash
vendor/bin/regex lint src/ --redos --redos-mode=confirmed --format=github
```

```
::error file=src/Validator.php,line=7,col=33,title=Security (regex.lint.redos)::Exponential backtracking (proven). Severity: CRITICAL, confidence: HIGH.%0AAttack: "0" x n . "!"%0AReplayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).%0ASuggestion: ...
::warning file=src/Validator.php,line=12,col=33,title=Security (regex.lint.redos)::Polynomial backtracking, degree 3 (proven). Severity: HIGH, confidence: MEDIUM.%0AAttack: "0" x n . "!"%0ASuggestion: ...
```

Here `/^(\d+)+$/` sits on line 7 and `/^\d*\d*\d*$/` on line 12, each in a
`preg_match()` call whose opening quote is at column 33. The lint issues of the
same lines (nested quantifiers, a quantified capturing group) are left out.

In JSON, the issue carries the whole analysis under `analysis`, with the keys
`analyze --format=json` prints (see the
[JSON output reference](../reference/json-output.md)).

When one attempt is proven linear but the unanchored search retries it along a
run, as `/\s+$/` on a run of spaces, the issue is `regex.lint.redos.search`: the
search is quadratic in PCRE2's interpreter, `pcre.backtrack_limit` does not stop
it, and the JIT may avoid it for some patterns (see the
[ReDoS guide](redos.md#the-cost-of-an-unanchored-search)). It runs
under `--redos`, is `medium` like a proven quadratic attempt, so the default
`high` threshold hides it, and is a warning in every mode:

```bash
vendor/bin/regex lint app --redos --redos-threshold=medium
```

```
  app/Whitespace.php:3:12
      → /\s+$/
    WARN Quadratic search: one attempt is linear (proven); an unanchored search is quadratic in PCRE2's interpreter (pcre.jit=0, a build without JIT, or (*NO_JIT)); the JIT may avoid it for some patterns. Severity: MEDIUM.
         ↳ Attack: " " x n . "!". pcre.backtrack_limit does not stop it: the limit counts each attempt apart. preg_match_all(), preg_replace() and preg_split() retry the same way. Anchor the pattern when ever...


  PASS 1 warnings found, 0 optimizations available.
```

Its attack is in the analysis' `search_cost`; `--disable-rule=regex.lint.redos.search`,
or `"redos.search": false` in `checks.lint.rules`, turns it off.

---

### 8. Graph (Automaton)

Print the automaton a pattern compiles to — Dot by default, Mermaid with `--format=mermaid`:

```bash
vendor/bin/regex graph '/ab?c/'
```

**Output:**
```
digraph NFA {
  rankdir=LR;
  node [shape=circle, fontname="Helvetica", fontsize=10];
  edge [fontname="Helvetica", fontsize=10];
  start [shape=point, width=0];
  start -> 0;
  ...
```

The Mermaid form pastes straight into GitHub issues and pull requests:

```bash
vendor/bin/regex graph '/\d{3}-\d{4}/' --format=mermaid
```

---

### 9. Redos (Measure the Cost)

`analyze` proves the risk; `redos` measures it. It builds the attack input, runs the
pattern on it, and reports the wall time and the failure mode — here, the backtrack
limit giving out:

```bash
vendor/bin/regex redos '/(a+)+$/'
```

**Output:**
```
  [1/3] Patterns
  Vulnerable
      → /(a+)+$/

  [2/3] Input
  Source       : auto
  Base length  : 51
  Final length : 51
  Iterations   : 1 (warmup 0)
  Preview      : "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!"

  [3/3] Benchmark
case     | result |    wall_ms |     avg_ms |     cpu_ms |     mem_kb |    peak_kb | err
--------------------------------------------------------------------------------------------
vuln     | error  |       2.57 |       2.57 |       2.39 |       +0.0 |       +0.0 | Backtrack limit exhausted
```

`--safe '<pattern>'` measures a fixed variant alongside, `--input` pins the subject,
`--jit 0` forces the interpreter. See the [ReDoS guide](redos.md#confirmed-mode) for
what a replay does and does not prove.

---

### 10. Format (Lay Out or Compact)

`format` lays a long pattern out over lines under `x`, keeping its comments and
its spelling; `--compact` writes it back on one line, without `x`, layout or
comments. A pattern that already fits comes back unchanged:

```bash
vendor/bin/regex format '/^(?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS)\s+(?<path>\/[^\s?]*)(?:\?(?<query>\S*))?\s+HTTP\/(?<version>1\.[01]|2(?:\.0)?)$/'
```

**Output:**
```
/
    ^
    (?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS)
    \s+
    (?<path>\/[^\s?]*)
    (?:\?(?<query>\S*))?
    \s+HTTP\/
    (?<version>1\.[01]|2(?:\.0)?)
    $
/x
```

`--width=N` sets the width a line may take (80 by default); `--json` prints
`{"pattern", "formatted", "changed", "refusal"}`. Where a layout under `x` would
change what the pattern matches, the pattern is printed unchanged with a note on
stderr, and the exit code stays 0. See [Formatting patterns](formatting.md) for
the layout rules, the guarantees and the refusals.

---

## Symfony and Laravel Commands

The Symfony bundle and the Laravel package expose the same engine as console
commands — `bin/console regex:lint`, `regex:routes`, `regex:security` and
`regex:analyze`, or `php artisan regex:lint` — and exit with the same codes as
the binary. See [the Symfony guide](symfony.md#commands) and
[the Laravel guide](laravel.md#commands) for their full list and configuration.

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
| `jobs`                          | int              | Number of parallel workers, at least 1 (default: the logical CPUs the process may use, see [Parallel workers](#parallel-workers)) |
| `ide`                           | string           | IDE for clickable links                                  |
| `reportUnmatchedIgnores`        | boolean          | Report the inline markers and baseline entries that silence nothing, as `regex.ignore.unmatched` (default `true`, see [Markers that silence nothing](#markers-that-silence-nothing)) |
| `phpVersion`                    | string or int    | PHP version the patterns are judged for: `"8.3"`, `80300`, or `"runtime"` for the PHP running the command |
| `pcreVersion`                   | string           | PCRE2 release the patterns are judged for: `"10.44"`     |
| `extraction.interop`            | array            | Libraries whose calls carry patterns (default: every preset; a list reads exactly those, `[]` the native calls only) |
| `extraction.functions`          | array            | Project helpers carrying patterns                        |
| `checks.validation`             | boolean          | Report patterns PCRE2 refuses (default `true`)           |
| `checks.redos`                  | object           | `enabled` (default `false`), `mode` (`theoretical` or `confirmed`), `threshold` (`low`, `medium`, `high`, `critical`) |
| `checks.optimizations`          | object           | `enabled` (default `true`), `minSavings`, `options`      |
| `checks.optimizations.options`  | object           | digits, word, ranges, canonicalizeCharClasses, possessive, factorize, minQuantifierCount, verifyWithAutomata |
| `checks.lint`                   | object           | `enabled` (default `true`) and `rules`, a map of rule id to `true` or `false`; `redos.search` turns the search cost of the ReDoS check off |
| `checks.complexity`             | object           | `enabled` (default `false`) and `threshold`, an integer of at least 1 (default `50`): a warning, `regex.lint.complexity`, for each pattern whose complexity score reaches it |

`checks.redos`, `checks.optimizations`, `checks.lint` and `checks.complexity`
are objects, and only their `enabled` key switches a check on or off: setting
`threshold`, `mode`, `minSavings`, `options` or `rules` alone never enables it. `mode` and
`threshold` are read whatever their case.

The lint command refuses a file it cannot fully use. Every unknown key, every
unknown lint rule id and every value of the wrong kind is an error, and the
command lists all of them at once, each with the key it is about, then exits
with code 2 before scanning anything.

### Removed Keys

These 1.x keys no longer exist: each is refused like any unknown key, with a
plain unknown-key error naming it (a "Did you mean" hint appears when a known
key is close). The table says where each setting went:

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
3. `composer.json` in the working directory: `config.platform.php` if set
   (one below 7.3 gives way to `require.php`, see below), else the lowest
   version `require.php` allows (`^8.2 || ^8.3` is PHP 8.2),
   and then the patterns are also validated on the later PHP versions the
   constraint allows ([Several PHP versions](#several-php-versions));
   The `COMPOSER` environment variable names another file, as it does for
   Composer;
4. the PHP running the command.

`runtime`, given to `--php-version` or as `phpVersion`, names the PHP running
the command and the PCRE2 it links, as PHPStan's `phpVersion` does: a project
that lints for its floor can still ask what the engine at hand says.

Each version is chosen on its own. Without a PCRE2 release, the command uses
the one the target PHP bundles (10.32 for PHP 7.3, 10.33 for 7.4, 10.35 for
8.0, 10.39 for 8.1, 10.40 for 8.2, 10.42 for
8.3, 10.44 for 8.4 and 8.5), or the PCRE2 of the running PHP when the target is the running
PHP.

The lowest PHP is the one that matters because a library, or an application
deployed on several servers, runs on every version its constraint allows: a
pattern that only compiles on a newer PCRE2 fails on the oldest one. PHP 7.3
is the oldest PHP the command judges, the first on PCRE2. A PCRE2 release
older than 10.39, the oldest whose rules the library knows in full, is judged
with the 10.39 rules but for the few older changes it names, and the command
says so with a note such as `PCRE2 10.33 is judged with the PCRE2 10.39
rules, the oldest this library knows in full.` (see
[PHP 7.3 to 8.0, and PCRE1](../concepts/pcre.md#php-73-to-80-and-pcre1)).

PHP 7.2 and earlier are never judged: they ship PCRE1, another engine than
PCRE2, and no verdict of this library holds for it. PHP 7.3 is the first PHP
on PCRE2 (10.32), with no release in between. What happens to a project that
allows such a PHP depends on where the version comes from:

- A `require.php` that starts below 7.3 but also allows PHP 7.3 or later,
  `^7.1 || ^8.0` or `>=5.4` for instance, is judged from the lowest PHP of
  7.3 or later it allows, usually 7.3, as any other floor. The versions below
  7.3 are left out, and a note says so; the note does not change the exit
  code:

  ```text
  Note: composer.json require.php allows PHP 7.1, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP 7.3, the lowest version it allows on PCRE2.
  ```

- A `config.platform.php` below 7.3 pins the PHP Composer resolves the
  dependencies for, not the only PHP the code runs on: it gives way to a
  `require.php` that also allows 7.3 or later, judged from the lowest such
  PHP the same way, with the note naming the platform:

  ```text
  Note: composer.json config.platform.php pins PHP 7.2, and PHP 7.2 and earlier ship PCRE1, which this library does not judge: judging from PHP 7.3, the lowest version require.php allows on PCRE2.
  ```

- A `require.php` that allows no PHP of 7.3 or later (`7.2.*`, `~7.1.0`,
  `>=5.6 <7.3`) is refused, and so is a `config.platform.php` below 7.3 with
  no such `require.php` beside it. So is a
  version below 7.3 named by `--php-version`, `phpVersion` in `regex.json` or
  a bridge setting: that names a PHP on PCRE1 outright. The run stops until
  a PHP version of 7.3 or later is named:

  ```text
  Error: composer.json require.php allows PHP 5.6: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.
  ```

A refused run exits with 2, and with `--format=json` prints the
[error envelope](../reference/json-output.md#the-error-envelope) with stage
`target`, so that a pipeline never reads a project the command refused to
judge as a clean one. The Symfony and Laravel `regex:lint` commands follow the
same rule; the language server logs the note, or shows the refusal and judges
no pattern. `validate`, `analyze` and the other commands that read one pattern
refuse a `--php-version` below 7.3 the same way, with exit code 2 and, under
`--json` where the command takes it, the envelope with stage `target`; the
PHPStan extension and the Psalm plugin refuse one too. A constraint the command cannot read (`*`, `<9`, a branch name)
falls back to the running PHP, with a note naming it: a `composer.json` the
command cannot read never stops a run.

Where the target is recorded depends on the format. The console format shows
it as a `Target` row in the banner, right under `Runtime`, with any `Note:`
lines from the resolution below the table — so it lives wherever the banner
lives: on the terminal, and in a shell redirect such as
`regex lint src/ > report.txt`, but never in the file named by `--output`,
which holds the report alone. The GitHub, Checkstyle and JUnit formats keep
stdout report-only and print a stable stderr line instead:

```text
Target: PHP 8.2, PCRE2 10.40 (composer.json require.php)
```

Machine consumers that need the target programmatically should read the JSON
report's `target` key (see [JSON](#json) below) rather than parse that line;
with `--format=json` the notes stay on stderr, so a JSON pipeline that
discards stderr loses the why behind `target.source`. Two neighbouring
surfaces keep their own spelling: the language server logs its own
`Target: ...` line on initialization, and the Symfony and Laravel commands
print the stderr line for the JSON format too.

#### Several PHP versions

A pattern valid on the lowest PHP may be refused by a later one the project
installs on: PHP 8.5 refuses `\K` in a lookaround, which PHP 8.4 compiles.
When the target comes from `require.php`, the command therefore also
validates every pattern at each PHP version above the floor where a rule of
the library changes, as long as the constraint allows it: today 8.3, 8.4,
8.4.25, 8.5 and 8.5.10. Each is judged with the PCRE2 release it bundles, or
with the one `--pcre-version` or `pcreVersion` names. `>=8.2 <8.5` stops at
8.4.25, `~8.3.0` is 8.3 alone, and an open constraint such as `>=8.2` runs to
the newest version the library knows. Each branch of an OR constraint is also
validated at its own lowest version, which no rule change stands for:
`~8.3.0 || >=8.5.3` is judged at 8.3, 8.5.3 and 8.5.10, and a pattern PHP 8.5
refuses is reported "On PHP 8.5.3 and later".

What runs where:

- **At the floor**, everything: validation, the lint rules, the ReDoS
  analysis and the optimizations. A pattern the floor refuses is reported
  there, once, and not validated elsewhere.
- **At every later version**, validation only: the pattern is parsed and
  checked as that PHP and PCRE2 would compile it. A pattern the floor accepts
  and a later version refuses is reported as an error naming the versions
  that refuse it, and fails the run:

```text
  app/Example.php:3:12
      → /(?<=a\Kb)c/
    FAIL On PHP 8.5 and later: \K is not allowed in a lookaround from PHP 8.5, which compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
         ↳ Up to PHP 8.4 a \K in a lookaround resets the start of the match from inside it: "(?=\Ka)" matches an empty string in "a", and "(?<=\Ka)b" matches "ab" in "ab". No spelling keeps that in general: rewrite the pattern without it.
```

  The lint changes no code. A `\K` alone at the start of a plain lookahead is
  the one case a spelling keeps: `(?=\Kab)a` resets the start of the match
  where `\K(?=ab)a` does, on every PHP, and the hint says
  `This \K opens the lookahead: write "\K(?=" for "(?=\K", which every PHP reads alike.`

- **A pattern every version accepts but one reads otherwise** is a warning,
  `regex.lint.compat.meaningChanges`, naming the first version that parses it
  into another meaning: `/a{,3}/` is the text `a{,3}` under PCRE2 before 10.43
  (PHP 8.2 and 8.3) and `a` zero to three times from 10.43 (PHP 8.4). Write
  `a{0,3}` or `a\{,3}`, which every version reads alike; `"compat.meaningChanges":
  false` in `checks.lint.rules` turns the check off. The same warning names a
  change of meaning that keeps the tree, from the release table: a possessive
  `{1}+` on a group (PCRE2 10.34), a group that refers to itself, a repeated
  assertion holding a group and `(*UCP)` casing without UTF (10.35), a bare
  script name (10.40); see [the rules reference](../reference/rules.md#meaning-that-changes-across-php-versions).

The console banner still names the floor. The JSON report lists every PHP and
PCRE2 it validated at under `target.range`, and the issue carries the lowest
one that refuses the pattern under `target`
([JSON output](../reference/json-output.md#target-linttarget)).

Every other source names one version, and only that version is judged:
`--php-version`, `phpVersion` in `regex.json`, `config.platform.php`, and the
running PHP. To judge one version of a project whose `require.php` spans
several, name it:

```bash
vendor/bin/regex lint src/ --php-version=8.2
```

Symfony's and Laravel's `regex:lint` judge the same range when they read
`composer.json`; their `php_version` setting names one version, as
`--php-version` does.

#### Code that runs on some PHP versions only

A pattern the code only runs on some of the versions judged is judged for
those alone: the lint narrows the versions, it never silences the pattern.
The guard around it says which:

```php
if (PHP_VERSION_ID >= 80400) {
    preg_match('/a{,3}/', $s);   // judged for 8.4 and later: a quantifier everywhere
}
preg_match('/a{,3}/', $s);       // regex.lint.compat.meaningChanges: text on 8.2 and 8.3
```

Under `require.php` `^8.2`, the first `a{,3}` is judged for 8.4, 8.4.25, 8.5
and 8.5.10, which all read a quantifier there, and is not reported; the
second is judged for the whole range, and PCRE2 before 10.43 (PHP 8.2 and
8.3) reads it as text.

The guards the lint reads:

- `PHP_VERSION_ID` or `\PHP_VERSION_ID` compared to an integer literal, on
  either side, with `>=`, `>`, `<`, `<=`, `===`, `!==`, `==` or `!=`;
- `version_compare(PHP_VERSION, '8.4', '>=')`, with a literal version (`x.y`
  or `x.y.z`) and one of `>=`, `ge`, `>`, `gt`, `<`, `lt`, `<=`, `le`;
- `&&` of those, in parentheses or not;

on the branches of an `if`, an `elseif` and an `else` (each where its
condition holds and the ones before it fail), and on the rest of a block
after `if (<guard>) { return; }`, `throw`, `continue`, `break` or `exit` alone
in the body, up to the next `case` of a `switch`. A function or a class
declared after such an exit is judged for the whole range: PHP declares those
of a file before running it, whatever the file returns. A file holding a
`goto` reads no early exit. With `nikic/php-parser` installed, the lint also reads them on
the branches of a ternary, on the arms of `match (true)` and in the
alternative syntax (`if (...): ... endif;`); the tokenizer, which reads the
files otherwise, does not, and judges those for the whole range. Anything else is no guard, and the pattern is judged for the whole
range: a negation (`!(PHP_VERSION_ID >= 80400)`), an `||`, a comparison with
anything but a literal, an `&&` one side of which is no guard,
`version_compare()` with `==` or `!=`, a guard in the calling function. In a
namespace, an unqualified `PHP_VERSION_ID` is read only when the file
declares no constant of that name. A pattern held in a constant or a property
is judged where its literal is written, as for `@regex-ignore`: a guard
around the call does not narrow it. A guard that keeps no version of the
range leaves the pattern unjudged: the code never runs there.

Where no guard says it, `@regex-target` names the versions, as a Composer
constraint after `php`, `pcre`, or both:

```php
preg_match('/(?a)\d+/', $s); // @regex-target php>=8.4

// @regex-target-next-line pcre>=10.43
preg_match('/(?a)\d+/', $s);

/**
 * @regex-target php>=8.4 pcre>=10.43
 */
final class AsciiMatcher
{
    // every pattern of the class is judged for 8.4 and later
}
```

Under `require.php` `^8.2`, `(?a)` (an option PCRE2 10.43 added) is judged
for 8.4, 8.4.25, 8.5 and 8.5.10, and not reported; without the marker, PHP
8.2 refuses it. The versions judged are the lowest one the marker keeps, with
the PCRE2 it bundles (or the one named), then each later version of the
range it keeps. `pcre>=10.43` keeps the versions whose PCRE2 is 10.43 or
later.

- **Placement.** As for `@regex-ignore`: `@regex-target` covers the last line
  of its comment, `@regex-target-next-line` the line after it. In a doc
  comment that opens its line, `@regex-target` covers the statement or the
  declaration after it, whole: a class, an interface, a trait, an enum, a
  function, a method, a constant.
- **Several markers.** Markers that cover one line all apply, and so does
  the guard around them: the versions judged are those every one keeps.
- **A marker that keeps no version** of the range, `php>=9.0` under `^8.2`, is
  an error, `regex.target.empty`, on the marker's line, and the patterns it
  covers are not judged. Under one version named, `--php-version=8.2`, the
  range is the 8.2 series.
- **A marker that cannot be read**, `php>=eight`, is an error,
  `regex.target.invalid`, and the patterns it covers are judged for the whole
  range.
- **In the JSON report**, an issue of a pattern a guard or a marker narrows names the version that
  reported it in `target` and the constraint in `target.guard` (`>=8.4`), the
  PCRE2 part in `target.guard_pcre`
  ([JSON output](../reference/json-output.md#issue-target-lintresultsissuestarget)).

The PHPStan extension, the Psalm plugin and the language server read the
same markers.

Single-pattern commands, such as `analyze` or `validate`, judge for the
running PHP unless `--php-version` or `--pcre-version` is given; their
banners show `Target PHP` / `Target PCRE2` rows only when those flags are
passed.

### Errors and Exit Codes

`lint` exits with the codes every command uses (see
[Exit Codes](#exit-codes)). What each command counts as a problem (code 1):

| Command                                      | Exits with 1 when                                                                  |
|----------------------------------------------|------------------------------------------------------------------------------------|
| `lint`                                       | A pattern does not compile, a ReDoS verdict reproduced at high or above, or a lint rule of error severity fires (warnings and infos alone leave 0); or the files cannot be read; or a worker fails or exits |
| `validate`, `parse --validate`, `analyze`    | The pattern is invalid                                                             |
| `parse`, `explain`, `diagram`, `highlight`   | The pattern does not parse                                                         |
| `graph`                                      | The pattern does not parse, or cannot be drawn as an automaton                     |
| `transpile`                                  | The pattern is invalid, or cannot be written for the target                        |
| `format`                                     | The pattern does not parse, or the formatter could not prove its output the same pattern; a refusal leaves 0 |
| `debug`                                      | The pattern is invalid, a semantic error such as `/(?<=a+)b/` included, in every format |
| `analyze`, `debug`                           | `--redos-mode=confirmed` reproduces a ReDoS verdict of high severity or more, at or above `--redos-threshold`, on the running PCRE, or proves one it cannot replay because `ini_set()` is disabled |
| `compare`                                    | The answer is no: the patterns intersect, the first is not a subset of the second, or they differ; or they cannot be compared |
| `redos`                                      | PHP refuses to compile the pattern or the `--safe` one; a slow run alone leaves 0 |
| `self-update`                                | The update fails                                                                   |

The lint rules of error severity target patterns that compile but do not do
what they say without `/u`: a multibyte character in a class, a quantified
multibyte character, a Unicode property. The `lint` summary counts them as lint
errors (`FAIL 1 lint errors, 2 warnings, 0 optimizations.`), apart from the
invalid patterns, which PCRE refuses to compile. Every other rule is a warning or
an info, printed without changing the code.

A theoretical ReDoS verdict is a warning, as it is for `lint`: it is printed
and leaves the code at 0, proven or not. A verdict the confirmed mode did not
reproduce (`Not reproduced on PCRE2 …`), and a polynomial verdict, which is
never replayed, leave 0 too.

Code 2 covers an unknown command or option, an option without its value or
with a value the command does not accept (an unknown `--format`, `--target`,
`--method` or `--redos-mode`, an invalid `--php-version` or `--pcre-version`),
a missing pattern, an argument the command does not take (one pattern more
than it reads, or anything after `version` or `self-update`), an
`--input-file` that cannot be read, an `--output` file that cannot be written,
a `regex.json` that cannot be read (`lint` and `debug`), and, for `lint`, a
target PHP of 7.2 or earlier, which ships PCRE1: one named outright, or a
`composer.json` that allows no PHP of 7.3 or later (see
[Target PHP and PCRE2](#target-php-and-pcre2)) — in which case nothing was
scanned. `regex` run without a command prints the help and exits
with 2, as `regex help` with an unknown command does.

For `lint`, code 2 also covers the paths it is given:

| Case                                                         | Exit code | JSON `stage` |
|--------------------------------------------------------------|-----------|--------------|
| A path on the command line that does not exist               | `2`       | `usage`      |
| A path listed in `regex.json` that does not exist            | `2`       | `config`     |
| A `--baseline` file that is missing, cannot be read, or is not a baseline | `2` | `usage` |
| A `--generate-baseline` file that cannot be written          | `2`       | `usage`      |
| An `--output` file that cannot be written                    | `2`       | `usage`      |
| A path that exists but holds no pattern                      | `0`       | none         |

With `--format=json`, a configuration or command-line error is printed on
stdout as the error envelope, `{"error": "...", "stage": "config"}` (stage
`usage` for the command line, a path argument that does not exist included,
`config` for a path in `regex.json` that does not exist, `collect` when the
files cannot be read, `analyze` when a worker fails or exits), so that stdout always holds one JSON document; with
the other formats it is printed on stderr. See
[the error envelope](../reference/json-output.md#the-error-envelope).

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

`// @regex-ignore` at the end of a line silences the issues of that line;
`// @regex-ignore-next-line` silences those of the line after the comment.
A marker starts its comment (after `//`, `#`, `/*` or `/**`) or a line of a
doc comment: one quoted or met in the middle of a sentence is prose about the
markers, and does nothing.

```php
// @regex-ignore-next-line
preg_match('/(a+)+$/', $input);
```

A pattern read through a constant, a variable or a property is silenced on
the line of its literal, where it is reported, not on the call:

```php
private const LEGACY = '/(a+)+$/'; // @regex-ignore
```

A marker may name the issues it silences, comma-separated, right after it.
The other issues of the line are still reported:

```php
preg_match('/\d+[11]/i', $input); // @regex-ignore regex.lint.flag.useless.i
```

The identifiers are the `issue_id` of the JSON report (the error code, such
as `regex.group.unclosed`, for a pattern that does not compile). Anything
after the list, or after a marker that names none, is a note for the reader:
`// @regex-ignore legacy input, see #12` silences the whole line.

#### Markers that silence nothing

A marker left behind once its issue is fixed would silence the next issue of
its line unseen. The lint reports each marker that silences nothing, and each
identifier of a marker that silences no issue of that name, as an error,
`regex.ignore.unmatched`, on the line of the marker:

```text
src/Legacy.php:14  The @regex-ignore marker silences nothing: no issue is reported on line 14. Remove it.
src/Legacy.php:20  The @regex-ignore marker names regex.lint.redos, which no issue reports on line 20. Remove the identifier.
```

The report judges the markers against the checks the run performs: a marker
naming `regex.lint.redos` in a run without `--redos` silences nothing in that
run, and is reported. A run that leaves a check off on purpose passes
`--no-report-unmatched-ignores`, and `"reportUnmatchedIgnores": false` in
`regex.json` turns the report off for every run. The report itself is never
silenced by a marker.

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

# The sample below: one invalid pattern, one replayed ReDoS verdict
vendor/bin/regex lint src/ --redos --redos-mode=confirmed --no-lint --no-optimize --format=json
```

**Output (trimmed):**
```json
{
    "target": {
        "php": "8.4.26",
        "pcre": "10.49",
        "source": "running PHP",
        "range": [
            {
                "php": "8.4.26",
                "pcre": "10.49"
            }
        ]
    },
    "stats": {
        "errors": 2,
        "warnings": 0,
        "optimizations": 0,
        "redos_errors": 1,
        "infos": 0,
        "lint_errors": 0,
        "parser_fallbacks": 0
    },
    "results": [
        {
            "file": "src/Example.php",
            "line": 3,
            "column": 12,
            "file_offset": 18,
            "source": "preg_match()",
            "pattern": "/(?<=a+)b/",
            "location": null,
            "issues": [
                {
                    "severity": "error",
                    "file": "src/Example.php",
                    "line": 3,
                    "column": 12,
                    "file_offset": 18,
                    "position": 0,
                    "issue_id": "regex.lookbehind.unbounded",
                    "message": "Lookbehind is unbounded. PCRE requires a bounded maximum length.",
                    ...
                    "validation": { ... },
                    "analysis": null,
                    "target": null
                }
            ],
            "optimizations": []
        },
        {
            "file": "src/Example.php",
            "line": 4,
            ...
            "pattern": "/^(a+)+$/",
            "issues": [
                {
                    "severity": "error",
                    ...
                    "issue_id": "regex.lint.redos",
                    "message": "Exponential backtracking (proven). Severity: CRITICAL, confidence: HIGH.",
                    "hint": "Attack: \"a\" x n . \"!\" Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off). ...",
                    "source": "preg_match()",
                    "validation": null,
                    "analysis": { ... },
                    "target": null
                }
            ],
            "optimizations": []
        }
    ]
}
```

`stats.errors` counts every error; `stats.redos_errors` counts the ReDoS errors
among them and `stats.lint_errors` the lint rules of error severity that fired;
`stats.infos` counts the issues of severity `info`; `stats.parser_fallbacks` counts
the files the PHP parser could not read, whose patterns the tokenizer read instead.
Every key is always present,
`0` when there is none, and every issue carries every key, `null` when it does
not apply. An issue's `severity` is `error`, `warning` or `info`, from the
severity of the rule that reported it (see
[Severity in Each Format](../reference/diagnostics.md#severity-in-each-format)).
Results are sorted by file, line and column, the same whatever `--jobs` says.

The [JSON output reference](../reference/json-output.md) lists every key of
this report and of the other commands' JSON (`analyze`, `debug`, `redos`,
`transpile`), with the units of each position, the error envelope and what a
minor release may add.

### GitHub Actions

```bash
vendor/bin/regex lint src/ --format=github
```

**Output:**
```
::error file=src/Example.php,line=3,col=16,title=Semantic (regex.lookbehind.unbounded)::Lookbehind is unbounded. PCRE requires a bounded maximum length.%0ALine 1: (?<=a+)b%0A        ^%0ASuggestion: Use a bounded quantifier instead of "+".
```

This is `if (preg_match('/(?<=a+)b/', $input)) {` on line 3. `col` is the
column of the pattern in the file, its opening quote, the `column` of the JSON
report; it is left out when the column is unknown, as for a pattern not read
from a PHP string. The message is escaped as GitHub's format requires: a
newline is `%0A`, a carriage return `%0D` and `%` is `%25`. In the `file` and
`title` properties, `:` and `,` are escaped too, as `%3A` and `%2C`.

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
| `-j, --jobs <n>`    | Parallel workers (default: the logical CPUs, see [Parallel workers](#parallel-workers)) |
| `--format <format>` | Output format (console, json, github, checkstyle, junit) |
| `--json`            | Same as `--format=json`                            |
| `--output <file>`   | Also write the report to a file                    |
| `--baseline <file>` | Leave out the issues a baseline file lists (see [Baseline](#baseline)); `--baseline=<file>` works too |
| `--generate-baseline <file>` | Write every issue of this run to a baseline file; `--generate-baseline=<file>` works too |
| `--no-report-unmatched-ignores` | Do not report the inline markers and baseline entries that silence nothing |
| `--report-unmatched-ignores` | Report them when `regex.json` turns the report off (the default) |
| `--redos`           | Run the ReDoS analysis, off by default             |
| `--no-redos`        | Skip it when `regex.json` turns it on              |
| `--redos-mode <mode>` | `theoretical` or `confirmed`                     |
| `--redos-threshold <sev>` | Lowest severity reported: low, medium, high, critical |
| `--no-validate`     | Skip validation                                    |
| `--no-optimize`     | Disable optimization suggestions                   |
| `--lint`            | Run the lint rules (the default)                   |
| `--no-lint`         | Skip the lint rules                                |
| `--enable-rule=<id>` | Turn a lint rule on (repeatable)                  |
| `--disable-rule=<id>` | Turn a lint rule off (repeatable)                |
| `--interop <presets>` | Libraries to read patterns from, every preset by default (comma separated, `none` for the native calls only) |
| `--no-interop`      | Read patterns from the native calls only (`preg_*`, `RegexIterator`, `filter_var()`) |
| `--pattern-function <spec>` | Extra call carrying a pattern (repeatable) |
| `-v, --verbose`     | Detailed output                                    |
| `--debug`           | Debug information                                  |

> **Note:** ReDoS analysis is disabled by default for performance. Enable it with `--redos` or via configuration. The default threshold is `high`: a proven quadratic pattern is `medium`, and is reported with `--redos-threshold=medium` only.

`--redos-mode` takes `theoretical` or `confirmed`, and `--no-redos` skips the
analysis; the confirmation always runs without JIT. Any other mode is a usage
error (exit code 2), as is an unknown `--format`, and an `--enable-rule` or
`--disable-rule` id that `checks.lint.rules` in `regex.json` would refuse
(`Unknown lint rule "quantifer.nested" in --disable-rule.`).

When `nikic/php-parser` is installed, and the lint reads files with it, a
file the PHP parser cannot read (a syntax error, for one) is read again
with PHP's tokenizer, so its patterns are still linted. The run counts such
files in `stats.parser_fallbacks` of the JSON report, never as an error, and
`--verbose` names each one with the parser's message:

```text
  Parsed with the tokenizer: src/Broken.php (Syntax error, unexpected EOF on line 12)
```

Outside the console format, that line goes to stderr, so that stdout holds
the report alone.

### Parallel workers

Without `--jobs` (or `"jobs"` in `regex.json`), the lint runs one worker per
logical CPU the process may use: the CPUs it is pinned to on Linux, else
`nproc` or `sysctl hw.logicalcpu`, else `/proc/cpuinfo`. Inside a container,
the cgroup CPU quota (`docker run --cpus=2`) and `KUBERNETES_CPU_LIMIT` cap
that number, a fraction rounding up, and so does the cgroup memory limit
(`docker run --memory=1g`): each worker is a copy of the main process, so the
limit holds the main process and as many workers as fit beside it, at least
one. `regex:lint` in Symfony and Laravel counts the same way. Without the
`pcntl` extension, on Windows for one, the lint runs in one process. The banner shows the number
planned; the `Processes:` line under the report shows the number of workers
the run started, 1 when it had a single file or pattern to share out.

The workers take the files, then the patterns, a few at a time, as each one
finishes the last, and report as they go: the progress moves with the work
and its clock keeps running while the run waits. In a terminal the console
format shows each step and its progress on stderr, so that
`vendor/bin/regex lint src/ > report.txt` keeps the report alone:

```text
  [1/3] Scanning files
   Patterns
       1240/4510 [▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░]  27%    00:04
```

Step 1 names what it reads on a line above its bar, so that its bar lines up
with those of the next steps: `Finding files`, then `Declarations` (the files
read for the `#[RegexPattern]` functions they declare, see [Patterns Behind a Wrapper](#patterns-behind-a-wrapper)),
`Functions` when namespaced functions are read too, then `Patterns`. Step 2
analyses the patterns, step 3 suggests the optimizations; with
`--no-optimize` the run has two steps. Without a terminal, in a CI log, the
bar is not drawn: a line every 30 seconds says where a long step stands,
such as `   7449/24824 (30%) 01:30` under `[2/3] Analyzing patterns`.
When no worker has reported for 10 seconds, the run names the file it is
held on, the first file of the oldest share still being worked on
(`Still on src/web/Request.php`): above the bar in a terminal until the next
file is done, on a line of its own in a log. `regex:lint` in Symfony and
Laravel does the same.

A worker that fails or exits, a PHP fatal error or the system killing it for
memory, stops the run with exit code 1 and a message naming the files it was
reading:

```text
Error: Parallel analysis failed: a worker exited unexpectedly while reading src/Huge.php and 41 more
```

With `--format=json` the error envelope says `"stage": "analyze"` (see
[JSON output](../reference/json-output.md)). `--jobs=1` runs everything in
one process.

### Baseline

A baseline records the issues a codebase has today, so that a CI run fails
only on new ones:

```bash
vendor/bin/regex lint src/ --generate-baseline regex-baseline.json
vendor/bin/regex lint src/ --baseline regex-baseline.json
```

Both options take their file after a space or an `=`.

- **What an entry matches.** An issue is left out when an entry has the same
  issue identifier, the same file and the same pattern (compared by a hash of
  its exact bytes). The line only decides between entries that share all
  three, so moving a pattern down a file, or a message reworded in a newer
  release, does not bring the issue back. One entry leaves out one issue: a
  second, identical pattern in the same file is reported. When copies of a
  pattern are added, the entries are aligned with the issues in line order,
  as a diff aligns two versions of a file, so the copy reported is the one
  inserted, not one that moved.
- **Every format.** A baselined issue is gone from the console, JSON, GitHub,
  Checkstyle and JUnit reports alike, and the exit code is computed without
  it.
- **Paths.** Files are recorded relative to the directory the command runs
  from: generate and apply the baseline from the same directory, usually the
  project root.
- **The file.** `{"version": 1, "issues": [...]}`, each issue with its `file`,
  `line`, `column`, `issue_id`, `message`, `severity`, `pattern` and
  `pattern_hash`; the [JSON output reference](../reference/json-output.md#baseline-file)
  lists them. Bytes that are not valid UTF-8 are written `\xHH`, so the file
  is always valid JSON.
- **Entries that match nothing.** An entry no issue takes any more, the
  issue fixed or its pattern removed, is reported as `regex.ignore.unmatched`
  on the entry's file and line: remove it, or generate the baseline again.
  Only the files the run reads are judged, so a run over `src/Controller`
  says nothing of the entries of `src/Legacy`. `--no-report-unmatched-ignores`
  turns the report off, as it does for the inline markers.
- **A 1.x baseline**, a plain list, is refused like any non-baseline file:
  exit code 2, `stage: "usage"` in JSON, before anything is scanned — even on
  a run that also passes `--generate-baseline`. Regenerate it once.
- **Exit code 2** when the baseline file is missing, empty or not a baseline,
  or when the generated file cannot be written.

The `column` of a JSON result is a 1-based byte column in its line, and
`file_offset` a 0-based byte offset in the file.

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
      # The monorepo is a dev dependency of composer.json
      # (see the install block at the top of this guide)
      - run: composer install --no-interaction --no-progress
      - run: vendor/bin/regex lint src/ --format=github
```
{: data-file=".github/workflows/regex-lint.yml" data-copy="" }

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
{: data-file=".gitlab-ci.yml" data-copy="" }

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
vendor/bin/regex lint src/ --redos --no-lint --no-validate --no-optimize

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

Every command name is listed in the [command table](#command-overview). An
unknown name prints `Unknown command: <name>`, followed by
`Did you mean "<command>"?` when a command is one or two letters away
(`anlyze`), then the help, and exits with 2:
```bash
# Wrong
vendor/bin/regex check '/test/'

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
- **[ReDoS Guide](redos.md)** - Preventing catastrophic backtracking
- **[Cookbook](../cookbook.md)** - Ready-to-use patterns

New to regex? Start with [the tutorial](../tutorial/README.md), or read
[Regex in PHP](regex-in-php.md) for the PHP fundamentals first.

---

## Self-Update (PHAR Only)

If using the PHAR, update to the latest version:

```bash
regex self-update
```

---

End of CLI guide.
