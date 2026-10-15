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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * While "(?xx)" is in force, PCRE2 skips an unescaped space or tab anywhere
 * in a class, not only before its first member (PHP 8.4, PCRE2 10.49):
 * "/(?xx)[a b]/" does not match " ", "/(?xx)[a - c]/" matches "b" and not
 * "-", "/(?xx)[ ^a]/" does not match "a". A line feed, an escaped space and
 * a quoted one stay members. The PHP modifier "xx" is "x": only the inline
 * option reaches a class.
 */
final class ExtendedMoreClassSpaceTest extends TestCase
{
    use PcreGates;

    /**
     * @param array<int|string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideSkippedSpaces')]
    public function test_a_class_under_extended_more_reads_as_if_its_spaces_were_not_written(string $pattern, string $without, array $subjects): void
    {
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on '.json_encode((string) $subject));
            $this->assertSame($matches, preg_match($without, (string) $subject), 'The oracle on '.json_encode((string) $subject));
        }

        $regex = Regex::create(['cache' => null]);

        $this->assertSame(PatternEquivalence::structure($regex->parse($without)), PatternEquivalence::structure($regex->parse($pattern)));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, subjects: array<int|string, int>}>
     */
    public static function provideSkippedSpaces(): iterable
    {
        yield 'a space between two members' => ['pattern' => '/(?xx)[a b]/', 'without' => '/(?xx)[ab]/', 'subjects' => [' ' => 0, 'b' => 1]];
        yield 'a tab between two members' => ['pattern' => "/(?xx)[a\tb]/", 'without' => '/(?xx)[ab]/', 'subjects' => ["\t" => 0, 'b' => 1]];
        yield 'spaces around the hyphen of a range' => ['pattern' => '/(?xx)[a - c]/', 'without' => '/(?xx)[a-c]/', 'subjects' => ['b' => 1, '-' => 0, ' ' => 0]];
        yield 'a space before the negation' => ['pattern' => '/(?xx)[ ^a]/', 'without' => '/(?xx)[^a]/', 'subjects' => ['a' => 0, 'b' => 1]];
        yield 'a space after the negation' => ['pattern' => '/(?xx)[^ a]/', 'without' => '/(?xx)[^a]/', 'subjects' => ['a' => 0, ' ' => 1]];
        yield 'a space after a POSIX class' => ['pattern' => '/(?xx)[[:alpha:] 1]/', 'without' => '/(?xx)[[:alpha:]1]/', 'subjects' => [' ' => 0, '1' => 1]];
        yield 'a space before a closing bracket' => ['pattern' => '/(?xx)[a ]]/', 'without' => '/(?xx)[a]]/', 'subjects' => ['a]' => 1, ']]' => 0]];
        yield 'in a group that turns xx on' => ['pattern' => '/(?xx:[a b])c/', 'without' => '/(?xx:[ab])c/', 'subjects' => ['ac' => 1, 'bc' => 1, 'cc' => 0]];
    }

    /**
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideKeptSpaces')]
    public function test_a_space_extended_more_does_not_skip_stays_a_member(string $pattern, array $subjects): void
    {
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on '.json_encode((string) $subject));
        }

        $explained = Regex::create(['cache' => null])->explain($pattern);

        $this->assertStringContainsString(' ' === array_key_first($subjects) ? "' ' (space)" : "'\\n'", $explained);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>}>
     */
    public static function provideKeptSpaces(): iterable
    {
        yield 'an escaped space' => ['pattern' => '/(?xx)[a\ b]/', 'subjects' => [' ' => 1]];
        yield 'a quoted space' => ['pattern' => '/(?xx)[\Q \E]/', 'subjects' => [' ' => 1]];
        yield 'under a single x' => ['pattern' => '/(?xx)(?x:[a b])/', 'subjects' => [' ' => 1]];
        yield 'with x turned off' => ['pattern' => '/(?xx)(?-x:[a b])/', 'subjects' => [' ' => 1]];
        yield 'under the modifier xx, which is x' => ['pattern' => '/[a b]/xx', 'subjects' => [' ' => 1]];
    }

    /**
     * "[\d -z]" is "[\d-z]" there: a range that starts at a class escape,
     * which PCRE2 refuses. PCRE2 10.44 still compiled it; 10.48 refuses it.
     */
    #[Test]
    #[DataProvider('provideRangesTheSpacesHid')]
    public function test_a_range_its_spaces_hid_is_refused_as_pcre_refuses_it(string $pattern): void
    {
        $this->requiresPcre2('10.48', $pattern);

        $this->assertFalse(@preg_match($pattern, ''), 'The oracle: PCRE2 refuses the pattern.');

        $this->assertFalse(Regex::create(['cache' => null])->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRangesTheSpacesHid(): iterable
    {
        yield 'a range from a class escape' => ['pattern' => '/(?xx)[\d -z]/'];
    }
}
