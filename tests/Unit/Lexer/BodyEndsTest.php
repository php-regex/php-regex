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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Parser\Lexing\BodyEnds;
use PHPRegex\Parser\Lexing\PatternText;
use PHPRegex\Parser\Lexing\TargetSyntax;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the body of an alphabetic assertion ends, read ahead of its tokens
 * as PCRE reads any group body, and where a Perl extended class ends.
 */
final class BodyEndsTest extends TestCase
{
    /**
     * The ")" found is the one PCRE closes the body with: in each pattern
     * the ")" after it is the first one PCRE finds unmatched, or there is
     * none and the pattern compiles. PCRE2 reports that ")" past it from
     * 10.47, on it before.
     */
    #[Test]
    #[DataProvider('provideClosedBodies')]
    public function test_a_body_ends_past_the_parenthesis_pcre_closes_it_with(string $pattern, string $opener, bool $extended, int $end, ?int $unmatched): void
    {
        $compiled = @preg_match('/'.($extended ? '(?x)' : '').$pattern.'/', '');
        if (null === $unmatched) {
            $this->assertSame(0, $compiled);
        } else {
            $this->assertFalse($compiled);
            $this->assertStringContainsString('unmatched closing parenthesis at offset '.PcreTarget::runtime()->faultOffset($unmatched + 1 + ($extended ? 4 : 0)), error_get_last()['message'] ?? '');
        }

        $this->assertSame($end, (new BodyEnds())->assertionEnd(self::text($pattern), 0, $opener, $extended, false));
    }

    /**
     * @return iterable<string, array{pattern: string, opener: string, extended: bool, end: int, unmatched: int|null}>
     */
    public static function provideClosedBodies(): iterable
    {
        yield 'nested groups' => ['pattern' => '(*pla:a(b)c)d', 'opener' => '(*pla:', 'extended' => false, 'end' => 12, 'unmatched' => null];
        yield 'a parenthesis in a class' => ['pattern' => '(*pla:[)])x)', 'opener' => '(*pla:', 'extended' => false, 'end' => 10, 'unmatched' => 11];
        yield 'a quoted parenthesis' => ['pattern' => '(*pla:\Q)\E)', 'opener' => '(*pla:', 'extended' => false, 'end' => 12, 'unmatched' => null];
        yield 'a comment' => ['pattern' => '(?*a(?#))b)', 'opener' => '(?*', 'extended' => false, 'end' => 9, 'unmatched' => 10];
        yield 'a hash without x' => ['pattern' => "(*pla:a#)\n)", 'opener' => '(*pla:', 'extended' => false, 'end' => 9, 'unmatched' => 10];
        yield 'a line comment under x' => ['pattern' => "(*pla:a#)\n)", 'opener' => '(*pla:', 'extended' => true, 'end' => 11, 'unmatched' => null];
    }

    #[Test]
    #[DataProvider('provideUnclosedBodies')]
    public function test_a_body_that_never_closes_has_no_end(string $pattern, string $pcreMessage): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/', ''));
        $this->assertStringContainsString($pcreMessage, error_get_last()['message'] ?? '');

        $this->assertNull((new BodyEnds())->assertionEnd(self::text($pattern), 0, '(*pla:', false, false));
    }

    /**
     * One reader reads each of them, an end found for one pattern kept for
     * no other.
     */
    #[Test]
    public function test_one_reader_finds_no_end_in_each_unclosed_body(): void
    {
        $bodies = new BodyEnds();
        $this->assertNull($bodies->assertionEnd(self::text('(*pla:a(b'), 0, '(*pla:', false, false));
        $this->assertNull($bodies->assertionEnd(self::text('(*pla:a(b)'), 0, '(*pla:', false, false));
        $bodies->forget();
        $this->assertNull($bodies->assertionEnd(self::text('(*pla:[a)'), 0, '(*pla:', false, false));
        $this->assertSame(10, $bodies->assertionEnd(self::text('(*pla:[a])'), 0, '(*pla:', false, false));
    }

    /**
     * A backslash that ends the text read starts no item, in the body or in
     * one of its classes: the body never closes there. PHP cannot hand PCRE
     * such a pattern, the backslash escaping the delimiter, but a part of a
     * pattern the parser tokenizes may end so.
     */
    #[Test]
    public function test_a_backslash_ending_the_text_leaves_the_body_unclosed(): void
    {
        $this->assertFalse(@preg_match(self::delimited('(*pla:(?#)\\'), ''));
        $this->assertStringContainsString('No ending delimiter', error_get_last()['message'] ?? '');

        $this->assertNull((new BodyEnds())->assertionEnd(self::text('(*pla:(?#)\\'), 0, '(*pla:', false, false));
        $this->assertNull((new BodyEnds())->assertionEnd(self::text('(*pla:[)\\'), 0, '(*pla:', false, false));
    }

    /**
     * @return iterable<string, array{pattern: string, pcreMessage: string}>
     */
    public static function provideUnclosedBodies(): iterable
    {
        yield 'a group left open' => ['pattern' => '(*pla:a(b', 'pcreMessage' => 'missing closing parenthesis'];
        yield 'the body left open' => ['pattern' => '(*pla:a(b)', 'pcreMessage' => 'missing closing parenthesis'];
        yield 'a class left open' => ['pattern' => '(*pla:[a)', 'pcreMessage' => 'missing terminating ] for character class'];
    }

    /**
     * An end found is kept, by the offset of the body in the whole pattern:
     * a part of the pattern that still holds the ")" finds it again, and a
     * part that ends before it does not, until the ends are forgotten.
     */
    #[Test]
    public function test_the_ends_found_are_kept_for_the_parts_of_the_same_pattern(): void
    {
        $whole = 'x(*pla:a(*pla:b))';
        $syntax = new TargetSyntax(PcreTarget::runtime());
        $bodies = new BodyEnds();

        $this->assertSame(17, $bodies->assertionEnd(new PatternText($syntax, $whole, '', $whole, 0), 1, '(*pla:', false, false));

        // The inner body, at 8 in the whole pattern, read in the part from 7.
        $part = new PatternText($syntax, substr($whole, 7), '', $whole, 7);
        $this->assertSame(9, $bodies->assertionEnd($part, 1, '(*pla:', false, false));

        $bodies->forget();
        $this->assertSame(9, $bodies->assertionEnd($part, 1, '(*pla:', false, false));
    }

    #[Test]
    public function test_an_extended_class_ends_past_its_bracket_and_parenthesis(): void
    {
        $this->assertSame(8, BodyEnds::extendedClassEnd(self::text('(?[a+b])c'), 0));
        $this->assertSame(9, BodyEnds::extendedClassEnd(self::text('(?[[]a]])'), 0));
        $this->assertSame(6, BodyEnds::extendedClassEnd(self::text('(?[a+b'), 0));
        $this->assertSame(6, BodyEnds::extendedClassEnd(self::text('(?[(a))'), 0));
    }

    private static function delimited(string $pattern): string
    {
        return '/'.$pattern.'/';
    }

    private static function text(string $pattern): PatternText
    {
        return new PatternText(new TargetSyntax(PcreTarget::runtime()), $pattern, '', $pattern, 0);
    }
}
