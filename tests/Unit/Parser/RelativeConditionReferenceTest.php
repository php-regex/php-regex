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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?(-n)" counts back over the groups already opened: PCRE2 refuses a
 * count past them as it reads it, before any later error (pcre2test 10.49,
 * error 115 "reference to non-existent subpattern").
 */
final class RelativeConditionReferenceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBackwardReferencesPastTheGroups')]
    public function test_a_backward_count_past_the_open_groups_is_refused_first(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
        $this->assertStringContainsString('relative reference', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideBackwardReferencesPastTheGroups(): iterable
    {
        yield 'no group before, group left open after' => ['pattern' => '/(?(-1)(/', 'offset' => 5];
        yield 'one group before, two counted' => ['pattern' => '/(a)(?(-2)(/', 'offset' => 8];
    }

    /**
     * A verb or an unknown alphabetic name right after "(?(" is no
     * condition: PCRE2 expects a group name there (error 162 at 3), whether
     * the verb is closed or runs to the end.
     */
    #[Test]
    #[DataProvider('provideVerbsOpeningACondition')]
    public function test_a_verb_opening_a_condition_is_refused_where_the_name_is_expected(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame(3, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVerbsOpeningACondition(): iterable
    {
        yield 'unknown name left open' => ['pattern' => '/(?((*foo:/'];
        yield 'unknown name closed' => ['pattern' => '/(?((*foo:a)b)/'];
    }

    /**
     * A callout number past 255 in a condition is refused as PCRE2 reads
     * it, before the assertion due after the callout (error 138 at 8).
     */
    #[Test]
    #[DataProvider('provideCalloutsPast255')]
    public function test_a_callout_number_past_255_in_a_condition_is_refused_first(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($offset, $result->offset);
        $this->assertStringContainsString('255', (string) $result->error);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideCalloutsPast255(): iterable
    {
        yield 'callout before the assertion' => ['pattern' => '/(?(?C256)a)/', 'offset' => 8];
    }

    /**
     * A name already used, with no ">" after it, is refused on the missing
     * terminator, as PCRE2 reads the name and what closes it first (error
     * 142 "syntax error in subpattern name (missing terminator?)").
     */
    #[Test]
    #[DataProvider('provideDuplicateNamesLeftOpen')]
    public function test_a_duplicate_name_left_open_is_refused_on_the_terminator(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideDuplicateNamesLeftOpen(): iterable
    {
        yield 'end of the pattern' => ['pattern' => '/(?<n>a)(?<n/', 'offset' => 11];
        yield 'group closed instead' => ['pattern' => '/(?<n>a)(?<n)/', 'offset' => 11];
        yield 'python spelling' => ['pattern' => '/(?<n>a)(?P<n/', 'offset' => 12];
    }

    #[Test]
    public function test_a_backward_count_within_the_open_groups_is_accepted(): void
    {
        foreach (['/(a)(?(-1)a|b)/', '/((?(-1)a)b)/'] as $pattern) {
            $this->assertNotFalse(preg_match($pattern, ''), $pattern);
            $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
        }
    }
}
