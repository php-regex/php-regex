<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lexer;

use PhpRegex\Parser\Lexer;
use PHPUnit\Framework\TestCase;

final class LexerQuoteModeTest extends TestCase
{
    /**
     * Tests \Q in the middle without \E at the end.
     */
    public function test_quote_mode_mid_string_unterminated(): void
    {
        $tokens = (new Lexer())->tokenize('a\Qbc')->getTokens();

        // Now emits: a (LITERAL), \Q (T_QUOTE_MODE_START), bc (LITERAL via quote mode), EOF
        $this->assertCount(4, $tokens);
        $this->assertSame('a', $tokens[0]->value);
        $this->assertSame('\Q', $tokens[1]->value);
        $this->assertSame('bc', $tokens[2]->value);
    }

    /**
     * Tests \Q...\E where ... is empty.
     * This can return null in consumeQuoteMode and must be handled.
     */
    public function test_quote_mode_empty_content(): void
    {
        $tokens = (new Lexer())->tokenize('a\Q\Eb')->getTokens();

        // Now emits: a, \Q, \E, b, EOF
        $this->assertCount(5, $tokens);
        $this->assertSame('a', $tokens[0]->value);
        $this->assertSame('\Q', $tokens[1]->value);
        $this->assertSame('\E', $tokens[2]->value);
        $this->assertSame('b', $tokens[3]->value);
    }
}
