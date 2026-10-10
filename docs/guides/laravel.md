---
description: "Wire PHPRegex into a Laravel 12 app: the Regex service and facade, the regex:* artisan commands, lint targets, ReDoS findings and the 1.x upgrade."
---

# Laravel Guide

The service provider registers a `Regex` service (and the `Regex` facade) for
your application and the `php artisan regex:*` commands. Package discovery
enables it on install.

Requires Laravel 12 and PHP 8.2 or later.

{% include install-prerelease.html package="php-regex/regex-laravel" %}

Publishing the configuration is optional: a key missing from your file takes
the package default, so publish it only to customize it:

```bash
# Optional: publish config/php-regex.php
php artisan vendor:publish --tag=php-regex-config
```

## Configuration

`config/php-regex.php`, with its defaults. A `config/regex-parser.php` published
by 1.x is no longer read: publish the new file, move your settings to it and
delete the old one (the provider raises a deprecation while the old one is
there).

```php
return [
    'max_pattern_length' => 100_000,
    'max_lookbehind_length' => 255,
    // Whether the Regex service also compiles every pattern with the running
    // PHP. regex:lint never does.
    'runtime_pcre_validation' => false,
    // The PHP version and PCRE2 release regex:lint judges patterns for.
    'php_version' => null,   // "8.2", "8.2.4" or 80200
    'pcre_version' => null,  // "10.42"
    'cache' => [
        'store' => null,     // a Laravel cache store, used before "directory"
        'directory' => '{storage_path}/framework/cache/php-regex',
        'prefix' => 'regex_',
    ],
    'redos' => [
        'enabled' => false,
        'threshold' => 'high', // low, medium, high or critical, in any case
        'ignored_patterns' => [],
    ],
    'analysis' => [
        'warning_threshold' => 50,
    ],
    'automata' => [
        'minimization_algorithm' => 'hopcroft',          // hopcroft or moore
        'determinization_algorithm' => 'subset-indexed', // subset or subset-indexed
    ],
    'optimizations' => [
        'digits' => true,
        'word' => true,
        'ranges' => true,
        'canonicalize_char_classes' => true,
        'possessive' => false,
        'factorize' => false,
        'min_quantifier_count' => 4,
    ],
    'paths' => ['app'],
    'exclude' => ['vendor', 'node_modules', 'storage'],
    'ide' => env('REGEX_PARSER_IDE', env('APP_EDITOR', null)),
];
```

A key missing from your file takes the package default, inside each section
too: a file published by an older release keeps working after an upgrade.

The `automata` settings are the defaults of `regex:compare`; its
`--minimizer` and `--determinizer` options override them.

An unknown `redos.threshold` stops `regex:lint` with an error naming the
value, and leaves every other artisan command working. `safe` and `unknown`
are not thresholds: they are the verdicts a pattern gets.

## Using the service

The `Regex` facade forwards to the `PHPRegex\Toolkit\Regex` service the
provider registers:

```php
use PHPRegex\Laravel\Facades\Regex;

Regex::validate('/^[a-z0-9-]{3,}$/')->isValid; // true

$invalid = Regex::validate('/^(unclosed/');

$invalid->error; // "Expected ) at end of input (found eof)"
echo $invalid->caretSnippet;
// Line 1: ^(unclosed
//                   ^
```

Explain a pattern in plain English:

```php
echo Regex::explain('/^\d{4}$/');
// Regex matches
//   Anchor: the beginning of a line
//     Character Type: A digit: [0-9] (exactly 4 times)
//   Anchor: the end of a line
```

Check one for ReDoS:

```php
$analysis = Regex::redos('/^(a+)+$/');

$analysis->isSafe();                  // false
$analysis->severity->value;           // 'critical'
$analysis->getVulnerableSubpattern(); // 'a+'
$analysis->headline();                // 'Exponential backtracking (proven)'
$analysis->witness->render();         // '"a" x n . "!"', the input that triggers it
```

The facade mirrors the whole service — `create`, `parse`, `parseTolerant`, `parsePattern`,
`parser`, `validate`, `analyze`, `redos`, `optimize`, `transpile`, `explain`, `highlight`,
`literals`, `captureShape`, `info`, `compatibility`, `generate`, `tokenize`, `target`,
`getCache`, `getCacheStats` and `clearCaches`; [the API reference](../reference/api.md) documents each.
Two of those bypass the configured singleton: `Regex::create()` returns a fresh instance,
and `Regex::tokenize()` never sees instance options. Type-hinting
`PHPRegex\Toolkit\Regex` injects the same service — no facade needed.

## The service and the lint judge for different targets

The `Regex` service runs in your application: it judges patterns for the PHP
running it, and `php_version` / `pcre_version` do not change that.

`regex:lint` judges the patterns of your code for the PHP your project
supports. It picks the target in this order:

