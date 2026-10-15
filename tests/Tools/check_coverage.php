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

/*
 * Compares the line coverage of a Clover report with the threshold CI holds
 * it to.
 *
 * Usage: php tests/Tools/check_coverage.php <clover.xml> [<threshold file>]
 *
 * The threshold file defaults to .github/coverage-threshold: one percentage,
 * raised by hand as the coverage grows.
 *
 * Exit code 0: the coverage reaches the threshold. 1: it is below. 2: the
 * report or the threshold cannot be read.
 */

require_once __DIR__.'/../TestUtils/CoverageThreshold.php';

use PHPRegex\Tests\TestUtils\CoverageThreshold;

$arguments = $_SERVER['argv'] ?? [];
$arguments = is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), is_string(...))) : [];

if ([] === $arguments || count($arguments) > 2) {
    fwrite(\STDERR, "Usage: php tests/Tools/check_coverage.php <clover.xml> [<threshold file>]\n");

    exit(2);
}

$report = $arguments[0];
$thresholdFile = $arguments[1] ?? __DIR__.'/../../.github/coverage-threshold';

$read = static function (string $path): ?string {
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $contents = file_get_contents($path);

    return false === $contents ? null : $contents;
};

foreach ([$report, $thresholdFile] as $path) {
    if (null === $read($path)) {
        fwrite(\STDERR, sprintf("Cannot read %s.\n", $path));

        exit(2);
    }
}

$coverage = CoverageThreshold::lineCoverage((string) $read($report));
if (null === $coverage) {
    fwrite(\STDERR, sprintf("No line coverage in %s.\n", $report));

    exit(2);
}

$threshold = CoverageThreshold::threshold((string) $read($thresholdFile));
if (null === $threshold) {
    fwrite(\STDERR, sprintf("No percentage in %s: it holds one number from 0 to 100.\n", $thresholdFile));

    exit(2);
}

printf("Line coverage: %.2f%% (threshold %.2f%%)\n", $coverage, $threshold);

if ($coverage < $threshold) {
    fwrite(\STDERR, "The line coverage dropped below the threshold: cover the lines the change left unreached.\n");

    exit(1);
}

exit(0);
