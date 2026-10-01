<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PHPRegex\Toolkit\Regex;

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Pattern Length
    |--------------------------------------------------------------------------
    |
    | The maximum allowed length for a regex pattern string to parse.
    | This helps prevent denial of service attacks with extremely long patterns.
    |
    */
    'max_pattern_length' => Regex::DEFAULT_MAX_PATTERN_LENGTH,

    /*
    |--------------------------------------------------------------------------
    | Maximum Lookbehind Length
    |--------------------------------------------------------------------------
    |
    | The maximum length of a variable-length lookbehind, such as
    | (?<=a{1,300}). A fixed-length lookbehind is only limited by PCRE's own
    | ceiling of 65535 characters.
    |
    */
    'max_lookbehind_length' => Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH,

    /*
    |--------------------------------------------------------------------------
    | Runtime PCRE Validation
    |--------------------------------------------------------------------------
    |
    | Whether the Regex service also compiles every pattern with the running
    | PHP (preg_match compile check), on top of its own validation. Off by
    | default: it compiles each pattern twice. regex:lint never does it: it
    | judges for php_version / pcre_version below.
    |
    */
    'runtime_pcre_validation' => false,

    /*
    |--------------------------------------------------------------------------
    | Target PHP and PCRE2
    |--------------------------------------------------------------------------
    |
    | The PHP version ("8.2", "8.2.4" or 80200) and the PCRE2 release
    | ("10.42") regex:lint judges patterns for. Left null, the PHP version is
    | the lowest one composer.json allows (base_path('composer.json')), else
    | the running PHP, and the PCRE2 release is the one that PHP bundles.
    | The Regex service always judges for the running PHP: it is the one the
    | application runs on.
    |
    */
    'php_version' => null,

    'pcre_version' => null,

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache configuration for storing parsed regex patterns.
    |
    | Supported options:
    | - store: Laravel cache store name (e.g., 'file', 'redis', 'memcached')
    | - directory: Filesystem directory for caching (used if store is null)
    | - prefix: Cache key prefix
    |
    */
    'cache' => [
        'store' => null,
        'directory' => '{storage_path}/framework/cache/php-regex',
        'prefix' => 'regex_',
    ],

    /*
    |--------------------------------------------------------------------------
    | ReDoS Analysis
    |--------------------------------------------------------------------------
    |
    | Configuration for ReDoS (Regular Expression Denial of Service)
    | vulnerability analysis.
    |
    */
    'redos' => [
        /*
        | Enable ReDoS vulnerability analysis. Disabled by default for
        | performance; enable explicitly when needed.
        */
        'enabled' => false,

        /*
        | Minimum ReDoS severity to report: low, medium, high or critical, in
        | any case. Another value stops regex:lint with an error.
        */
        'threshold' => 'high',

        /*
        | Patterns, fragments or full regexes to skip in the risk analysis.
        */
        'ignored_patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for regex complexity analysis.
    |
    */
    'analysis' => [
        /*
        | Complexity score above which a warning is emitted.
        */
        'warning_threshold' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automata Settings
    |--------------------------------------------------------------------------
    |
    | The algorithms regex:compare uses unless its options say otherwise.
    |
    */
    'automata' => [
        /*
        | DFA minimization strategy for automata comparisons.
        | Options: hopcroft, moore
        */
        'minimization_algorithm' => 'hopcroft',

        /*
        | NFA determinization strategy for automata comparisons.
        | Options: subset, subset-indexed
        */
        'determinization_algorithm' => 'subset-indexed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Optimization Options
    |--------------------------------------------------------------------------
    |
    | Default optimization options for regex:lint command.
    |
    */
    'optimizations' => [
        /*
        | Optimize digit character classes (e.g., [0-9] -> \d).
        */
        'digits' => true,

        /*
        | Optimize word character classes (e.g., [A-Za-z0-9_] -> \w).
        */
        'word' => true,

        /*
        | Allow range merging inside character classes.
        */
        'ranges' => true,

        /*
        | Normalize character class order and deduplicate elements.
        */
        'canonicalize_char_classes' => true,

        /*
        | Enable auto-possessive quantifier optimizations.
        */
        'possessive' => false,

        /*
        | Enable alternation factorization optimizations.
        */
        'factorize' => false,

        /*
        | Minimum repeated quantifier count before collapsing (e.g., aaaa -> a{4}).
        */
        'min_quantifier_count' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan Paths
    |--------------------------------------------------------------------------
    |
    | Directories to scan for regex patterns.
    |
    */
    'paths' => ['app'],

    /*
    |--------------------------------------------------------------------------
    | Exclude Paths
    |--------------------------------------------------------------------------
    |
    | Directories regex:lint does not scan.
    |
    */
    'exclude' => ['vendor', 'node_modules', 'storage'],

    /*
    |--------------------------------------------------------------------------
    | IDE Integration
    |--------------------------------------------------------------------------
    |
    | IDE shorthand (vscode, phpstorm, etc.) or custom URL template for
    | clickable links.
    |
    | Examples:
    | - 'vscode'
    | - 'phpstorm'
    | - 'phpstorm://open?file=%file%&line=%line%&column=%column%'
    |
    */
    'ide' => env('REGEX_PARSER_IDE', env('APP_EDITOR', null)),
];
