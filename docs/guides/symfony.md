# Symfony Bundle Guide

The bundle registers a `Regex` service for your application and the
`bin/console regex:*` commands. Its configuration lives under the
`php_regex` key.

## Installation

```bash
composer require --dev php-regex/regex-symfony
```

```php
// config/bundles.php
return [
    // ...
    PHPRegex\Symfony\PHPRegexBundle::class => ['dev' => true, 'test' => true],
];
```

## Configuration

Every key is optional; the values below are the defaults.

```yaml
# config/packages/php_regex.yaml
php_regex:
    max_pattern_length: 100000
    max_lookbehind_length: 255
    # Whether the php_regex.regex service also compiles every pattern
    # with the running PHP. regex:lint never does.
    runtime_pcre_validation: false
    # The PHP version and PCRE2 release regex:lint judges patterns for.
    php_version: null      # "8.2", "8.2.4" or 80200
    pcre_version: null     # "10.42"
    cache:
        pool: null         # a PSR-6 pool service id, used before "directory"
        directory: '%kernel.cache_dir%/php_regex'
        prefix: regex_
    extractor_service: null
    redos:
        enabled: false
        threshold: high    # low, medium, high or critical, in any case
        ignored_patterns: []
    analysis:
        warning_threshold: 50
    automata:
        minimization_algorithm: hopcroft         # hopcroft or moore
        determinization_algorithm: subset-indexed # subset or subset-indexed
    optimizations:
        digits: true
        word: true
        ranges: true
        canonicalize_char_classes: true
        possessive: false
        factorize: false
        min_quantifier_count: 4
    paths: [src]
    exclude: [vendor]
    ide: '%env(default::SYMFONY_IDE)%'
```

A value the bundle cannot read stops the container compile with the key
named: an unknown ReDoS threshold, a `php_version` that is no version, a
`pcre_version` that is no release. `safe` and `unknown` are not thresholds:
they are the verdicts a pattern gets.

`redos.ignored_patterns` lists patterns, fragments of patterns or whole
regexes the risk analysis skips, such as the requirement constants of
Symfony routes.

## The service and the lint judge for different targets

The `php_regex.regex` service (autowired as `PHPRegex\Toolkit\Regex`) runs in
your application: it judges patterns for the PHP running it, and
`php_version` / `pcre_version` do not change that.

`regex:lint` judges the patterns of your code for the PHP your project
supports. It picks the target in this order:

1. `php_regex.php_version` and `php_regex.pcre_version`;
2. `composer.json` in `%kernel.project_dir%`: `config.platform.php` if set,
   else the lowest version `require.php` allows;
3. the PHP running the command.

Each version is chosen on its own: without `pcre_version`, the lint uses the
PCRE2 release the target PHP bundles (10.40 for PHP 8.2, 10.42 for 8.3, 10.44
for 8.4 and 8.5). A `regex.json` in the project is the configuration of the
standalone `vendor/bin/regex` command, not of the bundle.

The console report shows the target in its header. The JSON report carries it
as a top-level `target` object:

```json
{
    "target": {"php": "8.2", "pcre": "10.40", "source": "php_regex.php_version"},
    "stats": {"errors": 0, "warnings": 0, "optimizations": 0},
    "results": []
}
```

`source` names where each version came from: `php_regex.php_version`,
`composer.json require.php`, `composer.json config.platform.php` or
`running PHP`, followed by `php_regex.pcre_version` when the PCRE2 release
came from there.

`runtime_pcre_validation` compiles with the PHP that runs, which cannot tell
whether an older PHP accepts a pattern: the lint never uses it, whatever the
bundle says.

## Commands

| Command          | Description                                          |
|------------------|------------------------------------------------------|
| `regex:lint`     | Lint the regex patterns of your PHP code             |
| `regex:compare`  | Compare two patterns with automata                   |
| `regex:routes`   | Detect route conflicts and overlaps                  |
| `regex:security` | Analyze access control ordering and firewall regexes |
| `regex:analyze`  | Run the routes and security analyzers                |
| `regex:transpile`| Translate a pattern for another regex engine         |

```bash
bin/console regex:lint
bin/console regex:lint src/ --format=json
bin/console regex:analyze --redos-threshold=medium
```

`--redos-threshold` takes the same values as `redos.threshold`, in any case.

Each command exits with 0 when it found nothing wrong, 1 when the patterns or
the files it judged have a problem, and 2 when an option or the configuration
cannot be used (see [the CLI guide](cli.md#exit-codes)).

## Upgrading from 1.x

See [UPGRADE-2.0.md](../../UPGRADE-2.0.md): `exclude_paths` is now `exclude`,
`analysis.ignore_patterns` is merged into `redos.ignored_patterns`, and
`analysis.redos_threshold` is gone. A 1.x key stops the container compile with
the key that replaces it.
