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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without UTF, "(*UCP)" gives a byte past ASCII the case of the character
 * of the same value, from PCRE2 10.35: "/(*UCP)\xE9/i" matches "\xC9", so
 * its i is not useless, as it is in "/\xE9/i" under C. That case is
 * Unicode's under any locale, whose tables keep the case of ASCII alone. A
 * byte whose other cases are no bytes, as the micro sign, matches no other
 * byte, and its i is useless.
 *
 * Engine: of the 256 bytes, with the JIT and without it, "/(*UCP)\xE9/i"
 * matches "\xE9" and "\xC9", "/(*UCP)\xB5/i" "\xB5" alone, on PCRE2 10.35,
 * 10.37, 10.39, 10.40, 10.42 and 10.44 (the official php:7.4 to php:8.4
 * images, under C and glibc's ru_RU.KOI8-R, pl_PL.ISO-8859-2 and
 * fr_FR.ISO-8859-1, which pair other bytes) and on 10.49 (PHP 8.4.28 on
 * macOS, under C and the same locales).
 */
final class UselessIFlagUnderUcpTest extends TestCase
{
    use InstalledLocales;
    use PcreGates;

    private const ID = 'regex.lint.flag.useless.i';

    private const KOI8_R = 'ru_RU.KOI8-R';

    private const LATIN2 = 'pl_PL.ISO8859-2';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    private const GIVEN = 'xx_XX.GIVEN_UNDER_UCP';

