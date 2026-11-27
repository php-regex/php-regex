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
use PHPRegex\Linter\Rule\UselessIFlagRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under i a reference matches what its group captured in either case: the
 * flag is useless only where the group captures no character with a case.
 * An anchor, an assertion, a comment or a lookaround captures none; nor
 * does a class of digits, written as a code point, a type, a POSIX class
 * or the property of the decimal digits under PCRE2's own tables. A class
 * of word characters or of letters does, and a call what the group it runs
 * does.
 */
final class UselessIFlagOfReferencesTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    private const SUBJECT_LETTERS = ['a', 'A', '0', '5', '_', ' ', "\n"];

    #[Test]
    #[DataProvider('provideReferences')]
    public function test_flag_i_is_called_useless_where_the_referenced_group_captures_no_case(string $pattern, bool $useless): void
    {
        // Oracle: every subject of one or two characters over a small and a
        // capital letter, digits, an underscore, a space and a line feed;
        // i is useless where none answers otherwise without it.
        $without = substr($pattern, 0, -1);
        $differs = [];
        foreach (self::subjects() as $subject) {
            if (preg_match($pattern, $subject) !== preg_match($without, $subject)) {
                $differs[] = $subject;
            }
        }
        $this->assertSame($useless, [] === $differs, \sprintf('Oracle: %s answers otherwise without i on %s.', $pattern, json_encode($differs)));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($useless, \in_array(UselessIFlagRule::ID, $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, useless: bool}>
     */
    public static function provideReferences(): iterable
    {
        yield 'a group of a line start' => ['pattern' => '/(^)\1/i', 'useless' => true];
        yield 'a group of a word boundary' => ['pattern' => '/(\b)\1/i', 'useless' => true];
        yield 'a group of a comment' => ['pattern' => '/((?#c))\1/i', 'useless' => true];
        yield 'a class of a digit written as a code point' => ['pattern' => '/([\x30])\1/i', 'useless' => true];
        yield 'a class of the digits type' => ['pattern' => '/([\d])\1/i', 'useless' => true];
        yield 'a class of the POSIX digits' => ['pattern' => '/([[:digit:]])\1/i', 'useless' => true];
        yield 'a class of the word type' => ['pattern' => '/([\w])\1/i', 'useless' => false];
        yield 'a class of the POSIX letters' => ['pattern' => '/([[:alpha:]])\1/i', 'useless' => false];
        yield 'a class of a letter written as a code point' => ['pattern' => '/([\x61])\1/i', 'useless' => false];
    }

    /**
     * A group captures the text its own characters match: a lookaround in
     * it reads characters that are no part of that text, and a call matches
     * what the group it runs matches, so neither gives the reference a case
     * the characters around them do not have. The decimal digits of
     * "\p{Nd}" have none, as those of "\d" have none.
     *
     * Engine, with the JIT and without it: no decimal digit matches another
     * character as a caseless literal, a caseless class or the text of a
     * caseless reference, of the 650 of PCRE2 10.35 to the 770 of 10.49
     * (10.35, 10.39, 10.40, 10.42, 10.43, 10.44, 10.46 and 10.49), every
     * character tried after each.
     *
     * @param bool        $useless what the engine says of the subjects tried: none answers otherwise without i
     * @param string|null $witness a longer subject that answers otherwise without i
     */
    #[Test]
    #[DataProvider('provideGroupsOfLookaroundsCallsAndDecimalDigits')]
    public function test_flag_i_is_called_useless_where_lookarounds_calls_and_decimal_digits_give_the_group_no_case(string $pattern, bool $useless, bool $reported, ?string $witness = null): void
    {
        // Oracle: every subject of up to five characters over two letters
        // in both cases, two digits and a hyphen, or of up to four over
        // "k", its capital, the Kelvin sign, two digits and both cases of a
        // Roman numeral under u; i is useless where none gives another
        // match or other groups without it.
        $without = (string) preg_replace('/i([a-z]*)$/', '$1', $pattern);
        $subjects = str_ends_with($pattern, 'u')
            ? self::subjectsOver(['k', 'K', "\u{212A}", '1', "\u{663}", "\u{216B}", "\u{217B}"], 4)
            : self::subjectsOver(['a', 'A', '1', '2', '-'], 5);
        $differs = [];
        foreach (null === $witness ? $subjects : [...$subjects, $witness] as $subject) {
            preg_match_all($pattern, $subject, $with, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            preg_match_all($without, $subject, $withoutI, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            if ($with !== $withoutI) {
                $differs[] = $subject;
            }
        }
        $this->assertSame($useless, [] === $differs, \sprintf('Oracle: %s answers otherwise without i on %s.', $pattern, json_encode(\array_slice($differs, 0, 5), \JSON_UNESCAPED_UNICODE)));

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertSame($reported, \in_array(UselessIFlagRule::ID, $ids, true), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, useless: bool, reported: bool, witness?: string}>
     */
    public static function provideGroupsOfLookaroundsCallsAndDecimalDigits(): iterable
    {
        // A lookaround reads a word character, or any: the group captures
        // the digit alone.
        yield 'a digit a lookahead sees before a word character' => ['pattern' => '/(\d(?=\w))\1/i', 'useless' => true, 'reported' => true];
        yield 'a digit a negative lookahead sees before no word character' => ['pattern' => '/(\d(?!\w))-\1/i', 'useless' => true, 'reported' => true];
        yield 'a digit a lookbehind sees after no word character' => ['pattern' => '/((?<=\W)\d)\1/i', 'useless' => true, 'reported' => true];
        yield 'a digit a negative lookbehind sees after no word character' => ['pattern' => '/((?<!\w)\d)\1/i', 'useless' => true, 'reported' => true];
        yield 'a digit a lookahead sees as any character' => ['pattern' => '/((?=.)\d)\1/i', 'useless' => true, 'reported' => true];
        yield 'a decimal digit a lookahead sees as a word character, under u' => ['pattern' => '/((?=\w)\p{Nd})\1/iu', 'useless' => true, 'reported' => true];
        // The group the reference names is the one inside the lookaround.
        yield 'a word character captured inside a lookahead' => ['pattern' => '/(?=(\w)).\1/i', 'useless' => false, 'reported' => false];
        // The lookahead leaves the word character a digit alone: the rule,
        // which reads what the group may capture from its own characters,
        // keeps the flag.
        yield 'a word character a lookahead sees as a digit' => ['pattern' => '/((?=\d)\w)\1/i', 'useless' => true, 'reported' => false];
        // A call matches what the group it runs matches.
        yield 'a call of a group of a digit' => ['pattern' => '/(\d)((?1))\2/i', 'useless' => true, 'reported' => true];
        yield 'a call by name of a group of a digit' => ['pattern' => '/(?<d>\d)((?&d))\2/i', 'useless' => true, 'reported' => true];
        yield 'a call written as \g of a group of a digit' => ['pattern' => '/(\d)(\g<1>)\2/i', 'useless' => true, 'reported' => true];
        yield 'a call of a group of a digit that follows' => ['pattern' => '/((?2))(\d)\1/i', 'useless' => true, 'reported' => true];
        yield 'a call of a group of a digit before a lookahead, under u' => ['pattern' => '/(\d)((?1)(?=\w))\2/iu', 'useless' => true, 'reported' => true];
        yield 'a group that calls itself' => ['pattern' => '/(\d|-(?1))\1/i', 'useless' => true, 'reported' => true];
        yield 'two groups that call each other' => ['pattern' => '/((?2)|1)(2|-(?1))\1/i', 'useless' => true, 'reported' => true];
        yield 'a call of a group of a word character' => ['pattern' => '/(\w)((?1))\2/i', 'useless' => false, 'reported' => false];
        // The whole pattern, which a group named "R" does not answer for,
        // may match the word character beside the group.
        yield 'a call of the whole pattern' => ['pattern' => '/(?<R>-)\w?((?R)|1)\2/i', 'useless' => false, 'reported' => false, 'witness' => '--a11-A11'];
        yield 'a call of the whole pattern by its number' => ['pattern' => '/(-)\w?((?0)|1)\2/i', 'useless' => false, 'reported' => false, 'witness' => '--a11-A11'];
        // The rule reads a call of a group named "R" as one of the whole
        // pattern, and keeps the flag.
        yield 'a call by name of a group named R' => ['pattern' => '/(?<R>-)\w?((?&R)|1)\2/i', 'useless' => true, 'reported' => false];
        // The decimal digits have no case, in a class or alone.
        yield 'a class of the decimal digits' => ['pattern' => '/([\p{Nd}])\1/i', 'useless' => true, 'reported' => true];
        yield 'the decimal digits' => ['pattern' => '/(\p{Nd})\1/i', 'useless' => true, 'reported' => true];
        yield 'a class of the decimal digits, under u' => ['pattern' => '/([\p{Nd}])\1/iu', 'useless' => true, 'reported' => true];
        yield 'a run of decimal digits, under u' => ['pattern' => '/(\p{Nd}+)-\1/iu', 'useless' => true, 'reported' => true];
        yield 'every character but the decimal digits' => ['pattern' => '/(\P{Nd})\1/i', 'useless' => false, 'reported' => false];
        yield 'every character but the decimal digits, negated inside the braces' => ['pattern' => '/(\p{^Nd})\1/i', 'useless' => false, 'reported' => false];
        yield 'a class of the decimal digits and a letter' => ['pattern' => '/([\p{Nd}a])\1/i', 'useless' => false, 'reported' => false];
        // The numbers hold the Roman numerals, which have a case, past the
        // bytes: the rule keeps the flag without u too.
        yield 'the numbers, under u' => ['pattern' => '/(\p{N})\1/iu', 'useless' => false, 'reported' => false];
        yield 'a class of the numbers, under u' => ['pattern' => '/([\p{N}])\1/iu', 'useless' => false, 'reported' => false];
        yield 'the numbers' => ['pattern' => '/(\p{N})\1/i', 'useless' => true, 'reported' => false];
    }

    /**
     * Under another locale's tables a digit may have a case: the rule,
     * which does not read the tables for one, keeps the flag of a reference
     * to the digits, written as the type or as the property. Latin-1's
     * give them none, and the engine finds the flag useless there.
     */
    #[Test]
    #[DataProvider('provideDigitsUnderLocaleTables')]
    public function test_flag_i_is_kept_on_a_reference_to_digits_under_another_locale_tables(string $pattern): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        // Oracle: under the locale, every pair of bytes; none answers
        // otherwise without i.
        $differs = new \ArrayObject();
        $without = substr($pattern, 0, -1);
        CharacterTables::under(self::LATIN1, static function () use ($pattern, $without, $differs): void {
            for ($first = 0; $first < 256; $first++) {
                for ($second = 0; $second < 256; $second++) {
                    $subject = \chr($first).\chr($second);
                    if (preg_match($pattern, $subject) !== preg_match($without, $subject)) {
                        $differs[] = bin2hex($subject);
                    }
                }
            }
        });
        $this->assertSame([], $differs->getArrayCopy(), 'Oracle: '.$pattern.' under '.self::LATIN1);

        $regex = Regex::create(['cache' => null, 'locale' => self::LATIN1]);
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);
        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        $this->assertNotContains(UselessIFlagRule::ID, $ids, $pattern.' under '.self::LATIN1);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDigitsUnderLocaleTables(): iterable
    {
        yield 'the digits type' => ['pattern' => '/(\d)\1/i'];
        yield 'the decimal digits' => ['pattern' => '/(\p{Nd})\1/i'];
        yield 'a class of the decimal digits' => ['pattern' => '/([\p{Nd}])\1/i'];
    }

    /**
     * Every subject of up to $length of the letters, the empty one first.
     *
     * @param list<string> $letters
     *
     * @return list<string>
     */
    private static function subjectsOver(array $letters, int $length): array
    {
        $subjects = $last = [''];
        for ($size = 0; $size < $length; $size++) {
            $next = [];
            foreach ($last as $prefix) {
                foreach ($letters as $letter) {
                    $next[] = $prefix.$letter;
                }
            }

            $subjects = [...$subjects, ...$next];
            $last = $next;
        }

        return $subjects;
    }

    /**
     * @return list<string>
     */
    private static function subjects(): array
    {
        $subjects = [''];
        foreach (self::SUBJECT_LETTERS as $first) {
            $subjects[] = $first;
            foreach (self::SUBJECT_LETTERS as $second) {
                $subjects[] = $first.$second;
            }
        }

        return $subjects;
    }
}
