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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\ParseState;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reading state and steps the token parser shares with the class
 * and condition readers.
 */
final class ParseStateTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideAtoms')]
    public function test_match_atom_reads_a_character_the_same_inside_and_outside_a_class(string $pattern, NodeInterface $expected): void
    {
        $state = self::state($pattern);

        $this->assertEquals($expected, $state->matchAtom(0));
        $this->assertSame(1, $state->stream->getPosition());
    }

    /**
     * @return iterable<string, array{pattern: string, expected: NodeInterface}>
     */
    public static function provideAtoms(): iterable
    {
        yield 'a literal' => ['pattern' => 'a', 'expected' => new LiteralNode('a', 0, 1)];
        yield 'an escaped literal' => ['pattern' => '\.', 'expected' => new LiteralNode('.', 0, 2)];
        // "\x" with no digit is NUL where PCRE takes it.
        yield 'a bare \x' => ['pattern' => '\x', 'expected' => new CharLiteralNode('\x', 0, CharLiteralType::Unicode, 0, 2)];
        yield 'a type' => ['pattern' => '\d', 'expected' => new CharTypeNode('d', 0, 2)];
        yield 'a control character' => ['pattern' => '\cA', 'expected' => new ControlCharNode('A', 1, 0, 3)];
        yield 'a property' => ['pattern' => '\p{L}', 'expected' => new UnicodePropNode('{L}', true, 0, 5, false)];
        // The lexer folds the negation into the value; the text tells "\P".
        yield 'a negated property' => ['pattern' => '\P{L}', 'expected' => new UnicodePropNode('{^L}', true, 0, 5, true)];
        yield 'a code point' => ['pattern' => '\x{41}', 'expected' => new CharLiteralNode('\x{41}', 0x41, CharLiteralType::Unicode, 0, 6)];
        yield 'a named code point' => ['pattern' => '\N{U+41}', 'expected' => new CharLiteralNode('\N{U+41}', 0x41, CharLiteralType::UnicodeNamed, 0, 8)];
        yield 'an octal escape' => ['pattern' => '\o{101}', 'expected' => new CharLiteralNode('\o{101}', 0x41, CharLiteralType::Octal, 0, 7)];
        yield 'a legacy octal escape' => ['pattern' => '\012', 'expected' => new CharLiteralNode('\012', 0o12, CharLiteralType::OctalLegacy, 0, 4)];
    }

    #[Test]
    public function test_match_atom_leaves_what_depends_on_where_it_stands(): void
    {
        foreach (['^', '\b', '\1', '.', '('] as $pattern) {
            $state = self::state($pattern);

            $this->assertNull($state->matchAtom(0), $pattern);
            $this->assertSame(0, $state->stream->getPosition(), $pattern);
        }
    }

    #[Test]
    public function test_atom_refuses_a_token_that_names_no_character(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Unsupported character literal token type.');

        self::state('a')->atom(new Token(TokenType::GroupOpen, '(', 0), TokenType::GroupOpen, 0);
    }

    #[Test]
    public function test_skip_empty_quotes_skips_what_pcre_reads_as_nothing(): void
    {
        // "\E", then an empty "\Q\E", then "a".
        $state = self::state('\E\Q\Ea');
        $state->inQuoteMode = true;

        $state->skipEmptyQuotes();

        $this->assertFalse($state->inQuoteMode);
        $this->assertSame(TokenType::Literal, $state->stream->current()->type);
        $this->assertSame('a', $state->stream->current()->value);
    }

    #[Test]
    public function test_skip_empty_quotes_stops_at_a_quote_that_holds_text(): void
    {
        $state = self::state('\Qa\E');

        $state->skipEmptyQuotes();

        $this->assertSame(0, $state->stream->getPosition());
        $this->assertFalse($state->inQuoteMode);
    }

    #[Test]
    public function test_consume_while_concatenates_the_literals_it_takes(): void
    {
        $state = self::state('12a');

        $this->assertSame('12', $state->consumeWhile(static fn (string $c): bool => ctype_digit($c)));
        $this->assertSame('a', $state->stream->current()->value);
        $this->assertSame('', $state->consumeWhile(static fn (string $c): bool => ctype_digit($c)));
    }

    #[Test]
    public function test_consume_while_stops_at_a_token_that_is_no_literal(): void
    {
        $state = self::state('1(');

        $this->assertSame('1', $state->consumeWhile(static fn (): bool => true));
        $this->assertSame(TokenType::GroupOpen, $state->stream->current()->type);
    }

    #[Test]
    public function test_literal_digit_and_letter_tokens_are_told_apart(): void
    {
        $digit = self::state('1');
        $letter = self::state('a');
        $escaped = self::state('\1');

        $this->assertTrue($digit->isLiteralDigitToken());
        $this->assertFalse($digit->isLiteralAlphaToken());
        $this->assertTrue($letter->isLiteralAlphaToken());
        $this->assertFalse($letter->isLiteralDigitToken());
        $this->assertFalse($escaped->isLiteralDigitToken());
    }

    #[Test]
    public function test_comment_keeps_the_text_as_written(): void
    {
        $state = self::state('(?#a\d b)c');
        $state->stream->advance();

        $comment = $state->comment();

        $this->assertEquals(new CommentNode('a\d b', 0, 9), $comment);
        $this->assertSame('c', $state->stream->current()->value);
    }

    #[Test]
    public function test_comment_wants_its_closing_parenthesis(): void
    {
        // The lexer refuses "(?#a" itself: the tokens are cut short by hand.
        $tokens = [new Token(TokenType::CommentOpen, '(?#', 0), new Token(TokenType::Literal, 'a', 3), new Token(TokenType::Eof, '', 4)];
        $state = new ParseState(new TokenStream($tokens, '(?#a'), '(?#a', PcreTarget::runtime(), false, false);
        $state->stream->advance();

        try {
            $state->comment();
            $this->fail('An unclosed comment is refused.');
        } catch (SyntaxErrorException $error) {
            $this->assertSame(ErrorCode::CommentUnclosed, $error->errorCode);
        }
    }

    #[Test]
    public function test_error_places_the_message_in_the_pattern(): void
    {
        $error = self::state('abc')->error('Bad at position 1.', ErrorCode::TokenUnexpected, 1);

        $this->assertSame(['Bad at position 1.', ErrorCode::TokenUnexpected, 1], [$error->getMessage(), $error->errorCode, $error->position]);
        $this->assertInstanceOf(SyntaxErrorException::class, $error);
    }

    #[Test]
    public function test_source_text_is_the_spelling_of_the_token(): void
    {
        $state = self::state('\P{Greek}');

        $this->assertSame('\P{Greek}', $state->sourceTextOf($state->stream->current()));
    }

    private static function state(string $pattern): ParseState
    {
        $target = PcreTarget::runtime();
        $stream = (new Lexer($target))->tokenize($pattern, 'u');

        return new ParseState(new TokenStream($stream->getTokens(), $pattern), $pattern, $target, true, false);
    }
}
