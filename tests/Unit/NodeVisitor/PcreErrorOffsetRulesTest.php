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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * Each construct PCRE refuses is reported where PCRE reports it, one row per
 * rule the library follows to place the offset.
 *
 * Both offsets of a row were read from the engines: the first is PHP's
 * warning on PCRE2 10.48 ("Compilation failed: ... at offset N"), the second
 * pcre2test 10.40's, the PCRE2 PHP 8.2 bundles. Where they differ, either is
 * right; the library follows 10.48, or 10.40 for a construct only newer
 * releases read. An error message that quotes a position quotes the offset.
 */
final class PcreErrorOffsetRulesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedConstructs')]
    public function test_validate_reports_the_offset_pcre_reports(string $pattern, int $pcre2Offset, int $floorOffset): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
        $this->assertContains($result->offset, array_values(array_unique([$pcre2Offset, $floorOffset])), \sprintf(
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
        yield '\\c at the end' => ['/^\\c/', 3, 3];
        yield '\\c before a two-byte character' => ['/^\\cģ/', 4, 3];
        yield '\\c before a character in UTF mode' => ['/^\\cģ/u', 5, 3];
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
