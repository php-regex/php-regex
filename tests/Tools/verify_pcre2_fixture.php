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
 * Re-observes the committed PCRE2 suite fixture on real engines.
 *
 * Usage: php tests/Tools/verify_pcre2_fixture.php --floor-pcre2test=<path> --pin-pcre2test=<path> [<fixture>]
 *
 * --floor-pcre2test must be a pcre2test of PCRE2 10.40 and --pin-pcre2test
 * one of PCRE2 10.48, each built with the options PHP's bundled PCRE2 uses
 * (checked through "pcre2test -C"). Every row carrying a floor observation is
 * compiled again on 10.40, every assertable row on 10.48 under PHP's compile
 * context, and each result must equal what the fixture records. The fixture
 * defaults to tests/Fixtures/Pcre2/suite-cases.json.
 *
 * Exit code 0: every record matches. 1: a mismatch (each one is printed) or
 * an unusable binary.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PhpRegex\Tests\TestUtils\Pcre2FixtureVerifier;
use PhpRegex\Tests\TestUtils\Pcre2FloorOracle;
use PhpRegex\Tests\TestUtils\Pcre2TestdataExtractor;

$argv = $_SERVER['argv'] ?? [];
$binaries = ['floor' => null, 'pin' => null];
$positional = [];

foreach (is_array($argv) ? array_slice($argv, 1) : [] as $argument) {
    if (!is_string($argument)) {
        continue;
    }

    if (str_starts_with($argument, '--floor-pcre2test=')) {
        $binaries['floor'] = substr($argument, strlen('--floor-pcre2test='));

        continue;
    }

    if (str_starts_with($argument, '--pin-pcre2test=')) {
        $binaries['pin'] = substr($argument, strlen('--pin-pcre2test='));

        continue;
    }

    $positional[] = $argument;
}

$fixturePath = $positional[0] ?? __DIR__.'/../Fixtures/Pcre2/suite-cases.json';
$versions = ['floor' => Pcre2TestdataExtractor::PCRE2_FLOOR, 'pin' => Pcre2TestdataExtractor::PCRE2_PIN];
$startedAt = hrtime(true);

foreach ($binaries as $role => $binary) {
    if (null === $binary || '' === $binary || !is_file($binary) || !is_executable($binary)) {
        fwrite(\STDERR, sprintf('Pass --%s-pcre2test=<path> to an executable pcre2test of PCRE2 %s.%s', $role, $versions[$role], \PHP_EOL));

        exit(1);
    }

    [$usable, $report] = Pcre2FloorOracle::checkBinary($binary, $versions[$role]);

    if (!$usable) {
        fwrite(\STDERR, sprintf('Unusable %s pcre2test: %s %s.%s', $role, $binary, $report, \PHP_EOL));

        exit(1);
    }

    printf('%s: %s (%s)%s', $role, $report, $binary, \PHP_EOL);
}

$raw = is_file($fixturePath) ? file_get_contents($fixturePath) : false;

if (false === $raw) {
    fwrite(\STDERR, sprintf('Missing fixture %s.%s', $fixturePath, \PHP_EOL));

    exit(1);
}

$fixture = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

if (!is_array($fixture)) {
    fwrite(\STDERR, sprintf('%s does not hold a JSON object.%s', $fixturePath, \PHP_EOL));

    exit(1);
}

// Each engine runs once, in one batch, over every row it must observe; the
// observers then answer from those results.
$rows = Pcre2FixtureVerifier::rows($fixture);
$floorRows = array_values(array_filter($rows, static fn (array $row): bool => is_array($row['floor'] ?? null)));
$pinRows = array_values(array_filter(
    $rows,
    static fn (array $row): bool => in_array($row['skipCategory'] ?? null, [null, 'newer-than-floor', 'stricter-than-floor'], true)
        && (null === ($row['skipCategory'] ?? null) || is_array($row['floor'] ?? null)),
));

try {
    $observations = [
        'floor' => Pcre2FloorOracle::observeAll((string) $binaries['floor'], $floorRows),
        'pin' => Pcre2FloorOracle::observeAll((string) $binaries['pin'], $pinRows),
    ];
} catch (RuntimeException $exception) {
    fwrite(\STDERR, 'A pcre2test run could not be read back: '.$exception->getMessage().\PHP_EOL);

    exit(1);
}

$observer = static fn (string $role): Closure => static function (array $row) use ($observations, $role): array {
    $id = is_string($row['id'] ?? null) ? $row['id'] : '?';

    return $observations[$role][$id] ?? throw new RuntimeException(sprintf('No %s observation for %s.', $role, $id));
};

$mismatches = Pcre2FixtureVerifier::verify($fixture, $observer('floor'), $observer('pin'));

foreach ($mismatches as $mismatch) {
    fwrite(\STDERR, $mismatch.\PHP_EOL);
}

printf(
    'Re-observed %d floor records on PCRE2 %s and %d assertable or floor-classified cases on PCRE2 %s: %d mismatch%s (%d ms)%s',
    count($floorRows),
    $versions['floor'],
    count($pinRows),
    $versions['pin'],
    count($mismatches),
    1 === count($mismatches) ? '' : 'es',
    (int) round((hrtime(true) - $startedAt) / 1e6),
    \PHP_EOL,
);

exit([] === $mismatches ? 0 : 1);
