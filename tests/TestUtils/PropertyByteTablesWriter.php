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

namespace PHPRegex\Tests\TestUtils;

use PHPRegex\Parser\Syntax\PropertyNameTables;
use PHPRegex\Parser\Validation\PropertyBytes;
use PHPRegex\Parser\Validation\PropertyByteTables;

/**
 * Writes src/Parser/Validation/PropertyByteTables.php from pcre2test of
 * each PCRE2 release from 10.45: the characters below 256 each property
 * matches in a class, the bits it sets in the map of that class, read from
 * the engine itself, one match per character and property.
 *
 * A name is read in each spelling the library looks up: a script with
 * "sc:" and with "scx:", which the name alone stands for (the reader fails
 * where it does not), any other name alone. The POSIX names and the types
 * UCP reads as properties are read too: those PropertyBytes reads as a
 * name must match what that name matches, the others are kept under their
 * own key, as must a caseless "Lu", "Ll" or "Lt" match what "L&" matches.
 *
 * Releases that match the same are one table, under the first of them.
 *
 * @phpstan-type Release = array{release: string, unicode: string, bytes: array<string, string>}
 * @phpstan-type Tables = array{releases: array<string, string>, tables: array<string, array<string, list<string>>>}
 */
final class PropertyByteTablesWriter
{
    public const FILE = __DIR__.'/../../src/Parser/Validation/PropertyByteTables.php';

    /**
     * The first release whose classes set the bits of their properties in
     * their map.
     */
    public const FIRST_RELEASE = '10.45';

    /**
     * The longest line of names the file holds.
     */
    private const LINE_WIDTH = 100;

    /**
     * What the pcre2test of a release reads: its release, its Unicode
     * version, and the characters below 256 each spelling matches, as
     * runs, for those that match any.
     *
     * @return Release
     */
    public static function probe(string $pcre2test): array
    {
        $configuration = self::run($pcre2test, ['-C']);
        if (1 !== preg_match('/PCRE2 version (\d+\.\d+)/', $configuration, $version) || 1 !== preg_match('/Unicode version ([0-9.]+)/', $configuration, $unicode)) {
            throw new \RuntimeException(\sprintf('%s names no release or no Unicode version.', $pcre2test));
        }

        $release = $version[1];
        if (version_compare($release, self::FIRST_RELEASE, '<')) {
            throw new \RuntimeException(\sprintf('PCRE2 %s keeps the bits of its properties out of the map: read releases from %s.', $release, self::FIRST_RELEASE));
        }

        $probes = self::probes($release);
        $matched = self::matches($pcre2test, array_values($probes));
        $read = array_combine(array_keys($probes), $matched);

        $bytes = [];
        foreach ($read as $probe => $runs) {
            [$kind, $name] = explode(' ', $probe, 3) + [1 => ''];
            $expected = match ($kind) {
                'key' => $runs,
                'scx' => $read['key scx:'.$name] ?? null,
                default => $read['key '.$name] ?? null,
            };

            if ($expected !== $runs) {
                throw new \RuntimeException(\sprintf('On PCRE2 %s, "%s" matches "%s" below 256, not "%s" as the library reads it.', $release, $probe, $runs, (string) $expected));
            }

            if ('key' === $kind && '' !== $runs) {
                $bytes[$name] = $runs;
            }
        }

        ksort($bytes, \SORT_STRING);

        return ['release' => $release, 'unicode' => $unicode[1], 'bytes' => $bytes];
    }

    /**
     * The tables of the releases read: one for each run of releases that
     * match the same, under the first of them, the names that match each
     * set of characters by those characters.
     *
     * @param list<Release> $releases
     *
     * @return Tables
     */
    public static function build(array $releases): array
    {
        usort($releases, static fn (array $a, array $b): int => version_compare($a['release'], $b['release']));

        $tables = ['releases' => [], 'tables' => []];
        $before = null;
        foreach ($releases as ['release' => $release, 'unicode' => $unicode, 'bytes' => $bytes]) {
            if ($bytes === $before) {
                continue;
            }

            $table = [];
            foreach ($bytes as $name => $runs) {
                $table[$runs][] = (string) $name;
            }

            $tables['releases'] = [$release => $unicode] + $tables['releases'];
            $tables['tables'][$release] = $table;
            $before = $bytes;
        }

        return self::sorted($tables);
    }

