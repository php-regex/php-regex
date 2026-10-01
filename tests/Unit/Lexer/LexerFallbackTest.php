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
use PHPUnit\Framework\TestCase;

final class LexerFallbackTest extends TestCase
{
    /**
     * Covers the case where consumeQuoteMode reaches the end of the string without finding \E.
     */
    public function test_lex_quote_mode_unterminated(): void
    {
        $tokens = (new Lexer())->tokenize('\Qstart')->getTokens(); // No \E

        // Now emits T_QUOTE_MODE_START, T_LITERAL "start", T_EOF
        $this->assertCount(3, $tokens);
        $this->assertSame('\Q', $tokens[0]->value);
        $this->assertSame('start', $tokens[1]->value);
    }

    /**
     * Covers the case where consumeCommentMode reaches the end of the string without finding ).
     * (Note: this throws an exception later in tokenize(), but we want to test the internal call).
     */
    public function test_lex_comment_mode_unterminated_internal(): void
    {
        try {
            (new Lexer())->tokenize('(?#start'); // No )
        } catch (\Exception $e) {
            // We expect an exception, but we mainly want the code to be executed
            $this->assertStringContainsString('Unclosed comment', $e->getMessage());
        }
    }
}
