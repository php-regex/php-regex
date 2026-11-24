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
use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Validation\OtherCases;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under UTF a range has a case where any of its characters has one, not
 * only where an end has: "[×-÷]" holds Ø to ö, so "/[×-÷]/iu" matches "ø",
 * and "ẞ", only with i. Each verdict is held to the engine, every character
 * matched once with the flag and without it: the rule may keep a flag it
 * cannot prove useless, never call one useless that the engine contradicts.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS: of the 1,112,064 characters,
 * "/[×-÷]/iu" matches 65 and "/[×-÷]/u" 33; "/\x{A7CE}/iu" matches 2 and
 * "/\x{A7CE}/u" 1, though mbstring gives U+A7CE no other case there.
 */
final class UselessIFlagUnderUtfTest extends TestCase
{
    use PcreGates;

    private const ID = 'regex.lint.flag.useless.i';

    /**
     * The rows verified against a newer release than the floor: the casing
     * verbs come with 10.45, and Unicode 17, which pairs U+A7CE with U+A7CF
     * and U+16EA0 with U+16EBB, with 10.48.
     */
    private const RELEASES = [
        '10.45' => ['/(*TURKISH_CASING)[\x{130}-\x{131}]/iu'],
        '10.48' => ['/\x{A7CE}/iu', '/[\x{A7CE}]/iu', '/[\x{16E9B}-\x{16EA0}]/iu'],
    ];

    private static ?string $everyCharacter = null;

