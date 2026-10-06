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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\JsonContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * docs/reference/json-output.md documents every key of every JSON document,
 * and nothing else: its key tables and JsonContract list the same objects
 * with the same keys, both ways.
 *
 * The format this test reads:
 *
 * - Each JSON object is described by one key table, introduced by a level-3
 *   heading ("### ") whose text holds exactly one code span: the object's
 *   address. Other text may surround it:
 *       ### Issue: `lint.results[].issues[]`
 * - Addresses are those of JsonContract: the document kind for the top
 *   level (`lint`, `analyze`, `debug`, `redos`, `transpile`, `error` for the
 *   error envelope, `baseline` for a baseline file), then `.key` for a
 *   nested object and `[]` for the elements of a list. An object several
 *   documents embed has an address of its own (`runtime`, `validation`,
 *   `redos_analysis` and its parts, `benchmark`) and one table; the prose
 *   says where it is embedded.
 * - Prose may sit between the heading and its table. The table's header row
 *   is exactly "| key | type | meaning |", then the separator row, then one
 *   row per key: the key alone in a code span in the first cell, a type and
 *   a meaning that are not empty. A "|" inside a cell is written "\|".
 * - A key that may be absent (JsonContract::OPTIONAL_KEYS) says "optional"
 *   in its type cell.
 * - A key table under any other heading fails the test, and so does an
 *   address described twice.
 * - The error envelope's stage values are each named in a code span
 *   somewhere on the page.
 */
final class JsonOutputReferenceTest extends TestCase
{
    private const PAGE = __DIR__.'/../../docs/reference/json-output.md';

    private const STAGES = ['usage', 'config', 'collect', 'pattern', 'internal'];

    #[Test]
    public function test_json_reference_page_exists(): void
    {
        $this->assertFileExists(self::PAGE);
    }

    #[Test]
    public function test_json_reference_documents_every_object_of_the_contract(): void
    {
        $documented = array_keys(self::tables());
        $missing = array_values(array_diff(array_keys(JsonContract::OBJECTS), $documented));

        $this->assertSame([], $missing, 'Objects with no key table in json-output.md.');
    }

    #[Test]
    public function test_json_reference_documents_no_object_outside_the_contract(): void
    {
        $documented = array_keys(self::tables());
        $unknown = array_values(array_diff($documented, array_keys(JsonContract::OBJECTS)));

        $this->assertSame([], $unknown, 'Key tables in json-output.md for objects no document has.');
    }

    #[Test]
    #[DataProvider('provideObjects')]
    public function test_json_reference_lists_exactly_the_keys_of_the_object(string $address): void
    {
        $tables = self::tables();
        $this->assertArrayHasKey($address, $tables, 'No key table for `'.$address.'` in json-output.md.');

        $documented = array_keys($tables[$address]);
        $expected = JsonContract::OBJECTS[$address];
        sort($documented);
        sort($expected);

        $this->assertSame($expected, $documented, 'The keys of `'.$address.'`.');
    }

    #[Test]
    #[DataProvider('provideOptionalKeys')]
    public function test_json_reference_marks_an_optional_key(string $address, string $key): void
    {
        $tables = self::tables();
        $this->assertArrayHasKey($address, $tables);
        $this->assertArrayHasKey($key, $tables[$address]);

        $this->assertStringContainsStringIgnoringCase('optional', $tables[$address][$key], 'The type of `'.$address.'`.'.$key);
    }

    #[Test]
    #[DataProvider('provideStages')]
    public function test_json_reference_names_every_envelope_stage(string $stage): void
    {
        $this->assertFileExists(self::PAGE);

        $this->assertStringContainsString('`'.$stage.'`', (string) file_get_contents(self::PAGE));
    }

    /**
     * @return iterable<string, array{address: string}>
     */
    public static function provideObjects(): iterable
    {
        foreach (array_keys(JsonContract::OBJECTS) as $address) {
            yield $address => ['address' => $address];
        }
    }

    /**
     * @return iterable<string, array{address: string, key: string}>
     */
    public static function provideOptionalKeys(): iterable
    {
        foreach (JsonContract::OPTIONAL_KEYS as $address => $keys) {
            foreach ($keys as $key) {
                yield $address.'.'.$key => ['address' => $address, 'key' => $key];
            }
        }
    }

    /**
     * @return iterable<string, array{stage: string}>
     */
    public static function provideStages(): iterable
    {
        foreach (self::STAGES as $stage) {
            yield $stage => ['stage' => $stage];
        }
    }

    /**
     * The key tables of the page, by address: key => type cell.
     *
     * @return array<string, array<string, string>>
     */
    private static function tables(): array
    {
        self::assertFileExists(self::PAGE);
        $lines = preg_split('/\R/', (string) file_get_contents(self::PAGE)) ?: [];

        $tables = [];
        $address = null;
        $current = null;

        foreach ($lines as $number => $line) {
            $where = 'json-output.md line '.($number + 1);

            if (1 === preg_match('/^(#+)\s+(.*)$/', $line, $heading)) {
                $current = null;
                $address = null;
                if ('###' === $heading[1] && 1 === preg_match_all('/`([^`]+)`/', $heading[2], $spans)) {
                    $address = $spans[1][0];
                }

                continue;
            }

            if (1 === preg_match('/^\|\s*key\s*\|\s*type\s*\|\s*meaning\s*\|\s*$/', $line)) {
                self::assertNotNull($address, $where.': a key table with no "### `address`" heading above it.');
                self::assertArrayNotHasKey($address, $tables, $where.': `'.$address.'` is described twice.');
                $tables[$address] = [];
                $current = $address;

                continue;
            }

            if (null === $current) {
                continue;
            }

            if (!str_starts_with(ltrim($line), '|')) {
                // The table ended; prose until the next heading belongs to no table.
                $current = null;
                $address = null;

                continue;
            }

            if (1 === preg_match('/^\s*\|[\s:|-]+\|\s*$/', $line)) {
                continue;
            }

            $cells = array_map(trim(...), preg_split('/(?<!\\\\)\|/', trim(trim($line), '|')) ?: []);
            self::assertCount(3, $cells, $where.': a key row has three cells.');
            self::assertSame(1, preg_match('/^`([^`]+)`$/', $cells[0], $key), $where.': the key goes alone in a code span.');
            self::assertNotSame('', $cells[1], $where.': the type of `'.$key[1].'` is empty.');
            self::assertNotSame('', $cells[2], $where.': the meaning of `'.$key[1].'` is empty.');
            self::assertArrayNotHasKey($key[1], $tables[$current], $where.': `'.$key[1].'` is listed twice.');

            $tables[$current][$key[1]] = $cells[1];
        }

        return $tables;
    }
}
