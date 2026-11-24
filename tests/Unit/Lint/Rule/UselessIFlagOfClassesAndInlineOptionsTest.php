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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Validation\OtherCases;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The i flag of a pattern is useless where it changes the case of nothing
 * the pattern matches: a class that holds the other case of every byte it
 * holds, "[aA]", and a character whose case an inline option decides, off
 * in "(?-i)a", on again in "(?i)a". An option set in an alternative holds
 * for the alternatives after it, up to the end of the group around them.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, with the JIT and without it:
 * of the 256 bytes, "/[aA]/i" matches "a" and "A", as "/[aA]/" does; of
 * the 256 bytes and their 65,536 pairs, "/(?-i)a|b/i" matches the strings
 * "/(?-i)a|b/" matches, and "/(?:(?-i)a|b)c/i" matches "bC" besides.
 * "/(*UCP)[\xC9\xE9]/i", "/(*UCP)[\xC0-\xFE]/i" and "/(*UCP)[\xC5\xE5]/i"
 * match the bytes they match without i on 10.34, 10.35 and 10.39 too
 * (php:7.4.10, php:8.0 and php:8.1), with the JIT and without it.
 */
final class UselessIFlagOfClassesAndInlineOptionsTest extends TestCase
{
    use InstalledLocales;
    use PcreGates;

    private const ID = 'regex.lint.flag.useless.i';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    private const TURKISH = 'tr_TR.ISO8859-9';

