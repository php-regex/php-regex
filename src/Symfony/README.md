<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Symfony" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-symfony"><img src="https://img.shields.io/packagist/dt/php-regex/regex-symfony.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-symfony"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-symfony.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Symfony
================

Symfony bundle for PHPRegex: the Regex service and the regex:lint, regex:routes, regex:security, regex:analyze, regex:compare, regex:explain and regex:transpile commands.

Documentation: [php-regex.com](https://php-regex.com) — the [Symfony guide](https://php-regex.com/guides/symfony/) walks the bundle end to end.

Features
--------

* A `Regex` service, autowired as `PHPRegex\Toolkit\Regex`, to validate, parse, optimize, transpile and ReDoS-check patterns from your own code
* A `ValidRegex` constraint for a pattern your users submit: valid as `regex validate` judges it, and optionally free of ReDoS
* Seven console commands: `regex:lint`, `regex:routes`, `regex:security`, `regex:analyze`, `regex:compare`, `regex:explain` and `regex:transpile`, with the options of the same commands in the CLI and the Laravel package
* `regex:lint` reads your PHP files, your route requirements and the `Regex` constraints of your validator mapping files (`config/validator/*.yaml`, `*.xml`) in one pass, each reported at the line that writes it
* In PHP files, `regex:lint` reads the calls `regex lint` reads: `preg_*`, `RegexIterator`, `filter_var()`, `new Regex()` and `#[Assert\Regex]`, the wrapper libraries, PHPUnit's regex assertions, and a pattern held in a constant, a variable or a property of the same file
* Reports in console, JSON, GitHub, Checkstyle and JUnit formats, with clickable editor links
* `regex:lint` judges patterns for the PHP your `composer.json` supports, not for the one running it
* `regex:routes` and `regex:security` analyze route conflicts, `access_control` ordering and firewall regexes, including their ReDoS risk

Installation
------------

```bash
composer require php-regex/regex-symfony
```

Requires PHP 8.1+ and Symfony 6.4, 7.4 or 8.x. `symfony/routing`, `symfony/validator` and `nikic/php-parser` are optional and widen what `regex:lint` reads; `symfony/validator` also brings the `ValidRegex` constraint.

Configuration
-------------

Every key is optional and lives under `php_regex` in `config/packages/php_regex.yaml`:

| Option | Default | Role |
|--------|---------|------|
| `max_pattern_length`, `max_lookbehind_length` | `100000`, `255` | Longest pattern string; longest variable-length lookbehind |
| `runtime_pcre_validation` | `false` | The service also compiles each pattern with the running PHP |
| `php_version`, `pcre_version` | `null` | PHP version and PCRE2 release `regex:lint` judges for (`"8.2"`, `"10.42"`) |
| `locale` | `null` | `LC_CTYPE` locale `regex:lint` judges under (`"tr_TR.UTF-8"`); `null` for the one of the PHP running the command. The `php_regex.regex` service judges under the running PHP's |
| `cache.pool`, `cache.directory`, `cache.prefix` | `null`, `'%kernel.cache_dir%/php_regex'`, `'regex_'` | Cache for parsed ASTs: a PSR-6 pool, else this directory |
| `extractor_service` | `null` | Service id of a custom pattern extractor |
| `extraction.interop`, `extraction.functions` | every preset, `[]` | The libraries whose calls `regex:lint` reads patterns from (a list for exactly those, `[]` for the native calls only), and your own helpers carrying a pattern, as `extraction` of `regex.json`; unused with `extractor_service` |
| `redos.enabled`, `redos.threshold`, `redos.ignored_patterns` | `false`, `'high'`, `[]` | ReDoS analysis on or off, minimum severity reported, patterns skipped |
| `analysis.complexity`, `analysis.warning_threshold` | `false`, `50` | `regex:lint` reports the patterns whose complexity score reaches the threshold, as `regex lint` does under `checks.complexity` |
| `automata.minimization_algorithm`, `automata.determinization_algorithm` | `'hopcroft'`, `'subset-indexed'` | Algorithms behind the automata comparisons |
| `optimizations.*` | `digits`, `word`, `ranges`, `canonicalize_char_classes` `true`; `possessive`, `factorize` `false`; `min_quantifier_count` `4` | Default optimization switches for `regex:lint` |
| `paths`, `exclude` | `['src']`, `['vendor']` | Directories `regex:lint` scans, and skips, read from `%kernel.project_dir%` whatever directory it runs from; paths given on the command line are read from the working directory |
| `report_unmatched_ignores` | `true` | `regex:lint` reports each `@regex-ignore` marker, or issue identifier it names, that silences nothing, as `regex.ignore.unmatched` |
| `ide` | `null` | IDE behind the clickable links (`phpstorm`, `vscode` or a URL template); `null` for the one FrameworkBundle links to, `framework.ide`, which defaults to the `SYMFONY_IDE` environment variable |

The bundle does not read `regex.json`: `extraction` is set here. A later configuration file's `extraction.interop` or `extraction.functions` list replaces the earlier one, where `paths` and `exclude` add up. A name in `extraction.interop` that is no preset is a configuration error, raised as the container is built, which names the preset it most likely means.

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
$analysis->vulnerablePart; // 'a+'
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
echo $transpiled->literal;     // /^[a-z]+(?=\d)(?=\n?$)/i
echo $transpiled->constructor; // new RegExp("^[a-z]+(?=\\d)(?=\\n?$)", "i")
```

The service also exposes `parse`, `parseTolerant`, `analyze`, `explain`, `highlight`, `literals`, `generate` and `parsePattern`.

Validate a pattern your users submit with the `ValidRegex` constraint (`symfony/validator`), judged by the same service:

```php
use PHPRegex\Symfony\Validator\Constraints\ValidRegex;

#[ValidRegex]                  // "/abc)/": This value is not a valid regular expression: Unmatched closing parenthesis at position 4.
public string $pattern;

#[ValidRegex(redos: true)]     // also refuses a ReDoS severity of high or more ("redosThreshold")
public string $routePattern;
```

Its messages are translation keys of the `validators` domain: the bundle ships their English catalogue, and your `translations/validators.<locale>.*` files translate them.

Integration
-----------

Register the bundle in `config/bundles.php`:

```php
return [
    PHPRegex\Symfony\PHPRegexBundle::class => ['all' => true],
];
```

Code that injects `PHPRegex\Toolkit\Regex` needs the bundle in every environment. A `['dev' => true, 'test' => true]` registration, for the commands alone, leaves no `Regex` service in prod and needs its configuration under `when@dev:` (and `when@test:`): a top-level `php_regex` key in prod has no extension to load it.

| Command           | Description                                            |
|-------------------|--------------------------------------------------------|
| `regex:lint`      | Lint, validate and optimize the patterns in your code  |
| `regex:routes`    | Detect route requirement conflicts and overlaps        |
| `regex:security`  | Analyze `access_control` ordering and firewall regexes |
| `regex:analyze`   | Run the routes and security analyzers in one pass      |
| `regex:compare`   | Compare two patterns with automata logic               |
| `regex:explain`   | Explain a pattern in plain English                     |
| `regex:transpile` | Translate a pattern for another regex engine           |

```bash
bin/console regex:lint src/ --format=json
bin/console regex:routes --show-overlaps
bin/console regex:analyze --only=routes --redos-threshold=medium
bin/console regex:compare '/[a-z]+/' '/edit/' --method=subset
bin/console regex:transpile '/^\d{4}$/' --target=python
```

`regex:security` and `regex:analyze` read the security configuration your application runs with, as `bin/console debug:config security` shows it, whatever the format of its files and with its parameters resolved; `--config=<file>` analyzes one YAML file instead, which needs `symfony/yaml`.

Exit codes: 0 when nothing is wrong, 1 when the judged patterns or files have a problem, 2 when an option or the configuration cannot be used.

Documentation
-------------

* [The Symfony guide](https://php-regex.com/guides/symfony/) — configuration, lint targets, the commands, upgrading from 1.x
* [The CLI guide](https://php-regex.com/guides/cli/) — `regex:lint` in depth, exit codes
* [The ReDoS guide](https://php-regex.com/guides/redos/) — how the risk analysis reaches its verdicts
* [JSON output](https://php-regex.com/reference/json-output/) — every key of the `regex:lint`, `regex:transpile` and `regex:analyze` reports
* [Backward compatibility promise](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
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