    /**
     * @param bool $useless what the engine says: no byte answers otherwise without i
     */
    #[Test]
    #[DataProvider('provideBytesUnderUcp')]
    public function test_flag_i_is_called_useless_under_ucp_only_where_the_engine_agrees(string $pattern, string $locale, bool $useless, bool $reported): void
    {
        // The casing verbs come with 10.45, which refuses none of the rows.
        $this->requiresCompile($pattern);
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: every byte once, under the row's locale; i is useless
        // where no byte answers otherwise without it.
        $differs = new \ArrayObject();
        $without = (string) preg_replace('/i([a-z]*)$/', '$1', $pattern);
        CharacterTables::under($locale, static function () use ($pattern, $without, $differs): void {
            for ($byte = 0; $byte < 256; $byte++) {
                if (preg_match($pattern, \chr($byte)) !== preg_match($without, \chr($byte))) {
                    $differs[] = \sprintf('%02x', $byte);
                }
            }
        });
        $this->assertSame($useless, [] === $differs->getArrayCopy(), \sprintf('Oracle: %s under %s answers otherwise without i on %s.', addcslashes($pattern, "\200..\377"), $locale, json_encode($differs->getArrayCopy())));

        $this->assertSame($reported, self::reports($pattern, ['locale' => $locale]), addcslashes($pattern, "\200..\377").' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, useless: bool, reported: bool}>
     */
    public static function provideBytesUnderUcp(): iterable
    {
        foreach ([
            'a Latin-1 letter written as an escape' => ['/(*UCP)\xE9/i', false, false],
            'a raw Latin-1 letter' => ["/(*UCP)\xE9/i", false, false],
            'a raw Latin-1 capital in a class' => ["/(*UCP)[\xC9]/i", false, false],
            'a Latin-1 letter written with braces' => ['/(*UCP)\x{E9}/i', false, false],
            'a Latin-1 letter written in octal' => ['/(*UCP)\351/i', false, false],
            'a Latin-1 letter written with \o' => ['/(*UCP)\o{351}/i', false, false],
            'a repeated Latin-1 capital' => ['/(*UCP)\xC9+/i', false, false],
            'a class leaving out a Latin-1 letter' => ['/(*UCP)[^\xE9]/i', false, false],
            '(*UCP) after a newline option' => ['/(*CRLF)(*UCP)\xE9/i', false, false],
            '(*UCP) after a limit' => ['/(*LIMIT_MATCH=100)(*UCP)\xE9/i', false, false],
            '(*UCP) before another option' => ['/(*UCP)(*NO_JIT)\xE9/i', false, false],
            // The restriction keeps ASCII apart from the characters past it.
            '(*UCP) with the caseless restriction' => ['/(*UCP)(*CASELESS_RESTRICT)\xE9/i', false, false],
            'a range of Latin-1 capitals' => ['/(*UCP)[\xC0-\xDE]/i', false, false],
            // Ø to ö stand between the two signs.
            'a range between two signs' => ['/(*UCP)[\xD7-\xF7]/i', false, false],
            'a range from a sign to a letter' => ['/(*UCP)[\xBF-\xC0]/i', false, false],
            'a sign' => ['/(*UCP)\xD7/i', true, true],
            'two signs in a class' => ['/(*UCP)[\xD7\xF7]/i', true, true],
            'a range of one sign' => ['/(*UCP)[\xD7-\xD7]/i', true, true],
            'a range of signs before the micro sign' => ['/(*UCP)[\xA0-\xB4]/i', true, true],
            'an ASCII letter written as an escape' => ['/(*UCP)\x41/i', false, false],
            'an ASCII digit' => ['/(*UCP)7/i', true, true],
            'a range of ASCII signs' => ['/(*UCP)[{-\x7F]/i', true, true],
            // Their other cases are no bytes, U+039C, U+1E9E and U+0178:
            // they match themselves alone.
            'the micro sign' => ['/(*UCP)\xB5/i', true, true],
            'the sharp s' => ['/(*UCP)\xDF/i', true, true],
            'the small y with diaeresis' => ['/(*UCP)\xFF/i', true, true],
            'a raw small y with diaeresis' => ["/(*UCP)\xFF/i", true, true],
            'a range of signs holding the micro sign' => ['/(*UCP)[\x80-\xBF]/i', true, true],
            'a range from a sign to the small y with diaeresis' => ['/(*UCP)[\xF7-\xFF]/i', false, false],
            'a Latin-1 letter without (*UCP)' => ['/\xE9/i', true, true],
            'a range of Latin-1 capitals without (*UCP)' => ['/[\xC0-\xDE]/i', true, true],
        ] as $name => [$pattern, $useless, $reported]) {
            yield $name => ['pattern' => $pattern, 'locale' => CharacterTables::BUILT_IN, 'useless' => $useless, 'reported' => $reported];
        }

        // KOI8-R pairs 0xD7 with 0xF7, "в" and "В", and ISO 8859-2 0xA1
        // with 0xB1, "Ą" and "ą": under (*UCP) they are "×" and "¡".
        yield 'a sign KOI8-R pairs, under (*UCP)' => ['pattern' => '/(*UCP)\xD7/i', 'locale' => self::KOI8_R, 'useless' => true, 'reported' => true];
        yield 'that byte under KOI8-R, without (*UCP)' => ['pattern' => '/\xD7/i', 'locale' => self::KOI8_R, 'useless' => false, 'reported' => false];
        yield 'a Latin-1 letter under KOI8-R, under (*UCP)' => ['pattern' => '/(*UCP)\xE9/i', 'locale' => self::KOI8_R, 'useless' => false, 'reported' => false];
        yield 'a sign ISO 8859-2 pairs, under (*UCP)' => ['pattern' => '/(*UCP)\xA1/i', 'locale' => self::LATIN2, 'useless' => true, 'reported' => true];
        yield 'a Latin-1 letter under Latin-1, under (*UCP)' => ['pattern' => '/(*UCP)\xE9/i', 'locale' => self::LATIN1, 'useless' => false, 'reported' => false];
    }

    /**
     * Tables no runner may build: they pair 0xD7 with 0xF7, as KOI8-R does,
     * "x" with 0x7F and "q" with 0x80, and give 0xE9 no case. Under (*UCP)
     * the case of a byte past ASCII is Unicode's, and the tables keep that
     * of ASCII: under glibc's tr_TR.ISO-8859-9, on PCRE2 10.35 to 10.44,
     * "/(*UCP)I/i" matches "\xFD", which those tables pair it with, and
     * "/(*UCP)\xFD/i" matches "\xDD", not "I".
     */
    #[Test]
    #[DataProvider('provideReadsUnderGivenTables')]
    public function test_read_under_given_tables_ucp_takes_the_case_past_ascii_from_unicode(string $pattern, bool $reported): void
    {
        self::giveTables();

        $this->assertSame($reported, self::reports($pattern, ['locale' => self::GIVEN]), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideReadsUnderGivenTables(): iterable
    {
        yield 'a sign they pair, under (*UCP)' => ['pattern' => '/(*UCP)\xD7/i', 'reported' => true];
        yield 'that sign raw, under (*UCP)' => ['pattern' => "/(*UCP)\xD7/i", 'reported' => true];
        yield 'that sign in a range, under (*UCP)' => ['pattern' => '/(*UCP)[\xD7-\xD7]/i', 'reported' => true];
        yield 'that sign, without (*UCP)' => ['pattern' => '/\xD7/i', 'reported' => false];
        yield 'the first byte past ASCII, which they pair with "q", under (*UCP)' => ['pattern' => '/(*UCP)\x80/i', 'reported' => true];
        yield 'an ASCII sign they pair, under (*UCP)' => ['pattern' => '/(*UCP)\x7F/i', 'reported' => false];
        yield 'a range of ASCII signs ending at one they pair, under (*UCP)' => ['pattern' => '/(*UCP)[\x7B-\x7F]/i', 'reported' => false];
        yield 'a range of ASCII signs stopping before one they pair, under (*UCP)' => ['pattern' => '/(*UCP)[\x7B-\x7E]/i', 'reported' => true];
        yield 'a raw ASCII sign they pair, under (*UCP)' => ['pattern' => "/(*UCP)\x7F/i", 'reported' => false];
        yield 'a Latin-1 letter they give no case, under (*UCP)' => ['pattern' => '/(*UCP)\xE9/i', 'reported' => false];
        yield 'that letter raw, under (*UCP)' => ['pattern' => "/(*UCP)\xE9/i", 'reported' => false];
        yield 'that letter, without (*UCP)' => ['pattern' => '/\xE9/i', 'reported' => true];
    }

    /**
     * Before 10.35 (*UCP) leaves the case of every byte to the tables,
     * which give none past ASCII under C, as no runner can check. Engine:
     * on PCRE2 10.32 (php:7.3) and 10.34 (php:7.4.10), with the JIT and
     * without it, "/(*UCP)\xE9/i" matches "\xE9" alone of the 256 bytes,
     * and every byte under glibc's ru_RU.KOI8-R and pl_PL.ISO-8859-2 reads
     * the same with (*UCP) as without it.
     */
    #[Test]
    #[DataProvider('provideReadsBeforeUcpFolds')]
    public function test_before_pcre2_10_35_ucp_leaves_the_case_of_a_byte_to_the_tables(string $pattern, string $php, string $pcre, string $locale, bool $reported): void
    {
        self::giveTables();

        $this->assertSame($reported, self::reports($pattern, ['php_version' => $php, 'pcre_version' => $pcre, 'locale' => $locale]), \sprintf('%s on PCRE2 %s under %s', addcslashes($pattern, "\200..\377"), $pcre, $locale));
    }

    /**
     * @return iterable<string, array{pattern: string, php: string, pcre: string, locale: string, reported: bool}>
     */
    public static function provideReadsBeforeUcpFolds(): iterable
    {
        $c = CharacterTables::BUILT_IN;
        yield 'a Latin-1 letter on 10.32' => ['pattern' => '/(*UCP)\xE9/i', 'php' => '7.3', 'pcre' => '10.32', 'locale' => $c, 'reported' => true];
        yield 'a Latin-1 letter on 10.34' => ['pattern' => '/(*UCP)\xE9/i', 'php' => '7.4', 'pcre' => '10.34', 'locale' => $c, 'reported' => true];
        yield 'a raw Latin-1 letter on 10.34' => ['pattern' => "/(*UCP)\xE9/i", 'php' => '7.4', 'pcre' => '10.34', 'locale' => $c, 'reported' => true];
        yield 'a range of Latin-1 capitals on 10.34' => ['pattern' => '/(*UCP)[\xC0-\xDE]/i', 'php' => '7.4', 'pcre' => '10.34', 'locale' => $c, 'reported' => true];
        yield 'a Latin-1 letter on 10.35' => ['pattern' => '/(*UCP)\xE9/i', 'php' => '8.0', 'pcre' => '10.35', 'locale' => $c, 'reported' => false];
        yield 'a sign the given tables pair, on 10.34' => ['pattern' => '/(*UCP)\xD7/i', 'php' => '7.4', 'pcre' => '10.34', 'locale' => self::GIVEN, 'reported' => false];
        yield 'a sign the given tables pair, on 10.35' => ['pattern' => '/(*UCP)\xD7/i', 'php' => '8.0', 'pcre' => '10.35', 'locale' => self::GIVEN, 'reported' => true];
    }

    /**
     * @param array{locale: string, php_version?: string, pcre_version?: string} $options
     */
    private static function reports(string $pattern, array $options): bool
    {
        $regex = Regex::create(['cache' => null] + $options);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        return \in_array(self::ID, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()), true);
    }

    /**
     * Gives the tables of GIVEN: ASCII's cases but for "x", paired with
     * 0x7F, and "q" with 0x80, and 0xD7 with 0xF7.
     */
    private static function giveTables(): void
    {
        $others = [0x78 => 0x7F, 0x7F => 0x78, 0x71 => 0x80, 0x80 => 0x71, 0xD7 => 0xF7, 0xF7 => 0xD7];
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        CharacterTables::useTables(self::GIVEN, $cases);
    }
}
