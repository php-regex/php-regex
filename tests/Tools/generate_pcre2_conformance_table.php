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

/*
 * Regenerates the generated sections of the public PCRE2 conformance page.
 *
 * Usage: php tests/Tools/generate_pcre2_conformance_table.php [--baseline] [<page-file>]
 *
 * --baseline first re-measures tests/Fixtures/Pcre2/conformance-baseline.json:
 * every assertable case of the committed suite fixture is run through
 * Regex::validate(), and every case that does not agree is written with its
 * defect class and a reason.
 *
 * Then everything from the generation marker to the end of the page
 * (default docs/reference/pcre2-conformance.md) is replaced with the
 * current generator output; the hand-written header above the marker is
 * kept. A page without the marker gets the generated sections appended; a
 * missing page is created with the generated sections only.
 *
 * The command prints its measured wall-clock time and peak memory; neither
 * is written into the page.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PhpRegex\Tests\TestUtils\Pcre2ConformanceTable;

$argv = $_SERVER['argv'] ?? [];
$arguments = [];

foreach (\is_array($argv) ? \array_slice($argv, 1) : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

$measureBaseline = \in_array('--baseline', $arguments, true);
$positional = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));
$pagePath = $positional[0] ?? __DIR__.'/../../docs/reference/pcre2-conformance.md';

$startedAt = hrtime(true);

if ($measureBaseline) {
    $baseline = Pcre2ConformanceTable::measureBaseline();
    Pcre2ConformanceTable::writeBaseline($baseline);

    $classes = array_count_values(array_column($baseline, 'category'));
    ksort($classes, \SORT_STRING);
    $breakdown = [];

    foreach ($classes as $class => $count) {
        $breakdown[] = \sprintf('%s=%d', $class, $count);
    }

    printf(
        'Re-measured %s: %d entries (%s)%s',
        realpath(Pcre2ConformanceTable::BASELINE_PATH) ?: Pcre2ConformanceTable::BASELINE_PATH,
        \count($baseline),
        [] === $breakdown ? 'none' : implode(', ', $breakdown),
        \PHP_EOL,
    );
}

$generated = Pcre2ConformanceTable::generate();

$current = is_file($pagePath) ? file_get_contents($pagePath) : false;
$marker = Pcre2ConformanceTable::GENERATION_MARKER;
$markerPosition = false === $current ? false : strpos($current, $marker);

if (false !== $current && false !== $markerPosition) {
    $page = substr($current, 0, $markerPosition).$generated;
} elseif (false !== $current && '' !== $current) {
    $separator = str_ends_with($current, "\n") ? '' : "\n";
    $page = $current.$separator."\n".$generated;
} else {
    $page = $generated;
}

if (false === file_put_contents($pagePath, $page)) {
    fwrite(\STDERR, 'Unable to write '.$pagePath.\PHP_EOL);

    exit(1);
}

$elapsedMs = (int) round((hrtime(true) - $startedAt) / 1e6);
$peakMemory = memory_get_peak_usage(true);

printf('Regenerated %s%s', realpath($pagePath) ?: $pagePath, \PHP_EOL);
printf('Measured: %d ms wall-clock, %.1f MB peak memory%s', $elapsedMs, $peakMemory / 1024 / 1024, \PHP_EOL);
