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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Exception\RecursionLimitException;
use PhpRegex\Parser\Exception\SyntaxErrorException;
use PhpRegex\Parser\Syntax\TokenParser;
use PhpRegex\Parser\Token\Token;
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Tests\TestUtils\ParserAccessor;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

/**
 * Tests specifically targeting uncovered methods to achieve 100% method coverage.
 */
final class ParserBranchesTest extends TestCase
{
    /**
     * Test Parser.parseCallout() invalid argument path
     * This triggers the else clause that throws for invalid callout arguments
     */
    public function test_parser_parse_callout_invalid_argument(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens with T_CALLOUT having invalid value
        $tokens = [
            new Token(TokenType::T_CALLOUT, '@invalid', 0),
            new Token(TokenType::T_EOF, '', 8),
        ];
        $accessor->setTokens($tokens);

        // Advance to make the T_CALLOUT the previous token
        $accessor->advance();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid callout argument: @invalid at position 0');

        // Call parseCallout which should throw for invalid argument
        $accessor->callPrivateMethod('parseCallout');
    }

    /**
     * Test Parser.createCharLiteralNodeFromToken() unsupported type path
     * This triggers the default case that throws InvalidArgumentException
     */
    public function test_parser_create_char_literal_unsupported_type(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        $token = new Token(TokenType::T_LITERAL, 'test', 0);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported character literal token type.');

        // Call createCharLiteralNodeFromToken with unsupported type
        $accessor->callPrivateMethod('createCharLiteralNodeFromToken', [$token, TokenType::T_LITERAL, 0]);
    }

    /**
     * Test Parser.guardRecursionDepth() by exceeding recursion limit
     * This triggers the recursion limit exception
     */
    public function test_parser_guard_recursion_depth(): void
    {
        // Create a deeply nested regex that exceeds the limit
        // This will cause parseAlternation -> parseSequence -> parseQuantifiedAtom -> parseAtom -> parseGroupOrCharClassAtom -> parseGroupModifier -> parseConditional -> parseAlternation (recursive)
        $nestedRegex = str_repeat('(', 5).'a'.str_repeat(')', 5);

        $this->expectException(RecursionLimitException::class);
        $this->expectExceptionMessage('Recursion limit of 3 exceeded');

        // Parse the deeply nested regex with low recursion limit
        Regex::create(['max_recursion_depth' => 3])->parse($nestedRegex);
    }

    /**
     * Test Parser.parseCharClassPart() unexpected token path
     * This triggers the else clause that throws for invalid tokens in character class
     */
    public function test_parser_parse_char_class_part_unexpected_token(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens with an unexpected token in char class
        // T_CHAR_CLASS_OPEN, T_ANCHOR('^'), T_CHAR_CLASS_CLOSE, T_EOF
        $tokens = [
            new Token(TokenType::T_CHAR_CLASS_OPEN, '[', 0),
            new Token(TokenType::T_ANCHOR, '^', 1),
            new Token(TokenType::T_CHAR_CLASS_CLOSE, ']', 2),
            new Token(TokenType::T_EOF, '', 3),
        ];
        $accessor->setTokens($tokens);

        // Advance to the T_ANCHOR token
        $accessor->advance();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unexpected token "^" in character class at position 1');

        // Call parseCharClassPart which should throw for unexpected token
        $accessor->callPrivateMethod('parseCharClassPart');
    }

    /**
     * Test Parser.parseCharClassPart() range with T_UNICODE_PROP end
     * This triggers the Unicode property handling in character class range end
     */
    public function test_parser_parse_char_class_part_range_unicode_prop(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens for [a-\pL]
        // T_LITERAL('a'), T_RANGE, T_UNICODE_PROP('L'), T_EOF
        $tokens = [
            new Token(TokenType::T_LITERAL, 'a', 0),
            new Token(TokenType::T_RANGE, '-', 1),
            new Token(TokenType::T_UNICODE_PROP, 'L', 2),
            new Token(TokenType::T_EOF, '', 4),
        ];
        $accessor->setTokens($tokens);

        // PCRE rejects a Unicode property as a range endpoint.
        $this->expectException(ParserException::class);
        $accessor->callPrivateMethod('parseCharClassPart');
    }

    /**
     * Test Parser.parseCharClassPart() range with T_POSIX_CLASS end
     * This triggers the POSIX class handling in character class range end
     */
    public function test_parser_parse_char_class_part_range_posix_class(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens for [a-[:alnum:]]
        // T_LITERAL('a'), T_RANGE, T_POSIX_CLASS('alnum'), T_EOF
        $tokens = [
            new Token(TokenType::T_LITERAL, 'a', 0),
            new Token(TokenType::T_RANGE, '-', 1),
            new Token(TokenType::T_POSIX_CLASS, 'alnum', 2),
            new Token(TokenType::T_EOF, '', 9),
        ];
        $accessor->setTokens($tokens);

        // PCRE rejects a POSIX class as a range endpoint.
        $this->expectException(ParserException::class);
        $accessor->callPrivateMethod('parseCharClassPart');
    }

    /**
     * Test Parser.parseCharClassPart() range with unexpected token end
     * This triggers the else clause that throws for invalid tokens in character class range
     */
    public function test_parser_parse_char_class_part_range_unexpected_token(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens for [a-^] (invalid range end)
        // T_LITERAL('a'), T_RANGE, T_ANCHOR('^'), T_EOF
        $tokens = [
            new Token(TokenType::T_LITERAL, 'a', 0),
            new Token(TokenType::T_RANGE, '-', 1),
            new Token(TokenType::T_ANCHOR, '^', 2),
            new Token(TokenType::T_EOF, '', 3),
        ];
        $accessor->setTokens($tokens);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unexpected token "^" in character class range at position 2');

        // Call parseCharClassPart which should throw for unexpected token in range
        $accessor->callPrivateMethod('parseCharClassPart');
    }

