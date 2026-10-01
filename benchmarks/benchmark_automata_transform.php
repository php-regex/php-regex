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

use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Transform\AstToNfaTransformer;
use PhpRegex\Toolkit\Regex;

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

$iterations = 200;
$regex = Regex::create();
$options = new SolverOptions(matchMode: MatchMode::FULL);

$patterns = [
    'long_literal' => '/'.str_repeat('a', 200).'/',
    'char_types' => '/(?:\\w+\\s+\\d+){30}/',
    'mixed_class' => '/[a-zA-Z0-9_\\-]{80}/',
];

echo "Automata Transform Benchmark\n";
echo "============================\n\n";
echo \sprintf("Iterations: %d\n\n", $iterations);

foreach ($patterns as $name => $pattern) {
    $ast = $regex->parse($pattern);
    $startMemory = memory_get_usage(true);
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $transformer = new AstToNfaTransformer($pattern);
        $transformer->transform($ast, $options);
    }

    $elapsed = hrtime(true) - $start;
    $memory = memory_get_usage(true) - $startMemory;

    echo \sprintf("Pattern: %s\n", $name);
    echo \sprintf("Time: %.2f ms\n", $elapsed / 1_000_000);
    echo \sprintf("Memory: %.2f MB\n\n", $memory / 1024 / 1024);
}