1. `php_version` and `pcre_version` in `config/php-regex.php`;
2. `composer.json` at `base_path()`: `config.platform.php` if set, else the
   lowest version `require.php` allows, and then every pattern is also validated on
   the later PHP versions the constraint allows, as
   [the standalone command does](cli.md#several-php-versions);
3. the PHP running the command.

Each version is chosen on its own: without `pcre_version`, the lint uses the
PCRE2 release the target PHP bundles (10.40 for PHP 8.2, 10.42 for 8.3, 10.44
for 8.4 and 8.5). The console report shows the target in its header; the JSON
report carries it as a top-level `target` object:

```json
{
    "target": {"php": "8.2", "pcre": "10.40", "source": "config php-regex.php_version", "range": [{"php": "8.2", "pcre": "10.40"}]},
    "stats": {"errors": 0, "warnings": 0, "optimizations": 0, "redos_errors": 0, "infos": 0, "lint_errors": 0, "parser_fallbacks": 0},
    "results": []
}
```

The report is the one `vendor/bin/regex lint --format=json` prints, and
`regex:transpile --format=json` prints the same document as
`vendor/bin/regex transpile`; the [JSON output reference](../reference/json-output.md)
lists every key.

`runtime_pcre_validation` compiles with the PHP that runs, which cannot tell
whether an older PHP accepts a pattern: the lint never uses it.

## Commands

| Command           | Description                                  |
|-------------------|----------------------------------------------|
| `regex:lint`      | Lint the regex patterns of your PHP code     |
| `regex:compare`   | Compare two patterns with automata           |
| `regex:explain`   | Explain a pattern in plain English           |
| `regex:routes`    | Detect route conflicts                       |
| `regex:transpile` | Translate a pattern for another regex engine |

```bash
php artisan regex:lint
php artisan regex:lint app/ --format=json
php artisan regex:compare '/[0-9]+/' '/\d+/'
```

Each command exits with 0 when it found nothing wrong, 1 when the patterns or
the files it judged have a problem, and 2 when an option or the configuration
cannot be used (see [the CLI guide](cli.md#exit-codes)).

`regex:lint` reads the functions marked `#[RegexPattern]` (or PhpStorm's
`#[Language('RegExp')]`) in the configured `paths`, with `exclude` where they are linted, the
linted paths when `paths` names none, and in the application's `vendor/`,
whatever `exclude` says; a project declaration wins over a copy in
`vendor/`. A call to one is linted as a `preg_*()` call (see
[the CLI guide](cli.md#patterns-behind-a-wrapper)); every rule it can report
is catalogued in [Lint rules](../reference/rules.md).

## ReDoS findings

With `'redos' => ['enabled' => true]`, `regex:lint` adds the ReDoS issue,
`regex.lint.redos`, to the findings of each pattern at or above
`redos.threshold`:

```
  ./app/Validator.php:7:33
      → /^(\w+\s?)+$/
     ...
     WARN  Exponential backtracking (proven). Severity: CRITICAL, confidence: MEDIUM.
         ↳ Attack: "0" x n . "!" Unbounded quantifier detected. May cause backtracking on non-matching input. ...

  ./app/Validator.php:12:33
      → /\d*\d*\d*$/
     ...
     WARN  Polynomial backtracking, degree 3 (proven). Severity: HIGH, confidence: MEDIUM.
         ↳ Attack: "0" x n . "!" Adjacent quantified tokens with overlapping character sets can cause ambiguous backtracking ...
```

`regex:explain` ends its explanation with a security warning when the pattern
is at risk, and prints nothing more for a safe one:

```bash
php artisan regex:explain '/(a+)+$/'
```

```
Security Warning:
  Exponential backtracking (proven)
  Severity: critical
  Attack: "a" x n . "!"
  Vulnerable part: a+
  Recommendations:
    - Unbounded quantifier detected. May cause backtracking on non-matching input. ...
    - Nested unbounded quantifiers detected. This allows exponential backtracking. ...
```

The first line is the verdict's headline, then its severity; `Attack` is the input that drives
the worst case, as PHP: `str_repeat("a", $n) . "!"`. A pattern the structural
heuristics judge, such as one with a backreference, shows `Potential
backtracking (heuristic)` and no attack. The [ReDoS guide](redos.md)
explains each verdict.

## Upgrading from 1.x

2.0 renames or removes three keys. `regex:lint` warns about each one it still
finds in your file, with the key to use instead:

| 1.x key                     | 2.0                                   |
|-----------------------------|---------------------------------------|
| `exclude_paths`             | `exclude`                             |
| `analysis.ignore_patterns`  | merged into `redos.ignored_patterns`  |
| `analysis.redos_threshold`  | removed (it was never read)           |

`redos.enabled` now makes `regex:lint` report ReDoS findings: 1.x never ran
the analysis, whatever the setting. Expect new warnings on the first run.

Re-publish the file to pick up the new keys and comments:

```bash
php artisan vendor:publish --tag=php-regex-config --force
```

See [UPGRADE-2.0.md](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) for the rest.
