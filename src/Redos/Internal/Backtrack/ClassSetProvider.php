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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Internal\StaticCaches;

/**
 * The exact set of characters one atom matches, asked of the running PCRE:
 * the atom runs over a subject holding every character of the alphabet
 * once, in order, and each run of matches is a range of the set. The answer
 * is kept for the process, keyed by the atom and its modes, and emptied
 * with the library's other caches.
 *
 * @internal
 */
final class ClassSetProvider
{
    /**
     * Where each UTF-8 length starts in the Unicode subject: one byte up to
     * U+007F, two up to U+07FF, three up to U+FFFF but the surrogates, four
     * beyond.
     */
    private const TWO_BYTES_AT = 0x80;

    private const THREE_BYTES_AT = 0x80 + 0x780 * 2;

    private const AFTER_SURROGATES_AT = self::THREE_BYTES_AT + (0xD800 - 0x800) * 3;

    private const FOUR_BYTES_AT = self::AFTER_SURROGATES_AT + (0x10000 - 0xE000) * 3;

    private const DELIMITERS = ['/', '#', '~', '%', '!', '@', ';', "\x01"];

    /**
     * How many characters one engine call scans.
     */
    private const BLOCK_SIZE = 16384;

    private static ?string $unicodeSubject = null;

    private static ?string $byteSubject = null;

    /**
     * @var array<string, CharSet|false>
     */
    private static array $sets = [];

    /**
     * The characters the atom matches, or null when PCRE refuses it.
     *
     * @param string $atom             one character's worth of pattern: a class, an escape, a dot
     * @param string $modifiers        the inline modifiers in force: any of "i", "s", "x"
     * @param bool   $caselessRestrict whether the pattern carries /r: no caseless match
     *                                 between ASCII and non-ASCII characters
     */
    public static function query(string $atom, bool $unicode, string $modifiers, bool $caselessRestrict = false): ?CharSet
    {
        StaticCaches::register(self::class, self::clear(...));

        $global = ($unicode ? 'u' : '').($caselessRestrict ? 'r' : '');
        $key = $global.'|'.$modifiers.':'.$atom;
        if (!isset(self::$sets[$key])) {
            self::$sets = StaticCaches::makeRoom(self::$sets);
            self::$sets[$key] = self::scan($atom, $unicode, $modifiers, $global) ?? false;
        }

        $set = self::$sets[$key];

        return false === $set ? null : $set;
    }

    private static function clear(): void
    {
        self::$sets = [];
        self::$unicodeSubject = null;
        self::$byteSubject = null;
    }

    private static function scan(string $atom, bool $unicode, string $modifiers, string $global): ?CharSet
    {
        $delimiter = null;
        foreach (self::DELIMITERS as $candidate) {
            if (!str_contains($atom, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        if (null === $delimiter) {
            return null;
        }

        $pattern = $delimiter.'(?'.$modifiers.':'.$atom.')++'.$delimiter.$global;
        $subject = $unicode ? self::unicodeSubject() : self::byteSubject();
        $engine = new PcreEngine();
        $ranges = [];

        // A block at a time: a run over the whole subject (".") would pass the
        // engine's backtrack limit with the JIT off. Runs cut at a block's
        // edge join again in the set.
        foreach (self::blocks($unicode, \strlen($subject)) as [$blockStart, $blockLength]) {
            $block = substr($subject, $blockStart, $blockLength);
            $runs = $engine->matchAll($pattern, $block);
            if (null === $runs) {
                return null;
            }

            $position = 0;
            foreach ($runs as $text) {
                // Every character stands once in the subject: a run is found
                // where the previous one ended, or further on.
                $offset = '' === $text ? false : strpos($block, $text, $position);
                if (false === $offset) {
                    continue;
                }

                $position = $offset + \strlen($text);
                $from = $blockStart + $offset;
                $after = $blockStart + $position;
                $ranges[] = $unicode
                    ? [self::codePointAt($from), self::codePointAt($after) - 1]
                    : [$from, $after - 1];
            }
        }

        return CharSet::fromRanges($ranges)->intersect(CharSet::universe($unicode));
    }

    /**
     * The byte offset and length of each block of the subject, cut on
     * character boundaries.
     *
     * @return list<array{int, int}>
     */
    private static function blocks(bool $unicode, int $length): array
    {
        if (!$unicode) {
            return [[0, $length]];
        }

        $blocks = [];
        $characters = 0x110000 - 0x800;
        for ($first = 0; $first < $characters; $first += self::BLOCK_SIZE) {
            $start = self::offsetOf(self::codePointOfIndex($first));
            $last = min($first + self::BLOCK_SIZE, $characters);
            $end = $last >= $characters ? $length : self::offsetOf(self::codePointOfIndex($last));
            $blocks[] = [$start, $end - $start];
        }

        return $blocks;
    }

    /**
     * The code point at a position in the subject's order: the surrogates
     * are left out.
     */
    private static function codePointOfIndex(int $index): int
    {
        return $index < 0xD800 ? $index : $index + 0x800;
    }

    /**
     * Where a code point starts in the Unicode subject.
     */
    private static function offsetOf(int $codePoint): int
    {
        return match (true) {
            $codePoint < 0x80 => $codePoint,
            $codePoint < 0x800 => self::TWO_BYTES_AT + ($codePoint - 0x80) * 2,
            $codePoint < 0xD800 => self::THREE_BYTES_AT + ($codePoint - 0x800) * 3,
            $codePoint < 0x10000 => self::AFTER_SURROGATES_AT + ($codePoint - 0xE000) * 3,
            default => self::FOUR_BYTES_AT + ($codePoint - 0x10000) * 4,
        };
    }

    private static function codePointAt(int $offset): int
    {
        return match (true) {
            $offset < self::TWO_BYTES_AT => $offset,
            $offset < self::THREE_BYTES_AT => 0x80 + intdiv($offset - self::TWO_BYTES_AT, 2),
            $offset < self::AFTER_SURROGATES_AT => 0x800 + intdiv($offset - self::THREE_BYTES_AT, 3),
            $offset < self::FOUR_BYTES_AT => 0xE000 + intdiv($offset - self::AFTER_SURROGATES_AT, 3),
            default => 0x10000 + intdiv($offset - self::FOUR_BYTES_AT, 4),
        };
    }

    private static function byteSubject(): string
    {
        return self::$byteSubject ??= implode('', array_map(chr(...), range(0, 0xFF)));
    }

    /**
     * Every code point but the surrogates, in order, as one UTF-8 string.
     */
    private static function unicodeSubject(): string
    {
        if (null !== self::$unicodeSubject) {
            return self::$unicodeSubject;
        }

        $text = '';
        foreach ([[0, 0xD7FF], [0xE000, 0x10FFFF]] as [$from, $to]) {
            for ($start = $from; $start <= $to; $start += 0x10000) {
                $codePoints = range($start, min($start + 0xFFFF, $to));
                $text .= (string) mb_convert_encoding(pack('N*', ...$codePoints), 'UTF-8', 'UTF-32BE');
            }
        }

        return self::$unicodeSubject = $text;
    }
}
