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

/**
 * The other case of each character in the Unicode tables of a PCRE2
 * release, which a caseless class past ASCII and a class of a character
 * and its other case are compiled from: Unicode 11 in 10.32 and 10.33,
 * 12.1 in 10.34, 13 from 10.35, 14 from 10.39, 15 from 10.43 (which drops
 * the other case of the long s and the Kelvin sign, each in a caseless set
 * with an ASCII letter), 16 from 10.45, 17 from 10.48. A release older than
 * the library knows reads the oldest tables, a newer one the newest.
 *
 * @internal
 */
final class OtherCases
{
    /**
     * Each table read, by the Unicode version it ships: at most one per
     * release the library knows.
     *
     * @var array<string, self>
     */
    private static array $tables = [];

    /**
     * @param array<int, int> $otherCases the other case of each character
     *                                    that has one, by code point in order
     * @param list<int>       $codePoints those code points
     */
    private function __construct(private readonly array $otherCases, private readonly array $codePoints) {}

    public static function of(PcreTarget $target): self
    {
        $unicode = null;
        foreach (OtherCaseTables::RELEASES as $release => $unicode) {
            if ($target->pcreAtLeast($release)) {
                break;
            }
        }

        $unicode = (string) $unicode;
        if (!isset(self::$tables[$unicode])) {
            StaticCaches::register(self::class, static function (): void {
                self::$tables = [];
            });
            $otherCases = self::read($unicode);
            ksort($otherCases);
            self::$tables[$unicode] = new self($otherCases, array_keys($otherCases));
        }

        return self::$tables[$unicode];
    }

    /**
     * The other case of a character, or the character itself when it has
     * none.
     */
    public function otherCase(int $codePoint): int
    {
        return $this->otherCases[$codePoint] ?? $codePoint;
    }

    /**
     * The first character from $codePoint on that has another case, or null
     * when none has.
     */
    public function nextCased(int $codePoint): ?int
    {
        [$first, $last] = [0, \count($this->codePoints)];
        while ($first < $last) {
            $middle = ($first + $last) >> 1;
            if ($this->codePoints[$middle] < $codePoint) {
                $first = $middle + 1;
            } else {
                $last = $middle;
            }
        }

        return $this->codePoints[$first] ?? null;
    }

    /**
     * The other cases of a table: those of the table it builds on, less the
     * ones it drops, with the runs it adds.
     *
     * @return array<int, int>
     */
    private static function read(string $unicode): array
    {
        ['base' => $base, 'add' => $add, 'drop' => $drop] = OtherCaseTables::TABLES[$unicode];
        $otherCases = null === $base ? [] : self::read($base);
        foreach ('' === $drop ? [] : explode(' ', $drop) as $codePoint) {
            unset($otherCases[(int) hexdec($codePoint)]);
        }

        foreach ('' === $add ? [] : explode(' ', $add) as $run) {
            [$first, $count, $distance] = explode(':', $run);
            [$first, $count] = [(int) hexdec($first), (int) hexdec($count)];
            $distance = str_starts_with($distance, '-') ? -(int) hexdec(substr($distance, 1)) : (int) hexdec($distance);
            for ($offset = 0; $offset < $count; $offset++) {
                // A distance of 0 is a run of pairs, each the other case of
                // the other.
                $otherCases[$first + $offset] = 0 === $distance ? $first + ($offset ^ 1) : $first + $offset + $distance;
            }
        }

        return $otherCases;
    }
}
