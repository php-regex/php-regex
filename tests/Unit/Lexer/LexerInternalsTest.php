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
use PHPUnit\Framework\TestCase;

/**
 * White-box tests to force execution of defensive branches
 * (null coalescing, switch default) unreachable via normal parsing.
 */
final class LexerInternalsTest extends TestCase
{
    /**
     * Tests the "?? ''" fallback in POSIX class extraction.
     * Normally, the regex guarantees v_posix is set, but we test PHP robustness.
     */
    public function test_extract_posix_fallback(): void
    {

        // Simulates an incomplete match to force the `?? ''`
        $result = TokenValue::of(
            TokenType::PosixClass,
            '[[:alnum:]]',
            [], // No 'v_posix' key
            false,
        );

        $this->assertSame('', $result);
    }

    /**
     * Tests the "?? ''" fallback in normalizeUnicodeProp.
     */
    public function test_normalize_unicode_fallback(): void
    {

        // Empty property to force the fallback path
        $result = TokenValue::unicodeProperty('\p{}');

        $this->assertSame('', $result);
    }

    /**
     * Tests the `default` case of the T_LITERAL_ESCAPED switch with a weird character.
     * Normal tests cover \t, \n etc. We want to test the `substr($val, 1)` fallback.
     */
    public function test_extract_literal_escaped_default(): void
    {

        // Tests an escaped character that is not special (e.g. \@)
        // This forces the `default => substr(...)`
        $result = TokenValue::of(
            TokenType::LiteralEscaped,
            '\@',
            [],
            false,
        );

        $this->assertSame('@', $result);
    }

    /**
     * Tests the backreference fallback if v_backref_num is missing.
     */
    public function test_extract_backref_fallback(): void
    {

        // Forces the `??` for the backref number
        $result = TokenValue::of(
            TokenType::Backref,
            '\1',
            [], // No 'v_backref_num' key
            false,
        );

        $this->assertSame('\1', $result);
    }

    public function test_extract_token_value_default_case(): void
    {

        // Case where type is T_LITERAL (the global switch default)
        $val = TokenValue::of(
            TokenType::Literal,
            'X',
            [],
            false,
        );
        $this->assertSame('X', $val);

        // Case where type is T_LITERAL_ESCAPED but char is not special (the internal match default)
        $val = TokenValue::of(
            TokenType::LiteralEscaped,
            '\@', // @ is not t, n, r, etc.
            [],
            false,
        );
        // The code performs substr($val, 1) -> "@"
        $this->assertSame('@', $val);
    }
}
