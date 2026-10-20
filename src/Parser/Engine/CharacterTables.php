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

namespace PHPRegex\Parser\Engine;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;

/**
 * The character tables PCRE2 compiles a pattern with, as PHP hands them to
 * it: PCRE2's own under "C", and under any other LC_CTYPE locale a call of
 * setlocale() names, those pcre2_maketables() builds from what the C
 * library says of each byte under it. They decide the other case of each
 * byte, and the bytes each type and POSIX name holds without UCP.
 *
 * The tables of a locale are read from the engine itself, once a process:
 * LC_CTYPE is set to the locale for the time of the read, and set back
 * after it. PHP starts under "C.UTF-8" with PCRE2's own tables, which the
 * name does not bring back where the C library reads that locale past
 * ASCII, as macOS does: "C" is set back there, which keeps the tables PHP
 * compiled with before the read.
 *
 * @internal
 */
final class CharacterTables
{
    /**
     * The locale whose tables are PCRE2's own.
     */
    public const BUILT_IN = 'C';

    /**
     * The names whose bytes the tables give, each with the class member
     * that reads them.
     */
    private const MEMBERS = [
        'd' => '\d',
        's' => '\s',
        'w' => '\w',
        'alpha' => '[:alpha:]',
        'lower' => '[:lower:]',
        'upper' => '[:upper:]',
        'alnum' => '[:alnum:]',
        'ascii' => '[:ascii:]',
        'blank' => '[:blank:]',
        'cntrl' => '[:cntrl:]',
        'digit' => '[:digit:]',
        'graph' => '[:graph:]',
        'print' => '[:print:]',
        'punct' => '[:punct:]',
        'space' => '[:space:]',
        'word' => '[:word:]',
        'xdigit' => '[:xdigit:]',
    ];