    /**
     * The tables the committed file holds.
     *
     * @return Tables
     */
    public static function committed(): array
    {
        $tables = [];
        foreach (PropertyByteTables::TABLES as $release => $table) {
            $tables[(string) $release] = array_map(static fn (string $names): array => explode(' ', $names), $table);
        }

        return self::sorted(['releases' => PropertyByteTables::RELEASES, 'tables' => $tables]);
    }

    /**
     * The file that holds the tables.
     *
     * @param Tables $tables
     */
    public static function render(array $tables): string
    {
        $releases = '';
        foreach ($tables['releases'] as $release => $unicode) {
            $releases .= \sprintf("        '%s' => '%s',\n", $release, $unicode);
        }

        $code = '';
        foreach ($tables['tables'] as $release => $table) {
            $code .= \sprintf("        '%s' => [\n", $release);
            foreach ($table as $runs => $names) {
                $code .= \sprintf("            '%s' => '%s',\n", $runs, self::wrapped($names));
            }
            $code .= "        ],\n";
        }

        return <<<CODE
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

            namespace PHPRegex\\Parser\\Validation;

            /**
             * The characters below 256 each property matches in a class of PCRE2
             * 10.45 and later, the bits it sets in the map of that class, written by
             * tests/Tools/write_property_bytes.php from pcre2test of each release:
             * never edited by hand.
             *
             * A name is spelled as PCRE2 looks it up, in lower case, without spaces,
             * hyphens or underscores, a script after "sc:" or "scx:" (the name alone
             * reads as "scx:"), a Bidi class as "bidi" then the class, and a POSIX
             * name UCP reads as a property no name spells in brackets. A name not
             * listed matches no character below 256.
             *
             * @internal
             */
            final class PropertyByteTables
            {
                /**
                 * The first release of each table, from the newest, with the Unicode
                 * version it ships.
                 */
                public const RELEASES = [
            {$releases}    ];

                /**
                 * Each table by its first release: the characters a property matches,
                 * as runs "first-last" or one character, in hexadecimal, and the
                 * names that match them.
                 */
                public const TABLES = [
            {$code}    ];
            }

            CODE;
    }

    /**
     * The characters set, as runs "first-last" or one character, in
     * hexadecimal.
     *
     * @param list<bool> $matched
     */
    public static function runs(array $matched): string
    {
        $runs = [];
        for ($byte = 0; $byte < 256; $byte++) {
            if (!$matched[$byte]) {
                continue;
            }

            $last = $byte;
            while ($last < 255 && $matched[$last + 1]) {
                $last++;
            }

            $runs[] = $last === $byte ? \sprintf('%02x', $byte) : \sprintf('%02x-%02x', $byte, $last);
            $byte = $last;
        }

        return implode(' ', $runs);
    }

    /**
     * The tables in the order the file writes them: each set of characters
     * by its first one, the names in order.
     *
     * @param Tables $tables
     *
     * @return Tables
     */
    private static function sorted(array $tables): array
    {
        foreach ($tables['tables'] as $release => $table) {
            foreach ($table as $runs => $names) {
                sort($names, \SORT_STRING);
                $table[$runs] = $names;
            }

            ksort($table, \SORT_STRING);
            $tables['tables'][$release] = $table;
        }

        krsort($tables['tables'], \SORT_NATURAL);

        return $tables;
    }

