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
 * Reduces the PHPUnit XML coverage of the whole suite to what a mutation run
 * reads: the files it mutates, each line naming the test methods that reach
 * it once, whatever the number of their data sets.
 *
 * Usage: php tests/Tools/reduce_mutation_coverage.php <coverage-xml directory>
 *            [--shard=K/N] [--list=<file>] [<path>...]
 *
 * Each path is a source file or directory relative to the working directory,
 * the project root; no path keeps no file. --shard=K/N deals those files to
 * N shards, largest first and each to the lightest shard so far, and keeps
 * the Kth share. --list writes the kept
 * paths to <file>, one per line, for the mutation run to take as arguments.
 * The directory is rewritten in place.
 *
 * Exit code 0: the report is reduced. 2: it cannot be read or rewritten.
 */

require_once __DIR__.'/../TestUtils/MutationCoverage.php';

use PHPRegex\Tests\TestUtils\MutationCoverage;

$arguments = $_SERVER['argv'] ?? [];
$arguments = is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), is_string(...))) : [];

if ([] === $arguments) {
    fwrite(\STDERR, "Usage: php tests/Tools/reduce_mutation_coverage.php <coverage-xml directory> [--shard=K/N] [--list=<file>] [<path>...]\n");

    exit(2);
}

$directory = rtrim(array_shift($arguments), '/');
$shard = [1, 1];
$list = null;
$paths = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--shard=')) {
        $value = substr($argument, 8);
        if (1 !== preg_match('~^(\d+)/(\d+)$~D', $value, $match) || (int) $match[1] < 1 || (int) $match[1] > (int) $match[2]) {
            fwrite(\STDERR, sprintf("The shard is written K/N, K from 1 to N: %s.\n", $value));

            exit(2);
        }

        $shard = [(int) $match[1], (int) $match[2]];
    } elseif (str_starts_with($argument, '--list=')) {
        $list = substr($argument, 7);
    } else {
        $paths[] = $argument;
    }
}

if (!is_file($directory.'/index.xml')) {
    fwrite(\STDERR, sprintf("Cannot read %s/index.xml.\n", $directory));

    exit(2);
}

try {
    $kept = MutationCoverage::reduce($directory, (string) getcwd(), $paths, $shard[0], $shard[1]);
} catch (UnexpectedValueException $exception) {
    fwrite(\STDERR, $exception->getMessage()."\n");

    exit(2);
}

if (null !== $list && false === file_put_contents($list, [] === $kept ? '' : implode("\n", $kept)."\n")) {
    fwrite(\STDERR, sprintf("Cannot write %s.\n", $list));

    exit(2);
}

printf(
    "Kept the coverage of %d file%s for the mutations%s\n",
    count($kept),
    1 === count($kept) ? '' : 's',
    [] === $kept ? '.' : ': '.implode(', ', $kept),
);

exit(0);
