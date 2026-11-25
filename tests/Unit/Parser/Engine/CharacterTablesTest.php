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

namespace PHPRegex\Tests\Unit\Parser\Engine;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The character tables PHP hands PCRE2: its own under "C", those
 * pcre2_maketables() builds under any other locale setlocale() names. Each
 * fact is checked with pcre2test's "locale=" modifier, 10.32 to 10.49, and
 * with PHP 8.4.26 (PCRE2 10.49) under setlocale(), on macOS:
 *
 * - "[\xa6\xa8]" compiles to one caseless character (9 units) from 10.34
 *   under fr_FR.ISO8859-15, where they are Š and š, to a class (40) under C;
 * - "/[\x00-\xfe]/i" compiles to a match of any character (8) from 10.45
 *   under fr_FR.ISO8859-15, as Ÿ (\xbe) adds ÿ (\xff), to a class under C;
 * - "\w" matches "\xe9" under fr_FR.ISO8859-15, not under C;
 * - "[\xff\x78]" compiles to one caseless character from 10.34 to 10.42
 *   under fr_FR.UTF-8, whose C library uppercases ÿ to U+0178, of which
 *   pcre2_maketables() kept the low byte, "x", before 10.43; a class from
 *   10.43, which keeps ÿ as it is.
 */
final class CharacterTablesTest extends TestCase
{
    use InstalledLocales;
    use PhpUnitForwardCompat;

    /**
     * The bytes whose other case the engine's paths read otherwise, as
     * measured on macOS, by locale.
     */
    private const UNSETTLED_ON_MACOS = [
        'tr_TR.ISO8859-9' => [0x49, 0x69, 0xDD],
        'fr_FR.UTF-8' => [0xB5, 0xFF],
        'en_US.UTF-8' => [0xB5, 0xFF],
    ];

    #[Test]
    public function test_the_c_locale_reads_the_tables_pcre2_ships(): void
    {
        $tables = CharacterTables::forLocale('C');
        $target = new PcreTarget(80400, '10.44', 'C');

        $this->assertSame('C', $tables->locale);
        $this->assertSame(0x41, $tables->otherCase(0x61, $target));
        $this->assertSame(0x7A, $tables->otherCase(0x5A, $target));
        $this->assertSame(0xE9, $tables->otherCase(0xE9, $target));
        $this->assertSame(0x31, $tables->otherCase(0x31, $target));
        $this->assertSame([[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]], $tables->bytesOf('w'));
        $this->assertSame([[0x09, 0x0D], [0x20, 0x20]], $tables->bytesOf('s'));
        $this->assertSame([[0x00, 0x7F]], $tables->bytesOf('ascii'));
        $this->assertSame([[0x09, 0x09], [0x20, 0x20]], $tables->bytesOf('blank'));
        $this->assertSame(CharacterTables::builtIn(), $tables);
        $this->assertSame(0x41, $tables->settledOtherCase(0x61));
        $this->assertSame(0x61, $tables->settledOtherCase(0x41));
        $this->assertSame(0xE9, $tables->settledOtherCase(0xE9));
        $this->assertSame(0x31, $tables->settledOtherCase(0x31));
    }

    /**
     * The other case of a byte is settled where every path of the engine
     * reads it alike: a class holds the byte and its other case, as the
     * JIT does for a character alone, while the interpreter compares a
     * character alone by the small letters of the tables (pcre2_match.c,
     * OP_CHARI), which may join a third byte to the pair; and the tables
     * of either side of 10.43 hold the byte alike. Elsewhere it is not.
     *
     * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS. Under tr_TR.ISO8859-9 the
     * interpreter matches "\xDD" for "i" and "I", which a class of either
     * leaves out; under fr_FR.UTF-8 and en_US.UTF-8, µ (0xB5) and ÿ (0xFF)
     * have their own case from 10.43, and the low byte of their capital
     * before.
     */
    #[Test]
    #[DataProvider('provideLocalesWithCases')]
    public function test_a_locale_settles_the_other_case_its_engine_paths_agree_on(string $locale): void
    {
        $tables = $this->requiresLocale($locale, 'The other case each path of the engine reads');

        $subject = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $subject .= \chr($byte);
        }

