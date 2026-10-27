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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PropertyNameTables;
use PHPRegex\Parser\Validation\OtherCaseTables;
use PHPRegex\Parser\Validation\PropertyBytes;
use PHPRegex\Parser\Validation\PropertyByteTables;
use PHPRegex\Tests\Support\PcreGateLimits;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\TestUtils\PropertyByteTablesWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The characters below 256 each property matches, as
 * tests/Tools/write_property_bytes.php reads them from pcre2test of each
 * release from 10.45, held against the engine that runs: each name of its
 * table matches the characters listed, and those only.
 */
final class PropertyByteTablesTest extends TestCase
{
    use PcreGates;

    #[Test]
    public function test_the_file_is_what_the_script_writes_from_the_tables_it_holds(): void
    {
        $this->assertSame(
            PropertyByteTablesWriter::render(PropertyByteTablesWriter::committed()),
            (string) file_get_contents(PropertyByteTablesWriter::FILE),
            'Run "php tests/Tools/write_property_bytes.php" with pcre2test of each release: the file is never edited by hand.',
        );
    }

    /**
     * The tables start at 10.45, the first release whose classes set the
     * bits of their properties in their map, and each names the Unicode
     * version its first release ships. Releases of another Unicode
     * version may match the same below 256, as 10.45 to 10.49 all do.
     */
    #[Test]
    public function test_each_table_names_the_unicode_version_its_first_release_ships(): void
    {
        $this->assertSame(PropertyByteTablesWriter::FIRST_RELEASE, array_key_last(PropertyByteTables::RELEASES));
        $this->assertSame(array_keys(PropertyByteTables::RELEASES), array_keys(PropertyByteTables::TABLES));
        $named = array_intersect_key(PropertyNameTables::RELEASES, PropertyByteTables::RELEASES);
        $this->assertCount(\count(PropertyByteTables::RELEASES), $named);
        foreach ($named as $release => $unicode) {
            $this->assertSame($unicode, PropertyByteTables::RELEASES[$release], (string) $release);
        }

        $shipped = array_filter(OtherCaseTables::RELEASES, static fn (string $release): bool => version_compare($release, PropertyByteTablesWriter::FIRST_RELEASE, '>='), \ARRAY_FILTER_USE_KEY);
        $this->assertSame([], array_diff(PropertyByteTables::RELEASES, $shipped));
    }

    #[Test]
    public function test_the_runs_are_read_as_written(): void
    {
        $matched = array_map(static fn (int $byte): bool => \in_array($byte, [0x00, 0x30, 0x31, 0x32, 0xFF], true), range(0, 255));

        $this->assertSame('00 30-32 ff', PropertyByteTablesWriter::runs($matched));
        $this->assertSame('', PropertyByteTablesWriter::runs(array_fill(0, 256, false)));
        $this->assertSame('00-ff', PropertyByteTablesWriter::runs(array_fill(0, 256, true)));
    }

    /**
     * @param list<int> $bytes
     */
    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_a_property_is_looked_up_as_pcre2_spells_it(string $prop, bool $caseless, string $release, array $bytes): void
    {
        $this->assertSame(self::map($bytes), PropertyBytes::ofProperty($prop, $caseless, new PcreTarget(80400, $release)), $prop);
    }

