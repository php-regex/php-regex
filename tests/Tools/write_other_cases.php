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

namespace PHPRegex\Tests\Tools;

/*
 * Writes src/Parser/Validation/OtherCaseTables.php from the Unicode tables
 * of PCRE2 releases.
 *
 * Each release ships the other case of every character in pcre2_ucd.c,
 * which a caseless class and a class of a letter and its other case are
 * compiled from. This reads them from the release sources, keeps one table
 * for each set of releases whose other cases are the same, named by the
 * Unicode version they ship, and writes the oldest in full, each later one
 * as what it adds to the table before it and drops from it. The caseless
 * sets, the characters of more than one other case, are the same in every
 * release but for the two Turkish ones 10.45 added: the script fails when a
 * release differs from those ClassLength knows.
 *
 * Usage: php tests/Tools/write_other_cases.php [--check] <release>...
 *
 * Each <release> is the source tree of a PCRE2 release, every release from
 * 10.32 on, as https://github.com/PCRE2Project/pcre2/releases ships them. With --check it writes nothing and exits non-zero when the
 * file is out of date.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Parser\Validation\ClassLength;

/**
 * The text of a file of a release source tree.
 */
function readSource(string $source, string $file): string
{
    $text = is_file(rtrim($source, '/').'/'.$file) ? file_get_contents(rtrim($source, '/').'/'.$file) : false;
    if (!\is_string($text)) {
        throw new \RuntimeException(\sprintf('%s holds no %s.', $source, $file));
    }

    return $text;
}

/**
 * The body of the C array $name, the last one defined: a file that does not
 * build the Unicode tables defines a dummy one first.
 */
function arrayBody(string $code, string $name): string
{
    $start = strrpos($code, 'PRIV('.$name.')[]');
    $open = false === $start ? false : strpos($code, '{', $start);
    if (false === $open) {
        throw new \RuntimeException(\sprintf('No table %s.', $name));
    }

    $depth = 0;
    for ($at = $open; $at < \strlen($code); $at++) {
        $depth += match ($code[$at]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };
        if (0 === $depth) {
            return substr($code, $open + 1, $at - $open - 1);
        }
    }

    throw new \RuntimeException(\sprintf('The table %s is not closed.', $name));
}

/**
 * The numbers of the C array $name, NOTACHAR read as -1.
 *
 * @return list<int>
 */
