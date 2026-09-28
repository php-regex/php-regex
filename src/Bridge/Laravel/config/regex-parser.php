<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use RegexParser\Regex;

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
    | Whether to validate patterns against the runtime PCRE engine
    | (preg_match compile check). Useful for catching PCRE-specific errors.
    |
    | Set to true in development, false in production for performance.
    |
    */
    'runtime_pcre_validation' => env('APP_DEBUG', false),

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
        'directory' => '{storage_path}/framework/cache/regex-parser',
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
        | Minimum ReDoS severity to report: safe, low, medium, high, critical
        */
        'threshold' => 'high',

        /*
        | List of patterns or full regexes to exclude from ReDoS analysis.
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

        /*
        | Complexity score above which a pattern is flagged as ReDoS risk.
        */
        'redos_threshold' => 100,

        /*
        | List of regex fragments to treat as safe.
        */
        'ignore_patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Automata Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for automata-based regex comparisons.
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
    | Directories to exclude from scanning.
    |
    */
    'exclude_paths' => ['vendor', 'node_modules', 'storage'],

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
