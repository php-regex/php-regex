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

    /**
     * "\g{-n" with no "}": PCRE2 counts back over the open groups first and
     * refuses a count past them on the "{" (error 115); within them, it
     * wants the "}" (error 219).
     */
    #[Test]
    #[DataProvider('provideUnclosedBackwardReferences')]
    public function test_an_unclosed_backward_reference_is_refused_as_pcre_reads_it(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedBackwardReferences(): iterable
    {
        yield 'no group open' => ['pattern' => '/\g{-1/', 'offset' => 2];
        yield 'past the one group open' => ['pattern' => '/(a)\g{-2/', 'offset' => 5];
        yield 'within the groups, the brace is due' => ['pattern' => '/(a)\g{-1/', 'offset' => 8];
    }

    /**
     * PCRE2 skips the spaces after a braced name, then wants the "}": it
     * stops on the first character that is neither (error 142 at 5).
     */
    #[Test]
    #[DataProvider('provideBracedNamesNotClosed')]
    public function test_a_braced_name_not_closed_is_refused_past_its_padding(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), $pattern);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideBracedNamesNotClosed(): iterable
    {
        yield 'space then a letter' => ['pattern' => '/\k{a b}/', 'offset' => 5];
        yield 'space before the name, never closed' => ['pattern' => '/\k{ a/', 'offset' => 5];
        // A "(" after a called name: PCRE2 stops past it (error 217).
        yield 'call followed by a group' => ['pattern' => '/(?&a(?:z)/', 'offset' => 5];
        yield 'python call followed by a group' => ['pattern' => '/(?P>a(?:z)/', 'offset' => 6];
    }

    #[Test]
    public function test_the_message_quotes_the_reference_as_written(): void
    {
        $result = Regex::create(['cache' => null])->validate("/\\g{\u{663}a}/u");

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString("\\g{\u{663}a}", (string) $result->error);
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