function arrayNumbers(string $code, string $name): array
{
    $values = [];
    foreach (preg_split('/[\s,{}]+/', arrayBody($code, $name), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
        $values[] = 'NOTACHAR' === $value ? -1 : (str_starts_with($value, '0x') ? (int) hexdec($value) : (int) $value);
    }

    return $values;
}

/**
 * The release a source tree holds, its Unicode version, the other case of
 * each character that has one and the caseless sets, the Turkish ones
 * apart.
 *
 * @return array{0: string, 1: string, 2: array<int, int>, 3: list<list<int>>}
 */
function readRelease(string $source): array
{
    if (1 !== preg_match('/PCRE2_MAJOR\s+(\d+).*?PCRE2_MINOR\s+(\d+)/s', readSource($source, 'src/pcre2.h.generic'), $version)) {
        throw new \RuntimeException(\sprintf('%s names no release.', $source));
    }

    $release = $version[1].'.'.$version[2];
    $ucd = readSource($source, 'src/pcre2_ucd.c');
    if (1 !== preg_match('/unicode_version\)\s*=\s*"([0-9.]+)"/', $ucd, $unicode)) {
        throw new \RuntimeException(\sprintf('PCRE2 %s names no Unicode version.', $release));
    }

    $code = (string) preg_replace('~/\*.*?\*/~s', '', $ucd);

    // A record of ucd_records: script, type, grapheme break, the offset of
    // the caseless set and the distance to the other case, then what later
    // releases added.
    preg_match_all('/\{([^{}]*)\}/', arrayBody($code, 'ucd_records'), $records);
    $distances = [];
    foreach ($records[1] as $record) {
        $distances[] = (int) trim(explode(',', $record)[4] ?? '0');
    }

    $stage1 = arrayNumbers($code, 'ucd_stage1');
    $stage2 = arrayNumbers($code, 'ucd_stage2');
    $other = [];
    for ($codePoint = 0; $codePoint <= 0x10FFFF; $codePoint++) {
        $distance = $distances[$stage2[$stage1[$codePoint >> 7] * 128 + ($codePoint & 127)]];
        if (0 !== $distance) {
            $other[$codePoint] = $codePoint + $distance;
        }
    }

    $sets = [];
    $set = [];
    foreach (arrayNumbers($code, 'ucd_caseless_sets') as $value) {
        if (-1 !== $value) {
            $set[] = $value;
        } elseif ([] !== $set) {
            $sets[] = $set;
            $set = [];
        }
    }

    return [$release, $unicode[1], $other, $sets];
}

/**
 * Runs of characters whose other case is the same distance away, as
 * "first:count:distance" in hexadecimal; a distance of 0 for a run of
 * pairs, each the other case of the other.
 *
 * @param array<int, int> $other
 */
function runs(array $other): string
{
    ksort($other);
    $runs = [];
    $codePoints = array_keys($other);
    $count = \count($codePoints);
    for ($index = 0; $index < $count;) {
        $first = $codePoints[$index];
        $pairs = 0;
        while (($other[$first + $pairs] ?? null) === $first + $pairs + 1 && ($other[$first + $pairs + 1] ?? null) === $first + $pairs) {
            $pairs += 2;
        }

        if ($pairs > 0) {
            $runs[] = \sprintf('%x:%x:0', $first, $pairs);
            $index += $pairs;

            continue;
        }

        $distance = $other[$first] - $first;
        $length = 1;
        while (($other[$first + $length] ?? null) === $first + $length + $distance) {
            $length++;
        }

        $runs[] = \sprintf('%x:%x:%s%x', $first, $length, $distance < 0 ? '-' : '', abs($distance));
        $index += $length;
    }

    return implode(' ', $runs);
}

/**
 * Runs as PHP string literals, twelve a line.
 */
function quote(string $runs): string
{
    if ('' === $runs) {
        return "''";
    }

    $lines = array_map(static fn (array $line): string => implode(' ', $line), array_chunk(explode(' ', $runs), 12));

    return "'".implode("'\n                .' ", $lines)."'";
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$check = \in_array('--check', $arguments, true);
$sources = array_values(array_filter(\array_slice($arguments, 1), static fn (string $argument): bool => '--check' !== $argument));
if ([] === $sources) {
    fwrite(\STDERR, 'Usage: php tests/Tools/write_other_cases.php [--check] <PCRE2 source tree>...'.\PHP_EOL);

    exit(2);
}

$known = (new \ReflectionClassConstant(ClassLength::class, 'CASELESS_SETS'))->getValue();
$tables = [];
foreach ($sources as $source) {
    [$release, $unicode, $other, $sets] = readRelease($source);
    $turkish = version_compare($release, '10.45', '>=') ? [[0x69, 0x130], [0x49, 0x131]] : [];
    if ([...(\is_array($known) ? $known : []), ...$turkish] !== $sets) {
        throw new \RuntimeException(\sprintf('The caseless sets of PCRE2 %s are not those ClassLength knows.', $release));
    }

    [$major, $minor] = explode('.', $release);
    $tables[(int) $major * 1000 + (int) $minor] = [$release, $unicode, $other];
}

ksort($tables);

$releases = [];
$entries = [];
$before = null;
foreach ($tables as [$release, $unicode, $other]) {
    if (null !== $before && $before[1] === $other) {
        continue;
    }

    if (null !== $before && $before[0] === $unicode) {
        throw new \RuntimeException(\sprintf('PCRE2 %s changes the other cases of Unicode %s.', $release, $unicode));
    }

    $releases[$release] = $unicode;
    $added = null === $before ? $other : array_diff_assoc($other, $before[1]);
    $dropped = null === $before ? [] : array_keys(array_diff_assoc($before[1], $other));
    $entries[$unicode] = [null === $before ? null : $before[0], runs($added), implode(' ', array_map(dechex(...), $dropped))];
    $before = [$unicode, $other];
}

$php = <<<'CODE'
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

    namespace PHPRegex\Parser\Validation;

    /**
     * The other case of each character in the Unicode tables of PCRE2
     * (pcre2_ucd.c), as tests/Tools/write_other_cases.php writes them from the
     * release sources: never edited by hand.
     *
     * @internal
     */
    final class OtherCaseTables
    {
        /**
         * The first release of each table, from the newest.
         */
        public const RELEASES = [

    CODE;
foreach (array_reverse($releases, true) as $release => $unicode) {
    $php .= \sprintf("        '%s' => '%s',\n", $release, $unicode);
}

$php .= <<<'CODE'
        ];

        /**
         * Each table by the Unicode version it ships: the one it builds on,
         * the runs of characters whose other case it adds or changes, each as
         * "first:count:distance" in hexadecimal, a distance of 0 for a run of
         * pairs, each the other case of the other; and the characters whose
         * other case it drops.
         */
        public const TABLES = [

    CODE;
foreach ($entries as $unicode => [$base, $added, $dropped]) {
    $php .= \sprintf("        '%s' => [\n            'base' => %s,\n            'add' => %s,\n            'drop' => '%s',\n        ],\n", $unicode, null === $base ? 'null' : "'".$base."'", quote($added), $dropped);
}

$php .= <<<'CODE'
        ];
    }

    CODE;

$path = \dirname(__DIR__, 2).'/src/Parser/Validation/OtherCaseTables.php';
$current = is_file($path) ? file_get_contents($path) : false;
if ($php === $current) {
    echo 'OtherCaseTables.php is up to date.', \PHP_EOL;

    exit(0);
}

if ($check) {
    fwrite(\STDERR, 'OtherCaseTables.php differs from the release tables. Run "php tests/Tools/write_other_cases.php" with the release sources and commit src/Parser/Validation/OtherCaseTables.php.'.\PHP_EOL);

    exit(1);
}

file_put_contents($path, $php);

echo 'OtherCaseTables.php written.', \PHP_EOL;
