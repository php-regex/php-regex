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

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\ParserOptions;

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Benchmarking ParserOptions performance improvements...\n\n";

// Test different option configurations
$testConfigurations = [
    'empty' => [],
    'simple' => ['max_pattern_length' => 10000],
    'cache_string' => ['cache' => '/tmp/cache'],
    'cache_object' => ['cache' => new NullCache()],
    'redos_simple' => ['redos_ignored_patterns' => ['/test/']],
    'redos_complex' => ['redos_ignored_patterns' => ['/test1/', '/test2/', '/test3/', '/test1/']], // with duplicate
    'full' => [
        'max_pattern_length' => 50000,
        'cache' => new NullCache(),
        'redos_ignored_patterns' => ['/user_/', '/admin_/', '/guest_/'],
    ],
];

$iterations = 1000;

foreach ($testConfigurations as $name => $config) {
    echo "=== Testing {$name} configuration ===\n";
    echo 'Config: '.json_encode($config)."\n";

    // Benchmark option creation
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $options = ParserOptions::fromArray($config);
    }
    $creationTime = microtime(true) - $start;

    echo \sprintf("Option creation (%d iterations): %.4f seconds\n", $iterations, $creationTime);
    echo \sprintf("Average time per creation: %.6f seconds\n", $creationTime / $iterations);

    // Verify the result is correct
    $options = ParserOptions::fromArray($config);
    $maxLength = $options->maxPatternLength;
    $cacheType = \get_class($options->cache);
    $redosCount = \count($options->redosIgnoredPatterns);

    echo \sprintf("Result: max_length=%d, cache=%s, redos_count=%d\n", $maxLength, $cacheType, $redosCount);
    echo "\n";
}

// Test error handling performance
echo "=== Error Handling Performance ===\n";

$invalidConfigs = [
    'unknown_key' => ['unknown_option' => true],
    'invalid_max_length' => ['max_pattern_length' => 'invalid'],
    'invalid_redos' => ['redos_ignored_patterns' => [123]],
    'invalid_cache' => ['cache' => new stdClass()],
];

$errorIterations = 100;

foreach ($invalidConfigs as $name => $config) {
    echo "Testing {$name} error handling...\n";

    $start = microtime(true);
    $errors = 0;
    for ($i = 0; $i < $errorIterations; $i++) {
        try {
            ParserOptions::fromArray($config);
        } catch (Exception) {
            $errors++;
        }
    }
    $errorTime = microtime(true) - $start;

    echo \sprintf("Error handling (%d iterations): %.4f seconds (%d errors caught)\n", $errorIterations, $errorTime, $errors);
    echo \sprintf("Average time per error: %.6f seconds\n", $errorTime / $errorIterations);
    echo "\n";
}

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
