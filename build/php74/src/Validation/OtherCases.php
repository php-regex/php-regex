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
     * @var array<int, int>
     *
     */
    private array $otherCases;
    /**
     * @var list<int>
     *
     */
    private array $codePoints;

    /**
     * @param array<int, int> $otherCases the other case of each character
     *                                    that has one, by code point in order
     * @param list<int>       $codePoints those code points
     */
    private function __construct(array $otherCases, array $codePoints)
    {
        $this->otherCases = $otherCases;
        $this->codePoints = $codePoints;
    }

    public static function of(PcreTarget $target): self
    {
        $unicode = OtherCaseTables::RELEASES[array_key_last(OtherCaseTables::RELEASES)];
        foreach (OtherCaseTables::RELEASES as $release => $version) {
            if ($target->pcreAtLeast($release)) {
                $unicode = $version;

                break;
            }
        }

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
        return $this->codePoints[$this->indexOf($codePoint)] ?? null;
    }

    /**
     * The other case of each character from $low to $high that has one, by
     * code point in order.
     *
     * @return array<int, int>
     */
    public function between(int $low, int $high): array
    {
        $first = $this->indexOf($low);

        return \array_slice($this->otherCases, $first, $this->indexOf($high + 1) - $first, true);
    }

    /**
     * The index of the first code point from $codePoint on, or the count of
     * them past the last one.
     */
    private function indexOf(int $codePoint): int
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

        return $first;
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
            unset($otherCases[intval($codePoint, 16)]);
        }

        foreach ('' === $add ? [] : explode(' ', $add) as $run) {
            [$first, $count, $distance] = explode(':', $run);
            [$first, $count, $distance] = [intval($first, 16), intval($count, 16), intval($distance, 16)];
            for ($offset = 0; $offset < $count; ++$offset) {
                // A distance of 0 is a run of pairs, each the other case of
                // the other.
                $otherCases[$first + $offset] = 0 === $distance ? $first + ($offset ^ 1) : $first + $offset + $distance;
            }
        }

        return $otherCases;
    }
}
