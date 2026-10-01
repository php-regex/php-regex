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
use PhpRegex\Parser\Token\TokenType;
use PhpRegex\Tests\TestUtils\LexerAccessor;
use PHPUnit\Framework\TestCase;

final class LexerMethodTest extends TestCase
{
    /**
     * Tests the default fallback of extractTokenValue for an unknown type.
     * This case is normally unreachable as the main Regex filters tokens,
     * but for 100% coverage we must force it via Reflection.
     */
    public function test_extract_token_value_unknown_type(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $accessor = new LexerAccessor($lexer);

        // Force a token that has no specific extraction logic
        // (e.g. T_LITERAL goes to the default)
        $val = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::Literal, // Cas default
            'TEST_VALUE',
            []
        ]);
        $this->assertSame('TEST_VALUE', $val);
    }

    /**
     * Tests the fallback of T_BACKREF if the 'v_backref_num' key is missing from the matches array.
     */
    public function test_extract_token_value_backref_fallback(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $accessor = new LexerAccessor($lexer);

        $val = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::Backref,
            '\99',
            []         // Empty array -> force the ?? null
        ]);
        $this->assertSame('\99', $val);
    }

    /**
     * Tests the fallback of normalizeUnicodeProp if the capture keys are missing.
     */
    public function test_normalize_unicode_prop_fallback(): void
    {
        $lexer = new Lexer();
        $lexer->tokenize('');
        $accessor = new LexerAccessor($lexer);

        $val = $accessor->callPrivateMethod('normalizeUnicodeProp', [
            '\p{}',
        ]);
        $this->assertSame('', $val); // Returns the empty property
    }
}
