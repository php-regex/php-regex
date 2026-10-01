<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Symfony" width="100%">
    </picture>
</p>

PHPRegex Symfony
================

Symfony bundle for PHPRegex: the Regex service and the regex:lint, regex:routes, regex:security, regex:analyze, regex:compare and regex:transpile commands.

Features
--------

* A `Regex` service, autowired as `PHPRegex\Toolkit\Regex`, to validate, parse, optimize, transpile and ReDoS-check patterns from your own code
* Six console commands: `regex:lint`, `regex:routes`, `regex:security`, `regex:analyze`, `regex:compare` and `regex:transpile`
* `regex:lint` reads your PHP files, your route requirements and your validator constraints in one pass
* Reports in console, JSON, GitHub, Checkstyle and JUnit formats, with clickable editor links
* `regex:lint` judges patterns for the PHP your `composer.json` supports, not for the one running it
* `regex:routes` and `regex:security` analyze route conflicts, `access_control` ordering and firewall regexes, including their ReDoS risk

Installation
------------

```bash
composer require php-regex/regex-symfony
```

Requires PHP 8.2+ and Symfony 7.4+ or 8.0+. `symfony/routing`, `symfony/validator` and `nikic/php-parser` are optional and widen what `regex:lint` reads.

Configuration
-------------

Every key is optional and lives under `php_regex` in `config/packages/php_regex.yaml`:

| Option | Default | Role |
|--------|---------|------|
| `max_pattern_length`, `max_lookbehind_length` | `100000`, `255` | Longest pattern string; longest variable-length lookbehind |
| `runtime_pcre_validation` | `false` | The service also compiles each pattern with the running PHP |
| `php_version`, `pcre_version` | `null` | PHP version and PCRE2 release `regex:lint` judges for (`"8.2"`, `"10.42"`) |
| `cache.pool`, `cache.directory`, `cache.prefix` | `null`, `'%kernel.cache_dir%/php_regex'`, `'regex_'` | Cache for parsed ASTs: a PSR-6 pool, else this directory |
| `extractor_service` | `null` | Service id of a custom pattern extractor |
| `redos.enabled`, `redos.threshold`, `redos.ignored_patterns` | `false`, `'high'`, `[]` | ReDoS analysis on or off, minimum severity reported, patterns skipped |
| `analysis.warning_threshold` | `50` | Complexity score above which a warning is emitted |
| `automata.minimization_algorithm`, `automata.determinization_algorithm` | `'hopcroft'`, `'subset-indexed'` | Algorithms behind the automata comparisons |
| `optimizations.*` | `digits`, `word`, `ranges`, `canonicalize_char_classes` `true`; `possessive`, `factorize` `false`; `min_quantifier_count` `4` | Default optimization switches for `regex:lint` |
| `paths`, `exclude` | `['src']`, `['vendor']` | Directories `regex:lint` scans, and skips |
| `ide` | `'%env(default::SYMFONY_IDE)%'` | IDE behind the clickable links; falls back to `framework.ide` |

Usage
-----

Inject `PHPRegex\Toolkit\Regex` where you need it — the container builds it from the configuration above:

```php
$regex = Regex::create(); // what the container injects
$regex->validate('/^[a-z0-9-]{3,}$/')->isValid; // true

$invalid = $regex->validate('/^(unclosed/');
$invalid->error; // "Expected ) at end of input (found eof)"
```

Check one for ReDoS:

```php
$analysis = $regex->redos('/^(a+)+$/');
$analysis->isSafe();                  // false
$analysis->severity->value;           // 'critical'
$analysis->getVulnerableSubpattern(); // 'a+'
$analysis->headline();                // 'Exponential backtracking (proven)'
$analysis->witness->render();         // '"a" x n . "!"', the input that triggers it
```

Optimize a pattern:

```php
$optimized = $regex->optimize('/[0-9]{4}-[0-9]{2}/');
echo $optimized->optimized; // /\d{4}-\d{2}/
```

Or transpile it for another engine:

```php
$transpiled = $regex->transpile('/^[a-z]+(?=\d)$/i', 'js');
echo $transpiled->literal;     // /^[a-z]+(?=\d)$/i
echo $transpiled->constructor; // new RegExp("^[a-z]+(?=\\d)$", "i")
```

The service also exposes `parse`, `parseTolerant`, `analyze`, `explain`, `highlight`, `literals`, `generate` and `parsePattern`.

Integration
-----------

Register the bundle in `config/bundles.php`:

```php
return [
    PHPRegex\Symfony\PHPRegexBundle::class => ['dev' => true, 'test' => true],
];
```

| Command           | Description                                            |
|-------------------|--------------------------------------------------------|
| `regex:lint`      | Lint, validate and optimize the patterns in your code  |
| `regex:routes`    | Detect route requirement conflicts and overlaps        |
| `regex:security`  | Analyze `access_control` ordering and firewall regexes |
| `regex:analyze`   | Run the routes and security analyzers in one pass      |
| `regex:compare`   | Compare two patterns with automata logic               |
| `regex:transpile` | Translate a pattern for another regex engine           |

```bash
bin/console regex:lint src/ --format=json
bin/console regex:routes --show-overlaps
bin/console regex:analyze --only=routes --redos-threshold=medium
bin/console regex:transpile '/^\d{4}$/' --target=python
```

Exit codes: 0 when nothing is wrong, 1 when the judged patterns or files have a problem, 2 when an option or the configuration cannot be used.

Documentation
-------------

* [The Symfony guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/symfony.md) — configuration, lint targets, the commands, upgrading from 1.x
* [The CLI guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/cli.md) — `regex:lint` in depth, exit codes
* [The ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — how the risk analysis reaches its verdicts
* [Backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — what stays stable across releases

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The runtime library behind the service: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).
