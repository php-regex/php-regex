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

use PHPRegex\Parser\Lexing\TokenValue;
use PHPRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LexerMethodTest extends TestCase
{
    /**
     * Tests the default fallback of extractTokenValue for an unknown type.
     * This case is normally unreachable as the main Regex filters tokens,
     * but for 100% coverage we must force it via Reflection.
     */
    #[Test]
    public function test_extract_token_value_unknown_type(): void
    {

        // Force a token that has no specific extraction logic
        // (e.g. T_LITERAL goes to the default)
        $val = TokenValue::of(
            TokenType::Literal, // Cas default
            'TEST_VALUE',
            [],
            false,
        );
        $this->assertSame('TEST_VALUE', $val);
    }

    /**
     * Tests the fallback of T_BACKREF if the 'v_backref_num' key is missing from the matches array.
     */
    #[Test]
    public function test_extract_token_value_backref_fallback(): void
    {

        $val = TokenValue::of(
            TokenType::Backref,
            '\99',
            [], // Empty array -> force the ?? null
            false,
        );
        $this->assertSame('\99', $val);
    }

    /**
     * Tests the fallback of normalizeUnicodeProp if the capture keys are missing.
     */
    #[Test]
    public function test_normalize_unicode_prop_fallback(): void
    {

        $val = TokenValue::unicodeProperty('\p{}');
        $this->assertSame('', $val); // Returns the empty property
    }
}
