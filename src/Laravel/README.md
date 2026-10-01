<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.svg?v=1">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.svg?v=1">
        <img src="art/banner.svg?v=1" alt="PHPRegex Laravel" width="100%">
    </picture>
</p>

PHPRegex Laravel
================

Laravel integration for PHPRegex: the Regex service and facade, and the regex:lint, regex:routes, regex:explain, regex:compare and regex:transpile commands.

Requires PHP 8.2+, Laravel 12.

Features
--------

* A `Regex` service and a `Regex` facade, registered by package discovery on install
* Five artisan commands: `regex:lint`, `regex:routes`, `regex:explain`, `regex:compare` and `regex:transpile`
* `regex:lint` reads your PHP files, your route constraints and your validator rules in one pass
* Reports in console, JSON, GitHub, checkstyle and JUnit formats, with clickable editor links
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
| `cache.store`, `cache.directory`, `cache.prefix` | `null`, `'{storage_path}/framework/cache/php-regex'`, `'regex_'` | Cache for parsed patterns: a Laravel store, else this directory |
| `redos.enabled`, `redos.threshold`, `redos.ignored_patterns` | `false`, `'high'`, `[]` | ReDoS analysis on or off, minimum severity reported (`low` to `critical`), patterns skipped |
| `analysis.warning_threshold` | `50` | Complexity score above which a warning is emitted |
| `automata.minimization_algorithm`, `automata.determinization_algorithm` | `'hopcroft'`, `'subset-indexed'` | Algorithms behind `regex:compare` |
| `optimizations.*` | see the published file | Default optimization switches for `regex:lint` |
| `paths`, `exclude`, `ide` | `['app']`, `['vendor', 'node_modules', 'storage']`, `env('REGEX_PARSER_IDE', env('APP_EDITOR'))` | What `regex:lint` scans and skips, and the editor behind its clickable links |

Usage
-----

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
```

Or transpile it for another engine:

```php
$result = Regex::transpile('/^[a-z]+(?=\d)$/i', 'javascript');

$result->constructor; // 'new RegExp("^[a-z]+(?=\d)$", "i")'
```

The facade also exposes `parse`, `parseTolerant`, `analyze`, `optimize`, `highlight`, `literals`, `generate` and `parsePattern`.

Artisan commands
----------------

| Command           | Description                                  |
|-------------------|----------------------------------------------|
| `regex:lint`      | Lint the regex patterns of your PHP code     |
| `regex:compare`   | Compare two patterns with automata           |
| `regex:explain`   | Explain a pattern in plain English           |
| `regex:routes`    | Detect route conflicts                       |
| `regex:transpile` | Translate a pattern for another regex engine |

```bash
php artisan regex:lint
php artisan regex:lint app/ tests/ --format=json
php artisan regex:routes --validate
```

Exit codes: 0 when nothing is wrong, 1 when the judged patterns or files have a problem, 2 when an option or the configuration cannot be used.

Documentation
-------------

* [The Laravel guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/laravel.md) — configuration, lint targets, the commands, upgrading from 1.x
* [Quick start](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) — first steps with the `Regex` service
* [The ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — how the risk analysis reaches its verdicts
* [Backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — what stays stable across releases

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
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
