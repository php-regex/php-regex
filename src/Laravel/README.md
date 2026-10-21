<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Laravel" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-laravel"><img src="https://img.shields.io/packagist/dt/php-regex/regex-laravel.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-laravel"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-laravel.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Laravel
================

Laravel integration for PHPRegex: the Regex service and facade, and the regex:lint, regex:routes, regex:explain, regex:compare and regex:transpile commands.

Requires PHP 8.1+ and Laravel 10.37 or later, 11, 12 or 13 (11 and 12 need PHP 8.2, 13 needs PHP 8.3). Laravel 10 and 11 carry a security advisory with no fixed release (PKSA-mdq4-51ck-6kdq), so Composer refuses to install them unless the project's `audit` settings in `composer.json` (`config.audit.block-insecure`, `config.audit.ignore`) allow it.

Documentation: [php-regex.com](https://php-regex.com) — the [Laravel guide](https://php-regex.com/guides/laravel/) walks the integration end to end.

Features
--------

* A `Regex` service and a `Regex` facade, registered by package discovery on install
* A `ValidRegex` rule for a pattern your users submit: valid as `regex validate` judges it, and optionally free of ReDoS
* Five artisan commands: `regex:lint`, `regex:routes`, `regex:explain`, `regex:compare` and `regex:transpile`, with the options of the same commands in the CLI and the Symfony bundle
* `regex:lint` reads your PHP files, your route constraints and your validator rules in one pass, a route constraint
  anchored and flagged as the router matches it
* A `regex:` or `not_regex:` validation rule is read as Laravel reads it: a rules string is split on every `|`, one
  inside the pattern included, so `'code' => 'required|regex:/^(?:a|b)$/'` is linted as the `/^(?:a` Laravel runs (write
  such a rule as an array element); the pattern is linted as written, `{^a$}` between its braces
* In PHP files, `regex:lint` reads the calls `regex lint` reads: `preg_*`, `RegexIterator`, `filter_var()`, Laravel's `Str::match()` and the other wrapper libraries, PHPUnit's regex assertions, and a pattern held in a constant, a variable or a property of the same file
* Reports in console, JSON, GitHub, Checkstyle and JUnit formats, with clickable editor links
* `regex:lint` judges patterns for the PHP your `composer.json` supports, not for the one running it

Installation
------------

```bash
composer require php-regex/regex-laravel
```

Package discovery registers `PHPRegexServiceProvider` and the `Regex` alias — nothing to
add to `config/app.php`. The config file is optional; publish it to edit defaults:

```bash
php artisan vendor:publish --tag=php-regex-config
```

Configuration
-------------

Every option lives in `config/php-regex.php`; unpublished keys take the package default, section by section.

| Option | Default | Role |
|--------|---------|------|
| `max_pattern_length`, `max_lookbehind_length` | `100000`, `255` | Longest pattern string accepted; longest variable-length lookbehind |
| `runtime_pcre_validation` | `false` | The `Regex` service also compiles each pattern with the running PHP |
| `php_version`, `pcre_version` | `null` | PHP version and PCRE2 release `regex:lint` judges for (`"8.2"`, `"10.42"`) |
| `locale` | `null` | `LC_CTYPE` locale `regex:lint` judges under (`"tr_TR.UTF-8"`); `null` for the one of the PHP running the command. The `Regex` service judges under the running PHP's |
| `cache.store`, `cache.directory`, `cache.prefix` | `null`, `'{storage_path}/framework/cache/php-regex'`, `'regex_'` | Cache for parsed patterns: a Laravel store, else this directory |
| `redos.enabled`, `redos.threshold`, `redos.ignored_patterns` | `false`, `'high'`, `[]` | ReDoS analysis on or off, minimum severity reported (`low` to `critical`), patterns skipped |
| `analysis.complexity`, `analysis.warning_threshold` | `false`, `50` | `regex:lint` reports the patterns whose complexity score reaches the threshold, as `regex lint` does under `checks.complexity` |
| `automata.minimization_algorithm`, `automata.determinization_algorithm` | `'hopcroft'`, `'subset-indexed'` | Algorithms behind `regex:compare` |
| `optimizations.*` | see the published file | Default optimization switches for `regex:lint` |
| `paths`, `exclude`, `vendor_paths`, `ide` | `['app']`, `['vendor', 'node_modules', 'storage']`, `null`, `env('PHP_REGEX_IDE', env('APP_EDITOR'))` | What `regex:lint` scans and skips, read from `base_path()` whatever directory it runs from (paths given on the command line are read from the working directory); the libraries whose declared patterns it reads beside the scan paths, the application's `vendor/` by default and none as `[]`; and the editor behind its clickable links |
| `report_unmatched_ignores` | `true` | `regex:lint` reports each `@regex-ignore` marker, or issue identifier it names, that silences nothing, as `regex.ignore.unmatched` |
| `extraction.interop`, `extraction.functions` | `null`, `[]` | The libraries whose calls `regex:lint` reads patterns from (`null` for every preset, those a later release adds included; a list for exactly those; `[]` for the native calls only), and your own helpers carrying a pattern, as `extraction` of `regex.json` |

The package does not read `regex.json`: `extraction` is set here. A name in `extraction.interop` that is no preset stops `regex:lint` with `Invalid config/php-regex.php: Unknown extraction preset "…"`, naming the preset it most likely means.

Usage
-----

```php
use PHPRegex\Laravel\Facades\Regex;

Regex::validate('/^[a-z0-9-]{3,}$/')->isValid; // true

$invalid = Regex::validate('/^(unclosed/');

$invalid->error; // "Expected ) at end of input (found eof)."
echo $invalid->caretSnippet;
// Line 1: ^(unclosed
//                   ^
```

Explain a pattern in plain English:

```php
echo Regex::explain('/^\d{4}$/');
// Regex matches
//   Anchor: the start of the subject
//   Character Type: A digit: [0-9] (exactly 4 times)
//   Anchor: the end of the subject, or just before a newline (\n) that ends it
```

Check one for ReDoS:

```php
$analysis = Regex::redos('/^(a+)+$/');

$analysis->isSafe();                  // false
$analysis->severity->value;           // 'critical'
$analysis->vulnerablePart; // 'a+'
$analysis->headline();                // 'Exponential backtracking (proven)'
$analysis->witness->render();         // '"a" x n . "!"', the input that triggers it
```

Or transpile it for another engine:

```php
$result = Regex::transpile('/^[a-z]+(?=\d)$/i', 'javascript');

$result->constructor; // 'new RegExp("^[a-z]+(?=\\d)(?=\\n?$)", "i")'
```

The facade mirrors the whole service: `create`, `fromOptions`, `parse`, `parseTolerant`, `parsePattern`, `parser`, `validate`,
`analyze`, `redos`, `optimize`, `transpile`, `explain`, `highlight`, `literals`, `captureShape`, `info`,
`compatibility`, `generate`, `tokenize`, `target`, `getCache`, `getCacheStats` and `clearCaches`.

One of those calls bypasses the configured singleton: `Regex::create()` returns a fresh instance, so the cache
store and the ReDoS ignore-list from `config/php-regex.php` do not apply to it.

Validate a pattern your users submit with the `ValidRegex` rule, judged by the same service:

```php
use PHPRegex\Laravel\Rules\ValidRegex;

$request->validate([
    'pattern' => ['required', new ValidRegex()],                  // "/abc)/" fails with "Unmatched closing parenthesis at position 4."
    'route_pattern' => ['required', new ValidRegex(redos: true)], // also refuses a ReDoS severity of high or more ("redosThreshold")
]);
```

`optimize()` and `transpile()` can throw the parse and lexer exception families — on a pattern that does not
parse or does not tokenize, on an option `optimize()` does not know (`InvalidRegexOptionException`), or on an
unknown target or a construct the target cannot express (`TranspileException`).

Artisan commands
----------------

| Command           | Description                                  |
|-------------------|----------------------------------------------|
| `regex:lint`      | Lint the regex patterns of your PHP code     |
| `regex:compare`   | Compare two patterns with automata           |
| `regex:explain`   | Explain a pattern in plain English           |
| `regex:routes`    | List and validate route constraints          |
| `regex:transpile` | Translate a pattern for another regex engine |

```bash
php artisan regex:lint
php artisan regex:lint app/ tests/ --format=json
php artisan regex:routes --validate
php artisan regex:compare '/[0-9]+/' '/\d+/' --method=equivalence
```

Exit codes: 0 when nothing is wrong, 1 when the judged patterns or files have a problem, 2 when an option or the configuration cannot be used.

Documentation
-------------

* [The Laravel guide](https://php-regex.com/guides/laravel/) — configuration, lint targets, the commands, upgrading from 1.x
* [Quick start](https://php-regex.com/quick-start/) — first steps with the `Regex` service
* [The ReDoS guide](https://php-regex.com/guides/redos/) — how the risk analysis reaches its verdicts
* [JSON output](https://php-regex.com/reference/json-output/) — every key of the `regex:lint`, `regex:transpile`, `regex:routes` and `regex:compare` reports
* [Backward compatibility promise](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* The runtime library behind the facade: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).