    /**
     * Test Parser.parseGroupModifier() rewind 'R' path
     * This triggers the stream rewind when (?R is not followed by )
     */
    public function test_parser_parse_group_modifier_rewind_r(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens for (?Rabc) invalid
        // T_GROUP_MODIFIER_OPEN, T_LITERAL('R'), T_LITERAL('a'), T_LITERAL('b'), T_LITERAL('c'), T_GROUP_CLOSE, T_EOF
        $tokens = [
            new Token(TokenType::T_GROUP_MODIFIER_OPEN, '(?', 0),
            new Token(TokenType::T_LITERAL, 'R', 2),
            new Token(TokenType::T_LITERAL, 'a', 3),
            new Token(TokenType::T_LITERAL, 'b', 4),
            new Token(TokenType::T_LITERAL, 'c', 5),
            new Token(TokenType::T_GROUP_CLOSE, ')', 6),
            new Token(TokenType::T_EOF, '', 7),
        ];
        $accessor->setTokens($tokens);

        // Advance past the (? to set up for parseGroupModifier
        $accessor->advance();

        // Expect exception because after rewind, no valid modifier is found
        $this->expectException(SyntaxErrorException::class);
        $this->expectExceptionMessage('Invalid group modifier syntax');

        // Call parseGroupModifier which should match 'R', not find ), rewind, then fail
        $accessor->callPrivateMethod('parseGroupModifier');
    }

    /**
     * Test Parser.parsePcreVerbInGroup() verb name collection break
     * This triggers the break in verb name collection when encountering non-literal token
     */
    public function test_parser_parse_pcre_verb_in_group_verb_break(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens for (?(*TEST^)expr) where ^ breaks verb collection
        // T_GROUP_MODIFIER_OPEN, T_LITERAL('*'), T_LITERAL('T'), T_LITERAL('E'), T_LITERAL('S'), T_LITERAL('T'), T_ANCHOR('^'), T_GROUP_CLOSE, T_LITERAL('e'), T_EOF
        $tokens = [
            new Token(TokenType::T_GROUP_MODIFIER_OPEN, '(?', 0),
            new Token(TokenType::T_LITERAL, '*', 2),
            new Token(TokenType::T_LITERAL, 'T', 3),
            new Token(TokenType::T_LITERAL, 'E', 4),
            new Token(TokenType::T_LITERAL, 'S', 5),
            new Token(TokenType::T_LITERAL, 'T', 6),
            new Token(TokenType::T_ANCHOR, '^', 7),  // Non-literal token breaks verb collection
            new Token(TokenType::T_GROUP_CLOSE, ')', 8),
            new Token(TokenType::T_LITERAL, 'e', 9),
            new Token(TokenType::T_EOF, '', 10),
        ];
        $accessor->setTokens($tokens);

        // Advance past the (? to set up for parseGroupModifier
        $accessor->advance();

        // Expect exception because after break, consume expects ) but finds ^
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected ) to close PCRE verb');

        // Call parseGroupModifier which should match * and call parsePcreVerbInGroup with verb collection break
        $accessor->callPrivateMethod('parseGroupModifier');
    }

    /**
     * Test Parser.parsePcreVerbInGroup() argument collection break
     * This triggers the break in argument collection when encountering non-literal token
     */
    public function test_parser_parse_pcre_verb_in_group_arg_break(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Create tokens for (?(*MARK:name^)expr) where ^ breaks argument collection
        // T_GROUP_MODIFIER_OPEN, T_LITERAL('*'), T_LITERAL('M'), T_LITERAL('A'), T_LITERAL('R'), T_LITERAL('K'), T_LITERAL(':'), T_LITERAL('n'), T_LITERAL('a'), T_LITERAL('m'), T_LITERAL('e'), T_ANCHOR('^'), T_GROUP_CLOSE, T_LITERAL('e'), T_EOF
        $tokens = [
            new Token(TokenType::T_GROUP_MODIFIER_OPEN, '(?', 0),
            new Token(TokenType::T_LITERAL, '*', 2),
            new Token(TokenType::T_LITERAL, 'M', 3),
            new Token(TokenType::T_LITERAL, 'A', 4),
            new Token(TokenType::T_LITERAL, 'R', 5),
            new Token(TokenType::T_LITERAL, 'K', 6),
            new Token(TokenType::T_LITERAL, ':', 7),
            new Token(TokenType::T_LITERAL, 'n', 8),
            new Token(TokenType::T_LITERAL, 'a', 9),
            new Token(TokenType::T_LITERAL, 'm', 10),
            new Token(TokenType::T_LITERAL, 'e', 11),
            new Token(TokenType::T_ANCHOR, '^', 12),  // Non-literal token breaks argument collection
            new Token(TokenType::T_GROUP_CLOSE, ')', 13),
            new Token(TokenType::T_LITERAL, 'e', 14),
            new Token(TokenType::T_EOF, '', 15),
        ];
        $accessor->setTokens($tokens);

        // Advance past the (? to set up for parseGroupModifier
        $accessor->advance();

        // Expect exception because after break, consume expects ) but finds ^
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected ) to close PCRE verb');

        // Call parseGroupModifier which should match * and call parsePcreVerbInGroup with argument collection break
        $accessor->callPrivateMethod('parseGroupModifier');
    }
}
