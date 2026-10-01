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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Tests\TestUtils\Pcre2LiveCrossCheck;
use PHPRegex\Tests\TestUtils\Pcre2TestdataExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Consistency between the vendored PCRE2 testdata, the extractor and the
 * committed tests/Fixtures/Pcre2/suite-cases.json.
 *
 * Re-runs the extractor over the raw vendored files and compares the result
 * with the committed fixture case by case, naming the first differing case
 * and field. Three parts of the fixture cannot be re-derived without the
 * live engines and are taken from the committed file: the top-level "meta"
 * provenance record, each row's phpOverride (what preg_match observed at
 * extraction) and each row's floor (what the PCRE2 10.40 pcre2test
 * observed). The floor classification itself is re-derived from those
 * observations, and each copied part is validated on its own, including the
 * meta.crossChecked checksum over the engine-backed rows, which catches an
 * edit made without regenerating (it is no proof of provenance: the
 * fixture verification script re-runs both engines for that). A missing
 * fixture, an extractor bug or a hand-edited file fails here on every
 * machine, in seconds — this test never skips and never vacuously passes.
 */
final class Pcre2ExtractorFidelityTest extends TestCase
{
    private const TESTDATA_DIR = __DIR__.'/../Fixtures/Pcre2/testdata';

    private const SUITE_JSON = __DIR__.'/../Fixtures/Pcre2/suite-cases.json';

    private const FILES = ['1', '2', '4', '5'];

    /**
     * Suite cases PHP compiles although testoutput2 rejects them with error
     * 199: php-src sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK by default, and
     * preg_match returns 0 for each of these on PCRE2 10.48. The ids are line
     * numbers in the vendored testinput2, so they move when a pin bump adds or
     * removes lines above them; tests/Fixtures/Pcre2/README.md lists the same
     * ids in its pin-bump steps, and both change together with the table of
     * known PHP compile-context differences.
     */
    private const LOOKAROUND_BSK_CASES = ['testinput2:6394', 'testinput2:6399', 'testinput2:6404', 'testinput2:6409'];

    /**
     * @var array<string, list<array<string, mixed>>>|null
     */
    private static ?array $reextracted = null;

    #[Test]
    public function test_vendored_pcre2_testdata_files_are_present(): void
    {
        foreach (self::FILES as $n) {
            $this->assertFileExists(
                self::TESTDATA_DIR.'/testinput'.$n,
                \sprintf('Vendored testinput%s is missing from %s', $n, self::TESTDATA_DIR),
            );
            $this->assertFileExists(
                self::TESTDATA_DIR.'/testoutput'.$n,
                \sprintf('Vendored testoutput%s is missing from %s', $n, self::TESTDATA_DIR),
            );
        }

        // PCRE2 is BSD-3-Clause with the PCRE2 exception: its licence file
        // must ship with the vendored data.
        $this->assertFileExists(self::TESTDATA_DIR.'/LICENCE.md');
    }

    #[Test]
    public function test_committed_suite_json_records_provenance(): void
    {
        $committed = self::committedSuite();

        $this->assertArrayHasKey('meta', $committed, 'suite-cases.json must carry a top-level "meta" provenance record');
        $meta = $committed['meta'];
        $this->assertIsArray($meta);
        $this->assertSame(['pin', 'phpVersion', 'pcreVersion', 'floorVersion', 'crossChecked'], array_keys($meta));
        $this->assertSame(Pcre2TestdataExtractor::PCRE2_PIN, $meta['pin']);
        $this->assertSame(Pcre2TestdataExtractor::PCRE2_FLOOR, $meta['floorVersion']);
        $this->assertIsString($meta['phpVersion']);
        $this->assertNotSame('', $meta['phpVersion']);
        $this->assertIsString($meta['pcreVersion']);
        $this->assertTrue(
            Pcre2LiveCrossCheck::engineMatchesPin($meta['pcreVersion'], Pcre2TestdataExtractor::PCRE2_PIN),
            \sprintf('the fixture was extracted on PCRE2 %s, not on the pinned %s', $meta['pcreVersion'], Pcre2TestdataExtractor::PCRE2_PIN),
        );
    }

