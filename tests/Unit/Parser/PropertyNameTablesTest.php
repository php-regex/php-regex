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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PropertyNames;
use PHPRegex\Parser\Syntax\PropertyNameTables;
use PHPRegex\Parser\Validation\OtherCaseTables;
use PHPRegex\Tests\Support\PcreGateLimits;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\TestUtils\PropertyNameTablesWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The property names of each release, as tests/Tools/write_property_names.php
 * reads them from the release sources, held against the engine that runs:
 * every name its release lists compiles, a script in the script tables too,
 * and no name a later release added compiles.
 */
final class PropertyNameTablesTest extends TestCase
{
    use PcreGates;

    #[Test]
    public function test_the_file_is_what_the_script_writes_from_the_tables_it_holds(): void
    {
        $this->assertSame(
            PropertyNameTablesWriter::render(PropertyNameTablesWriter::committed()),
            (string) file_get_contents(PropertyNameTablesWriter::FILE),
            'Run "php tests/Tools/write_property_names.php" with the release sources: the file is never edited by hand.',
        );
    }

    #[Test]
    public function test_every_release_the_library_knows_from_10_40_is_read(): void
    {
        $releases = array_values(array_filter(PcreGateLimits::KNOWN_RELEASES, static fn (string $release): bool => version_compare($release, '10.40', '>=')));

        $this->assertSame($releases, array_keys(PropertyNameTables::RELEASES));
    }

    #[Test]
    public function test_each_release_reads_the_unicode_version_its_case_tables_ship(): void
    {
        foreach (PropertyNameTables::RELEASES as $release => $unicode) {
            $shipped = null;
            foreach (OtherCaseTables::RELEASES as $first => $version) {
                if (version_compare((string) $release, (string) $first, '>=')) {
                    $shipped = $version;

                    break;
                }
            }

            $this->assertSame($shipped, $unicode, (string) $release);
        }
    }

    #[Test]
    public function test_no_name_is_listed_twice(): void
    {
        $names = [];
        foreach ([...array_values(PropertyNameTables::SCRIPTS), ...array_values(PropertyNameTables::PROPERTIES)] as $list) {
            array_push($names, ...explode(' ', $list));
        }

        $this->assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * Each name is known from the release that lists it, a script in the
     * script tables too, and a name in another table than its own never.
     */
    #[Test]
    public function test_each_name_is_known_from_the_release_that_lists_it(): void
    {
        foreach (['scripts' => PropertyNameTables::SCRIPTS, 'properties' => PropertyNameTables::PROPERTIES] as $kind => $lists) {
            foreach ($lists as $release => $list) {
                foreach (explode(' ', $list) as $name) {
                    $this->assertSame((string) $release, PropertyNames::firstReleaseKnowing($name, true), $name);
                    $this->assertSame(version_compare((string) $release, '10.45', '<') ? '10.45' : (string) $release, PropertyNames::firstReleaseKnowing('  ^ '.strtoupper($name), true), $name.' after a "^" put off the brace');
                    $this->assertSame('scripts' === $kind ? (string) $release : null, PropertyNames::firstReleaseKnowing('Script_Extensions = '.$name, true), 'scx '.$name);
                    $this->assertNull(PropertyNames::firstReleaseKnowing('gc='.$name, true), 'gc '.$name);
                }
            }
        }
    }

    #[Test]
    public function test_the_running_release_compiles_the_names_of_its_table_and_no_later_one(): void
    {
        $this->requiresPcre2('10.40', 'The names of the tables');

        $running = PcreTarget::runtime();
        $checked = 0;
        foreach (['scripts' => PropertyNameTables::SCRIPTS, 'properties' => PropertyNameTables::PROPERTIES] as $kind => $lists) {
            foreach ($lists as $release => $list) {
                $known = $running->pcreAtLeast((string) $release);
                foreach (explode(' ', $list) as $name) {
                    $this->assertSame($known, false !== @preg_match('/\\p{'.$name.'}/u', ''), \sprintf('\\p{%s} on PCRE2 %s, listed from %s.', $name, \PCRE_VERSION, $release));
                    $this->assertSame($known && 'scripts' === $kind, false !== @preg_match('/\\p{sc:'.$name.'}/u', ''), \sprintf('\\p{sc:%s} on PCRE2 %s.', $name, \PCRE_VERSION));
                    $this->assertSame($known && 'scripts' === $kind, false !== @preg_match('/\\P{scx='.$name.'}/u', ''), \sprintf('\\P{scx=%s} on PCRE2 %s.', $name, \PCRE_VERSION));
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(500, $checked);
    }
}
