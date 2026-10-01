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
use PhpRegex\Parser\Syntax\TokenParser;
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Tests\TestUtils\ParserAccessor;
use PHPUnit\Framework\TestCase;

final class ParserSubroutineFallbackTest extends TestCase
{
    /**
     * Tests the case where an unexpected token (non-literal) appears in a subroutine name.
     * E.g. (?&...) with a T_GROUP_OPEN token '(' instead of a literal.
     */
    public function test_parse_subroutine_name_unexpected_token_type(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Simulates: (?& ( ... )
        $tokens = [
            $accessor->createToken(TokenType::T_GROUP_OPEN, '(', 0),
            $accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 1),
        ];
        $accessor->setTokens($tokens);
        $accessor->setPosition(0);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unexpected token');

        $accessor->callPrivateMethod('parseSubroutineName');
    }

    /**
     * Tests the case where parseSubroutineName is called but finds no valid name before closure.
     * E.g. (?&) -> empty name.
     */
    public function test_parse_subroutine_name_empty(): void
    {
        $parser = new TokenParser();
        $accessor = new ParserAccessor($parser);

        // Simulates: ) immediately (end of group)
        $tokens = [
            $accessor->createToken(TokenType::T_GROUP_CLOSE, ')', 0),
        ];
        $accessor->setTokens($tokens);
        $accessor->setPosition(0);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Expected subroutine name');

        $accessor->callPrivateMethod('parseSubroutineName');
    }
}
