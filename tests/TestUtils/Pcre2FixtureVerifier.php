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

namespace PhpRegex\Tests\TestUtils;

/**
 * Re-observes the committed suite fixture on the two real engines and lists
 * every record that differs from what they do:
 *
 * - every row carrying a floor observation is compiled again on the PCRE2
 *   floor release, and must match that observation exactly;
 * - every assertable row is compiled on the pinned release under PHP's
 *   compile context, and must match the expected outcome: the phpOverride's
 *   verdict and offset when there is one, otherwise the row's verdict,
 *   offset and PCRE2 error number;
 * - every row skipped as newer-than-floor or stricter-than-floor is also
 *   compiled on the pinned release, which must confirm the verdict change
 *   the skip rests on.
 *
 * Rows skipped before any engine ran (modifiers, API operators, ambiguous
 * records) are not compiled here: the consistency test re-derives their
 * classification from the vendored testdata, so the two checks together
 * cover the whole fixture.
 *
 * The observers are callables so the unit tests can replace the binaries;
 * the verification script builds them from Pcre2FloorOracle::observeAll.
 *
 * @phpstan-type Observation = array{verdict: string, offset: int|null, pcre2Code: int|null}
 */
final class Pcre2FixtureVerifier
{
    /**
     * @param array<array-key, mixed>                     $fixture       the decoded suite fixture, "meta" included
     * @param callable(array<string, mixed>): Observation $floorObserver compiles one row on the floor release
     * @param callable(array<string, mixed>): Observation $pinObserver   compiles one row on the pinned release
     *
     * @return list<string> one line per mismatch, naming the case id
     */
    public static function verify(array $fixture, callable $floorObserver, callable $pinObserver): array
    {
        $mismatches = [];

        foreach (self::rows($fixture) as $row) {
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '?';
            $floor = $row['floor'] ?? null;

            if (\is_array($floor)) {
                $recorded = self::observation($floor);
                $observed = $floorObserver($row);

                if ($recorded !== $observed) {
                    $mismatches[] = \sprintf(
                        '%s: floor recorded %s, PCRE2 %s observed %s',
                        $id,
                        self::describe($recorded),
                        Pcre2TestdataExtractor::PCRE2_FLOOR,
                        self::describe($observed),
                    );
                }
            }

            $skipCategory = $row['skipCategory'] ?? null;

            if (\is_array($floor) && \in_array($skipCategory, ['newer-than-floor', 'stricter-than-floor'], true)) {
                // The skip rests on the two releases disagreeing: the pin must
                // compile what the floor rejects, or reject what it compiles.
                $expectedVerdict = 'newer-than-floor' === $skipCategory ? 'accept' : 'reject';
                $observed = $pinObserver($row);

                if ($expectedVerdict !== $observed['verdict'] || $expectedVerdict === self::observation($floor)['verdict']) {
                    $mismatches[] = \sprintf(
                        '%s: skipped as %s, but PCRE2 %s under PHP\'s compile context observed %s',
                        $id,
                        $skipCategory,
                        Pcre2TestdataExtractor::PCRE2_PIN,
                        self::describe($observed),
                    );
                }
            }

            if (null !== $skipCategory) {
                continue;
            }

            $observed = $pinObserver($row);
            $override = $row['phpOverride'] ?? null;

            if (\is_array($override)) {
                $expected = self::observation($override);
                $matches = $expected['verdict'] === $observed['verdict'] && $expected['offset'] === $observed['offset'];
                $source = 'phpOverride';
            } else {
                $expected = self::observation($row);
                $matches = $expected === $observed;
                $source = 'row';
            }

            if (!$matches) {
                $mismatches[] = \sprintf(
                    '%s: %s records %s, PCRE2 %s under PHP\'s compile context observed %s',
                    $id,
                    $source,
                    self::describe($expected),
                    Pcre2TestdataExtractor::PCRE2_PIN,
                    self::describe($observed),
                );
            }
        }

        return $mismatches;
    }

    /**
     * Every case row of the fixture, in file order, "meta" left out.
     *
     * @param array<array-key, mixed> $fixture
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $fixture): array
    {
        unset($fixture['meta']);
        $rows = [];

        foreach ($fixture as $cases) {
            if (!\is_array($cases)) {
                continue;
            }

            foreach ($cases as $case) {
                if (!\is_array($case)) {
                    continue;
                }

                $row = [];

                foreach ($case as $field => $value) {
                    if (\is_string($field)) {
                        $row[$field] = $value;
                    }
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Reads verdict, offset and PCRE2 error number from a row, a floor
     * observation or a phpOverride.
     *
     * @param array<array-key, mixed> $record
     *
     * @return Observation
     */
    private static function observation(array $record): array
    {
        return [
            'verdict' => \is_string($record['verdict'] ?? null) ? $record['verdict'] : '?',
            'offset' => \is_int($record['offset'] ?? null) ? $record['offset'] : null,
            'pcre2Code' => \is_int($record['pcre2Code'] ?? null) ? $record['pcre2Code'] : null,
        ];
    }

    /**
     * @param Observation $observation
     */
    private static function describe(array $observation): string
    {
        if ('reject' !== $observation['verdict']) {
            return $observation['verdict'];
        }

        return \sprintf('reject (error %s at offset %s)', $observation['pcre2Code'] ?? '?', $observation['offset'] ?? '?');
    }
}