    /**
     * Each spelling read, by what it is checked as: "key <name>" kept under
     * that name, "scx <name>" a script alone, which must match what "scx:"
     * does, "same <name> <spelling>" one that must match what <name> does.
     *
     * @return array<string, string> the pattern of each
     */
    private static function probes(string $release): array
    {
        $probes = [];
        foreach ([[PropertyNameTables::SCRIPTS, true], [PropertyNameTables::PROPERTIES, false]] as [$lists, $isScript]) {
            foreach ($lists as $added => $list) {
                if (version_compare((string) $added, $release, '>')) {
                    continue;
                }

                foreach (explode(' ', $list) as $name) {
                    if ($isScript) {
                        $probes['key sc:'.$name] = '/^[\\p{sc:'.$name.'}]$/utf';
                        $probes['key scx:'.$name] = '/^[\\p{scx:'.$name.'}]$/utf';
                        $probes['scx '.$name] = '/^[\\p{'.$name.'}]$/utf';
                    } else {
                        $probes['key '.$name] = '/^[\\p{'.$name.'}]$/utf';
                    }
                }
            }
        }

        // A POSIX name UCP reads as a property no name spells is kept under
        // its own key, any other must match what its name does.
        foreach (PropertyBytes::UCP_POSIX as $posix => $key) {
            $probes[(str_starts_with($key, '[') ? 'key ' : 'same '.$key.' ').'[:'.$posix.':]'] = '/^[[:'.$posix.':]]$/utf,ucp';
        }

        foreach (PropertyBytes::UCP_TYPES as $type => $key) {
            $probes['same '.$key.' \\'.$type] = '/^[\\'.$type.']$/utf,ucp';
        }

        foreach (PropertyBytes::CASELESS as $name => $key) {
            $probes['same '.$key.' caseless '.$name] = '/^[\\p{'.$name.'}]$/caseless,utf';
        }

        return $probes;
    }

    /**
     * The characters below 256 each pattern matches, as runs.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private static function matches(string $pcre2test, array $patterns): array
    {
        $subjects = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $subjects .= \sprintf("\\x{%02x}\n", $byte);
        }

        $input = '';
        foreach ($patterns as $pattern) {
            $input .= $pattern."\n".$subjects."\n";
        }

        $directory = sys_get_temp_dir().'/property-bytes-'.getmypid();
        if (!is_dir($directory) && !mkdir($directory) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create %s.', $directory));
        }

        file_put_contents($directory.'/input', $input);
        self::run($pcre2test, ['-q', $directory.'/input', $directory.'/output']);
        $output = (string) file_get_contents($directory.'/output');
        unlink($directory.'/input');
        unlink($directory.'/output');
        rmdir($directory);

        $results = [];
        $current = -1;
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '/')) {
                $results[++$current] = [];
            } elseif ('No match' === $line || str_starts_with($line, ' 0:')) {
                $results[$current][] = 'No match' !== $line;
            } elseif (str_starts_with($line, 'Failed') || str_starts_with($line, '**')) {
                throw new \RuntimeException(\sprintf('%s answers %s: %s', $pcre2test, $patterns[max($current, 0)], $line));
            }
        }

        if (\count($results) !== \count($patterns)) {
            throw new \RuntimeException(\sprintf('%s answered %d patterns of %d.', $pcre2test, \count($results), \count($patterns)));
        }

        return array_values(array_map(static function (array $matched) use ($pcre2test): string {
            if (256 !== \count($matched)) {
                throw new \RuntimeException(\sprintf('%s answered %d characters of 256.', $pcre2test, \count($matched)));
            }

            return self::runs($matched);
        }, $results));
    }

    /**
     * @param list<string> $arguments
     */
    private static function run(string $pcre2test, array $arguments): string
    {
        $command = escapeshellarg($pcre2test).' '.implode(' ', array_map(escapeshellarg(...), $arguments));
        $output = [];
        exec($command.' 2>&1', $output, $status);
        if (0 !== $status && !\in_array('-q', $arguments, true)) {
            throw new \RuntimeException(\sprintf('%s exited with %d.', $command, $status));
        }

        return implode("\n", $output);
    }

    /**
     * @param list<string> $names
     */
    private static function wrapped(array $names): string
    {
        $lines = [];
        $line = '';
        foreach ($names as $name) {
            if ('' !== $line && \strlen($line) + 1 + \strlen($name) > self::LINE_WIDTH) {
                $lines[] = $line;
                $line = '';
            }
            $line .= ('' === $line ? '' : ' ').$name;
        }
        $lines[] = $line;

        return implode("'\n                .' ", $lines);
    }
}
