# Laravel Guide

The service provider registers a `Regex` service (and the `Regex` facade) for
your application and the `php artisan regex:*` commands. Package discovery
enables it on install.

## Installation

```bash
composer require --dev yoeunes/regex-parser
php artisan vendor:publish --tag=regex-parser-config
```

## Configuration

`config/regex-parser.php`, with its defaults:

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
        'directory' => '{storage_path}/framework/cache/regex-parser',
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

## The service and the lint judge for different targets

The `Regex` service runs in your application: it judges patterns for the PHP
running it, and `php_version` / `pcre_version` do not change that.

`regex:lint` judges the patterns of your code for the PHP your project
supports. It picks the target in this order:

1. `php_version` and `pcre_version` in `config/regex-parser.php`;
2. `composer.json` at `base_path()`: `config.platform.php` if set, else the
   lowest version `require.php` allows;
3. the PHP running the command.

Each version is chosen on its own: without `pcre_version`, the lint uses the
PCRE2 release the target PHP bundles (10.40 for PHP 8.2, 10.42 for 8.3, 10.44
for 8.4 and 8.5). The console report shows the target in its header; the JSON
report carries it as a top-level `target` object:

```json
{
    "target": {"php": "8.2", "pcre": "10.40", "source": "config regex-parser.php_version"},
    "stats": {"errors": 0, "warnings": 0, "optimizations": 0},
    "results": []
}
```

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

## Upgrading from 1.x

2.0 renames or removes three keys. `regex:lint` warns about each one it still
finds in your file, with the key to use instead:

| 1.x key                     | 2.0                                   |
|-----------------------------|---------------------------------------|
| `exclude_paths`             | `exclude`                             |
| `analysis.ignore_patterns`  | merged into `redos.ignored_patterns`  |
| `analysis.redos_threshold`  | removed (it was never read)           |

Re-publish the file to pick up the new keys and comments:

```bash
php artisan vendor:publish --tag=regex-parser-config --force
```

See [UPGRADE-2.0.md](../../UPGRADE-2.0.md) for the rest.
