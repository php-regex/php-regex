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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuoteModeTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * A quoted run in a class stands for its characters one by one, and
     * each one is read without going over the rest of the run again.
     * Measured when each character searched the run for its "\E": 16 000
     * then 32 000 quoted letters took 0.52 s then 2.0 s, four times as long
     * for twice the text.
     */
    #[Test]
    #[DataProvider('provideQuotedRunsInAClass')]
    public function test_lexer_reads_a_quoted_run_in_a_class_in_linear_time(string $prefix, string $unit, string $suffix, int $size): void
    {
        $this->assertLinearTime(
            static function (int $count) use ($prefix, $unit, $suffix): void {
                try {
                    (new Lexer())->tokenize($prefix.str_repeat($unit, $count).$suffix);
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            $size,
            $prefix.$unit.$suffix,
        );
    }

    /**
     * @return iterable<string, array{prefix: string, unit: string, suffix: string, size: int}>
     */
    public static function provideQuotedRunsInAClass(): iterable
    {
        yield 'letters closed by \\E' => ['prefix' => '[\\Q', 'unit' => 'a', 'suffix' => '\\E]', 'size' => 16_000];
        yield 'two-byte characters closed by \\E' => ['prefix' => '[\\Q', 'unit' => "\u{e9}", 'suffix' => '\\E]', 'size' => 8_000];
        yield 'letters running to the end' => ['prefix' => '[\\Q', 'unit' => 'a', 'suffix' => ']', 'size' => 16_000];
        yield 'letters after a member' => ['prefix' => '[a\\Q', 'unit' => 'b', 'suffix' => '\\E]', 'size' => 16_000];
    }

    /**
     * The same quoted run in the class of an alphabetic lookahead, whose
     * body the parser reads: the whole validation stays linear. Measured
     * before: 16 000 then 32 000 letters took 0.66 s then 2.3 s.
     */
    #[Test]
    public function test_validate_reads_a_quoted_run_in_a_class_in_a_body_in_linear_time(): void
    {
        $this->assertNotFalse(@preg_match('/(*pla:[\\Qabc\\E])/', ''), 'Oracle: the body compiles.');

        $this->assertLinearTime(
            static function (int $count): void {
                try {
                    Regex::create(['cache' => null])->validate('/(*pla:[\\Q'.str_repeat('a', $count).'\\E])/');
                } catch (RegexException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            16_000,
            '(*pla:[\\Qa\\E])',
        );
    }

    /**
     * Under /u a quoted run in a class stands for its characters, a
     * multibyte one whole: the last one starts a range after "\E", and PCRE
     * compares its code point, not its last byte, with the end of the range.
     */
    #[Test]
    #[DataProvider('provideRangesFromAQuotedMultibyteCharacter')]
    public function test_validate_reads_a_quoted_multibyte_character_whole_as_a_range_start(string $pattern, ?int $offset): void
    {
        $warning = PcreMessageCodes::warningOf($pattern);
        $result = Regex::create(['cache' => null])->validate($pattern);

        if (null === $offset) {
            $this->assertNull($warning, 'Oracle: '.$pattern);
            $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));

            return;
        }

        $pcre = PcreMessageCodes::read($warning ?? 'compiles');
        $this->assertSame(['message' => 'range out of order in character class', 'offset' => $offset], $pcre, 'Oracle: '.$pattern);
        $this->assertSame([ErrorCode::RangeReversed, $offset], [$result->errorCode, $result->offset], \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int|null}>
     */
    public static function provideRangesFromAQuotedMultibyteCharacter(): iterable
    {
        // U+E9 comes after U+C0, its last byte 0xA9 before it.
        yield 'two-byte letter' => ['pattern' => "/[\\Q\u{e9}\\E-\\x{c0}]/u", 'offset' => 14];
        yield 'two-byte letter after a letter' => ['pattern' => "/[\\Qa\u{e9}\\E-\\x{c0}]/u", 'offset' => 15];
        yield 'three-byte character' => ['pattern' => "/[\\Q\u{20ac}\\E-\\x{20ab}]/u", 'offset' => 17];
        // In order: kept as guards.
        yield 'two-byte letter up to a later code point' => ['pattern' => "/[\\Q\u{e9}\\E-\\x{f0}]/u", 'offset' => null];
        yield 'three-byte character up to a later code point' => ['pattern' => "/[\\Q\u{20ac}\\E-\\x{20ad}]/u", 'offset' => null];
    }

    public function test_quote_mode_with_end_delimiter(): void
    {
        // \Q ... \E
        $tokens = (new Lexer())->tokenize('\Q*+?\E')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_QUOTE_MODE_END, T_EOF
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::QuoteModeStart, $tokens[0]->type);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('*+?', $tokens[1]->value);
        $this->assertSame(TokenType::QuoteModeEnd, $tokens[2]->type);
        $this->assertSame('\E', $tokens[2]->value);
    }

    public function test_quote_mode_until_end_of_string(): void
    {
        // \Q ... (no \E)
        $tokens = (new Lexer())->tokenize('\Q*+?')->getTokens();

        // Now emits T_QUOTE_MODE_START, T_LITERAL, T_EOF (no T_QUOTE_MODE_END since no \E)
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::QuoteModeStart, $tokens[0]->type);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame(TokenType::Literal, $tokens[1]->type);
        $this->assertSame('*+?', $tokens[1]->value);
    }

    public function test_empty_quote_mode(): void
    {
        // \Q\E
        $tokens = (new Lexer())->tokenize('a\Q\Eb')->getTokens();

        // Now emits: T_LITERAL('a'), T_QUOTE_MODE_START, T_QUOTE_MODE_END, T_LITERAL('b'), T_EOF
        $this->assertSame(TokenType::Literal, $tokens[0]->type);
        $this->assertSame('a', $tokens[0]->value);
        $this->assertSame(TokenType::QuoteModeStart, $tokens[1]->type);
        $this->assertSame('\Q', $tokens[1]->value);
        $this->assertSame(TokenType::QuoteModeEnd, $tokens[2]->type);
        $this->assertSame('\E', $tokens[2]->value);
        $this->assertSame(TokenType::Literal, $tokens[3]->type);
        $this->assertSame('b', $tokens[3]->value);
    }
}
