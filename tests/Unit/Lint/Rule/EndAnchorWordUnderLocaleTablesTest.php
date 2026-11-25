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
use PHPRegex\Linter\Rule\ImpossibleAnchorRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * After an end anchor, a word boundary before the newline holds where the
 * character read before the anchor is a word character, and "\B" where it
 * is none: without UTF nor UCP, the tables of the target's locale tell
 * which bytes are, above ASCII too. Under glibc's tr_TR.ISO-8859-9 0xDD is
 * one and 0xD7 is none, and "\W" takes no word character, so "\W$\b\n",
 * "\xDD$\B\n" and "\xD7$\b\n" can never match there, while "\xDD$\b\n"
 * and "\xD7$\B\n" can; under C 0xDD is no word character either.
 *
 * Engine, glibc's tr_TR.ISO-8859-9 (Debian, localedef): PHP 8.2.36 with
 * PCRE2 10.40, 8.4.28 and 8.6.0 with 10.44, the JIT on and off, each
 * setting in a process of its own: of every byte followed by a newline,
 * "/\W$\b\n/" and "/\xDD$\B\n/" match none, "/\xDD$\b\n/" matches 0xDD and
 * "/\xD7$\B\n/" 0xD7; under C "/\xDD$\B\n/" matches 0xDD.
 */
final class EndAnchorWordUnderLocaleTablesTest extends TestCase
{
    use InstalledLocales;

    private const ID = ImpossibleAnchorRule::ID_END;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * glibc's name of its Latin-5 Turkish locale, which macOS does not
     * set.
     */
    private const GLIBC_TURKISH = 'tr_TR.ISO-8859-9';

    /**
     * The tables of glibc's tr_TR.ISO-8859-9, given where no such locale is
     * installed.
     */
    private const GIVEN = 'xx_XX.WORDS';

