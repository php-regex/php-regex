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

/**
 * Writes src/Parser/Syntax/PropertyNameTables.php from the sources of the
 * PCRE2 releases: the names PRIV(utt) lists in pcre2_ucptables.c, or
 * pcre2_ucptables_inc.h from 10.47, and whether each is a script, the one
 * kind "sc:" and "scx:" take.
 *
 * Every release from 10.40 to 10.49 only adds names, and makes no script
 * another kind of property: the file lists the names by the release that
 * added them, and the reader refuses a release that breaks that rule, since
 * the library could not judge it from such lists.
 *
 * @phpstan-type Release = array{release: string, unicode: string, names: array<string, bool>}
 * @phpstan-type Tables = array{releases: array<string, string>, scripts: array<string, list<string>>, properties: array<string, list<string>>}
 */
final class PropertyNameTablesWriter
{
    public const FILE = __DIR__.'/../../src/Parser/Syntax/PropertyNameTables.php';

    /**
     * The longest line of names the file holds.
     */
    private const LINE_WIDTH = 100;

    /**
     * What a release source tree holds: its release, its Unicode version,
     * and its property names, each true for a script.
     *
     * @return Release
     */
    public static function read(string $source): array
    {
        if (1 !== preg_match('/PCRE2_MAJOR\s+(\d+).*?PCRE2_MINOR\s+(\d+)/s', self::source($source, 'src/pcre2.h.generic'), $version)) {
            throw new \RuntimeException(\sprintf('%s names no release.', $source));
        }

        $release = $version[1].'.'.$version[2];
        if (1 !== preg_match('/unicode_version\)\s*=\s*"([0-9.]+)"/', self::source($source, 'src/pcre2_ucd.c'), $unicode)) {
            throw new \RuntimeException(\sprintf('PCRE2 %s names no Unicode version.', $release));
        }

        $tables = is_file($source.'/src/pcre2_ucptables_inc.h')
            ? self::source($source, 'src/pcre2_ucptables_inc.h')
            : self::source($source, 'src/pcre2_ucptables.c');

        // Each name is a macro of its letters, and PRIV(utt_names) the names
        // one after the other, each ended by a NUL.
        preg_match_all('/^#define\s+(STRING_\w+0)\s+((?:STR_\w+\s+)*)"\\\\0"\s*$/m', $tables, $defines, \PREG_SET_ORDER);
        $spelled = [];
        foreach ($defines as [, $macro, $letters]) {
            $spelled[$macro] = implode('', array_map(self::letter(...), preg_split('/\s+/', trim($letters), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
        }

        if (1 !== preg_match('/PRIV\(utt_names\)\[\]\s*=(.*?);/s', $tables, $list)) {
            throw new \RuntimeException(\sprintf('PCRE2 %s holds no utt_names.', $release));
        }

        $byOffset = [];
        $offset = 0;
        preg_match_all('/\bSTRING_\w+0\b/', $list[1], $macros);
        foreach ($macros[0] as $macro) {
            $name = $spelled[$macro] ?? throw new \RuntimeException(\sprintf('PCRE2 %s does not define %s.', $release, $macro));
            $byOffset[$offset] = $name;
            $offset += \strlen($name) + 1;
        }

        if (1 !== preg_match('/PRIV\(utt\)\[\]\s*=\s*\{(.*?)\};/s', $tables, $table)) {
            throw new \RuntimeException(\sprintf('PCRE2 %s holds no utt table.', $release));
        }

        preg_match_all('/\{\s*(\d+),\s*(PT_\w+),\s*\w+\s*\}/', $table[1], $rows, \PREG_SET_ORDER);
        $names = [];
        foreach ($rows as [, $at, $type]) {
            $name = $byOffset[(int) $at] ?? throw new \RuntimeException(\sprintf('PCRE2 %s names no property at offset %s.', $release, $at));
            $names[$name] = 'PT_SC' === $type || 'PT_SCX' === $type;
        }

        if ([] === $names || \count($names) !== \count($rows)) {
            throw new \RuntimeException(\sprintf('PCRE2 %s lists no property, or one twice.', $release));
        }

        ksort($names, \SORT_STRING);

        return ['release' => $release, 'unicode' => $unicode[1], 'names' => $names];
    }

    /**
     * The tables of the releases read: each name under the release that
     * added it.
     *
     * @param list<Release> $releases
     *
     * @return Tables
     */
    public static function build(array $releases): array
    {
        usort($releases, static fn (array $a, array $b): int => version_compare($a['release'], $b['release']));

        $tables = ['releases' => [], 'scripts' => [], 'properties' => []];
        $before = [];
        foreach ($releases as ['release' => $release, 'unicode' => $unicode, 'names' => $names]) {
            foreach ($before as $name => $isScript) {
                if (($names[$name] ?? null) !== $isScript) {
                    throw new \RuntimeException(\sprintf('PCRE2 %s drops "%s" or changes its kind: the tables list names a release adds only.', $release, $name));
                }
            }

            $tables['releases'][$release] = $unicode;
            foreach (array_diff_key($names, $before) as $name => $isScript) {
                $tables[$isScript ? 'scripts' : 'properties'][$release][] = (string) $name;
            }

            $before = $names;
        }

        return $tables;
    }

    /**
     * The tables the committed file holds.
     *
     * @return Tables
     */
    public static function committed(): array
    {
        $split = static fn (string $names): array => explode(' ', $names);

        return [
            'releases' => PropertyNameTables::RELEASES,
            'scripts' => array_map($split, PropertyNameTables::SCRIPTS),
            'properties' => array_map($split, PropertyNameTables::PROPERTIES),
        ];
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
        $scripts = self::lists($tables['scripts']);
        $properties = self::lists($tables['properties']);

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

            namespace PHPRegex\\Parser\\Syntax;

            /**
             * The property names each PCRE2 release from 10.40 knows, as PRIV(utt) of
             * its pcre2_ucptables.c lists them (pcre2_ucptables_inc.h from 10.47),
             * written by tests/Tools/write_property_names.php from the release
             * sources: never edited by hand.
             *
             * A name is spelled as PCRE2 looks it up: in lower case, without spaces,
             * hyphens or underscores, and a Bidi class as "bidi" then the class. No
             * release dropped a name or made a script another kind of property, so
             * each list holds the names its release added.
             *
             * @internal
             */
            final class PropertyNameTables
            {
                /**
                 * The releases read, with the Unicode version of each.
                 */
                public const RELEASES = [
            {$releases}    ];

                /**
                 * The scripts, the names "sc:" and "scx:" take, by the release that
                 * added them.
                 */
                public const SCRIPTS = [
            {$scripts}    ];

                /**
                 * The other names, by the release that added them: the general
                 * categories, PCRE's own, the binary properties and the Bidi classes.
                 */
                public const PROPERTIES = [
            {$properties}    ];
            }

            CODE;
    }

    /**
     * @param array<string, list<string>> $lists
     */
    private static function lists(array $lists): string
    {
        $code = '';
        foreach ($lists as $release => $names) {
            sort($names, \SORT_STRING);
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

            $code .= \sprintf("        '%s' => '%s',\n", $release, implode("'\n            .' ", $lines));
        }

        return $code;
    }

    private static function letter(string $macro): string
    {
        if (1 === preg_match('/^STR_([a-z0-9])$/', $macro, $letter)) {
            return $letter[1];
        }

        return match ($macro) {
            'STR_AMPERSAND' => '&',
            default => throw new \RuntimeException(\sprintf('No letter for %s.', $macro)),
        };
    }

    private static function source(string $source, string $file): string
    {
        $path = rtrim($source, '/').'/'.$file;
        $text = is_file($path) ? file_get_contents($path) : false;
        if (!\is_string($text)) {
            throw new \RuntimeException(\sprintf('%s holds no %s.', $source, $file));
        }

        return $text;
    }
}
