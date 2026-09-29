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
 * Where PCRE2 reports a syntax error moved with its releases: 10.47 reports
 * most of them past the character at fault rather than on it (its ChangeLog,
 * "Improved error offsets", #756), and 10.45 moved a few. PHP 8.2 to 8.5
 * bundle 10.40 to 10.44; a PHP linked to a newer PCRE2 reports the newer
 * offset. Every offset below is pcre2test's on 10.40, 10.44, 10.45, 10.46
 * and 10.47.
 */
final class ErrorOffsetReleaseTest extends TestCase
{
    #[Test]
    #[DataProvider('provideErrors')]
    public function test_a_targeted_php_gets_the_offset_of_the_pcre2_it_bundles(string $pattern, int $bundled, int $newer, string $movedIn): void
    {
        unset($newer, $movedIn);

        foreach ([80200, 80300, 80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, $pattern);
            $this->assertSame($bundled, $result->offset, \sprintf('%s on PHP %d', $pattern, $phpVersion));
        }
    }

    #[Test]
    #[DataProvider('provideErrors')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern, int $bundled, int $newer, string $movedIn): void
    {
        $running = explode(' ', \PCRE_VERSION)[0];
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame(version_compare($running, $movedIn, '>=') ? $newer : $bundled, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, bundled: int, newer: int, movedIn: string}>
     */
    public static function provideErrors(): iterable
    {
        // PCRE2 10.47 reports past the character at fault.
        yield 'quantifier with nothing to repeat' => ['pattern' => '/+/', 'bundled' => 0, 'newer' => 1, 'movedIn' => '10.47'];
        yield 'quantifier after a possessive one' => ['pattern' => '/a+++/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'unknown character after (?' => ['pattern' => '/(?%)/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'unknown character after (?P' => ['pattern' => '/(?P?)/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'hexadecimal digit expected' => ['pattern' => '/\\x{zz}/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'octal digit expected' => ['pattern' => '/\\o{9}/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'reference to a missing group' => ['pattern' => '/\\9/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];
        yield 'unknown escape' => ['pattern' => '/\\y/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];
        yield 'callout without its end' => ['pattern' => '/(?C(?C/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'range out of order' => ['pattern' => '/[z-a]/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'reference name starting with a digit' => ['pattern' => '/\\k<9a>/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'group name starting with a digit' => ['pattern' => '/(?<1a>x)/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'condition that is no assertion' => ['pattern' => '/(?(?i))/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'relative call without a number' => ['pattern' => '/(?+)/', 'bundled' => 2, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'code point without UTF mode' => ['pattern' => '/\\N{U+41}/', 'bundled' => 2, 'newer' => 8, 'movedIn' => '10.47'];
        yield 'character name' => ['pattern' => '/\\N{name}/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'character name after text' => ['pattern' => '/a\\N{A B}/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'character name that is no count' => ['pattern' => '/\\N{25,ab}/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'character name in a class' => ['pattern' => '/[\\N{name}]/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'uppercase conversion escape' => ['pattern' => '/\\U/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        yield 'name escape in a class' => ['pattern' => '/[\\N{4}]/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'option setting with an unknown letter' => ['pattern' => '/(?iz)/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'hyphen after a caret' => ['pattern' => '/(?^-)/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'callout opened by no delimiter' => ['pattern' => '/(?Cx/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'quantifier after a boundary' => ['pattern' => '/\\b+/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'escape invalid in a class' => ['pattern' => '/[\\B]/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'relative reference to a missing group' => ['pattern' => '/\\g{2}/', 'bundled' => 4, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'number that nothing closes after g' => ['pattern' => '/\\g{9/', 'bundled' => 2, 'newer' => 4, 'movedIn' => '10.47'];
        // 10.47 reported one past the end of the pattern, 10.48 at its end.
        yield 'hexadecimal digits never closed' => ['pattern' => '/\\x{12/', 'bundled' => 4, 'newer' => 5, 'movedIn' => '10.48'];
        yield 'count past 65535' => ['pattern' => '/a{655360}/', 'bundled' => 7, 'newer' => 8, 'movedIn' => '10.47'];
        yield 'count past 65535 with nothing to repeat' => ['pattern' => '/{655360}/', 'bundled' => 6, 'newer' => 7, 'movedIn' => '10.47'];
        yield 'alphabetic name followed by no colon' => ['pattern' => '/(*pla}abc/', 'bundled' => 5, 'newer' => 6, 'movedIn' => '10.47'];
        yield 'verb opener alone' => ['pattern' => '/(*/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];
        yield 'verb opener closed at once' => ['pattern' => '/(*)/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];
        yield 'verb opener before a sign' => ['pattern' => '/(*+/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        yield 'setting after text' => ['pattern' => '/a(*UTF)/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
        yield 'limit after text' => ['pattern' => '/a(*LIMIT_MATCH=5)/', 'bundled' => 14, 'newer' => 14, 'movedIn' => '10.40'];
        yield 'unclosed limit after text' => ['pattern' => '/1(*LIMIT_MATCH=/', 'bundled' => 14, 'newer' => 14, 'movedIn' => '10.40'];
        yield 'setting in the body of an assertion' => ['pattern' => '/(*atomic:(*CR))/', 'bundled' => 13, 'newer' => 13, 'movedIn' => '10.40'];
        yield 'UTF setting in the body of an assertion' => ['pattern' => '/(*pla:(*UTF))/', 'bundled' => 11, 'newer' => 11, 'movedIn' => '10.40'];
        yield 'limit in the body of an assertion' => ['pattern' => '/(*pla:(*LIMIT_MATCH=5))/', 'bundled' => 19, 'newer' => 19, 'movedIn' => '10.40'];
        yield 'setting in the body of a script run' => ['pattern' => '/(*sr:(*CR)a)/', 'bundled' => 9, 'newer' => 9, 'movedIn' => '10.40'];
        yield 'alphabetic name at the end' => ['pattern' => '/(*pla/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'known alphabetic assertion never closed' => ['pattern' => '/(*pla:a/', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];
        yield 'unknown alphabetic name with a colon' => ['pattern' => '/(*plaa:/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
        yield 'escape where an option letter is due' => ['pattern' => '/(?i\\y/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'property escape right after (?' => ['pattern' => '/(?\\p{L/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'hexadecimal escape after (?-' => ['pattern' => '/a(?-\\x{zz}/', 'bundled' => 4, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'alphabetic name closed with no colon' => ['pattern' => '/(*pla)/', 'bundled' => 5, 'newer' => 6, 'movedIn' => '10.47'];
        yield 'known alphabetic name closed with no colon' => ['pattern' => '/(*atomic)/', 'bundled' => 8, 'newer' => 9, 'movedIn' => '10.47'];
        yield 'alphabetic name holding a digit' => ['pattern' => '/(*a9b)/', 'bundled' => 5, 'newer' => 6, 'movedIn' => '10.47'];
        yield 'alphabetic name ending in a digit' => ['pattern' => '/(*pla9)/', 'bundled' => 6, 'newer' => 7, 'movedIn' => '10.47'];
        yield 'alphabetic name ending in a digit at the end' => ['pattern' => '/(*pla9/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
        yield 'unknown verb name' => ['pattern' => '/(*FOO)/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'verb name of a digit at the end' => ['pattern' => '/(*9/', 'bundled' => 3, 'newer' => 3, 'movedIn' => '10.40'];
        yield 'verb name of a digit before a colon' => ['pattern' => '/(*9:/', 'bundled' => 3, 'newer' => 3, 'movedIn' => '10.40'];
        yield 'verb name ending in a digit' => ['pattern' => '/(*MARK9./', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];
        // After a callout, a condition needs an assertion: PCRE refuses what
        // comes instead where it starts, before 10.47 on the last byte of a
        // character read as is.
        yield 'character after a callout in a condition' => ['pattern' => '/(?(?C1)X)/', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];
        yield 'quoted text after a callout in a condition' => ['pattern' => '/(?(?C1)\\QXY\\E)/', 'bundled' => 9, 'newer' => 7, 'movedIn' => '10.47'];
        yield 'text after a comment after a callout in a condition' => ['pattern' => '/(?(?C1)(?#c)X)/', 'bundled' => 12, 'newer' => 12, 'movedIn' => '10.40'];
        yield 'text after an empty quote after a callout in a condition' => ['pattern' => '/(?(?C1)\\Q\\EX)/', 'bundled' => 11, 'newer' => 11, 'movedIn' => '10.40'];
        yield 'escape after a callout in a condition' => ['pattern' => '/(?(?C1)\\x41)/', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];
        yield 'two-byte character after a callout in a condition' => ['pattern' => '/(?(?C1)é)/u', 'bundled' => 8, 'newer' => 7, 'movedIn' => '10.47'];
        yield 'unmatched closing parenthesis' => ['pattern' => '/a)/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];

        yield 'group name starting with a Unicode digit' => ['pattern' => '/(?<٣a>x)/u', 'bundled' => 3, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'second hyphen in an option setting' => ['pattern' => '/(?i-x-)/', 'bundled' => 5, 'newer' => 6, 'movedIn' => '10.47'];
        yield 'reference name after g starting with a digit' => ['pattern' => '/\\g{9a}/', 'bundled' => 2, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'relative reference back past the start' => ['pattern' => '/\\g{-2}/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        yield 'version with no number' => ['pattern' => '/(?(VERSION=x)yes|no)/', 'bundled' => 11, 'newer' => 12, 'movedIn' => '10.47'];
        yield 'version with no minor after its dot' => ['pattern' => '/(?(VERSION=10.x)yes|no)/', 'bundled' => 14, 'newer' => 15, 'movedIn' => '10.47'];
        yield 'version compared with less than' => ['pattern' => '/(?(VERSION<10)yes|no)/', 'bundled' => 10, 'newer' => 11, 'movedIn' => '10.47'];
        yield 'version with a third number' => ['pattern' => '/(?(VERSION>=10.0.0)yes|no)/', 'bundled' => 16, 'newer' => 17, 'movedIn' => '10.47'];
        yield 'version followed by a letter' => ['pattern' => '/(?(VERSION=10z)yes|no)/', 'bundled' => 13, 'newer' => 14, 'movedIn' => '10.47'];
        yield 'version number past 1000' => ['pattern' => '/(?(VERSION=1001.1)yes|no)/', 'bundled' => 15, 'newer' => 15, 'movedIn' => '10.40'];

        // PCRE2 10.45 reads a group number past 65535 whole before it
        // refuses it; before, it stops past the digit that takes it over.
        yield 'reference number too big' => ['pattern' => '/\\g66666666/', 'bundled' => 7, 'newer' => 10, 'movedIn' => '10.45'];
        yield 'relative reference number too big' => ['pattern' => '/\\g+66666666/', 'bundled' => 8, 'newer' => 11, 'movedIn' => '10.45'];
        yield 'backward reference number too big' => ['pattern' => '/a\\g-66666666/', 'bundled' => 9, 'newer' => 12, 'movedIn' => '10.45'];
        yield 'call number too big' => ['pattern' => '/(?66666666)/', 'bundled' => 7, 'newer' => 10, 'movedIn' => '10.45'];
        yield 'relative call number too big' => ['pattern' => '/(?-66666666)/', 'bundled' => 8, 'newer' => 11, 'movedIn' => '10.45'];
        yield 'forward call number too big' => ['pattern' => '/(?+66666666)/', 'bundled' => 8, 'newer' => 11, 'movedIn' => '10.45'];
        yield 'condition number too big' => ['pattern' => '/(?(66666666)a)/', 'bundled' => 8, 'newer' => 11, 'movedIn' => '10.45'];
        yield 'condition number too big never closed' => ['pattern' => '/(?(8000000000/', 'bundled' => 8, 'newer' => 13, 'movedIn' => '10.45'];
        // Every release: a reference or a call to a group that does not exist.
        yield 'call by P to a missing name' => ['pattern' => '/(?P>nope)/', 'bundled' => 4, 'newer' => 4, 'movedIn' => '10.40'];
        yield 'call to a missing name' => ['pattern' => '/(?&nope)/', 'bundled' => 3, 'newer' => 3, 'movedIn' => '10.40'];
        yield 'call to a missing number' => ['pattern' => '/(?2)/', 'bundled' => 3, 'newer' => 3, 'movedIn' => '10.40'];
        yield 'condition on a missing name' => ['pattern' => '/(?(<nope>)a)/', 'bundled' => 4, 'newer' => 4, 'movedIn' => '10.40'];
        yield 'condition on recursion into a missing name' => ['pattern' => '/(?(R&nope)a)/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'condition on a group before the first' => ['pattern' => '/(?(-1)a)/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'condition on a missing number' => ['pattern' => '/(?(2)a)/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        // Every release: a braced number past 65535 is refused on its brace.
        yield 'braced reference number too big' => ['pattern' => '/\\g{66666666}/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        yield 'angled reference number too big' => ['pattern' => '/\\g<66666666>/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        yield 'backslash number too big' => ['pattern' => '/\\800000/', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];

        yield 'limit value too large' => ['pattern' => '/(*LIMIT_MATCH=4294967290)abc/', 'bundled' => 24, 'newer' => 23, 'movedIn' => '10.45'];
        yield 'limit value far too large' => ['pattern' => '/(*LIMIT_MATCH=99999999999)abc/', 'bundled' => 24, 'newer' => 23, 'movedIn' => '10.45'];
        yield 'verb as a condition' => ['pattern' => '/(?(*ACCEPT)xxx)/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'mark as a condition' => ['pattern' => '/(?(*MARK:a)b)/', 'bundled' => 2, 'newer' => 3, 'movedIn' => '10.47'];
        yield 'collating element outside a class' => ['pattern' => '/[.x.]/', 'bundled' => 0, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'equivalence class outside a class' => ['pattern' => '/[=x=]/', 'bundled' => 0, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'POSIX class outside a class' => ['pattern' => '/[:x:]/', 'bundled' => 0, 'newer' => 5, 'movedIn' => '10.47'];

        // PCRE2 10.45 reports an unknown POSIX class past its end.
        yield 'unknown POSIX class' => ['pattern' => '/[[:foo:]]/', 'bundled' => 3, 'newer' => 8, 'movedIn' => '10.45'];
        yield 'collating element' => ['pattern' => '/x[[=a=]]/', 'bundled' => 2, 'newer' => 7, 'movedIn' => '10.45'];
        yield 'POSIX class ending a range' => ['pattern' => '/[a-[:digit:]]/', 'bundled' => 4, 'newer' => 12, 'movedIn' => '10.45'];
        yield 'POSIX class starting a range' => ['pattern' => '/[[:digit:]-z]/', 'bundled' => 10, 'newer' => 11, 'movedIn' => '10.45'];
        yield 'character type starting a range' => ['pattern' => '/[\\d-a]/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.45'];
        yield 'character types on both ends' => ['pattern' => '/[\\d-\\w]/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.45'];
        yield 'property letter starting a range' => ['pattern' => '/[\\pL-z]/', 'bundled' => 4, 'newer' => 5, 'movedIn' => '10.45'];
        yield 'braced property starting a range' => ['pattern' => '/[\\p{Lu}-z]/', 'bundled' => 7, 'newer' => 8, 'movedIn' => '10.45'];
        yield 'property letter ending a range' => ['pattern' => '/[z-\\pL]/', 'bundled' => 5, 'newer' => 6, 'movedIn' => '10.45'];
        yield 'braced property ending a range' => ['pattern' => '/[z-\\p{Lu}]/', 'bundled' => 5, 'newer' => 9, 'movedIn' => '10.45'];
        yield 'character type ending a range' => ['pattern' => '/[a-\\d]/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'unknown negated POSIX class' => ['pattern' => '/a[[:^foo:]]b/', 'bundled' => 5, 'newer' => 10, 'movedIn' => '10.45'];

        // PCRE2 10.45 refuses a property name past its first character that
        // no name can hold, rather than at its end.
        yield 'property name never closed' => ['pattern' => '/\\p{L{./', 'bundled' => 6, 'newer' => 5, 'movedIn' => '10.45'];
        yield 'property name with a sign' => ['pattern' => '/\\p{L!}/', 'bundled' => 6, 'newer' => 5, 'movedIn' => '10.45'];
        yield 'property name with a brace in a class' => ['pattern' => '/[\\p{L{}]/', 'bundled' => 7, 'newer' => 6, 'movedIn' => '10.45'];
        yield 'negated property name with a tilde' => ['pattern' => '/\\P{^ L~x}/', 'bundled' => 9, 'newer' => 7, 'movedIn' => '10.45'];
        yield 'property name never closed in a class' => ['pattern' => '/[\\P{L!]/', 'bundled' => 7, 'newer' => 6, 'movedIn' => '10.45'];
        yield 'property name past its last character' => ['pattern' => '/\\p{ ^ L|/', 'bundled' => 8, 'newer' => 8, 'movedIn' => '10.40'];
        // Every release: a property name is read for 49 characters at most.
        yield 'property name too long' => ['pattern' => '/\\p{aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}/', 'bundled' => 52, 'newer' => 52, 'movedIn' => '10.40'];
        yield 'property name too long and never closed' => ['pattern' => '/\\p{aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/', 'bundled' => 52, 'newer' => 52, 'movedIn' => '10.40'];

        // PCRE refuses what a class holds as it reads the class, before any
        // error in what follows it.
        yield 'reversed range before an unclosed comment' => ['pattern' => '/[z-a](?#/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'reversed range before a class range on a type' => ['pattern' => '/[z-a][a-\\d]/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'reversed range before a quantifier with nothing to repeat' => ['pattern' => '/a[b-a]+++/', 'bundled' => 4, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'reversed range before counts out of order' => ['pattern' => '/[z-a]{2,1}/', 'bundled' => 3, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'unknown escape before counts out of order' => ['pattern' => '/\\y{2,1}/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];
        yield 'unknown property before a count too big' => ['pattern' => '/\\pX{65536}/', 'bundled' => 3, 'newer' => 3, 'movedIn' => '10.40'];
        yield 'reversed range in a group before counts out of order' => ['pattern' => '/([z-a]){2,1}/', 'bundled' => 4, 'newer' => 5, 'movedIn' => '10.47'];
        // What PCRE checks once the pattern is read comes after the counts.
        yield 'counts out of order after an unbounded lookbehind' => ['pattern' => '/(?<=a+){2,1}/', 'bundled' => 11, 'newer' => 11, 'movedIn' => '10.40'];
        yield 'counts out of order after a reference to a missing group' => ['pattern' => '/\\9{2,1}/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
        yield 'counts out of order after a call to a missing group' => ['pattern' => '/(?1){2,1}/', 'bundled' => 8, 'newer' => 8, 'movedIn' => '10.40'];
        yield 'valid class before an unclosed comment' => ['pattern' => '/[a-z](?#/', 'bundled' => 8, 'newer' => 8, 'movedIn' => '10.40'];
        yield 'unknown POSIX class before an unreadable condition' => ['pattern' => '/[[:foo:]](?(?(/', 'bundled' => 3, 'newer' => 8, 'movedIn' => '10.45'];
        yield 'unknown POSIX class before an unclosed comment' => ['pattern' => '/x[[:foo:]]}(?#/', 'bundled' => 4, 'newer' => 9, 'movedIn' => '10.45'];
        yield 'unknown POSIX class before a count too big' => ['pattern' => '/[[:foo:]]{65536}/', 'bundled' => 3, 'newer' => 8, 'movedIn' => '10.45'];

        // Every release: a count with nothing to repeat is read as a count.
        yield 'counts out of order at the start' => ['pattern' => '/{2,1}/', 'bundled' => 4, 'newer' => 4, 'movedIn' => '10.40'];
        yield 'count too big after an anchor' => ['pattern' => '/^{65536}/', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];
        yield 'count past 65535 after an anchor' => ['pattern' => '/^{655360}/', 'bundled' => 7, 'newer' => 8, 'movedIn' => '10.47'];
        yield 'counts out of order after an anchor' => ['pattern' => '/^{2,1}/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'counts out of order made possessive after a boundary' => ['pattern' => '/\\b{2,1}+/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
        yield 'count too big after a possessive quantifier' => ['pattern' => '/a++{65536}/', 'bundled' => 9, 'newer' => 9, 'movedIn' => '10.40'];
        yield 'count too big after a callout' => ['pattern' => '/(?C){70000}/', 'bundled' => 10, 'newer' => 10, 'movedIn' => '10.40'];
        yield 'count too big at the start' => ['pattern' => '/{65536}/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
    }

    #[Test]
    public function test_an_alphabetic_name_the_bundled_pcre2_does_not_know_is_refused_before_its_quantifier(): void
    {
        // "(*scs:" arrived in PCRE2 10.45: 10.40 to 10.44 refuse the name
        // where it ends, before they read what repeats it (pcre2test on each).
        foreach ([80200, 80500] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);

            $this->assertSame(8, $regex->validate('/(a)(*scs:(1)b)*c/')->offset);
            $this->assertSame(5, $regex->validate('/(*scs:(1)a)??(a)/')->offset);
            $this->assertSame(8, $regex->validate('/(a)(*scs:(1)b){3,}+c/')->offset);
        }
    }

    #[Test]
    public function test_a_class_read_past_the_fault_does_not_come_first(): void
    {
        // Before PCRE2 10.45 "(?[" is refused on the "[": the class it opens
        // is never read, and its reversed range is never met.
        foreach ([80200, 80500] as $phpVersion) {
            $this->assertSame(2, Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate('/(?[z-a])/')->offset);
        }
    }

    #[Test]
    public function test_a_property_name_with_a_character_no_name_holds_is_malformed_from_pcre2_10_45(): void
    {
        foreach ([80200, 80500] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);

            $this->assertSame('regex.unicode.property_invalid', $regex->validate('/\\p{L!}/')->errorCode);
            $this->assertSame(5, $regex->validate('/\\p{é/u')->offset);
        }

        // 10.45 and 10.46 stopped past the first byte of a UTF-8 character,
        // 10.47 past the whole character.
        $running = explode(' ', \PCRE_VERSION)[0];
        $regex = Regex::create(['cache' => null]);
        $this->assertSame(version_compare($running, '10.45', '>=') ? 'regex.unicode.property_malformed' : 'regex.unicode.property_invalid', $regex->validate('/\\p{L!}/')->errorCode);
        $this->assertSame(version_compare($running, '10.45', '>=') && version_compare($running, '10.47', '<') ? 4 : 5, $regex->validate('/\\p{é/u')->offset);
    }

    #[Test]
    public function test_padding_after_u_plus_is_refused_where_the_release_stops(): void
    {
        // Padding after "U+" arrived in PCRE2 10.43 (PHP 8.4); what follows it
        // that is no digit is refused on it by 10.44, past it by 10.47.
        foreach ([80400, 80500] as $phpVersion) {
            $this->assertSame(6, Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate('/\\N{U+ z}/u')->offset);
        }

        $running = explode(' ', \PCRE_VERSION)[0];
        if (version_compare($running, '10.43', '>=')) {
            $this->assertSame(version_compare($running, '10.47', '>=') ? 7 : 6, Regex::create(['cache' => null])->validate('/\\N{U+ z}/u')->offset);
        }
    }
}