    #[Test]
    #[DataProvider('provideRows')]
    public function test_a_boundary_after_an_end_anchor_reads_the_words_of_the_target_locale(string $pattern, string $locale, bool $reported): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: the pattern reads one character, then the subject's last
        // newline: it matches some byte followed by a newline, by the JIT
        // where it is on and by the interpreter, unless it can never match.
        $matched = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($pattern, $matched): void {
            foreach (['', '(*NO_JIT)'] as $path) {
                for ($byte = 0; $byte < 256; $byte++) {
                    if (1 === preg_match($pattern[0].$path.substr($pattern, 1), \chr($byte)."\n")) {
                        $matched[] = $path.\sprintf('%02x', $byte);
                    }
                }
            }
        });
        $label = \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale);
        $this->assertSame($reported, [] === $matched->getArrayCopy(), \sprintf('Oracle: %s matches after %s.', $label, json_encode($matched->getArrayCopy())));

        $this->assertSame($reported, self::reports($pattern, $locale), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, reported: bool}>
     */
    public static function provideRows(): iterable
    {
        foreach ([CharacterTables::BUILT_IN, self::LATIN1, self::GLIBC_TURKISH] as $locale) {
            yield 'no word character, then a word boundary, '.$locale => ['pattern' => '/\W$\b\n/', 'locale' => $locale, 'reported' => true];
            yield 'a word character, then no word boundary, '.$locale => ['pattern' => '/\w$\B\n/', 'locale' => $locale, 'reported' => true];
            yield 'a word character, then a word boundary, '.$locale => ['pattern' => '/\w$\b\n/', 'locale' => $locale, 'reported' => false];
            yield 'a raw sign, then no word boundary, '.$locale => ['pattern' => "/\xD7\$\\B\\n/", 'locale' => $locale, 'reported' => false];
        }

        foreach ([CharacterTables::BUILT_IN => false, self::LATIN1 => true] as $locale => $latin1) {
            yield 'a raw Latin-1 letter, then no word boundary, '.$locale => ['pattern' => "/\xE9\$\\B\\n/", 'locale' => $locale, 'reported' => $latin1];
            yield 'a raw Latin-1 letter, then a word boundary, '.$locale => ['pattern' => "/\xE9\$\\b\\n/", 'locale' => $locale, 'reported' => !$latin1];
            // Under UCP the properties of Unicode decide, where the
            // superscript two is a word character, which no table says.
            yield 'a superscript two, then a word boundary, under UCP, '.$locale => ['pattern' => '/(*UCP)\xB2$\b\n/', 'locale' => $locale, 'reported' => false];
        }

        $turkish = self::GLIBC_TURKISH;
        yield 'a Turkish capital, then no word boundary, '.$turkish => ['pattern' => '/\xDD$\B\n/', 'locale' => $turkish, 'reported' => true];
        yield 'a raw Turkish capital, then no word boundary, '.$turkish => ['pattern' => "/\xDD\$\\B\\n/", 'locale' => $turkish, 'reported' => true];
        yield 'a Turkish capital, then a word boundary, '.$turkish => ['pattern' => '/\xDD$\b\n/', 'locale' => $turkish, 'reported' => false];
        yield 'a sign, then a word boundary, '.$turkish => ['pattern' => '/\xD7$\b\n/', 'locale' => $turkish, 'reported' => true];
        yield 'a raw Turkish capital, then no word boundary, under C' => ['pattern' => "/\xDD\$\\B\\n/", 'locale' => CharacterTables::BUILT_IN, 'reported' => false];
    }

    /**
     * Under the tables of glibc's tr_TR.ISO-8859-9, given, as Linux
     * machines seldom have the locale.
     */
    #[Test]
    #[DataProvider('provideRowsUnderGivenTables')]
    public function test_a_boundary_after_an_end_anchor_reads_the_words_of_given_tables(string $pattern, bool $reported): void
    {
        self::giveTables();

        $this->assertSame($reported, self::reports($pattern, self::GIVEN), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideRowsUnderGivenTables(): iterable
    {
        yield 'no word character, then a word boundary' => ['pattern' => '/\W$\b\n/', 'reported' => true];
        yield 'no word character under m, then a word boundary' => ['pattern' => '/\W$\b\n/m', 'reported' => true];
        yield 'a Turkish capital, then no word boundary' => ['pattern' => '/\xDD$\B\n/', 'reported' => true];
        yield 'a raw Turkish capital, then no word boundary' => ['pattern' => "/\xDD\$\\B\\n/", 'reported' => true];
        yield 'a Turkish capital, then a word boundary' => ['pattern' => '/\xDD$\b\n/', 'reported' => false];
        yield 'a sign, then a word boundary' => ['pattern' => '/\xD7$\b\n/', 'reported' => true];
        yield 'a sign, then no word boundary' => ['pattern' => '/\xD7$\B\n/', 'reported' => false];
        yield 'a class of a letter and a sign, then a word boundary' => ['pattern' => '/[\xD7\xDD]$\b\n/', 'reported' => false];
        yield 'a class of a letter and a sign, then no word boundary' => ['pattern' => '/[\xD7\xDD]$\B\n/', 'reported' => false];
        yield 'the last byte, a letter, then no word boundary' => ['pattern' => '/\xFF$\B\n/', 'reported' => true];
        yield 'the last letters, then no word boundary' => ['pattern' => '/[\xF8-\xFF]$\B\n/', 'reported' => true];
        yield 'the division sign, then no word boundary' => ['pattern' => '/\xF7$\B\n/', 'reported' => false];
        yield 'the division sign or the last byte, then no word boundary' => ['pattern' => '/[\xF7\xFF]$\B\n/', 'reported' => false];
        yield 'a small i read caselessly, then no word boundary' => ['pattern' => '/i$\B\n/i', 'reported' => true];
        yield 'any character, then a word boundary' => ['pattern' => '/.$\b\n/', 'reported' => false];
        // Under UCP the properties of Unicode decide what a byte is: the
        // superscript two is a word character, which the tables say it is
        // not.
        yield 'a superscript two, then a word boundary, under UCP' => ['pattern' => '/(*UCP)\xB2$\b\n/', 'reported' => false];
    }

    private static function reports(string $pattern, string $locale): bool
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        return \in_array(self::ID, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()), true);
    }

    /**
     * Gives the tables of GIVEN, read from glibc's tr_TR.ISO-8859-9: its
     * word characters, ASCII's and the Latin-5 letters, and its cases, "i"
     * paired with 0xDD and "I" with 0xFD.
     */
    private static function giveTables(): void
    {
        $others = [0x49 => 0xFD, 0xFD => 0x49, 0x69 => 0xDD, 0xDD => 0x69];
        for ($byte = 0xC0; $byte <= 0xDE; $byte++) {
            if (0xD7 !== $byte && 0xDD !== $byte) {
                $others[$byte] = $byte + 0x20;
                $others[$byte + 0x20] = $byte;
            }
        }

        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        CharacterTables::useTables(self::GIVEN, $cases, [], null, ['w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xB5, 0xB5], [0xBA, 0xBA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]]]);
    }
}
