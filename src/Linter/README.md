<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Linter" width="100%">
    </picture>
</p>

PHPRegex Linter
===============

Lints the regex patterns of a PHP code base: extraction from PHP sources, lint rules, ReDoS and validity checks, reports in console, JSON, GitHub, Checkstyle and JUnit formats.

Features
--------

* 28 lint rules over the parsed AST: redundant groups and character classes, useless flags and quantifiers, suspicious escapes and ranges, undefined backreferences, impossible anchors.
* Patterns are validated before they are linted: parse and semantic errors arrive with a position and a tip, not a guess.
* Extraction from PHP sources: native `preg_*` calls, the composer/pcre, nette/utils, spatie/regex and Laravel `Str` wrappers, and your own helper functions — with `@regex-ignore` comments to suppress a finding inline.
* ReDoS detection through php-regex/regex-redos: theoretical or confirmed mode, four severity thresholds.
* Optimization suggestions through php-regex/regex-optimizer, each rewrite checked for equivalence by the automata solver.
* Five report formats: console, JSON, GitHub annotations, Checkstyle, JUnit.

Installation
------------

```bash
composer require php-regex/regex-linter
```

Requires PHP 8.2+. The extractor runs on the PHP tokenizer by default; install `nikic/php-parser` to extract from a full PHP parser instead.

To lint a whole code base from the terminal, add the console package:

```bash
composer require --dev php-regex/regex-cli
vendor/bin/regex lint src/
```

Configuration
-------------

The lint command reads a `regex.json` (committed) or `regex.dist.json` (template) from the project root. This package ships the JSON Schema as `regex.schema.json`, so editors validate the file as you type.

| Key | Default | What it does |
| --- | --- | --- |
| `paths`, `exclude` | working directory, `vendor` | Directories or files to scan, and paths to skip |
| `phpVersion`, `pcreVersion` | from composer.json | The PHP (`"8.3"`, `80300`, `"runtime"`) and PCRE2 (`"10.44"`) releases the patterns are judged for |
| `jobs` | CPU cores | Parallel workers |
| `format` | `console` | `console`, `json`, `github`, `checkstyle` or `junit` |
| `ide` | none | `phpstorm`, `vscode`, others, or a URL template — for clickable links |
| `extraction.interop` | `["composer-pcre"]` | Wrapper presets to read: `composer-pcre`, `nette-utils`, `spatie-regex`, `laravel-str` |
| `extraction.functions` | `[]` | Project helpers carrying a pattern, as `App\Support\Str::matches#1` |
| `checks.validation` | `true` | Syntax and semantic validation |
| `checks.redos.enabled` | `false` | ReDoS analysis |
| `checks.redos.mode`, `checks.redos.threshold` | `theoretical`, `high` | Analysis mode, and the lowest severity reported |
| `checks.optimizations.enabled`, `checks.optimizations.minSavings` | `true`, `1` | Optimization suggestions, and the minimum characters saved to report one |
| `checks.lint.enabled` | `true` | Lint rules |
| `checks.lint.rules.<id>` | `true` | One boolean per rule id; `unicode.shorthandWithoutU` defaults to `false` |

Usage
-----

`PatternLinter` is a node visitor: run it on any parsed AST.

```php
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\RegexParser;

$linter = new PatternLinter();
RegexParser::create()->parse('/(a+)+b/')->accept($linter);

foreach ($linter->getIssues() as $violation) {
    echo $violation->id, ': ', $violation->message, "\n";
}
```
```
regex.lint.quantifier.nested: Nested quantifiers can cause catastrophic backtracking.
regex.lint.group.quantifiedCapture: Quantified capturing group "(...)" with "+": only the last iteration's capture is retained.
```

Turn rules on or off by id — the same map `checks.lint.rules` reads from `regex.json`:

```php
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\RegexParser;

$linter = new PatternLinter([
    'quantifier.nested' => false,        // disable one rule
    'unicode.shorthandWithoutU' => true, // enable the opt-in rule
]);
RegexParser::create()->parse('/^\w+\d*$/')->accept($linter);

foreach ($linter->getIssues() as $violation) {
    echo $violation->id, ': ', $violation->message, "\n";
}
```
```
regex.lint.quantifier.concatenation: Concatenated quantifiers can be optimized when one character set is a subset of the other.
regex.lint.unicode.shorthandWithoutU: Shorthand "\w" matches only ASCII without /u flag.
regex.lint.unicode.shorthandWithoutU: Shorthand "\d" matches only ASCII without /u flag.
```

A whole code base, from the terminal — exits non-zero when at least one error is found, `--format=github` fits CI:

```bash
vendor/bin/regex lint src/
```
```
  demo.php:4:30
      → /(a+)+b/
    WARN Nested quantifiers can cause catastrophic backtracking.
         ↳ Consider using atomic groups (?>...) or possessive quantifiers.
    TIP
         - /(a+)+b/
         + /(?>(a+))+b/
```

Documentation
-------------

* [Quick start](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) — the lint command among the first five to know
* [CLI guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/cli.md) — every `regex lint` option and the full `regex.json` reference
* [Diagnostics](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/diagnostics.md) — how issues are reported and how to read them
* [ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — risky shapes, detection modes and mitigations

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released with its siblings under one version number; read [the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The console that drives this linter: [regex-cli](https://github.com/php-regex/php-regex/tree/2.x/src/Cli)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).
