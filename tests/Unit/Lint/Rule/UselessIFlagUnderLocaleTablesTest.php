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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under another locale's tables than PCRE2's own, a byte above ASCII may
 * have another case: the Latin-1 tables of fr_FR.ISO8859-1 pair 0xE9 with
 * 0xC9, so i is not useless in "/\xE9/i" there, which drops it to match
 * 0xC9 no more. Under C, PCRE2's own tables give a case to ASCII letters
 * alone, and the flag is useless there.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS: preg_match("/\xE9/i", "\xC9")
 * is 1 under fr_FR.ISO8859-1 and 0 under C; preg_match("/\xD7/i", "\xF7")
 * is 0 under both.
 */
final class UselessIFlagUnderLocaleTablesTest extends TestCase
{
    use InstalledLocales;

    private const ID = 'regex.lint.flag.useless.i';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    private const GIVEN = 'xx_XX.GIVEN';

    #[Test]
    #[DataProvider('provideOneCharacterPatterns')]
    public function test_the_i_flag_is_useless_where_no_byte_matches_otherwise_under_the_locale(string $pattern, string $locale, bool $reported): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: under the row's locale, the pattern matches one character;
        // i is useless where no byte answers otherwise without it.
        $differs = new \ArrayObject();
        $without = (string) preg_replace('/i([a-z]*)$/', '$1', $pattern);
        CharacterTables::under($locale, static function () use ($pattern, $without, $differs): void {
            for ($byte = 0; $byte < 256; $byte++) {
                if (preg_match($pattern, \chr($byte)) !== preg_match($without, \chr($byte))) {
                    $differs[] = \sprintf('%02x', $byte);
                }
            }
        });
        $this->assertSame($reported, [] === $differs->getArrayCopy(), \sprintf('Oracle: %s under %s answers otherwise without i on %s.', addcslashes($pattern, "\200..\377"), $locale, json_encode($differs->getArrayCopy())));

        $this->assertSame($reported, self::reports($pattern, $locale), addcslashes($pattern, "\200..\377").' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, reported: bool}>
     */
    public static function provideOneCharacterPatterns(): iterable
    {
        foreach ([CharacterTables::BUILT_IN => true, self::LATIN1 => false] as $locale => $useless) {
            yield 'a raw Latin-1 letter, '.$locale => ['pattern' => "/\xE9/i", 'locale' => $locale, 'reported' => $useless];
            yield 'a Latin-1 letter written as an escape, '.$locale => ['pattern' => '/\xE9/i', 'locale' => $locale, 'reported' => $useless];
            yield 'a class of a raw Latin-1 capital, '.$locale => ['pattern' => "/[\xC9]/i", 'locale' => $locale, 'reported' => $useless];
            yield 'a range of Latin-1 capitals, '.$locale => ['pattern' => '/[\xC0-\xC9]/i', 'locale' => $locale, 'reported' => $useless];
            yield 'a class of a digit and a Latin-1 capital, '.$locale => ['pattern' => '/[7\xC9]/i', 'locale' => $locale, 'reported' => $useless];
            yield 'a Latin-1 sign without a case, '.$locale => ['pattern' => "/\xD7/i", 'locale' => $locale, 'reported' => true];
            yield 'a range of Latin-1 signs without a case, '.$locale => ['pattern' => '/[\xD7\xF7]/i', 'locale' => $locale, 'reported' => true];
            yield 'an ASCII digit, '.$locale => ['pattern' => '/7/i', 'locale' => $locale, 'reported' => true];
        }
    }

    /**
     * Tables no machine here builds: what the rule reads from them, which
     * no engine can check. They pair 0xE9 with 0xC9, "x" with 0x7F, "q"
     * with 0x80, and read 0xB5 otherwise in the interpreter, 0xFF otherwise
     * before 10.43. Under u, a character above ASCII takes Unicode's case.
     */
    #[Test]
    #[DataProvider('provideReadsUnderGivenTables')]
    public function test_read_under_given_tables_a_byte_they_give_a_case_keeps_the_i_flag(string $pattern, bool $reported): void
    {
        self::giveTables();

        $this->assertSame($reported, self::reports($pattern, self::GIVEN), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideReadsUnderGivenTables(): iterable
    {
        yield 'a byte they pair' => ['pattern' => '/\xE9/i', 'reported' => false];
        yield 'a byte they leave without a case' => ['pattern' => '/\xD7/i', 'reported' => true];
        yield 'raw bytes, the second one they pair' => ['pattern' => "/\xD7\xE9/i", 'reported' => false];
        yield 'raw bytes they leave without a case' => ['pattern' => "/\xD7\xF7/i", 'reported' => true];
        yield 'an ASCII sign they pair' => ['pattern' => '/\x7F/i', 'reported' => false];
        yield 'an ASCII sign they pair, under u' => ['pattern' => '/\x7F/iu', 'reported' => false];
        yield 'a Latin-1 letter under u, whose case Unicode gives' => ['pattern' => '/\x{E9}/iu', 'reported' => false];
        yield 'a Latin-1 sign under u, which Unicode gives no case' => ['pattern' => '/\x{D7}/iu', 'reported' => true];
        yield 'a byte the interpreter reads otherwise' => ['pattern' => '/\xB5/i', 'reported' => false];
        yield 'a byte the releases read otherwise' => ['pattern' => '/\xFF/i', 'reported' => false];
        yield 'a range holding a byte they pair' => ['pattern' => '/[\xE0-\xEF]/i', 'reported' => false];
        yield 'a range of bytes they leave without a case' => ['pattern' => '/[\xD7-\xD8]/i', 'reported' => true];
        yield 'a range whose last byte alone the releases read otherwise' => ['pattern' => '/[\xF7-\xFF]/i', 'reported' => false];
        yield 'a range of ASCII signs, under u, ending at one they pair' => ['pattern' => '/[\x{7B}-\x{7F}]/iu', 'reported' => false];
        yield 'a range of ASCII signs, under u, stopping before one they pair' => ['pattern' => '/[\x{7B}-\x{7C}]/iu', 'reported' => true];
        yield 'the first character above ASCII, under u, which they pair and Unicode does not' => ['pattern' => '/\x{80}/iu', 'reported' => true];
        yield 'a range of ASCII signs, under u, past one they pair' => ['pattern' => '/[\x{7B}-\x{80}]/iu', 'reported' => false];
        yield 'a code point above a byte without u, beyond the tables' => ['pattern' => '/\x{100}/i', 'reported' => true];
        yield 'an ASCII digit' => ['pattern' => '/7/i', 'reported' => true];
    }

    private static function reports(string $pattern, string $locale): bool
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        return \in_array(self::ID, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()), true);
    }

    /**
     * Gives the tables of GIVEN: ASCII's cases but for "x", paired with
     * 0x7F, and "q" with 0x80, and 0xE9 with 0xC9; 0xB5 read otherwise by
     * the interpreter, 0xFF by the tables before 10.43.
     */
    private static function giveTables(): void
    {
        $others = [0x78 => 0x7F, 0x7F => 0x78, 0x71 => 0x80, 0x80 => 0x71, 0xE9 => 0xC9, 0xC9 => 0xE9];
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        $before = $cases;
        $before[0xFF] = 'x';
        CharacterTables::useTables(self::GIVEN, $cases, [0xB5], $before);
    }
}
