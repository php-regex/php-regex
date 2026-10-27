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

use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Lexing\ClassRanges;
use PHPRegex\Parser\Lexing\PatternText;
use PHPRegex\Parser\Lexing\TargetSyntax;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ranges of a class as the lexer reads them before the class closes:
 * where the first reversed one ends, and whether a class escape would end
 * one.
 */
final class ClassRangesTest extends TestCase
{
    /**
     * The offset found is the one the running PCRE2 reports, once the rule
     * of its release for the offset of a fault is applied.
     */
    #[Test]
    #[DataProvider('provideReversedRanges')]
    public function test_the_first_reversed_range_is_refused_where_pcre_reports_it(string $pattern): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/', ''));
        $this->assertSame(1, preg_match('/at offset (\d++)/', error_get_last()['message'] ?? '', $reported));

        $end = ClassRanges::reversedRangeEnd(self::text($pattern), self::tokens($pattern), 0);

        $this->assertNotNull($end);
        $this->assertSame((int) $reported[1], PcreTarget::runtime()->faultOffset($end));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReversedRanges(): iterable
    {
        yield 'two letters' => ['pattern' => '[z-a]'];
        yield 'a hexadecimal escape' => ['pattern' => '[\x7A-a]'];
        yield 'a quoted bound' => ['pattern' => '[\Qz\E-a]'];
        yield 'the second range of a chain' => ['pattern' => '[b-a-z]'];
        yield 'a multibyte start outside UTF mode' => ['pattern' => "[\xC3\xA9-a]"];
    }

    #[Test]
    public function test_a_class_without_a_reversed_range_has_none(): void
    {
        $this->assertSame(0, preg_match('/[a-z\d-]/', ''));

        $this->assertNull(ClassRanges::reversedRangeEnd(self::text('[a-z\d-]'), self::tokens('[a-z\d-]'), 0));
        $this->assertNull(ClassRanges::reversedRangeEnd(self::text('a[z-a]'), self::tokens('a[z-a]'), 0));
    }

    /**
     * PCRE2 10.32, the release PHP 7.3 bundles, reads a class escape that
     * ends a range as its letter under PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL:
     * "[a-\d]" there. The releases after refuse it whatever the options.
     */
    #[Test]
    public function test_a_class_escape_ends_a_range_only_after_a_character_and_a_dash(): void
    {
        $old = new TargetSyntax(PcreTarget::bundledWith(70300));
        $text = new PatternText($old, '[a-\d', '', '[a-\d', 0);
        $tokens = \array_slice(self::tokens('[a-\d]'), 0, 3);

        $this->assertTrue(ClassRanges::startsRangeEnd($text, $tokens, 3, 0));
        $this->assertFalse(ClassRanges::startsRangeEnd($text, $tokens, 3, null));
        $this->assertFalse(ClassRanges::startsRangeEnd($text, \array_slice($tokens, 0, 2), 2, 0));
        $this->assertFalse(ClassRanges::startsRangeEnd(self::text('[a-\d'), $tokens, 3, 0));

        $plain = new PatternText($old, '[a-\x41', '', '[a-\x41', 0);
        $this->assertFalse(ClassRanges::startsRangeEnd($plain, $tokens, 3, 0));
    }

    private static function text(string $pattern): PatternText
    {
        return new PatternText(new TargetSyntax(PcreTarget::runtime()), $pattern, '', $pattern, 0);
    }

    /**
     * @return list<Token>
     */
    private static function tokens(string $pattern): array
    {
        return array_values(array_filter((new Lexer())->tokenize($pattern)->getTokens(), static fn (Token $token): bool => '' !== $token->value || 0 !== $token->end() - $token->position));
    }
}
