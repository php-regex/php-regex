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

// Tally installs under tools/tally on PHP 8.2 and later only. Where it is
// installed, the counted benchmarks read their attributes from it. Where it
// is not (a job on PHP 8.1, a checkout that skipped the tool), a missing
// directory would stop the analysis and the benchmarks would name classes
// that do not exist: the analysis leaves them out instead. PHPStan keeps
// the container it compiled from this answer in .cache/phpstan: after
// installing or removing the tool, empty .cache/phpstan/cache once.
$root = \dirname(__DIR__, 3);
$tally = $root.'/tools/tally/vendor/phpstan/tally/src';

return ['parameters' => is_dir($tally)
    ? ['scanDirectories' => [$tally]]
    : ['excludePaths' => ['analyseAndScan' => [$root.'/tests/Benchmark/Tally']]],
];
