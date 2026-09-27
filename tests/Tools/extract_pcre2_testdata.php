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
 * Extracts the compilation cases of the vendored PCRE2 testdata into the
 * committed suite fixture.
 *
 * Usage: php tests/Tools/extract_pcre2_testdata.php --floor-pcre2test=<path> [<output-file>]
 *
 * The default output is tests/Fixtures/Pcre2/suite-cases.json, written in
 * the canonical serialization the fixture consistency test re-checks.
 *
 * Every assertable case is compiled with the running preg_match before
 * anything is written. The command refuses to write (exit code 1) when the
 * PHP running it is not linked to the pinned PCRE2 release, or when a case's
 * expected verdict or offset differs from what preg_match observed without
 * a known PHP compile-context difference to explain it. Explained cases get
 * a phpOverride recording the live observation.
 *
 * Every assertable case is also compiled by the pcre2test given with
 * --floor-pcre2test, which must be exactly PCRE2 10.40 (the oldest PCRE2 a
 * supported PHP ships). Each case records that observation as "floor", and
 * a case whose floor verdict differs from PHP's is skipped as
 * newer-than-floor or stricter-than-floor. meta.crossChecked is a checksum
 * of the engine-backed rows against edits made without regenerating;
 * tests/Tools/verify_pcre2_fixture.php re-runs both engines to prove them.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use RegexParser\Tests\TestUtils\Pcre2FloorOracle;
use RegexParser\Tests\TestUtils\Pcre2LiveCrossCheck;
use RegexParser\Tests\TestUtils\Pcre2TestdataExtractor;

$argv = $_SERVER['argv'] ?? [];
$floorBinary = null;
$positional = [];

foreach (\is_array($argv) ? \array_slice($argv, 1) : [] as $argument) {
    if (!\is_string($argument)) {
        continue;
    }

    if (str_starts_with($argument, '--floor-pcre2test=')) {
        $floorBinary = substr($argument, \strlen('--floor-pcre2test='));

        continue;
    }

    $positional[] = $argument;
}

$defaultPath = __DIR__.'/../Fixtures/Pcre2/suite-cases.json';
$outputPath = $positional[0] ?? $defaultPath;
$testdataDir = __DIR__.'/../Fixtures/Pcre2/testdata';
$pin = Pcre2TestdataExtractor::PCRE2_PIN;

$startedAt = hrtime(true);

if (!Pcre2LiveCrossCheck::engineMatchesPin(\PCRE_VERSION, $pin)) {
    fwrite(\STDERR, \sprintf(
        'Refusing to extract: this PHP is linked to PCRE2 %s, the vendored testdata is PCRE2 %s. Run the extraction on a PHP linked to PCRE2 %s.%s',
        \PCRE_VERSION,
        $pin,
        $pin,
        \PHP_EOL,
    ));

    exit(1);
}

$floor = Pcre2TestdataExtractor::PCRE2_FLOOR;

if (null === $floorBinary || '' === $floorBinary || !is_file($floorBinary) || !is_executable($floorBinary)) {
    fwrite(\STDERR, \sprintf(
        'Refusing to extract: pass --floor-pcre2test=<path> to an executable pcre2test of PCRE2 %s (build it from the release tarball, see tests/Fixtures/Pcre2/README.md).%s',
        $floor,
        \PHP_EOL,
    ));

    exit(1);
}

[$floorMatches, $floorReport] = Pcre2FloorOracle::checkBinary($floorBinary, $floor);

if (!$floorMatches) {
    fwrite(\STDERR, \sprintf('Refusing to extract: %s %s.%s', $floorBinary, $floorReport, \PHP_EOL));

    exit(1);
}

$extractor = new Pcre2TestdataExtractor();
$suite = [];
$summary = [];
$disagreements = [];
$overrideCount = 0;

