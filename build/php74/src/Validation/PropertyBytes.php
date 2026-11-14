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

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PropertyNames;
use PHPRegex\Parser\Text\Ascii;

/**
 * The characters below 256 a property matches in a class of PCRE2 10.45
 * and later, which sets them in the map of that class, as a map of 32
 * bytes: from the table of the release (PropertyByteTables), a release
 * newer than the library knows reading the newest.
 *
 * @internal
 */
final class PropertyBytes
{
    /**
     * The property each POSIX name UCP reads as one is looked up by, as
     * PCRE2 substitutes them; one no name spells is in brackets. "blank"
     * is "\h" and "ascii" a map.
     */
    public const UCP_POSIX = [
        'alpha' => 'l',
        'lower' => 'll',
        'upper' => 'lu',
        'alnum' => 'xan',
        'cntrl' => 'cc',
        'digit' => 'nd',
        'graph' => '[:graph:]',
        'print' => '[:print:]',
        'punct' => '[:punct:]',
        'space' => 'xps',
        'word' => 'xwd',
        'xdigit' => '[:xdigit:]',
    ];

    /**
     * The property each type UCP reads as one is looked up by.
     */
    public const UCP_TYPES = ['d' => 'nd', 's' => 'xsp', 'w' => 'xwd'];

    /**
     * The properties a caseless "\p" reads as "L&".
     */
    public const CASELESS = ['lu' => 'l&', 'll' => 'l&', 'lt' => 'l&'];

    /**
     * The characters a name drops when PCRE2 looks it up.
     */
    private const LOOSE_IGNORED = [' ', "\t", "\n", "\v", "\f", "\r", '-', '_'];

    /**
     * Each table read, by its first release: the map of each name.
     *
     * @var array<string, array<string, string>>
     */
    private static array $maps = [];

    /**
     * The characters below 256 a property matches, as written after "\p"
     * or "\P" (its braces and its "^" ignored), under caseless matching or
     * not; null before 10.45 or for a name the release does not know.
     */
    public static function ofProperty(string $prop, bool $caseless, PcreTarget $target): ?string
    {
        $braced = 0 === strncmp($prop, '{', strlen('{'));
        $name = str_replace('^', '', $braced ? (string) substr($prop, 1, -1) : $prop);
        $known = PropertyNames::firstReleaseKnowing($name, $braced);
        if (null === $known || !$target->pcreAtLeast($known)) {
            return null;
        }

        $key = Ascii::lower(str_replace(self::LOOSE_IGNORED, '', $name));
        $separator = strcspn($key, ':=');
        if ($separator < \strlen($key)) {
            // A release knows no other table than these, and "scx" or
            // "scriptextensions": a name in any other is not known above.
            $value = (string) substr($key, $separator + 1);
            $matchSubject93 = (string) substr($key, 0, $separator);
            switch (true) {
                case 'bc' === $matchSubject93:
                case 'bidiclass' === $matchSubject93:
                    $key = 'bidi'.$value;
                    break;
                case 'sc' === $matchSubject93:
                case 'script' === $matchSubject93:
                    $key = 'sc:'.$value;
                    break;
                default:
                    $key = 'scx:'.$value;
                    break;
            }
        }

        return self::ofKey($caseless ? self::CASELESS[$key] ?? $key : $key, $target);
    }

    /**
     * The characters below 256 a property matches, by the name it is
     * looked up by; a script alone is its extensions. Null before 10.45.
     */
    public static function ofKey(string $key, PcreTarget $target): ?string
    {
        $maps = self::maps($target);
        if (null === $maps) {
            return null;
        }

        return $maps[$key] ?? $maps['scx:'.$key] ?? ExtendedClassLength::NO_BYTE;
    }

    /**
     * @return array<string, string>|null
     */
    private static function maps(PcreTarget $target): ?array
    {
        $first = null;
        foreach (array_keys(PropertyByteTables::RELEASES) as $release) {
            if ($target->pcreAtLeast((string) $release)) {
                $first = (string) $release;

                break;
            }
        }

        if (null === $first) {
            return null;
        }

        if (!isset(self::$maps[$first])) {
            StaticCaches::register(self::class, static function (): void {
                self::$maps = [];
            });

            $maps = [];
            foreach (PropertyByteTables::TABLES[$first] as $runs => $names) {
                $map = ExtendedClassLength::NO_BYTE;
                foreach (explode(' ', (string) $runs) as $run) {
                    [$low, $high] = explode('-', $run) + [1 => $run];
                    for ($byte = (int) hexdec($low), $last = (int) hexdec($high); $byte <= $last; ++$byte) {
                        $map[$byte >> 3] = \chr(\ord($map[$byte >> 3]) | 1 << ($byte & 7));
                    }
                }

                foreach (explode(' ', $names) as $name) {
                    $maps[$name] = $map;
                }
            }

            self::$maps[$first] = $maps;
        }

        return self::$maps[$first];
    }
}
