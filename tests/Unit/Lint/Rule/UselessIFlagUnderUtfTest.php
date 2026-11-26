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
     * verbs and the extended classes come with 10.45, the restriction and
     * the ASCII options with 10.43, and Unicode 17, which pairs U+A7CE with
     * U+A7CF and U+16EA0 with U+16EBB, with 10.48.
     */
    private const RELEASES = [
        '10.45' => ['/(*TURKISH_CASING)[\x{130}-\x{131}]/iu', '/(*TURKISH_CASING)[\x{130}\x{131}iI]/iu', '/(*TURKISH_CASING)[iI]/iu', '/(?[ [:upper:] ])/iu', '/(?[ [:alpha:] - [:lower:] ])/iu', '/(?aP)(?[ [:upper:] ])/iu', '/(*UTF)(?[ ![:lower:] ])/i'],
        '10.43' => ['/(?r)[kK]/iu', '/(?aP)[[:upper:]]/iu', '/(?a)[[:lower:]]/iu', '/(?aP:[[:upper:]])/iu', '/(?aD)[[:upper:]]/iu', '/(?aP)(?-aP)[[:upper:]]/iu', '/(?aP:)[[:upper:]]/iu'],
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
        // Both cases of every character are in the range.
        yield 'every character' => ['pattern' => '/[\x{0}-\x{10FFFF}]/iu', 'useless' => true, 'reported' => true];
        // mbstring maps "ﬀ" to "FF", "ﬗ" to "ՄԽ" and "ŉ" to "ʼN", which
        // PCRE2 does not fold, one character being two: in a class, which
        // PCRE2's tables are read for, the flag is useless; read alone, as
        // mbstring reads it, it is kept.
        yield 'a range ending at a ligature' => ['pattern' => '/[\x{FAFF}-\x{FB00}]/iu', 'useless' => true, 'reported' => true];
        yield 'a range starting at a ligature' => ['pattern' => '/[\x{FB17}-\x{FB1C}]/iu', 'useless' => true, 'reported' => true];
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
        // A class holding every character PCRE2 folds each of its own with,
        // its caseless set included: the Kelvin sign with "k" and "K", the
        // long s with "s" and "S", the micro sign with the Greek mu.
        yield 'a small letter and its capital' => ['pattern' => '/[aA]/iu', 'useless' => true, 'reported' => true];
        yield 'a negated class of a small letter and its capital' => ['pattern' => '/[^aA]/iu', 'useless' => true, 'reported' => true];
        yield 'the ASCII letters, without the Kelvin sign and the long s' => ['pattern' => '/[a-zA-Z]/iu', 'useless' => false, 'reported' => false];
        yield 'the word characters of ASCII written out' => ['pattern' => '/[A-Za-z0-9_]/iu', 'useless' => false, 'reported' => false];
        yield 'the ASCII letters with the Kelvin sign and the long s' => ['pattern' => '/[a-zA-Z\x{17F}\x{212A}]/iu', 'useless' => true, 'reported' => true];
        yield 'the ASCII letters with the Kelvin sign, the long s and the digits' => ['pattern' => '/[a-z\x{17F}\x{212A}A-Z\d]/iu', 'useless' => true, 'reported' => true];
        yield 'a small letter, its capital and the Kelvin sign' => ['pattern' => '/[kK\x{212A}]/iu', 'useless' => true, 'reported' => true];
        yield 'a small letter, its capital and the long s' => ['pattern' => '/[sS\x{17F}]/iu', 'useless' => true, 'reported' => true];
        yield 'the micro sign and both cases of the Greek mu' => ['pattern' => '/[\x{B5}\x{39C}\x{3BC}]/iu', 'useless' => true, 'reported' => true];
        yield 'both cases of the Greek mu without the micro sign' => ['pattern' => '/[\x{39C}\x{3BC}]/iu', 'useless' => false, 'reported' => false];
        yield 'the micro sign and the small mu without the capital' => ['pattern' => '/[\x{B5}\x{3BC}]/iu', 'useless' => false, 'reported' => false];
        yield 'the capital sigma and both small ones' => ['pattern' => '/[\x{3A3}\x{3C2}\x{3C3}]/iu', 'useless' => true, 'reported' => true];
        yield 'the capital sigma without the final one' => ['pattern' => '/[\x{3A3}\x{3C3}]/iu', 'useless' => false, 'reported' => false];
        yield 'the A with ring above, its capital and the Angstrom sign' => ['pattern' => '/[\x{C5}\x{E5}\x{212B}]/iu', 'useless' => true, 'reported' => true];
        yield 'the A with ring above and its capital without the Angstrom sign' => ['pattern' => '/[\x{C5}\x{E5}]/iu', 'useless' => false, 'reported' => false];
        yield 'the capital, the title case and the small DZ with caron' => ['pattern' => '/[\x{1C4}-\x{1C6}]/iu', 'useless' => true, 'reported' => true];
        yield 'the capital and the small DZ with caron without the title case' => ['pattern' => '/[\x{1C4}\x{1C6}]/iu', 'useless' => false, 'reported' => false];
        yield 'the sharp s and its capital' => ['pattern' => '/[\x{DF}\x{1E9E}]/iu', 'useless' => true, 'reported' => true];
        yield 'two raw Latin-1 letters and their capitals' => ['pattern' => '/[ÀàÉé]/iu', 'useless' => true, 'reported' => true];
        // The y with diaeresis, whose capital U+0178 is in the range.
        yield 'the Latin Extended-A letters' => ['pattern' => '/[\x{100}-\x{17E}]/iu', 'useless' => false, 'reported' => false];
        yield 'the Latin Extended-A letters with the long s' => ['pattern' => '/[\x{100}-\x{17F}]/iu', 'useless' => false, 'reported' => false];
        yield 'a small letter and its capital with a script' => ['pattern' => '/[aA\p{Greek}]/iu', 'useless' => true, 'reported' => true];
        yield 'a small letter and its capital with the word characters' => ['pattern' => '/[aA\w]/iu', 'useless' => true, 'reported' => true];
        // Under u, which sets UCP, [:upper:] and [:lower:] read as \p{Lu}
        // and \p{Ll}, which i leaves as written in a class on every
        // release: i widens them to the letters without UCP alone, as under
        // (*UTF) or the ASCII option of the POSIX classes.
        yield 'a small letter and its capital with the capitals' => ['pattern' => '/[aA[:upper:]]/iu', 'useless' => true, 'reported' => true];
        yield 'a small letter and its capital with the small letters' => ['pattern' => '/[aA[:lower:]]/iu', 'useless' => true, 'reported' => true];
        yield 'the capitals' => ['pattern' => '/[[:upper:]]/iu', 'useless' => true, 'reported' => true];
        yield 'the small letters' => ['pattern' => '/[[:lower:]]/iu', 'useless' => true, 'reported' => true];
        yield 'a negated class of the capitals' => ['pattern' => '/[^[:upper:]]/iu', 'useless' => true, 'reported' => true];
        yield 'every character but the small letters' => ['pattern' => '/[[:^lower:]]/iu', 'useless' => true, 'reported' => true];
        yield 'the capitals and the small letters' => ['pattern' => '/[[:upper:][:lower:]]/iu', 'useless' => true, 'reported' => true];
        yield 'a small letter with the small letters' => ['pattern' => '/[a[:lower:]]/iu', 'useless' => false, 'reported' => false];
        // The capital i adds is among the capitals: the rule, which reads
        // the letters written apart from the POSIX class, keeps the flag.
        yield 'a small letter with the capitals' => ['pattern' => '/[a[:upper:]]/iu', 'useless' => true, 'reported' => false];
        yield 'the capitals under the UTF and UCP verbs' => ['pattern' => '/(*UTF)(*UCP)[[:upper:]]/i', 'useless' => true, 'reported' => true];
        yield 'the capitals under the UTF verb alone' => ['pattern' => '/(*UTF)[[:upper:]]/i', 'useless' => false, 'reported' => false];
        yield 'a small letter and its capital with the capitals under the UTF verb alone' => ['pattern' => '/(*UTF)[aA[:upper:]]/i', 'useless' => false, 'reported' => false];
        yield 'the capitals under the ASCII option of the POSIX classes' => ['pattern' => '/(?aP)[[:upper:]]/iu', 'useless' => false, 'reported' => false];
        yield 'the small letters under every ASCII option' => ['pattern' => '/(?a)[[:lower:]]/iu', 'useless' => false, 'reported' => false];
        yield 'the capitals in a group under the ASCII option of the POSIX classes' => ['pattern' => '/(?aP:[[:upper:]])/iu', 'useless' => false, 'reported' => false];
        // An ASCII option of another kind, or one turned off or ended by
        // its group, leaves the POSIX classes to UCP: the rule, which looks
        // for the letter of the option anywhere in the pattern, keeps the
        // flag.
        yield 'the capitals under the ASCII option of the digits' => ['pattern' => '/(?aD)[[:upper:]]/iu', 'useless' => true, 'reported' => false];
        yield 'the capitals after the ASCII option of the POSIX classes turned off' => ['pattern' => '/(?aP)(?-aP)[[:upper:]]/iu', 'useless' => true, 'reported' => false];
        yield 'the capitals after a group under the ASCII option of the POSIX classes' => ['pattern' => '/(?aP:)[[:upper:]]/iu', 'useless' => true, 'reported' => false];
        // A POSIX class stands on its own among the operands of an
        // extended class, where the ASCII option gives it back to the
        // tables as it does in a class.
        yield 'the capitals as the operand of an extended class' => ['pattern' => '/(?[ [:upper:] ])/iu', 'useless' => true, 'reported' => true];
        yield 'the letters less the small letters in an extended class' => ['pattern' => '/(?[ [:alpha:] - [:lower:] ])/iu', 'useless' => true, 'reported' => true];
        yield 'the capitals as the operand of an extended class under the ASCII option of the POSIX classes' => ['pattern' => '/(?aP)(?[ [:upper:] ])/iu', 'useless' => false, 'reported' => false];
        yield 'the complement of the small letters in an extended class under the UTF verb alone' => ['pattern' => '/(*UTF)(?[ ![:lower:] ])/i', 'useless' => false, 'reported' => false];
        // A group named "a", or options without that letter, set none.
        yield 'the capitals in a group named a' => ['pattern' => '/(?<a>[[:upper:]])/iu', 'useless' => true, 'reported' => true];
        yield 'the capitals in a group setting other options' => ['pattern' => '/(?s-m:[[:upper:]])/iu', 'useless' => true, 'reported' => true];
        // PCRE2 folds neither the dotted capital I nor the dotless small i
        // with another character but under Turkish rules, which pair them
        // with "i" and "I": the class is read under neither.
        yield 'both cases of "i" and the Turkish ones' => ['pattern' => '/[\x{130}\x{131}iI]/iu', 'useless' => true, 'reported' => true];
        yield 'both cases of "i" and the Turkish ones under Turkish rules' => ['pattern' => '/(*TURKISH_CASING)[\x{130}\x{131}iI]/iu', 'useless' => true, 'reported' => false];
        yield 'both cases of "i" under Turkish rules' => ['pattern' => '/(*TURKISH_CASING)[iI]/iu', 'useless' => false, 'reported' => false];
        // The rule reads the caseless sets whole, as a caseless class holds
        // them where nothing restricts it: restricted to ASCII, the Kelvin
        // sign folds with neither "k" nor "K", and the flag is kept.
        yield 'a small letter and its capital whose caseless set holds the Kelvin sign, restricted' => ['pattern' => '/(?r)[kK]/iu', 'useless' => true, 'reported' => false];
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

        $tree->accept(new PatternLinter($regex->target()));

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
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);

        return \in_array(self::ID, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()), true);
    }
}