    /**
     * The bytes of each name in PCRE2's own tables (pcre2_chartables.c).
     */
    private const BUILT_IN_BYTES = [
        'd' => [[0x30, 0x39]],
        's' => [[0x09, 0x0D], [0x20, 0x20]],
        'w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]],
        'alpha' => [[0x41, 0x5A], [0x61, 0x7A]],
        'lower' => [[0x61, 0x7A]],
        'upper' => [[0x41, 0x5A]],
        'alnum' => [[0x30, 0x39], [0x41, 0x5A], [0x61, 0x7A]],
        'ascii' => [[0x00, 0x7F]],
        'blank' => [[0x09, 0x09], [0x20, 0x20]],
        'cntrl' => [[0x00, 0x1F], [0x7F, 0x7F]],
        'digit' => [[0x30, 0x39]],
        'graph' => [[0x21, 0x7E]],
        'print' => [[0x20, 0x7E]],
        'punct' => [[0x21, 0x2F], [0x3A, 0x40], [0x5B, 0x60], [0x7B, 0x7E]],
        'space' => [[0x09, 0x0D], [0x20, 0x20]],
        'word' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]],
        'xdigit' => [[0x30, 0x39], [0x41, 0x46], [0x61, 0x66]],
    ];

    /**
     * The names PHP starts under, with PCRE2's own tables.
     */
    private const STARTING_LOCALES = ['C', 'POSIX', 'C.UTF-8', 'C.utf8'];

    /**
     * The longest locale name setlocale() takes.
     */
    private const MAX_NAME_LENGTH = 254;

    private static ?self $builtIn = null;

    /**
     * The tables read, by the locale name asked for and the one the C
     * library gave: as many as there are locales named.
     *
     * @var array<string, self>
     */
    private static array $read = [];

    /**
     * @param string                                     $locale         the name the C library gives the locale
     * @param string                                     $casesBefore143 the other case of each byte, one byte each, as
     *                                                                   pcre2_maketables() before 10.43 writes it
     * @param string                                     $casesFrom1043  the same, as 10.43 and later write it
     * @param array<string, list<array{0: int, 1: int}>> $bytes          the bytes of each name, as ranges
     */
    private function __construct(
        public readonly string $locale,
        private readonly string $casesBefore143,
        private readonly string $casesFrom1043,
        private readonly array $bytes,
    ) {}

    /**
     * PCRE2's own tables, which PHP compiles with under "C".
     */
    public static function builtIn(): self
    {
        if (null === self::$builtIn) {
            $otherCases = '';
            for ($byte = 0; $byte < 256; $byte++) {
                $otherCases .= \chr(match (true) {
                    $byte >= 0x41 && $byte <= 0x5A => $byte + 0x20,
                    $byte >= 0x61 && $byte <= 0x7A => $byte - 0x20,
                    default => $byte,
                });
            }

            self::$builtIn = new self(self::BUILT_IN, $otherCases, $otherCases, self::BUILT_IN_BYTES);
        }

        return self::$builtIn;
    }

    /**
     * The tables PHP compiles with under the locale, read once a process.
     *
     * @throws InvalidRegexOptionException when the machine cannot set the locale
     */
    public static function forLocale(string $locale): self
    {
        if (self::BUILT_IN === $locale) {
            return self::builtIn();
        }

        if (isset(self::$read[$locale])) {
            return self::$read[$locale];
        }

        StaticCaches::register(self::class, static function (): void {
            self::$read = [];
        });

        $tables = self::read($locale);
        self::$read[$tables->locale] = $tables;

        return self::$read[$locale] = $tables;
    }

    /**
     * The locale PHP compiles under now, as the C library names it: "C"
     * where PHP uses PCRE2's own tables, as it does from its start.
     */
    public static function current(): string
    {
        $locale = \function_exists('setlocale') ? setlocale(\LC_CTYPE, '0') : false;

        return false === $locale || \in_array($locale, self::STARTING_LOCALES, true) ? self::BUILT_IN : $locale;
    }

    /**
     * The other case of a byte, or the byte itself, as the tables of the
     * release judged hold it.
     */
    public function otherCase(int $byte, PcreTarget $target): int
    {
        $other = $this->casesFrom1043[$byte];

        return \ord($other === $this->casesBefore143[$byte] || $target->pcreAtLeast('10.43') ? $other : $this->casesBefore143[$byte]);
    }

    /**
     * The bytes a type ("d", "s", "w") or a POSIX name holds, as ranges.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function bytesOf(string $name): array
    {
        return $this->bytes[$name] ?? [];
    }

    /**
     * @throws InvalidRegexOptionException when the machine cannot set the locale
     */
    private static function read(string $locale): self
    {
        $refused = new InvalidRegexOptionException(\sprintf('"locale" names "%s", which this machine cannot set: install it, or name one that "locale -a" lists.', $locale));
        // "0" asks setlocale() for the current locale, and "" for the one
        // the environment names: neither names one.
        if (\in_array($locale, ['', '0'], true) || \strlen($locale) > self::MAX_NAME_LENGTH || str_contains($locale, "\0") || !\function_exists('setlocale')) {
            throw $refused;
        }

        $previous = setlocale(\LC_CTYPE, '0');
        $before = self::BUILT_IN === $previous ? null : self::probe();

        try {
            $set = setlocale(\LC_CTYPE, $locale);
            if (false === $set) {
                throw $refused;
            }

            return self::BUILT_IN === $set ? self::builtIn() : self::probe($set, self::readsOneByteACharacter());
        } finally {
            self::restore($previous, $before);
        }
    }

    /**
     * Set the locale back, and with it the tables PHP compiled with: the
     * tables a starting locale's name brings differ from PCRE2's own where
     * the C library reads it past ASCII, which "C" brings back.
     */
    private static function restore(string|false $previous, ?self $before): void
    {
        if (false === $previous || false === setlocale(\LC_CTYPE, $previous)) {
            setlocale(\LC_CTYPE, self::BUILT_IN);

            return;
        }

        if (null !== $before && $before->sameAs(self::builtIn()) && !self::probe()->sameAs($before)) {
            setlocale(\LC_CTYPE, self::BUILT_IN);
        }
    }

    /**
     * The tables the engine compiles with now: the bytes each name holds,
     * matched in a subject of every byte, and the other case of each byte
     * a caseless class of it adds. A byte that is neither a capital nor a
     * small letter is its own other case.
     *
     * @param bool $oneByte whether the C library reads the locale one byte a
     *                      character, where the case of a byte is a byte
     */
    private static function probe(string $locale = self::BUILT_IN, bool $oneByte = true): self
    {
        $subject = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $subject .= \chr($byte);
        }

        $bytes = [];
        foreach (self::MEMBERS as $name => $member) {
            $bytes[$name] = self::ranges((string) LibraryPcre::replace('/[^'.$member.']/', '', $subject));
        }

        $otherCases = $subject;
        foreach ([...$bytes['upper'] ?? [], ...$bytes['lower'] ?? []] as [$low, $high]) {
            for ($byte = $low; $byte <= $high; $byte++) {
                // The class holds the byte, its other case, and the NUL byte
                // with its own.
                $held = (string) LibraryPcre::replace(\sprintf('/[^\x00\x%02x]/i', $byte), '', $subject);
                $other = trim(str_replace(\chr($byte), '', $held), "\0");
                $otherCases[$byte] = '' === $other ? \chr($byte) : $other[0];
            }
        }

        // Before 10.43 pcre2_maketables() keeps the low byte of a case past
        // 255, from 10.43 the small letter itself. Where the C library reads
        // more than one byte a character, as under UTF-8 on macOS, it gives
        // a byte read as a code point its Unicode capital, which may be past
        // 255 (ÿ, U+0178): the tables of the other side of 10.43 than the
        // engine read hold that byte otherwise.
        $before = $from = $otherCases;
        $running = PcreTarget::runtime()->pcreAtLeast('10.43');
        foreach ($oneByte ? [] : $bytes['lower'] ?? [] as [$low, $high]) {
            for ($byte = $low; $byte <= $high; $byte++) {
                $capital = mb_ord(mb_convert_case((string) mb_chr($byte, 'UTF-8'), \MB_CASE_UPPER_SIMPLE, 'UTF-8'), 'UTF-8');
                if (false === $capital || $capital <= 0xFF) {
                    continue;
                }

                $lowByte = \chr($capital % 0x100);
                if ($running && \chr($byte) === $otherCases[$byte]) {
                    $before[$byte] = $lowByte;
                } elseif (!$running && $lowByte === $otherCases[$byte]) {
                    $from[$byte] = \chr($byte);
                }
            }
        }

        return new self($locale, $before, $from, $bytes);
    }

    /**
     * Whether the C library reads the current locale one byte a character,
     * as it reads ASCII, the ISO 8859 sets, the Windows code pages and
     * KOI8: the case it gives a byte is then a byte.
     */
    private static function readsOneByteACharacter(): bool
    {
        $codeset = \function_exists('nl_langinfo') && \defined('CODESET') ? nl_langinfo(\CODESET) : false;

        return \is_string($codeset) && 1 === LibraryPcre::match('/^(?:(?:US-)?ASCII|ANSI_X3\.4-1968|ISO[-_]?8859-[0-9]+|CP12[0-9]{2}|WINDOWS-12[0-9]{2}|KOI8-[A-Z]+)$/i', $codeset);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function ranges(string $held): array
    {
        $ranges = [];
        $start = null;
        $end = -2;
        for ($index = 0, $length = \strlen($held); $index < $length; $index++) {
            $byte = \ord($held[$index]);
            if ($end + 1 !== $byte) {
                if (null !== $start) {
                    $ranges[] = [$start, $end];
                }

                $start = $byte;
            }

            $end = $byte;
        }

        if (null !== $start) {
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    private function sameAs(self $other): bool
    {
        return $this->casesFrom1043 === $other->casesFrom1043 && $this->bytes === $other->bytes;
    }
}
