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
        yield 'alphabetic name at the end' => ['pattern' => '/(*pla/', 'bundled' => 5, 'newer' => 5, 'movedIn' => '10.40'];
        yield 'known alphabetic assertion never closed' => ['pattern' => '/(*pla:a/', 'bundled' => 7, 'newer' => 7, 'movedIn' => '10.40'];
        yield 'unknown alphabetic name with a colon' => ['pattern' => '/(*plaa:/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
        yield 'unmatched closing parenthesis' => ['pattern' => '/a)/', 'bundled' => 1, 'newer' => 2, 'movedIn' => '10.47'];

        yield 'group name starting with a Unicode digit' => ['pattern' => '/(?<٣a>x)/u', 'bundled' => 3, 'newer' => 5, 'movedIn' => '10.47'];
        yield 'second hyphen in an option setting' => ['pattern' => '/(?i-x-)/', 'bundled' => 5, 'newer' => 6, 'movedIn' => '10.47'];
        yield 'reference name after g starting with a digit' => ['pattern' => '/\\g{9a}/', 'bundled' => 2, 'newer' => 4, 'movedIn' => '10.47'];
        yield 'relative reference back past the start' => ['pattern' => '/\\g{-2}/', 'bundled' => 2, 'newer' => 2, 'movedIn' => '10.40'];
        yield 'version number past 1000' => ['pattern' => '/(?(VERSION=1001.1)yes|no)/', 'bundled' => 15, 'newer' => 15, 'movedIn' => '10.40'];

        // PCRE2 10.45 reports an unknown POSIX class past its end.
        yield 'unknown POSIX class' => ['pattern' => '/[[:foo:]]/', 'bundled' => 3, 'newer' => 8, 'movedIn' => '10.45'];
        yield 'collating element' => ['pattern' => '/x[[=a=]]/', 'bundled' => 2, 'newer' => 7, 'movedIn' => '10.45'];
        yield 'POSIX class ending a range' => ['pattern' => '/[a-[:digit:]]/', 'bundled' => 4, 'newer' => 12, 'movedIn' => '10.45'];
        yield 'POSIX class starting a range' => ['pattern' => '/[[:digit:]-z]/', 'bundled' => 10, 'newer' => 11, 'movedIn' => '10.45'];
        yield 'unknown negated POSIX class' => ['pattern' => '/a[[:^foo:]]b/', 'bundled' => 5, 'newer' => 10, 'movedIn' => '10.45'];

        // Every release: a count with nothing to repeat is read as a count.
        yield 'counts out of order at the start' => ['pattern' => '/{2,1}/', 'bundled' => 4, 'newer' => 4, 'movedIn' => '10.40'];
        yield 'count too big at the start' => ['pattern' => '/{65536}/', 'bundled' => 6, 'newer' => 6, 'movedIn' => '10.40'];
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
