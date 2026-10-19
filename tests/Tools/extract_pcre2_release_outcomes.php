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
 * Writes tests/Fixtures/Pcre2/release-outcomes.json: the compilation cases
 * of every PCRE2 release's own test suite, 10.32 to 10.49, on which the
 * releases do not all agree, with what each release does with them.
 *
 * Usage:
 *   php tests/Tools/extract_pcre2_release_outcomes.php --releases-dir=<dir> [--check] [--compare=<dir>] [<output-file>]
 *
 * <dir> holds one directory per release, pcre2-10.32 to pcre2-10.49, each
 * the release tarball extracted and built ("./configure --disable-shared
 * --enable-unicode && make pcre2test"), so that it holds pcre2test and the
 * release's testdata. Each pcre2test must report its release and the build
 * options PHP's bundled PCRE2 uses.
 *
 * --check writes nothing: it rebuilds the fixture and exits 1 when it
 * differs from the committed one. --compare=<dir> names a newer build, a
 * PCRE2 checkout with pcre2test built in it: every case, and the cases of
 * its own suite, are compiled by it too, and the ones it compiles another
 * way than the newest release are printed. Nothing of it is written.
 *
 * Refuses to write (exit code 1) when a binary is not the release it stands
 * for, or when pcre2test answers a case with a diagnostic instead of a
 * verdict.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Tests\TestUtils\Pcre2ReleaseOutcomes;

$arguments = $_SERVER['argv'] ?? [];
$releasesDir = null;
$compareDir = null;
$check = false;
$outputPath = Pcre2ReleaseOutcomes::FIXTURE;

foreach (\is_array($arguments) ? \array_slice($arguments, 1) : [] as $argument) {
    if (!\is_string($argument)) {
        continue;
    }

    if (str_starts_with($argument, '--releases-dir=')) {
        $releasesDir = rtrim(substr($argument, \strlen('--releases-dir=')), '/');
    } elseif (str_starts_with($argument, '--compare=')) {
        $compareDir = rtrim(substr($argument, \strlen('--compare=')), '/');
    } elseif ('--check' === $argument) {
        $check = true;
    } else {
        $outputPath = $argument;
    }
}

if (null === $releasesDir || !is_dir($releasesDir)) {
    fwrite(\STDERR, 'Pass --releases-dir=<dir>, the directory holding pcre2-10.32 to pcre2-10.49, each built (see tests/Fixtures/Pcre2/README.md).'.\PHP_EOL);

    exit(1);
}

$startedAt = hrtime(true);

try {
    $measure = Pcre2ReleaseOutcomes::build($releasesDir);
} catch (\RuntimeException $exception) {
    fwrite(\STDERR, 'Refusing to write: '.$exception->getMessage().\PHP_EOL);

    exit(1);
}

$fixture = $measure['fixture'];
$json = Pcre2ReleaseOutcomes::encode($fixture);
$releases = Pcre2ReleaseOutcomes::RELEASES;
$newest = $releases[\count($releases) - 1];

printf('%d cases read from the suites of PCRE2 %s to %s, %d whose outcome moves between releases%s', \count($measure['cases']), $releases[0], $newest, \count($fixture['cases']), \PHP_EOL);
foreach ($measure['measured'] as $release => $compiled) {
    printf('  %s: %d cases compiled%s%s', $release, $compiled, isset($measure['measuredAsPhp7'][$release]) ? \sprintf(', %d as PHP 7', $measure['measuredAsPhp7'][$release]) : '', \PHP_EOL);
}

if (null !== $compareDir) {
    try {
        $binary = Pcre2ReleaseOutcomes::checkBinary($compareDir.'/pcre2test', null);
        $label = Pcre2ReleaseOutcomes::version($binary);
        [$own] = Pcre2ReleaseOutcomes::suiteCases($compareDir.'/testdata', 'compare');
        $known = [];
        foreach ($measure['cases'] as $case) {
            $known[$case['flags']."\0".$case['pattern']] = true;
        }
        $added = array_values(array_diff_key($own, $known));
        $all = [...$measure['cases'], ...$added];
        $newer = Pcre2ReleaseOutcomes::observeCases($binary, $newest, false, $all);
        $older = Pcre2ReleaseOutcomes::observeCases($measure['binaries'][$newest] ?? '', $newest, false, $all);
    } catch (\RuntimeException $exception) {
        fwrite(\STDERR, 'Unable to compare: '.$exception->getMessage().\PHP_EOL);

        exit(1);
    }

    $moved = 0;
    foreach ($all as $index => $case) {
        $isNew = $index >= \count($measure['cases']);
        $differs = ($newer[$index] ?? null) !== ($older[$index] ?? null);
        if ($isNew || $differs) {
            printf('  %s %s %s%s%s%s: %s on %s, %s on PCRE2 %s%s', $isNew ? 'new' : 'moved', $case['id'], $case['delimiter'], $case['pattern'], $case['delimiter'], $case['flags'], json_encode($newer[$index] ?? null), $label, json_encode($older[$index] ?? null), $newest, \PHP_EOL);
            $moved += $differs ? 1 : 0;
        }
    }
    printf('%s against PCRE2 %s: %d cases, %d new in its suite, %d compiled another way%s', $label, $newest, \count($all), \count($added), $moved, \PHP_EOL);
}

printf('Measured: %d ms wall-clock, %.1f MB peak memory%s', (int) round((hrtime(true) - $startedAt) / 1e6), memory_get_peak_usage(true) / 1024 / 1024, \PHP_EOL);

if ($check) {
    if (!is_file($outputPath) || file_get_contents($outputPath) !== $json) {
        fwrite(\STDERR, $outputPath.' differs from what the release builds give: regenerate it.'.\PHP_EOL);

        exit(1);
    }

    echo 'The committed fixture is what the release builds give.'.\PHP_EOL;

    exit(0);
}

if (false === file_put_contents($outputPath, $json)) {
    fwrite(\STDERR, 'Unable to write '.$outputPath.\PHP_EOL);

    exit(1);
}

printf('Wrote %s (%d bytes)%s', realpath($outputPath) ?: $outputPath, \strlen($json), \PHP_EOL);