    /**
     * @param bool $useless what the engine says: the pattern matches the same characters without i
     */
    #[Test]
    #[DataProvider('provideCharactersPastAscii')]
    public function test_flag_i_is_called_useless_under_utf_only_where_the_engine_agrees(string $pattern, bool $useless, bool $reported): void
    {
        $this->requiresPcre2For($pattern, self::RELEASES);

        // Oracle: every character once. What the pattern matches without i
        // it matches with it, so i is useless where it matches as many.
        $without = (string) preg_replace('/i([a-z]*)$/', '$1', $pattern);
        $with = preg_match_all($pattern, self::everyCharacter());
        $withoutI = preg_match_all($without, self::everyCharacter());
        $this->assertSame($useless, $with === $withoutI, \sprintf('Oracle: %s matches %d characters, %s %d.', $pattern, $with, $without, $withoutI));

        $this->assertSame($reported, self::reports($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, useless: bool, reported: bool}>
     */
    public static function provideCharactersPastAscii(): iterable
    {
        // Ø to ö, between two signs: their other cases, ø to Ö and ẞ, lie
        // outside the range.
        yield 'a range of letters between two signs' => ['pattern' => '/[×-÷]/iu', 'useless' => false, 'reported' => false];
        yield 'that range written as escapes' => ['pattern' => '/[\x{D7}-\x{F7}]/iu', 'useless' => false, 'reported' => false];
        yield 'that range under the UTF verb' => ['pattern' => '/(*UTF)[\x{D7}-\x{F7}]/i', 'useless' => false, 'reported' => false];
        // The micro sign folds with the Greek mu, both cases.
        yield 'one letter between two signs' => ['pattern' => '/[\x{B4}-\x{B6}]/iu', 'useless' => false, 'reported' => false];
        // The letterlike symbols, the Roman numerals and the circled letters
        // stand among the punctuation, the arrows and the other symbols.
        yield 'symbols holding Roman numerals and circled letters' => ['pattern' => '/[\x{2000}-\x{2BFF}]/iu', 'useless' => false, 'reported' => false];
        yield 'every character past ASCII' => ['pattern' => '/[\x{80}-\x{10FFFF}]/iu', 'useless' => false, 'reported' => false];
        // Their capitals lie outside the range.
        yield 'the Greek small letters' => ['pattern' => '/[\x{3B1}-\x{3C9}]/iu', 'useless' => false, 'reported' => false];
        yield 'a symbol and the first Glagolitic capital' => ['pattern' => '/[\x{2BFF}-\x{2C00}]/iu', 'useless' => false, 'reported' => false];
        yield 'the CJK ideographs, which have no case' => ['pattern' => '/[\x{4E00}-\x{9FFF}]/iu', 'useless' => true, 'reported' => true];
        yield 'the planes past the last character with a case' => ['pattern' => '/[\x{20000}-\x{10FFFF}]/iu', 'useless' => true, 'reported' => true];
        yield 'a sign between two letters' => ['pattern' => '/[\x{F7}-\x{F7}]/iu', 'useless' => true, 'reported' => true];
        yield 'that sign alone' => ['pattern' => '/\x{F7}/iu', 'useless' => true, 'reported' => true];
        yield 'a range of ASCII signs' => ['pattern' => '/[{-\x{7F}]/iu', 'useless' => true, 'reported' => true];
        yield 'an ASCII sign' => ['pattern' => '/"/iu', 'useless' => true, 'reported' => true];
        // Both cases of every character are in the range: the rule reads
        // whether a character has a case, not where it folds to.
        yield 'every character' => ['pattern' => '/[\x{0}-\x{10FFFF}]/iu', 'useless' => true, 'reported' => false];
        // mbstring maps "ﬀ" to "FF", "ﬗ" to "ՄԽ" and "ŉ" to "ʼN", which
        // PCRE2 does not fold, one character being two: read alone, or at
        // an end of a range, as mbstring reads it, the flag is kept.
        yield 'a range ending at a ligature' => ['pattern' => '/[\x{FAFF}-\x{FB00}]/iu', 'useless' => true, 'reported' => false];
        yield 'a range starting at a ligature' => ['pattern' => '/[\x{FB17}-\x{FB1C}]/iu', 'useless' => true, 'reported' => false];
        yield 'a letter whose capital is two characters' => ['pattern' => '/\x{149}/iu', 'useless' => true, 'reported' => false];
        // PCRE2 folds the long s with "s" and the Kelvin sign with "k",
        // which its tables give no other case from 10.43.
        yield 'the long s in a class' => ['pattern' => '/[\x{17F}]/iu', 'useless' => false, 'reported' => false];
        yield 'a range ending at the Kelvin sign' => ['pattern' => '/[\x{2129}-\x{212A}]/iu', 'useless' => false, 'reported' => false];
        // Turkish rules fold the dotted capital with "i", the dotless small
        // letter with "I": PCRE2's tables give neither another case.
        yield 'the dotted capital and the dotless small letter under Turkish rules' => ['pattern' => '/(*TURKISH_CASING)[\x{130}-\x{131}]/iu', 'useless' => false, 'reported' => false];
        // Letters mbstring may know of no case for, as on PHP 8.4.
        yield 'a letter of Unicode 17' => ['pattern' => '/\x{A7CE}/iu', 'useless' => false, 'reported' => false];
        yield 'that letter in a class' => ['pattern' => '/[\x{A7CE}]/iu', 'useless' => false, 'reported' => false];
        yield 'a range ending at a letter of Unicode 17' => ['pattern' => '/[\x{16E9B}-\x{16EA0}]/iu', 'useless' => false, 'reported' => false];
        // The Kelvin sign folds with "k" and "K": a class of both bytes
        // leaves it out without i.
        yield 'a small letter and its capital whose caseless set holds the Kelvin sign' => ['pattern' => '/[kK]/iu', 'useless' => false, 'reported' => false];
        // An inline option decides the case of what follows it either way.
        yield 'a Latin-1 letter after i turned off' => ['pattern' => '/(?-i)\x{E9}/iu', 'useless' => true, 'reported' => true];
        yield 'a Latin-1 letter after i turned on again' => ['pattern' => '/(?i)\x{E9}/iu', 'useless' => true, 'reported' => true];
    }

    /**
     * PCRE2's tables are read where a character past ASCII is, and only
     * there: ASCII alone is read without them, which keeps a lint of
     * "/"/iu" from paying for them.
     */
    #[Test]
    #[DataProvider('provideTablesReads')]
    public function test_pcre2_case_tables_are_read_past_ascii_only(string $pattern, bool $read): void
    {
        $regex = Regex::create(['cache' => null]);
        $tree = $regex->parse($pattern);
        StaticCaches::clear();

        $tree->accept(new PatternLinter([], $regex->target()));

        $this->assertSame($read, [] !== (new \ReflectionClass(OtherCases::class))->getStaticPropertyValue('tables'), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, read: bool}>
     */
    public static function provideTablesReads(): iterable
    {
        yield 'an ASCII sign' => ['pattern' => '/"/iu', 'read' => false];
        yield 'the last ASCII character' => ['pattern' => '/\x{7F}/iu', 'read' => false];
        yield 'a range of ASCII signs' => ['pattern' => '/[{-\x{7F}]/iu', 'read' => false];
        yield 'the first character past ASCII' => ['pattern' => '/\x{80}/iu', 'read' => true];
        yield 'a range of signs past ASCII' => ['pattern' => '/[{-\x{80}]/iu', 'read' => true];
    }

    /**
     * Every character in UTF-8 once, in order, but the surrogates.
     */
    private static function everyCharacter(): string
    {
        if (null === self::$everyCharacter) {
            self::$everyCharacter = '';
            foreach ([[0, 0xD7FF], [0xE000, 0x10FFFF]] as [$first, $last]) {
                for ($from = $first; $from <= $last; $from += 0x10000) {
                    self::$everyCharacter .= mb_convert_encoding(pack('N*', ...range($from, min($from + 0xFFFF, $last))), 'UTF-8', 'UTF-32BE');
                }
            }
        }

        return self::$everyCharacter;
    }

    private static function reports(string $pattern): bool
    {
        $regex = Regex::create(['cache' => null]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        return \in_array(self::ID, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()), true);
    }
}