    /**
     * @return iterable<string, array{prop: string, caseless: bool, release: string, bytes: list<int>}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'one letter without braces' => ['prop' => 'N', 'caseless' => false, 'release' => '10.45', 'bytes' => [...range(0x30, 0x39), 0xB2, 0xB3, 0xB9, 0xBC, 0xBD, 0xBE]];
        yield 'general category, negated' => ['prop' => '{^Nd}', 'caseless' => false, 'release' => '10.49', 'bytes' => range(0x30, 0x39)];
        yield 'loose spelling' => ['prop' => '{ N_d }', 'caseless' => false, 'release' => '10.49', 'bytes' => range(0x30, 0x39)];
        yield 'capital letters' => ['prop' => '{Lu}', 'caseless' => false, 'release' => '10.48', 'bytes' => [...range(0x41, 0x5A), ...range(0xC0, 0xD6), ...range(0xD8, 0xDE)]];
        yield 'capital letters, caseless' => ['prop' => '{Lu}', 'caseless' => true, 'release' => '10.48', 'bytes' => [...range(0x41, 0x5A), ...range(0x61, 0x7A), 0xB5, ...range(0xC0, 0xD6), ...range(0xD8, 0xF6), ...range(0xF8, 0xFF)]];
        yield 'script alone, its extensions' => ['prop' => '{Greek}', 'caseless' => false, 'release' => '10.45', 'bytes' => [0xB7]];
        yield 'script after sc' => ['prop' => '{sc:Greek}', 'caseless' => false, 'release' => '10.45', 'bytes' => []];
        yield 'script after Script and an equals sign' => ['prop' => '{ Script = latin }', 'caseless' => false, 'release' => '10.47', 'bytes' => [...range(0x41, 0x5A), ...range(0x61, 0x7A), 0xAA, 0xBA, ...range(0xC0, 0xD6), ...range(0xD8, 0xF6), ...range(0xF8, 0xFF)]];
        yield 'script extensions after scx' => ['prop' => '{scx=Greek}', 'caseless' => false, 'release' => '10.49', 'bytes' => [0xB7]];
        yield 'bidi class after bc' => ['prop' => '{bc:AL}', 'caseless' => false, 'release' => '10.49', 'bytes' => []];
        yield 'bidi class after Bidi_Class' => ['prop' => '{Bidi_Class=EN}', 'caseless' => false, 'release' => '10.45', 'bytes' => [...range(0x30, 0x39), 0xB2, 0xB3, 0xB9]];
        yield 'script of no character below 256' => ['prop' => '{Cyrillic}', 'caseless' => false, 'release' => '10.46', 'bytes' => []];
    }

    #[Test]
    public function test_a_property_is_not_known_before_10_45_nor_for_a_name_the_release_does_not_know(): void
    {
        $this->assertNull(PropertyBytes::ofProperty('{Nd}', false, new PcreTarget(80300, '10.44')));
        $this->assertNull(PropertyBytes::ofProperty('{Garay}', false, new PcreTarget(80300, '10.44')));
        $this->assertNull(PropertyBytes::ofProperty('{Sidetic}', false, new PcreTarget(80400, '10.47')));
        $this->assertNull(PropertyBytes::ofProperty('{nonsense}', false, new PcreTarget(80400, '10.49')));
        $this->assertNotNull(PropertyBytes::ofProperty('{Sidetic}', false, new PcreTarget(80400, '10.48')));
        $this->assertNull(PropertyBytes::ofKey('nd', new PcreTarget(80300, '10.44')));
    }

    /**
     * A release newer than the library knows reads the newest table.
     */
    #[Test]
    public function test_a_newer_release_reads_the_newest_table(): void
    {
        $this->assertSame(PropertyBytes::ofKey('xps', new PcreTarget(80400, '10.49')), PropertyBytes::ofKey('xps', new PcreTarget(80600, '10.52')));
    }

    /**
     * The table of the running release, from 10.45: every name it knows
     * matches in a class the characters below 256 its table lists, the
     * POSIX names and the types under UCP too.
     */
    #[Test]
    public function test_the_running_release_matches_what_its_table_lists(): void
    {
        $this->requiresPcre2(PropertyByteTablesWriter::FIRST_RELEASE, 'The bits of properties in the map of a class');

        $running = PcreTarget::runtime();
        $checked = 0;
        foreach ([PropertyNameTables::SCRIPTS, PropertyNameTables::PROPERTIES] as $lists) {
            foreach ($lists as $release => $list) {
                if (!$running->pcreAtLeast((string) $release)) {
                    continue;
                }

                foreach (explode(' ', $list) as $name) {
                    $this->assertSame(self::matched('/^[\\p{'.$name.'}]$/u'), PropertyBytes::ofProperty('{'.$name.'}', false, $running), $name);
                    $checked++;
                }
            }
        }

        foreach (PropertyBytes::UCP_POSIX as $posix => $key) {
            $this->assertSame(self::matched('/^[[:'.$posix.':]]$/u'), PropertyBytes::ofKey($key, $running), $posix);
        }

        foreach (PropertyBytes::UCP_TYPES as $type => $key) {
            $this->assertSame(self::matched('/^[\\'.$type.']$/u'), PropertyBytes::ofKey($key, $running), $type);
        }

        $this->assertSame(self::matched('/^[\\p{Lt}]$/iu'), PropertyBytes::ofProperty('{Lt}', true, $running));
        $this->assertGreaterThan(500, $checked);
    }

    #[Test]
    public function test_every_release_the_library_knows_from_10_45_reads_a_table(): void
    {
        foreach (PcreGateLimits::KNOWN_RELEASES as $release) {
            $this->assertSame(version_compare($release, PropertyByteTablesWriter::FIRST_RELEASE, '>='), null !== PropertyBytes::ofKey('nd', new PcreTarget(80400, $release)), $release);
        }
    }

    /**
     * The map of the characters below 256 a pattern matches.
     */
    private static function matched(string $pattern): string
    {
        $bytes = [];
        for ($codePoint = 0; $codePoint < 256; $codePoint++) {
            if (1 === preg_match($pattern, mb_chr($codePoint, 'UTF-8'))) {
                $bytes[] = $codePoint;
            }
        }

        return self::map($bytes);
    }

    /**
     * @param list<int> $bytes
     */
    private static function map(array $bytes): string
    {
        $map = str_repeat("\0", 32);
        foreach ($bytes as $byte) {
            $map[$byte >> 3] = \chr(\ord($map[$byte >> 3]) | 1 << ($byte & 7));
        }

        return $map;
    }
}
