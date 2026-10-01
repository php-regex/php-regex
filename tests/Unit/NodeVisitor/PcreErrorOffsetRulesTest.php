<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each construct PCRE refuses is reported where PCRE reports it, one row per
 * rule the library follows to place the offset.
 *
 * Both offsets of a row were read from the engines: the first is PHP's
 * warning on PCRE2 10.48 ("Compilation failed: ... at offset N"), the second
 * pcre2test 10.40's, the PCRE2 PHP 8.2 bundles. The running engine has the
 * last word: where it warns with an offset, that offset is the one reported,
 * as releases between and after place some errors elsewhere (10.44 reports
 * "\g{ 3" at 2). An error message that quotes a position quotes the offset.
 */
final class PcreErrorOffsetRulesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedConstructs')]
    public function test_validate_reports_the_offset_pcre_reports(string $pattern, int $pcre2Offset, int $floorOffset): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));

        // The running engine says where: its offset is the one to report,
        // whatever release it is. The row's offsets stand where it says none.
        error_clear_last();
        @preg_match($pattern, '');
        if (1 === preg_match('/at offset (\d++)/', error_get_last()['message'] ?? '', $running)) {
            $this->assertSame((int) $running[1], $result->offset, \sprintf('%s: PCRE2 %s reports offset %d, the library %s: %s', $pattern, \PCRE_VERSION, (int) $running[1], var_export($result->offset, true), explode("\n", (string) $result->error)[0]));
        }

        $this->assertContains($result->offset, array_values(array_unique([$pcre2Offset, $floorOffset, ...(isset($running[1]) ? [(int) $running[1]] : [])])), \sprintf(
            '%s: PHP reports offset %d (PCRE2 10.40: %d), the library %s: %s',
            $pattern,
            $pcre2Offset,
            $floorOffset,
            var_export($result->offset, true),
            explode("\n", (string) $result->error)[0],
        ));

        if (1 === preg_match('/at position (\d++)/', (string) $result->error, $matches)) {
            $this->assertSame($result->offset, (int) $matches[1], 'The message quotes another position than the offset.');
        }
    }

    /**
     * pcre2test 10.44, 10.45, 10.47 and 10.49.
     *
     * @param array<string, int> $offsets by release
     */
    #[Test]
    #[DataProvider('provideReleaseOffsets')]
    public function test_each_release_reports_its_offset(string $pattern, array $offsets): void
    {
        foreach ($offsets as $release => $offset) {
            $result = Regex::create(['cache' => null, 'pcre_version' => $release])->validate($pattern);

            $this->assertFalse($result->isValid, $pattern.' on '.$release);
            $this->assertSame($offset, $result->offset, $pattern.' on '.$release);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, int>}>
     */
    public static function provideReleaseOffsets(): iterable
    {
        // An unclosed "\g{ 1": on the space before 10.43, which takes no
        // padding, on "\g" until 10.47, past the number from then on
        // (pcre2test 10.40, 10.42, 10.44, 10.45, 10.46; PHP on 10.49).
        yield 'unclosed padded \\g number' => ['/a\\g{ 1/', ['10.40' => 4, '10.42' => 4, '10.44' => 3, '10.45' => 3, '10.47' => 6, '10.49' => 6]];
        // Before 10.47 a minor is two digits: PCRE stops at a third one.
        yield 'minor of four digits, then text' => ['/(?(VERSION>=10.1001x)a|b)/', ['10.44' => 17, '10.45' => 17, '10.47' => 19, '10.49' => 19]];
        yield 'minor of three digits, then text' => ['/(?(VERSION>=10.999x)a|b)/', ['10.44' => 17, '10.45' => 17, '10.47' => 19, '10.49' => 19]];
        yield 'minor of two digits, then text' => ['/(?(VERSION>=10.99x)a|b)/', ['10.44' => 17, '10.45' => 17, '10.47' => 18, '10.49' => 18]];
        yield 'minor of one digit, then text' => ['/(?(VERSION>=10.9x)a|b)/', ['10.44' => 16, '10.45' => 16, '10.47' => 17, '10.49' => 17]];
        // Before 10.45 a range from a class escape is refused at the "-",
        // before the escape at its end is read.
        yield 'range from a type to \N' => ['/[\\d-\\N]/', ['10.44' => 3, '10.45' => 6, '10.47' => 6, '10.49' => 6]];
        yield 'range from a type to an unknown escape' => ['/[\\d-\\j]/', ['10.44' => 3, '10.45' => 5, '10.47' => 6, '10.49' => 6]];
        yield 'range from a type to \R, after a member' => ['/[a\\d-\\R]/', ['10.44' => 4, '10.45' => 6, '10.47' => 7, '10.49' => 7]];
        yield 'range from a POSIX class to \X' => ['/[[:alpha:]-\\X]/', ['10.44' => 10, '10.45' => 12, '10.47' => 13, '10.49' => 13]];
        yield 'range from a property to \X' => ['/[\\p{L}-\\X]/', ['10.44' => 6, '10.45' => 8, '10.47' => 9, '10.49' => 9]];
        yield 'range from a type to k, a letter from 10.45' => ['/[\\d-\\k]/', ['10.44' => 3, '10.45' => 4, '10.47' => 4, '10.49' => 4]];
        yield 'range from a type to \X' => ['/[\\d-\\X]/', ['10.44' => 3, '10.45' => 5, '10.47' => 6, '10.49' => 6]];
    }

    #[Test]
    public function test_unmatched_closing_parenthesis_is_reported_where_the_running_pcre_reports_it(): void
    {
        // PCRE2 after 10.45 moved the offset past the ")": preg_match('/a)/', '')
        // warns "unmatched closing parenthesis at offset 2" on 10.48, and at
        // offset 1 on the 10.44 that PHP 8.4 bundles.
        $expected = version_compare(explode(' ', \PCRE_VERSION)[0], '10.46', '>=') ? 2 : 1;

        $result = Regex::create(['cache' => null])->validate('/a)/');

        $this->assertFalse($result->isValid);
        $this->assertSame($expected, $result->offset);
        $this->assertStringContainsString(\sprintf('at position %d', $expected), (string) $result->error);
    }

    #[Test]
    public function test_unmatched_closing_parenthesis_follows_the_bundled_pcre_of_a_target_php_version(): void
    {
        // PHP 8.4 bundles PCRE2 10.44, which stops on the ")".
        $result = Regex::create(['cache' => null, 'php_version' => 80400])->validate('/a)/');

        $this->assertFalse($result->isValid);
        $this->assertSame(1, $result->offset);
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int}>
     */
    public static function provideRejectedConstructs(): iterable
    {
        yield 'missing group, numbered reference' => ['/(ab\\2)/', 5, 4];
        yield 'missing group, two-digit reference' => ['/((((((((x))))))))\\81/', 20, 19];
        yield 'missing group, braced \\g' => ['/()\\g{3}/', 7, 6];
        yield 'missing group, unbraced \\g' => ['/()\\g3/', 5, 4];
        yield 'relative \\g back past the first group, braced' => ['/()\\g{-2}/', 4, 4];
        yield 'relative \\g back past the first group, unbraced' => ['/()\\g-2/', 6, 6];
        yield 'relative zero, braced' => ['/\\g{+0}/', 2, 2];
        yield 'relative zero, unbraced' => ['/\\g-0/', 4, 4];
        yield 'group zero' => ['/^(a)\\g{0}/', 9, 9];
        yield 'named reference, \\k' => ['/\\k<nm>/', 3, 3];
        yield 'named reference, \\g braces' => ['/\\g{nm}/', 3, 3];
        yield 'named reference, Python' => ['/(?P=nm)/', 4, 4];
        yield 'named call' => ['/(?&nm)/', 3, 3];
        yield 'named call, Python' => ['/(?P>nm)/', 4, 4];
        yield 'named call, \\g' => ['/\\g<nm>/', 3, 3];
        yield 'numbered call' => ['/(?1)/', 3, 3];
        yield 'relative call forward' => ['/x(?+1)y/', 5, 5];
        yield 'relative call back' => ['/x(?-1)y/', 5, 5];
        yield 'numbered call, \\g' => ['/\\g<2>/', 5, 4];
        yield 'relative call back, \\g' => ['/\\g<-2>/', 2, 2];
        yield 'numbered condition' => ['/(?(2)a)/', 2, 2];
        yield 'relative condition back' => ['/()(?(-2)a)/', 7, 7];
        yield 'relative condition forward' => ['/(?(+10))/', 4, 4];
        yield 'zero condition' => ['/^(?(0)f|b)oo/', 5, 5];
        yield 'named condition, angle brackets' => ['/(?(<nm>)a)/', 4, 4];
        yield 'named condition, quotes' => ['/(?(\'nm\')a)/', 4, 4];
        yield 'bare name condition' => ['/(?(nm)a)/', 3, 3];
        yield 'recursion condition by name' => ['/(?(R&nm)a)/', 5, 5];
        yield 'recursion condition by number' => ['/(?(R2)a)/', 3, 3];
        yield 'recursion condition number too big' => ['/((?(R8000000000)))/', 9, 9];
        yield '\\k with a digit-first name' => ['/\\k<5ghj>/', 4, 3];
        yield '\\g with a number no bracket closes' => ['/\\g\'3gh\'/', 4, 2];
        yield 'missing group inside a lookbehind' => ['/(?<=b(?1))xyz/', 8, 8];
        yield 'relative call inside a lookbehind' => ['/(?<=\\bABQ(3(?-7)))/', 15, 15];
        yield 'forward relative reference inside a lookbehind' => ['/(?<=()\\g{+1})/', 12, 11];
        yield 'numbered back reference in a lookbehind of a branch reset pattern' => ['/(?<=\\3)a+(?|(a)|(b)(c))/', 0, 0];
        yield 'relative back reference past the first group in a lookbehind of a branch reset pattern' => ['/(?<=\\g{-1})a+(?|(a)|(b)(c))/', 6, 6];
        yield 'unbounded lookbehind' => ['/abc(?<=a+)b/', 3, 3];
        yield 'unbounded lookbehind branch' => ['/(?<=a|b+)c/', 0, 0];
        yield 'unbounded nested lookbehind' => ['/(?<=ab(?<=c+)d)ef/', 6, 6];
        yield 'innermost unbounded lookbehind' => ['/(?<=(?<=(?<=a+)b+))/', 8, 8];
        yield 'unbounded lookbehind in a lookahead inside a lookbehind' => ['/(?<=(?=(?<=a+))b)/', 7, 7];
        yield 'unbounded before a missing call' => ['/(?<=a+(?1))/', 0, 0];
        yield 'unknown verb' => ['/(*FOO)/', 5, 5];
        yield 'unknown verb with an argument' => ['/(*ploo:abc)/', 6, 6];
        yield 'empty verb' => ['/(*)b/', 2, 1];
        yield 'unclosed verb' => ['/(*MARK:a/', 8, 8];
        yield 'unclosed unknown verb' => ['/(*FOO:x/', 5, 5];
        yield 'unclosed verb name' => ['/(*pla/', 5, 5];
        yield 'class escape before a hyphen' => ['/[\\d-a]/', 4, 3];
        yield 'class escape after a hyphen' => ['/[a-\\d]/', 5, 5];
        yield 'POSIX class after a hyphen' => ['/[a-[:digit:]]+/', 12, 4];
        yield 'invalid class escape before a hyphen' => ['/[\\N-a]/', 3, 3];
        yield 'invalid class escape after a hyphen' => ['/[\\d-\\N]/', 6, 3];
        yield 'reversed range' => ['/[z-a]/', 4, 3];
        yield 'unknown option letter' => ['/(?z)/', 3, 2];
        yield 'unknown option letter after a known one' => ['/(?iz)/', 4, 3];
        yield 'second hyphen in options' => ['/(?x-i-i)/', 6, 5];
        yield 'hyphen after caret' => ['/(?^-i)/', 4, 3];
        yield 'unclosed options' => ['/(?i/', 3, 3];
        yield 'sign without a number' => ['/(?+-a)/', 4, 2];
        yield 'sign at the end' => ['/(?+/', 3, 2];
        yield 'recursion not closed' => ['/(?R-:)/', 3, 3];
        yield 'numbered call not closed' => ['/(?1/', 3, 3];
        yield 'extended class' => ['/(?[a])/', 4, 2];
        yield 'unclosed extended class' => ['/(?[])/', 4, 2];
        yield 'callout number too big' => ['/(?C256)ab/', 6, 6];
        yield 'callout number too big inside a longer number' => ['/(?C1000)/', 7, 7];
        yield 'callout argument unknown' => ['/(?C12vr)x/', 5, 5];
        yield 'callout string unclosed' => ['/a(?C"a/', 4, 4];
        yield 'callout string not followed by )' => ['/a(?C"a"bcde(?C"b")xyz/', 7, 7];
        yield 'callout delimiter unknown' => ['/(?Cab)xx/', 4, 3];
        yield 'callout at the end' => ['/(?C/', 3, 3];
        yield 'quantifier with nothing to repeat' => ['/*/', 1, 0];
        yield 'lazy quantifier with nothing to repeat' => ['/{4,5}?/', 5, 4];
        yield 'repeated assertion' => ['/\\A+a/', 3, 2];
        yield 'repeated callout' => ['/(?C1)*/', 6, 5];
        yield 'quantifier bounds out of order' => ['/x{5,4}/', 5, 5];
        yield 'lazy quantifier bounds out of order' => ['/x{5,4}?/', 5, 5];
        yield 'quantifier minimum too big' => ['/a{70000,1}/', 7, 7];
        yield 'quantifier maximum too big' => ['/x{1,70000}/', 9, 9];
        yield 'code point too large' => ['/\\x{110000}/u', 9, 9];
        yield 'octal code point too large' => ['/\\o{4200000}/u', 10, 10];
        yield 'octal code point too large without u' => ['/\\o{400}/', 6, 6];
        yield 'empty hex braces' => ['/\\x{}/', 3, 3];
        yield 'character name in \\N{}, which PCRE2 does not support' => ['/\\N{LATIN SMALL LETTER A}/u', 3, 2];
        yield 'code point above U+10FFFF in \\N{U+}' => ['/\\N{U+110000}/u', 11, 11];
        yield 'code point above U+10FFFF in \\N{U+} in a class' => ['/[\\N{U+110000}]/u', 12, 12];
        yield 'code point far above U+10FFFF in \\N{U+}' => ['/\\N{U+FFFFFFFFF}/u', 14, 14];
        yield 'sign without digits after \\g' => ['/\\g-/', 2, 2];
        yield 'plus without digits after \\g' => ['/a\\g+b/', 3, 3];
        yield 'quantified match limit' => ['/(*LIMIT_MATCH=10)+/', 18, 17];
        yield 'counted match limit' => ['/(*LIMIT_MATCH=10){2}/', 20, 19];
        yield '\\c at the end' => ['/^\\c/', 3, 3];
        yield '\\c before a two-byte character' => ['/^\\cģ/', 4, 3];
        yield '\\c before a character in UTF mode' => ['/^\\cģ/u', 5, 3];
        yield '\\c before a tab' => ["/^\\c\t/", 4, 3];
        yield '\\c before a newline' => ["/^\\c\n/", 4, 3];
        yield '\\c before a control character' => ["/^\\c\x1f/", 4, 3];
        yield '\\c before DEL' => ["/^\\c\x7f/", 4, 3];
        yield 'unknown property' => ['/\\p{Foo}/', 7, 7];
        yield 'unknown one-letter property' => ['/\\pQ/', 3, 3];
        yield 'unknown Unicode name' => ['/\\N{name}/', 3, 2];
        yield 'unknown Unicode name in a class' => ['/[\\N{name}]/', 4, 3];
        yield 'empty group name' => ['/(?<>a)/', 3, 3];
        yield 'group name that is no name' => ['/(?<%)b/', 3, 3];
        yield 'group name with a digit first' => ['/(?<0abc>xx)/', 4, 3];
        yield 'group name with a stray character' => ['/(?<ab-cd>xx)/', 5, 5];
        yield 'group name with a stray character in UTF mode' => ['/(?\'AB၌C\'x)/u', 5, 5];
        yield 'group name with a digit first in UTF mode' => ['/(?<١abc>x)/u', 5, 3];
        yield 'empty quoted condition name' => ['/(?(\'\'))/', 4, 4];
        yield 'duplicate group name' => ['/(?<x>a)(?<x>b)/', 12, 12];
        yield 'call to a digit-first name' => ['/(?&1abc)/', 4, 3];
        yield 'call to a digit-first name in UTF mode' => ['/(?&١abc)/u', 5, 3];
        yield '\\g with nothing after it' => ['/^(a)\\g/', 6, 6];
        yield '\\g with an unclosed name' => ['/\\g{A/', 4, 4];
        yield '\\g with an unclosed number' => ['/\\g{ 3/', 5, 3];
        yield '\\k not followed by a name' => ['/\\k/', 2, 2];
        yield '\\k with an empty name' => ['/\\k<>/', 3, 3];
        yield 'unknown after (?P' => ['/(?P)/', 4, 3];
        yield 'version condition with <' => ['/(?(VERSION<10)yes|no)/', 11, 10];
        yield 'version condition with >' => ['/(?(VERSION>10)yes|no)/', 11, 11];
        yield 'version condition with ==' => ['/(?(VERSION==10)a|b)/', 12, 11];
        yield 'version condition with a stray character' => ['/(?(VERSION=10z)yes|no)/', 14, 13];
        yield 'version condition with a trailing dot' => ['/(?(VERSION>=10.)a|b)/', 16, 15];
        yield 'version condition with a trailing dot at the end' => ['/(?(VERSION>=10.1a/', 17, 16];
        yield 'version condition with a major too big' => ['/(?(VERSION>=1001x)a|b)/', 16, 16];
        yield 'version condition with a minor too big' => ['/(?(VERSION>=10.1001x)a|b)/', 19, 17];
        yield 'version condition word' => ['/(?(VERSIONx)a|b)/', 11, 10];
        yield 'condition with a number and a letter' => ['/(?(1a)x)/', 4, 4];
        yield 'condition that is no name' => ['/(?(a.b)x)/', 4, 4];
        yield 'condition that is an escape' => ['/(?(\\g)a)/', 3, 3];
        yield 'condition with a sign and no number' => ['/(?(+a)b)/', 3, 3];
        yield 'valid version condition before a missing group' => ['/(?(VERSION>=10.4)a)\\1/', 21, 20];
        yield 'empty Unicode name' => ['/\\N{}/', 3, 2];
        yield 'unbounded alternative inside a lookbehind' => ['/(?<=x(?:a|b+)y)/', 0, 0];
        yield 'bounded nested lookbehind in an unbounded one' => ['/(?<=a(?<=b)c+)/', 0, 0];
        yield 'repeated option setting' => ['/(?i)*/', 5, 4];
        yield 'unknown option letter after a caret' => ['/(?^z)/', 4, 3];
        yield 'unclosed callout number too big' => ['/(?C256/', 6, 6];
        yield 'unclosed callout number' => ['/(?C12/', 5, 5];
        yield 'call to a name R and digits' => ['/(?&R1)/', 3, 3];
        yield 'call to a name R and a negative number' => ['/\\g<R-1>/', 4, 4];
        yield 'bare condition named VERSION' => ['/(?(VERSION)abcdef)/', 3, 3];
    }
}