    #[Test]
    public function test_committed_cross_check_hash_matches_the_rows(): void
    {
        // meta.crossChecked = xxh128 of the JSON list (unescaped slashes and
        // unicode) of [id, verdict, offset, phpOverride, floor] for every
        // assertable row (skipCategory null), in file order.
        $committed = self::committedSuite();
        $meta = $committed['meta'] ?? null;
        $this->assertIsArray($meta, 'suite-cases.json has no meta record');
        unset($committed['meta']);

        $covered = [];

        foreach ($committed as $rows) {
            $this->assertIsArray($rows);

            foreach ($rows as $row) {
                $this->assertIsArray($row);

                if (null !== ($row['skipCategory'] ?? null)) {
                    continue;
                }

                $covered[] = [$row['id'] ?? null, $row['verdict'] ?? null, $row['offset'] ?? null, $row['phpOverride'] ?? null, $row['floor'] ?? null];
            }
        }

        $this->assertNotSame([], $covered);
        $this->assertSame(
            hash('xxh128', json_encode($covered, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
            $meta['crossChecked'] ?? null,
            'meta.crossChecked does not match the rows: the fixture was edited without regenerating it',
        );
    }

    #[Test]
    public function test_committed_floor_observations_agree_with_the_classification(): void
    {
        $committed = self::committedSuite();
        unset($committed['meta']);
        $floorSkips = 0;

        foreach ($committed as $rows) {
            $this->assertIsArray($rows);

            foreach ($rows as $row) {
                $this->assertIsArray($row);
                $id = self::rowId($row);
                $this->assertArrayHasKey('floor', $row, \sprintf('case %s has no floor key', $id));
                $floor = $row['floor'];
                $skip = $row['skipCategory'] ?? null;

                if (null === $floor) {
                    $this->assertNotNull($skip, \sprintf('assertable case %s was never run on the PCRE2 floor', $id));

                    // A pcre2test modifier that PHP spells only from 8.4 on
                    // ("r", "caseless_restrict") dates the case by itself:
                    // no floor run is needed, and none is recorded.
                    if ('newer-than-floor' === $skip && self::skippedByNewerThanFloorModifier($row)) {
                        continue;
                    }

                    $this->assertNotContains($skip, ['newer-than-floor', 'stricter-than-floor'], \sprintf('case %s is floor-classified without a floor observation', $id));

                    continue;
                }

                $this->assertIsArray($floor);
                $this->assertSame(['verdict', 'offset', 'pcre2Code'], array_keys($floor), $id);

                if ('newer-than-floor' === $skip || 'stricter-than-floor' === $skip) {
                    $floorSkips++;
                    $this->assertSame('newer-than-floor' === $skip ? 'reject' : 'accept', $floor['verdict'], $id);

                    continue;
                }

                $this->assertNull($skip, \sprintf('case %s carries a floor observation but is skipped as %s', $id, \is_string($skip) ? $skip : '?'));
                $override = $row['phpOverride'] ?? null;
                $phpVerdict = \is_array($override) ? ($override['verdict'] ?? null) : ($row['verdict'] ?? null);
                $this->assertSame($phpVerdict, $floor['verdict'], \sprintf('case %s: the floor verdict differs from PHP\'s but the case is not skipped', $id));
            }
        }

        $this->assertGreaterThan(0, $floorSkips, 'no case differs between PCRE2 10.40 and 10.48: the floor was not run');
    }

    #[Test]
    public function test_reextracted_cases_match_committed_suite_json(): void
    {
        $committed = self::committedSuite();
        unset($committed['meta']);

        $actual = self::reextract();

        $this->assertSame(array_keys($actual), array_keys($committed), 'suite-cases.json must hold exactly the four vendored files');

        $firstDifference = self::firstDifference($committed, $actual);

        $this->assertNull($firstDifference, (string) $firstDifference);
    }

    #[Test]
    public function test_committed_suite_json_is_byte_stable(): void
    {
        $bytes = self::committedBytes();
        $committed = self::committedSuite();

        $canonical = ['meta' => $committed['meta'] ?? null];

        foreach (self::reextract() as $file => $rows) {
            $canonical[$file] = $rows;
        }

        $expected = json_encode($canonical, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";

        $this->assertSame(
            hash('xxh128', $expected),
            hash('xxh128', $bytes),
            'suite-cases.json is not the canonical serialization of the extraction; regenerate it with php tests/Tools/extract_pcre2_testdata.php',
        );
    }

    #[Test]
    public function test_committed_php_overrides_are_the_known_context_differences(): void
    {
        $committed = self::committedSuite();
        unset($committed['meta']);

        $differences = Pcre2LiveCrossCheck::contextDifferences();
        $overridden = [];

        foreach ($committed as $rows) {
            $this->assertIsArray($rows);

            foreach ($rows as $row) {
                $this->assertIsArray($row);
                $this->assertArrayHasKey('phpOverride', $row, \sprintf('case %s has no phpOverride key', self::rowId($row)));

                $override = $row['phpOverride'];

                if (null === $override) {
                    continue;
                }

                $id = self::rowId($row);
                $overridden[] = $id;

                $this->assertIsArray($override);
                $this->assertSame(['reason', 'verdict', 'offset', 'pcre2Code'], array_keys($override), $id);

                $reason = $override['reason'];
                $this->assertIsString($reason, $id);
                $this->assertArrayHasKey($reason, $differences, \sprintf('case %s is adjusted for an unlisted reason', $id));
                $this->assertSame($differences[$reason], $row['pcre2Code'], \sprintf(
                    'case %s is adjusted for %s but its suite error is not the one that difference explains',
                    $id,
                    $reason,
                ));
                $this->assertNull($row['skipCategory'], \sprintf('case %s is skipped and cannot carry an override', $id));
            }
        }

        $this->assertSame(self::LOOKAROUND_BSK_CASES, $overridden);

        foreach (self::LOOKAROUND_BSK_CASES as $id) {
            $row = self::committedRow($committed, $id);

            // The suite's own verdict stays in the row; the override sits beside it.
            $this->assertSame('reject', $row['verdict'], $id);
            $this->assertSame(199, $row['pcre2Code'], $id);
            $this->assertSame(
                ['reason' => 'allow-lookaround-bsk', 'verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
                $row['phpOverride'],
                $id,
            );
        }
    }

    #[Test]
    public function test_fixture_readme_names_the_pinned_override_cases(): void
    {
        // The pin-bump procedure must tell whoever moves the pin which case
        // ids this test expects to carry a PHP override.
        $readme = file_get_contents(__DIR__.'/../Fixtures/Pcre2/README.md');
        $this->assertIsString($readme);

        foreach (self::LOOKAROUND_BSK_CASES as $id) {
            $this->assertStringContainsString($id, (string) $readme);
        }
    }

    #[Test]
    public function test_fixture_readme_builds_the_engines_outside_the_repository(): void
    {
        // A recipe that changes directory to build pcre2test and then runs
        // "php tests/Tools/..." in the same shell fails from the wrong
        // directory: the build must happen in a subshell.
        $readme = (string) file_get_contents(__DIR__.'/../Fixtures/Pcre2/README.md');
        $this->assertGreaterThan(0, (int) preg_match_all('/^```[a-z]*\n(.*?)^```/ms', $readme, $blocks), 'the fixture README has no code block');

        $recipes = 0;

        foreach ($blocks[1] as $block) {
            if (!str_contains($block, 'php tests/Tools/')) {
                continue;
            }

            $recipes++;
            $this->assertStringContainsString('(cd ', $block, 'the build step must run in a subshell');
            $this->assertSame(0, preg_match('/(?<!\()\bcd\s/', $block), 'a bare cd would leave the next php tests/Tools/... line in the build directory');
        }

        $this->assertGreaterThan(0, $recipes, 'the fixture README has no regeneration recipe');
    }

    /**
     * Names the first case or field where the committed fixture and the
     * re-extraction differ, or null when they agree.
     *
     * @param array<array-key, mixed>                   $committed
     * @param array<string, list<array<string, mixed>>> $actual
     */
    private static function firstDifference(array $committed, array $actual): ?string
    {
        foreach ($actual as $file => $rows) {
            $committedRows = $committed[$file] ?? null;

            if (!\is_array($committedRows)) {
                return \sprintf('%s: missing from suite-cases.json', $file);
            }

            $committedRows = array_values($committedRows);

            foreach ($rows as $index => $row) {
                $id = self::rowId($row);
                $committedRow = $committedRows[$index] ?? null;

                if (!\is_array($committedRow)) {
                    return \sprintf('%s: re-extraction has %s at position %d, suite-cases.json ends before it', $file, $id, $index);
                }

                if (self::rowId($committedRow) !== $id) {
                    return \sprintf('%s: position %d is %s in suite-cases.json but %s in the re-extraction', $file, $index, self::rowId($committedRow), $id);
                }

                if (array_keys($committedRow) !== array_keys($row)) {
                    return \sprintf(
                        '%s: fields differ — suite-cases.json [%s], re-extraction [%s]',
                        $id,
                        implode(', ', array_keys($committedRow)),
                        implode(', ', array_keys($row)),
                    );
                }

                foreach ($row as $field => $value) {
                    if ($committedRow[$field] !== $value) {
                        return \sprintf(
                            '%s field %s: suite-cases.json has %s, re-extraction has %s',
                            $id,
                            $field,
                            json_encode($committedRow[$field], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                            json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                        );
                    }
                }
            }

            if (\count($committedRows) > \count($rows)) {
                $extra = $committedRows[\count($rows)];

                return \sprintf('%s: suite-cases.json has %s past the end of the re-extraction', $file, \is_array($extra) ? self::rowId($extra) : '?');
            }
        }

        return null;
    }

    /**
     * Copies each committed phpOverride onto the re-extracted row with the
     * same id, keeping the re-extracted key order.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<array-key, mixed>    $committedRows
     *
     * @return list<array<string, mixed>>
     */
    private static function withCommittedOverrides(array $rows, array $committedRows): array
    {
        $overrides = [];

        foreach ($committedRows as $committedRow) {
            if (\is_array($committedRow) && \array_key_exists('phpOverride', $committedRow)) {
                $overrides[self::rowId($committedRow)] = $committedRow['phpOverride'];
            }
        }

        foreach ($rows as $index => $row) {
            $id = self::rowId($row);

            if (\array_key_exists('phpOverride', $row) && \array_key_exists($id, $overrides)) {
                $rows[$index]['phpOverride'] = $overrides[$id];
            }
        }

        return $rows;
    }

    /**
     * Whether a row was skipped because one of its pcre2test modifiers is
     * newer than the floor: its reason ends with the name of an entry of
     * Pcre2TestdataExtractor::newerThanFloorModifiers().
     *
     * @param array<array-key, mixed> $row
     */
    private static function skippedByNewerThanFloorModifier(array $row): bool
    {
        $reason = $row['skipReason'] ?? null;

        if (!\is_string($reason)) {
            return false;
        }

        foreach (Pcre2TestdataExtractor::newerThanFloorModifiers() as $modifier) {
            if (str_ends_with($reason, ': '.$modifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A floor observer that replays the committed PCRE2 10.40 observation of
     * each case instead of running the binary.
     *
     * @param array<array-key, mixed> $committedRows
     *
     * @return \Closure(array<string, mixed>): array{verdict: string, offset: int|null, pcre2Code: int|null}
     */
    private static function committedFloorReplay(array $committedRows): \Closure
    {
        $floors = [];

        foreach ($committedRows as $committedRow) {
            if (!\is_array($committedRow)) {
                continue;
            }

            $floor = $committedRow['floor'] ?? null;

            if (\is_array($floor) && \is_string($floor['verdict'] ?? null)) {
                $floors[self::rowId($committedRow)] = [
                    'verdict' => $floor['verdict'],
                    'offset' => \is_int($floor['offset'] ?? null) ? $floor['offset'] : null,
                    'pcre2Code' => \is_int($floor['pcre2Code'] ?? null) ? $floor['pcre2Code'] : null,
                ];
            }
        }

        return static function (array $row) use ($floors): array {
            $id = self::rowId($row);

            if (!isset($floors[$id])) {
                throw new \RuntimeException(\sprintf('suite-cases.json holds no floor observation for assertable case %s; regenerate it with the PCRE2 10.40 pcre2test.', $id));
            }

            return $floors[$id];
        };
    }

    /**
     * @param array<array-key, mixed> $committed
     *
     * @return array<array-key, mixed>
     */
    private static function committedRow(array $committed, string $id): array
    {
        foreach ($committed as $rows) {
            if (!\is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (\is_array($row) && self::rowId($row) === $id) {
                    return $row;
                }
            }
        }

        throw new \RuntimeException(\sprintf('Case %s is missing from suite-cases.json.', $id));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function rowId(array $row): string
    {
        return \is_string($row['id'] ?? null) ? $row['id'] : '?';
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function reextract(): array
    {
        if (null !== self::$reextracted) {
            return self::$reextracted;
        }

        $extractor = new Pcre2TestdataExtractor();
        $committed = self::committedSuite();
        $actual = [];

        foreach (self::FILES as $n) {
            $inputPath = self::TESTDATA_DIR.'/testinput'.$n;
            $outputPath = self::TESTDATA_DIR.'/testoutput'.$n;

            foreach ([$inputPath, $outputPath] as $path) {
                if (!is_file($path)) {
                    throw new \RuntimeException(\sprintf('Vendored testdata file is missing: %s', $path));
                }
            }

            $file = 'testinput'.$n;
            $committedRows = $committed[$file] ?? [];
            $committedRows = \is_array($committedRows) ? $committedRows : [];

            $rows = $extractor->extractFilePair($inputPath, $outputPath, $file);
            $rows = self::withCommittedOverrides($rows, $committedRows);
            $actual[$file] = Pcre2TestdataExtractor::applyFloor($rows, self::committedFloorReplay($committedRows));
        }

        return self::$reextracted = $actual;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function committedSuite(): array
    {
        $decoded = json_decode(self::committedBytes(), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('%s does not hold a JSON object.', self::SUITE_JSON));
        }

        return $decoded;
    }

    private static function committedBytes(): string
    {
        $committed = is_file(self::SUITE_JSON) ? file_get_contents(self::SUITE_JSON) : false;

        if (false === $committed) {
            throw new \RuntimeException(\sprintf(
                'Missing %s — regenerate it from the vendored testdata before asserting conformance.',
                self::SUITE_JSON,
            ));
        }

        return $committed;
    }
}