        // What a class, and a character alone, take on the JIT: with
        // pcre.jit=0 the interpreter's repeat, which reads a character as
        // the JIT does alone, stands for it, without the start-up study,
        // which looks for 0xFF in its own case alone (pcre2_compile.c reads
        // a first code unit below 255 caseless).
        $taken = static function (string $pattern) use ($subject): array {
            preg_match_all($pattern, $subject, $matches);
            preg_match_all('/(*NO_JIT)(*NO_START_OPT)'.substr($pattern, 1, -2).'+/i', $subject, $repeated);
            $bytes = array_unique(array_map(\ord(...), str_split(implode('', [...$matches[0], ...$repeated[0]]))));
            sort($bytes);

            return $bytes;
        };
        $reads = [];
        CharacterTables::under($locale, static function () use ($subject, $taken, &$reads): void {
            for ($byte = 0; $byte < 256; $byte++) {
                preg_match_all(\sprintf('/(*NO_JIT)(*NO_START_OPT)\x%02x/i', $byte), $subject, $onInterpreter);
                $reads[$byte] = ['class' => $taken(\sprintf('/[\x%02x]/i', $byte)), 'jit' => $taken(\sprintf('/\x%02x/i', $byte)), 'interpreter' => array_map(\ord(...), $onInterpreter[0])];
            }
        });

        $before = new PcreTarget(80400, '10.42', 'C');
        $from = new PcreTarget(80400, '10.43', 'C');
        $unsettled = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $pair = array_values(array_unique([$byte, $tables->otherCase($byte, PcreTarget::runtime())]));
            sort($pair);
            $this->assertSame($pair, $reads[$byte]['class'], \sprintf('Oracle: a class of 0x%02X under %s.', $byte, $locale));
            $this->assertSame($pair, $reads[$byte]['jit'], \sprintf('Oracle: 0x%02X alone under %s.', $byte, $locale));