foreach (['1', '2', '4', '5'] as $fileNumber) {
    $name = 'testinput'.$fileNumber;
    $cases = $extractor->extractFilePair(
        $testdataDir.'/testinput'.$fileNumber,
        $testdataDir.'/testoutput'.$fileNumber,
        $name,
    );

    $crossCheck = Pcre2LiveCrossCheck::check($cases);
    $disagreements = array_merge($disagreements, $crossCheck['disagreements']);

    foreach ($cases as $index => $case) {
        $id = $case['id'];

        if (\is_string($id) && isset($crossCheck['overrides'][$id])) {
            $cases[$index]['phpOverride'] = $crossCheck['overrides'][$id];
            $overrideCount++;
        }
    }

    $assertableRows = array_values(array_filter($cases, static fn (array $case): bool => null === $case['skipCategory']));
    $floorObservations = Pcre2FloorOracle::observeAll($floorBinary, $assertableRows);
    $cases = Pcre2TestdataExtractor::applyFloor(
        $cases,
        static function (array $row) use ($floorObservations): array {
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '?';

            return $floorObservations[$id] ?? throw new \RuntimeException(\sprintf('No floor observation for %s.', $id));
        },
    );

    $suite[$name] = $cases;

    $assertable = 0;
    $skipCounts = [];

    foreach ($cases as $case) {
        if (null === $case['skipCategory']) {
            $assertable++;
        } elseif (\is_string($case['skipCategory'])) {
            $category = $case['skipCategory'];
            $skipCounts[$category] = ($skipCounts[$category] ?? 0) + 1;
        }
    }

    ksort($skipCounts, \SORT_STRING);

    $summary[$name] = [$assertable, $skipCounts];
}

if ([] !== $disagreements) {
    fwrite(\STDERR, \sprintf(
        'Refusing to write: %d case(s) disagree with preg_match on PCRE2 %s and no known PHP compile-context difference explains them:%s',
        \count($disagreements),
        \PCRE_VERSION,
        \PHP_EOL,
    ));

    foreach ($disagreements as $disagreement) {
        fwrite(\STDERR, '  '.$disagreement.\PHP_EOL);
    }

    exit(1);
}

// meta.crossChecked: xxh128 over [id, verdict, offset, phpOverride, floor]
// of every assertable row, in file order.
$covered = [];
$floorSkips = ['newer-than-floor' => 0, 'stricter-than-floor' => 0];

foreach ($suite as $cases) {
    foreach ($cases as $case) {
        $skipCategory = $case['skipCategory'] ?? null;

        if (\is_string($skipCategory) && isset($floorSkips[$skipCategory])) {
            $floorSkips[$skipCategory]++;
        }

        if (null === $skipCategory) {
            $covered[] = [$case['id'] ?? null, $case['verdict'] ?? null, $case['offset'] ?? null, $case['phpOverride'] ?? null, $case['floor'] ?? null];
        }
    }
}

$suite = [
    'meta' => [
        'pin' => $pin,
        'phpVersion' => \PHP_VERSION,
        'pcreVersion' => \PCRE_VERSION,
        'floorVersion' => $floor,
        'crossChecked' => hash('xxh128', json_encode($covered, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
    ],
    ...$suite,
];

$json = json_encode($suite, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

if (false === $json) {
    fwrite(\STDERR, 'Unable to serialize the extracted cases: '.json_last_error_msg().\PHP_EOL);

    exit(1);
}

if (!file_put_contents($outputPath, $json."\n")) {
    fwrite(\STDERR, 'Unable to write '.$outputPath.\PHP_EOL);

    exit(1);
}

$caseCount = 0;

foreach ($summary as [$assertable, $skipCounts]) {
    $caseCount += $assertable + array_sum($skipCounts);
}

printf('Wrote %d cases to %s (PHP %s, PCRE2 %s)%s', $caseCount, realpath($outputPath) ?: $outputPath, \PHP_VERSION, \PCRE_VERSION, \PHP_EOL);

foreach ($summary as $name => [$assertable, $skipCounts]) {
    $skipped = array_sum($skipCounts);
    $breakdown = [];

    foreach ($skipCounts as $category => $count) {
        $breakdown[] = \sprintf('%s=%d', $category, $count);
    }

    printf(
        '  %-11s %5d cases, %5d assertable, %d skipped (%s)%s',
        $name,
        $assertable + $skipped,
        $assertable,
        $skipped,
        [] === $breakdown ? 'none' : implode(', ', $breakdown),
        \PHP_EOL,
    );
}

printf('Verdicts adjusted to PHP compile context: %d%s', $overrideCount, \PHP_EOL);
printf(
    'Verdict differs on PCRE2 %s (%s): newer-than-floor=%d, stricter-than-floor=%d%s',
    $floor,
    $floorBinary,
    $floorSkips['newer-than-floor'],
    $floorSkips['stricter-than-floor'],
    \PHP_EOL,
);
printf(
    'Measured: %d ms wall-clock, %.1f MB peak memory%s',
    (int) round((hrtime(true) - $startedAt) / 1e6),
    memory_get_peak_usage(true) / 1024 / 1024,
    \PHP_EOL,
);
