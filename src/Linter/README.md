<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Linter" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-linter"><img src="https://img.shields.io/packagist/dt/php-regex/regex-linter.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-linter"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-linter.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Linter
===============

Lints the regex patterns of a PHP codebase: extraction from PHP sources, lint rules, ReDoS and validity checks, reports in console, JSON, GitHub, Checkstyle and JUnit formats.

Documentation: [php-regex.com](https://php-regex.com) — the [rule reference](https://php-regex.com/reference/rules/) lists every rule, when it fires and its fix.

Features
--------

* 53 lint rules over the parsed AST: redundant and empty groups and character classes, useless flags and quantifiers, lazy quantifiers that end the pattern, repeats that can match the empty string, suspicious escapes and ranges, undefined backreferences, impossible anchors and word boundaries, anchors that bind to one alternative, possessive repeats and lookaheads that can never match (proven by the automata), multibyte text PCRE reads as bytes without `/u`; every SonarPHP regex rule is mapped to its id in the [SonarPHP Regex Rules](https://php-regex.com/reference/sonar/) page.
* Patterns are validated before they are linted: parse and semantic errors arrive with a position and a tip, not a guess.
* Extraction from PHP sources: native `preg_*` calls, `RegexIterator` and `filter_var()` with `FILTER_VALIDATE_REGEXP`; the composer/pcre, nette/utils, spatie/regex and Laravel `Str` wrappers, PHPUnit's regex assertions, webmozart/assert, beberlei/assert and Symfony's `Regex` constraint; your own helper functions; and a pattern held in a constant, a local variable or a property of the same file, reported where its literal is written — with `@regex-ignore` comments to suppress a finding inline.
* ReDoS detection through php-regex/regex-redos: proven verdicts with their attack input, theoretical or confirmed mode, four severity thresholds, and `regex.lint.redos.search` for an unanchored search that retries a linear attempt along a run, quadratic in PCRE2's interpreter.
* Optimization suggestions through php-regex/regex-optimizer, each rewrite checked for equivalence by the automata solver.
* Five report formats: console, JSON, GitHub annotations, Checkstyle, JUnit.

Installation
------------

```bash
composer require php-regex/regex-linter
```

Requires PHP 8.1+ with the `tokenizer` extension. The extractor runs on it by default; install `nikic/php-parser` to extract from a full PHP parser instead. `symfony/console` is needed only to run the lint from a console command, as the Symfony and Laravel bridges do.

To lint a whole codebase from the terminal, add the console package:

```bash
composer require --dev php-regex/regex-cli
vendor/bin/regex lint src/
```

Configuration
-------------

The lint command reads `regex.dist.json` (the committed defaults), then `regex.json` (local overrides, which win: objects key by key, lists whole) from the project root. This package ships the JSON Schema as `regex.schema.json`, so editors validate the file as you type.

| Key | Default | What it does |
| --- | --- | --- |
| `paths`, `exclude` | working directory, `vendor` | Directories or files to scan, and paths to skip |
| `phpVersion`, `pcreVersion` | from composer.json | The PHP (`"8.3"`, `80300`, `"runtime"`) and PCRE2 (`"10.44"`) releases the patterns are judged for |
| `locale` | the running PHP's | The `LC_CTYPE` locale (`"tr_TR.UTF-8"`) the patterns run under |
| `jobs` | logical CPUs | Parallel workers: the CPUs the process may use, within a container's CPU quota; 1 without `pcntl` |
| `format` | `console` | `console`, `json`, `github`, `checkstyle` or `junit` |
| `ide` | none | `phpstorm`, `vscode`, others, or a URL template — for clickable links |
| `extraction.interop` | every preset | Libraries whose calls carry a pattern: `composer-pcre`, `nette-utils`, `spatie-regex`, `illuminate-support`, `phpunit`, `webmozart-assert`, `beberlei-assert`, `symfony-validator`; a list reads exactly those, `[]` the native calls only, an unknown name is an error that names the preset it most likely means |
| `extraction.functions` | `[]` | Project helpers carrying a pattern, as `App\Support\Str::matches#1` |
| `checks.validation` | `true` | Syntax and semantic validation |
| `checks.redos.enabled` | `false` | ReDoS analysis |
| `checks.redos.mode`, `checks.redos.threshold` | `theoretical`, `high` | Analysis mode, and the lowest severity reported |
| `checks.optimizations.enabled`, `checks.optimizations.minSavings` | `true`, `1` | Optimization suggestions, and the minimum characters saved to report one |
| `checks.optimizations.options` | see the next column | What the optimizer may rewrite: `digits`, `word`, `ranges`, `canonicalizeCharClasses` and `verifyWithAutomata` default to `true`, `possessive` and `factorize` to `false`, and `minQuantifierCount` (at least `2`) to `4` |
| `checks.lint.enabled` | `true` | Lint rules |
| `checks.lint.rules.<id>` | `true` | One boolean per rule id; `anchor.dollarNewline`, `unicode.shorthandWithoutU`, `charclass.single`, `literal.multipleSpaces`, `quantifier.lazyToClass`, `quantifier.uselessLazy`, `lookaround.edgeQuantifier` and `lookaround.redundant` default to `false` |
| `checks.complexity.enabled`, `checks.complexity.threshold` | `false`, `50` | `regex.lint.complexity`, a warning for each pattern whose complexity score reaches the threshold (an integer of at least `1`) |

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

Turn rules on or off by id — the same map `checks.lint.rules` reads from `regex.json`, each id without `regex.lint.` or in full. An id the linter does not run throws `UnknownLintRuleException`, which names the nearest one:

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

A whole codebase, from the terminal — exits non-zero when a pattern does not compile, a confirmed ReDoS verdict reaches `high`, or a rule of error severity fires (the `/u` rules: a multibyte character in a class, a quantified multibyte character, a Unicode property); `--format=github` fits CI:

```bash
vendor/bin/regex lint src/
```
```
  demo.php:4:30
      → /(a+)+b/
    WARN Nested quantifiers can cause catastrophic backtracking.
         ↳ Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.
```

Documentation
-------------

* [Quick start](https://php-regex.com/quick-start/) — the lint command among the first five to know
* [CLI guide](https://php-regex.com/guides/cli/) — every `regex lint` option and the full `regex.json` reference
* [Diagnostics](https://php-regex.com/reference/diagnostics/) — how issues are reported and how to read them
* [ReDoS guide](https://php-regex.com/guides/redos/) — risky shapes, detection modes and mitigations

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released with its siblings under one version number; read [the backward compatibility promise](https://php-regex.com/reference/backward-compatibility/).

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
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