    /**
     * @param bool $useless what the engine says: no byte answers otherwise without i
     */
    #[Test]
    #[DataProvider('provideClasses')]
    public function test_flag_i_is_called_useless_where_a_class_holds_every_other_case(string $pattern, string $locale, bool $useless): void
    {
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

        $this->assertSame($useless, self::reports($pattern, $locale), addcslashes($pattern, "\200..\377").' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, useless: bool}>
     */
    public static function provideClasses(): iterable
    {
        foreach ([
            'a letter and its capital' => ['/[aA]/i', true],
            'a letter and its capital written as escapes' => ['/[\x61\x41]/i', true],
            'a letter and its capital quoted' => ['/[\QaA\E]/i', true],
            'a negated class of a letter and its capital' => ['/[^aA]/i', true],
            'the ASCII letters' => ['/[a-zA-Z]/i', true],
            // "[A-z]" holds "[", "\", "]", "^", "_" and "`" between them.
            'a range from the first capital to the last small letter' => ['/[A-z]/i', true],
            'a range stopping before the last small letter' => ['/[A-y]/i', false],
            'a range starting past the first capital' => ['/[B-z]/i', false],
            'a letter without its capital' => ['/[a]/i', false],
            'a negated class of a letter without its capital' => ['/[^a]/i', false],
            'a letter and its capital with another letter' => ['/[aAb]/i', false],
            // A type matches the same characters with i: its own are read
            // apart from the letters written.
            'a letter and its capital with digits' => ['/[aA\d]/i', true],
            'a letter and its capital with a POSIX class without case' => ['/[aA[:digit:]]/i', true],
            // With i, [:upper:] matches the small letters too, and \p{Lu}.
            'a letter and its capital with the capitals' => ['/[aA[:upper:]]/i', false],
            'a letter and its capital with the capital property' => ['/[aA\p{Lu}]/i', false],
            'a letter and its capital with the last byte' => ['/[aA\xFF]/i', true],
            'a letter and its capital with a Latin-1 letter' => ['/[aA\xE9]/i', true],
            'every byte' => ['/[\x00-\xFF]/i', true],
            'a letter and its capital with a range of control characters' => ['/[aA\cA-\cZ]/i', true],
            // Under (*UCP) a byte past ASCII takes the case of the
            // character of the same value, from 10.35.
            'a Latin-1 letter and its capital under (*UCP)' => ['/(*UCP)[\xC9\xE9]/i', true],
            'the Latin-1 letters and two signs under (*UCP)' => ['/(*UCP)[\xC0-\xFE]/i', true],
            'the Latin-1 letters up to the small y with diaeresis under (*UCP)' => ['/(*UCP)[\xC0-\xFF]/i', true],
            'the Latin-1 capitals under (*UCP)' => ['/(*UCP)[\xC0-\xDF]/i', false],
            'the Latin-1 small letters under (*UCP)' => ['/(*UCP)[\xE0-\xFE]/i', false],
            'both runs of Latin-1 letters under (*UCP)' => ['/(*UCP)[\xC0-\xDE\xE0-\xFE]/i', true],
            // The Angstrom sign and the Kelvin sign, in a caseless set with
            // them, are no bytes.
            'the A with ring above and its capital under (*UCP)' => ['/(*UCP)[\xC5\xE5]/i', true],
            'the K and its small letter under (*UCP)' => ['/(*UCP)[kK]/i', true],
            'a range from the first capital to the last small letter under (*UCP)' => ['/(*UCP)[A-z]/i', true],
            // Their other cases, U+039C, U+1E9E and U+0178, are no bytes.
            'a Latin-1 letter and its capital with the micro sign, the sharp s and the small y with diaeresis under (*UCP)' => ['/(*UCP)[\xC9\xE9\xB5\xDF\xFF]/i', true],
            'a negated class of a Latin-1 letter and its capital under (*UCP)' => ['/(*UCP)[^\xC9\xE9]/i', true],
            'a Latin-1 letter and its capital with an ASCII letter under (*UCP)' => ['/(*UCP)[\xC9\xE9a]/i', false],
            'a Latin-1 letter without its capital under (*UCP)' => ['/(*UCP)[\xE9]/i', false],
        ] as $name => [$pattern, $useless]) {
            yield $name => ['pattern' => $pattern, 'locale' => CharacterTables::BUILT_IN, 'useless' => $useless];
        }

        // The Latin-1 tables pair 0xE9 with 0xC9, and every letter but the
        // sharp s and the small y with diaeresis with another.
        yield 'a Latin-1 letter and its capital under Latin-1' => ['pattern' => '/[\xC9\xE9]/i', 'locale' => self::LATIN1, 'useless' => true];
        yield 'the Latin-1 letters and two signs under Latin-1' => ['pattern' => '/[\xC0-\xFE]/i', 'locale' => self::LATIN1, 'useless' => true];
        yield 'a Latin-1 letter without its capital under Latin-1' => ['pattern' => '/[\xE9]/i', 'locale' => self::LATIN1, 'useless' => false];
        // The Turkish tables pair "I" with "i", and 0xFD with "I" alone: a
        // class of 0xFD matches "I" with i, and of "I" matches "i".
        yield 'a capital and the dotless small letter under Turkish' => ['pattern' => '/[I\xFD]/i', 'locale' => self::TURKISH, 'useless' => false];
    }

    /**
     * @param bool $useless what the engine says: no string of one or two bytes answers otherwise without i
     */
    #[Test]
    #[DataProvider('provideInlineOptions')]
    public function test_flag_i_is_called_useless_where_inline_options_decide_the_case(string $pattern, bool $useless): void
    {
        $this->requiresCompile($pattern);

        // Oracle: every byte and every pair of bytes, which every row
        // matches within.
        $differs = [];
        $without = substr($pattern, 0, -1);
        $seconds = ['', ...array_map(\chr(...), range(0, 255))];
        for ($first = 0; $first < 256; $first++) {
            foreach ($seconds as $second) {
                $subject = \chr($first).$second;
                if (preg_match($pattern, $subject) !== preg_match($without, $subject)) {
                    $differs[] = bin2hex($subject);
                }
            }
        }
        $this->assertSame($useless, [] === $differs, \sprintf('Oracle: %s answers otherwise without i on %s.', addcslashes($pattern, "\200..\377"), json_encode(\array_slice($differs, 0, 8))));

        $this->assertSame($useless, self::reports($pattern, CharacterTables::BUILT_IN), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, useless: bool}>
     */
    public static function provideInlineOptions(): iterable
    {
        foreach ([
            'a letter after i turned off' => ['/(?-i)a/i', true],
            'a Latin-1 letter after i turned off under (*UCP)' => ['/(*UCP)(?-i)\xE9/i', true],
            'a Latin-1 letter after i turned on again under (*UCP)' => ['/(*UCP)(?i)\xE9/i', true],
            'a letter after i turned on again' => ['/(?i)a/i', true],
            'a letter after every option reset' => ['/(?^)a/i', true],
            'a letter after the options reset and i turned on again' => ['/(?^i)a/i', true],
            'a letter in a group turning i off' => ['/(?-i:a)/i', true],
            'a letter after a group turning i off' => ['/(?-i:a)b/i', false],
            'a letter after a group turning i on' => ['/(?i:a)b/i', false],
            'a letter turning i on again after a letter with it off' => ['/(?-i)a(?i)b/i', true],
            'a letter turning i off after a letter with it on again' => ['/(?i)a(?-i)b/i', true],
            'a letter in a group turning i on, then one after it' => ['/(?:(?i)a)b/i', false],
            'a letter in a group turning i off, then one after it' => ['/(?:(?-i)a)b/i', false],
            // An option set in an alternative holds for the next ones.
            'an alternative after one turning i off' => ['/(?-i)a|b/i', true],
            'an alternative after one turning i off at its end' => ['/1(?-i)|b/i', true],
            'an alternative after one turning i off at its end, after a letter' => ['/a(?-i)|b/i', false],
            'an alternative turning i on again after one turning it off' => ['/(?-i)a|(?i)b/i', true],
            'a group of alternatives after one turning i off' => ['/(?:(?-i)a|b)/i', true],
            'a letter after a group of alternatives turning i off' => ['/(?:(?-i)a|b)c/i', false],
            'a letter after a group of alternatives turning i on' => ['/((?i)a|b)c/i', false],
            'the branches of a reset group after one turning i off' => ['/(?|(?-i)a|b)/i', true],
            'an atomic group of alternatives after one turning i off' => ['/(?>(?-i)a|b)/i', true],
            'the no branch of a condition after its yes branch turning i off' => ['/(?(?=1)(?-i)a|b)/i', true],
            'a letter after a repeated group turning i off' => ['/(?:a(?-i))+b/i', false],
            'a letter after a lookbehind turning i off' => ['/(?<=(?-i)a)b/i', false],
            'a lookbehind turning i off' => ['/(?<=(?-i)a)1/i', true],
            'a class of a letter after i turned off' => ['/(?-i)[a]b/i', true],
            'a class of a letter and its capital before i turned off' => ['/[aA](?-i)b/i', true],
            'a quoted letter after i turned off' => ['/(?-i)\Qab\E/i', true],
            'a quoted letter before i turned off' => ['/\Qab\E(?-i)/i', false],
            'the capital property after i turned off' => ['/(?-i)\p{Lu}/i', true],
            'the capital property' => ['/\p{Lu}/i', false],
            'the digit property after i turned on again' => ['/(?i)\p{Nd}/i', true],
            // A reference reads its case where it is written; the group
            // captures it where the group is.
            'a reference to a letter' => ['/(a)\1/i', false],
            'a reference after i turned off' => ['/(?-i)(a)\1/i', true],
            'a reference to a letter in a group turning i off' => ['/(?-i:(a))\1/i', false],
            'a reference turning i on again to a digit' => ['/(?-i)(1)(?i)\1/i', true],
            'a reference to a letter in a group turning i off within it' => ['/((?-i)a)\1/i', false],
        ] as $name => [$pattern, $useless]) {
            yield $name => ['pattern' => $pattern, 'useless' => $useless];
        }
    }

    /**
     * Under (*UCP) PCRE2's Unicode tables are read for a byte past ASCII in
     * a class, and only there: a class of ASCII is read without them,
     * which keeps a lint of "/(*UCP)[aA]/i" from paying for them.
     */
    #[Test]
    #[DataProvider('provideTablesReadsUnderUcp')]
    public function test_pcre2_case_tables_are_read_for_a_class_past_ascii_only(string $pattern, bool $read): void
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
    public static function provideTablesReadsUnderUcp(): iterable
    {
        yield 'a letter and its capital' => ['pattern' => '/(*UCP)[aA]/i', 'read' => false];
        yield 'a letter, its capital and the last ASCII character' => ['pattern' => '/(*UCP)[aA\x7F]/i', 'read' => false];
        yield 'a letter, its capital and the first byte past ASCII' => ['pattern' => '/(*UCP)[aA\x80]/i', 'read' => true];
    }

    /**
     * PCRE2 refuses a code point past a byte without UTF: such a class is
     * not read, nor its range walked.
     */
    #[Test]
    #[DataProvider('provideClassesPastAByte')]
    public function test_a_class_past_a_byte_without_utf_keeps_the_flag(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''), 'Oracle: PCRE2 refuses '.$pattern);

        $this->assertFalse(self::reports($pattern, CharacterTables::BUILT_IN), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideClassesPastAByte(): iterable
    {
        yield 'a letter and its capital with the first code point past a byte' => ['pattern' => '/[aA\x{100}]/i'];
        yield 'a letter and its capital with every code point' => ['pattern' => '/[\x00-\x{10FFFF}aA]/i'];
        yield 'a letter and its capital with every code point, backwards' => ['pattern' => '/[\x{10FFFF}-\x00aA]/i'];
    }

    private static function reports(string $pattern, string $locale): bool
    {
        $regex = Regex::create(['cache' => null, 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        return \in_array(self::ID, array_map(static fn ($issue): string => $issue->id, $linter->getIssues()), true);
    }
}