            $agreed = [] === array_diff($reads[$byte]['interpreter'], $pair) && $tables->otherCase($byte, $before) === $tables->otherCase($byte, $from);
            $this->assertSame($agreed ? $tables->otherCase($byte, $from) : null, $tables->settledOtherCase($byte), \sprintf('0x%02X under %s, the interpreter matching %s.', $byte, $locale, json_encode($reads[$byte]['interpreter'])));
            if (!$agreed) {
                $unsettled[] = $byte;
            }
        }

        if ('Darwin' === \PHP_OS_FAMILY) {
            $this->assertSame(self::UNSETTLED_ON_MACOS[$locale] ?? [], $unsettled, $locale);
        }
    }

    /**
     * @return iterable<string, array{locale: string}>
     */
    public static function provideLocalesWithCases(): iterable
    {
        foreach (['fr_FR.ISO8859-1', 'de_DE.ISO8859-1', 'el_GR.ISO8859-7', 'ru_RU.KOI8-R', 'tr_TR.ISO8859-9', 'fr_FR.UTF-8', 'en_US.UTF-8'] as $locale) {
            yield $locale => ['locale' => $locale];
        }
    }

    /**
     * Tables a test gives stand for their locale, as a machine that cannot
     * set it would read them: the other case of a byte is settled, but for
     * a byte the interpreter reads otherwise and one the tables of either
     * side of 10.43 hold apart.
     */
    #[Test]
    public function test_given_tables_settle_a_case_neither_the_interpreter_nor_a_release_reads_otherwise(): void
    {
        $from1043 = self::cases([0xE9 => 0xC9, 0xC9 => 0xE9, 0x49 => 0x69, 0x69 => 0x49, 0xDD => 0x69]);
        CharacterTables::useTables('xx_XX.GIVEN', $from1043, [0xDD], self::cases([0xE9 => 0xC9, 0xC9 => 0xE9, 0x49 => 0x69, 0x69 => 0x49, 0xDD => 0x69, 0xFF => 0x78]));
        $tables = CharacterTables::forLocale('xx_XX.GIVEN');

        $this->assertSame('xx_XX.GIVEN', $tables->locale);
        $this->assertSame(0xC9, $tables->settledOtherCase(0xE9));
        $this->assertSame(0xE9, $tables->settledOtherCase(0xC9));
        $this->assertSame(0x49, $tables->settledOtherCase(0x69));
        $this->assertSame(0x31, $tables->settledOtherCase(0x31));
        $this->assertNull($tables->settledOtherCase(0xDD));
        $this->assertNull($tables->settledOtherCase(0xFF));
        $this->assertSame(0x78, $tables->otherCase(0xFF, new PcreTarget(80400, '10.42', 'C')));
        $this->assertSame(0xFF, $tables->otherCase(0xFF, new PcreTarget(80400, '10.43', 'C')));

        // A byte pairs with the other case either side of 10.43 gives it.
        $this->assertTrue($tables->mayPair(0xE9, 0xC9));
        $this->assertTrue($tables->mayPair(0xDD, 0x69));
        $this->assertTrue($tables->mayPair(0xFF, 0x78));
        $this->assertFalse($tables->mayPair(0x49, 0xDD));
        $this->assertFalse($tables->mayPair(0xE9, 0xE8));

        // Given one table alone, both sides of 10.43 read it.
        CharacterTables::useTables('xx_XX.GIVEN', $from1043);
        $tables = CharacterTables::forLocale('xx_XX.GIVEN');
        $this->assertSame(0xFF, $tables->settledOtherCase(0xFF));
        $this->assertSame(0x69, $tables->settledOtherCase(0xDD));
        $this->assertSame(0x69, $tables->otherCase(0xDD, new PcreTarget(80400, '10.42', 'C')));
    }

    /**
     * Tables settle every case where no byte is read otherwise, by the
     * interpreter or by the tables of either side of 10.43: PCRE2's own,
     * and tables that pair "i" with 0xDD both ways, as glibc's
     * tr_TR.ISO-8859-9 do; not those where the interpreter reads 0xDD
     * otherwise, as on macOS, nor those that read 0xFF otherwise before
     * 10.43.
     */
    #[Test]
    public function test_tables_settle_every_case_where_no_byte_is_read_otherwise(): void
    {
        $this->assertTrue(CharacterTables::builtIn()->settlesEveryCase());

        $cases = self::cases([0x69 => 0xDD, 0xDD => 0x69, 0x49 => 0xFD, 0xFD => 0x49]);
        CharacterTables::useTables('xx_XX.GIVEN', $cases);
        $this->assertTrue(CharacterTables::forLocale('xx_XX.GIVEN')->settlesEveryCase());

        CharacterTables::useTables('xx_XX.GIVEN', $cases, [0xDD]);
        $this->assertFalse(CharacterTables::forLocale('xx_XX.GIVEN')->settlesEveryCase());

        $before = $cases;
        $before[0xFF] = 'x';
        CharacterTables::useTables('xx_XX.GIVEN', $cases, [], $before);
        $this->assertFalse(CharacterTables::forLocale('xx_XX.GIVEN')->settlesEveryCase());
    }

    /**
     * Tables a test gives hold the bytes given for a name, and PCRE2's own
     * for the names not given.
     */
    #[Test]
    public function test_given_tables_hold_the_bytes_given_for_a_name(): void
    {
        CharacterTables::useTables('xx_XX.GIVEN', self::cases([]), bytes: ['w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xC0, 0xFF]], 's' => [[0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0]]]);
        $tables = CharacterTables::forLocale('xx_XX.GIVEN');

        $this->assertSame([[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xC0, 0xFF]], $tables->bytesOf('w'));
        $this->assertSame([[0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0]], $tables->bytesOf('s'));
        $this->assertSame(CharacterTables::builtIn()->bytesOf('d'), $tables->bytesOf('d'));
        $this->assertSame(CharacterTables::builtIn()->bytesOf('alpha'), $tables->bytesOf('alpha'));

        // Given no bytes, every name holds PCRE2's own.
        CharacterTables::useTables('xx_XX.GIVEN', self::cases([]));
        $this->assertSame(CharacterTables::builtIn()->bytesOf('w'), CharacterTables::forLocale('xx_XX.GIVEN')->bytesOf('w'));
    }

    #[Test]
    public function test_a_latin9_locale_reads_the_tables_its_c_library_gives(): void
    {
        $tables = $this->requiresLocale('fr_FR.ISO8859-15', 'The Latin-9 tables');
        $target = new PcreTarget(80400, '10.49', 'C');

        $this->assertSame('fr_FR.ISO8859-15', $tables->locale);
        $this->assertSame(0xA8, $tables->otherCase(0xA6, $target));
        $this->assertSame(0xA6, $tables->otherCase(0xA8, $target));
        $this->assertSame(0xFF, $tables->otherCase(0xBE, $target));
        $this->assertSame(0x41, $tables->otherCase(0x61, $target));
        $this->assertContains([0xE9, 0xE9], array_map(static fn (array $range): array => [max($range[0], 0xE9), min($range[1], 0xE9)], array_filter($tables->bytesOf('w'), static fn (array $range): bool => $range[0] <= 0xE9 && 0xE9 <= $range[1])));
        $this->assertSame([[0x30, 0x39]], $tables->bytesOf('d'));
    }

    /**
     * pcre2_maketables() kept the low byte of a case past 255 before 10.43,
     * and the byte itself from 10.43: whichever engine the tables are read
     * from, each release gets its own.
     */
    #[Test]
    public function test_a_case_past_255_is_its_low_byte_before_10_43(): void
    {
        $tables = $this->requiresLocale('fr_FR.UTF-8', 'The other case of ÿ under fr_FR.UTF-8');

        $this->assertSame(0x78, $tables->otherCase(0xFF, new PcreTarget(80400, '10.42', 'C')));
        $this->assertSame(0x78, $tables->otherCase(0xFF, new PcreTarget(80400, '10.34', 'C')));
        $this->assertSame(0xFF, $tables->otherCase(0xFF, new PcreTarget(80400, '10.43', 'C')));
        $this->assertSame(0xFF, $tables->otherCase(0xFF, new PcreTarget(80400, '10.49', 'C')));
        $this->assertSame(0xDF, $tables->otherCase(0xDF, new PcreTarget(80400, '10.42', 'C')));
        $this->assertSame(0xC9, $tables->otherCase(0xE9, new PcreTarget(80400, '10.42', 'C')));
        $this->assertSame(0x41, $tables->otherCase(0x61, new PcreTarget(80400, '10.42', 'C')));
    }

    /**
     * A locale the C library reads one byte a character gives each byte a
     * case below 256: the tables of every release agree on it.
     */
    #[Test]
    public function test_a_single_byte_locale_knows_every_case_on_every_release(): void
    {
        $tables = $this->requiresLocale('fr_FR.ISO8859-1', 'The cases of a single-byte locale');

        foreach (['10.34', '10.42', '10.43', '10.49'] as $release) {
            $target = new PcreTarget(80400, $release, 'C');
            $this->assertSame(0xFF, $tables->otherCase(0xFF, $target), $release);
            $this->assertSame(0xDF, $tables->otherCase(0xDF, $target), $release);
            $this->assertSame(0xC9, $tables->otherCase(0xE9, $target), $release);
        }
    }

    #[Test]
    #[DataProvider('provideNamesNoMachineSets')]
    public function test_a_locale_the_machine_cannot_set_is_refused(string $locale): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"locale"');

        CharacterTables::forLocale($locale);
    }

    /**
     * @return iterable<string, array{locale: string}>
     */
    public static function provideNamesNoMachineSets(): iterable
    {
        yield 'unknown name' => ['locale' => 'xx_YY.NOWHERE'];
        yield 'empty name' => ['locale' => ''];
        yield 'the name that asks for the current locale' => ['locale' => '0'];
        yield 'name too long for setlocale()' => ['locale' => str_repeat('a', 255)];
    }

    #[Test]
    public function test_a_refused_locale_is_named_in_the_message(): void
    {
        try {
            CharacterTables::forLocale('xx_YY.NOWHERE');
            $this->fail('The locale was read.');
        } catch (InvalidRegexOptionException $exception) {
            $this->assertStringContainsString('"xx_YY.NOWHERE"', $exception->getMessage());
        }
    }

    /**
     * The tables PHP compiles with after a read are the ones it compiled
     * with before: the read sets LC_CTYPE back.
     */
    #[Test]
    public function test_reading_a_locale_leaves_the_engine_as_it_was(): void
    {
        $before = self::engineAnswers();
        $name = setlocale(\LC_CTYPE, '0');
        StaticCaches::clear();

        try {
            $this->requiresLocale('fr_FR.ISO8859-15', 'The read of the Latin-9 tables');
        } finally {
            $this->assertSame($before, self::engineAnswers());
            $this->assertContains(setlocale(\LC_CTYPE, '0'), [$name, 'C']);
        }
    }

    /**
     * Under a locale setlocale() named, the read sets that name back, and
     * PHP compiles with its tables again.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_reading_a_locale_sets_the_named_locale_back(): void
    {
        $this->requiresLocale('de_DE.ISO8859-1', 'The read under a named locale');
        $this->requiresLocale('fr_FR.ISO8859-15', 'The read under a named locale');
        StaticCaches::clear();
        setlocale(\LC_CTYPE, 'de_DE.ISO8859-1');
        $before = self::engineAnswers();

        CharacterTables::forLocale('fr_FR.ISO8859-15');

        $this->assertSame('de_DE.ISO8859-1', setlocale(\LC_CTYPE, '0'));
        $this->assertSame($before, self::engineAnswers());
        $this->assertSame([1, 0], $before);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function test_reading_a_locale_under_c_sets_c_back(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-15', 'The read under C');
        StaticCaches::clear();
        setlocale(\LC_CTYPE, 'C');

        CharacterTables::forLocale('fr_FR.ISO8859-15');

        $this->assertSame('C', setlocale(\LC_CTYPE, '0'));
        $this->assertSame([0, 0], self::engineAnswers());
    }

    /**
     * PHP starts under "C.UTF-8", where the C library has it, with PCRE2's
     * own tables: the runtime's locale reads as "C" there.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_the_runtime_locale_of_a_fresh_process_is_c(): void
    {
        $this->assertSame('C', CharacterTables::current());
        $this->assertSame('C', PcreTarget::runtime()->locale);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function test_the_runtime_locale_is_the_one_setlocale_named(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-15', 'The runtime locale');
        setlocale(\LC_CTYPE, 'fr_FR.ISO8859-15');

        $this->assertSame('fr_FR.ISO8859-15', CharacterTables::current());
        $this->assertSame('fr_FR.ISO8859-15', PcreTarget::runtime()->locale);
        $this->assertSame(0xA8, CharacterTables::forLocale(CharacterTables::current())->otherCase(0xA6, PcreTarget::runtime()));
        $this->assertSame('fr_FR.ISO8859-15', setlocale(\LC_CTYPE, '0'));
    }

    /**
     * The other case of each byte, one byte each: the byte itself but for
     * those given.
     *
     * @param array<int, int> $others
     */
    private static function cases(array $others): string
    {
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? $byte);
        }

        return $cases;
    }

    /**
     * Whether "\w" matches é in Latin-1 and Latin-9, and whether a caseless
     * Ÿ of Latin-9 matches ÿ: what PHP compiles with.
     *
     * @return array{0: int|false, 1: int|false}
     */
    private static function engineAnswers(): array
    {
        return [preg_match('/\w/', "\xe9"), preg_match('/^\xbe$/i', "\xff")];
    }
}
