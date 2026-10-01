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
        $accessor = new LexerAccessor(new Lexer());

        // Simulates an incomplete match to force the `?? ''`
        $result = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::PosixClass,
            '[[:alnum:]]',
            [] // No 'v_posix' key
        ]);

        $this->assertSame('', $result);
    }

    /**
     * Tests the "?? ''" fallback in normalizeUnicodeProp.
     */
    public function test_normalize_unicode_fallback(): void
    {
        $accessor = new LexerAccessor(new Lexer());

        // Empty property to force the fallback path
        $result = $accessor->callPrivateMethod('normalizeUnicodeProp', [
            '\p{}',
        ]);

        $this->assertSame('', $result);
    }

    /**
     * Tests the `default` case of the T_LITERAL_ESCAPED switch with a weird character.
     * Normal tests cover \t, \n etc. We want to test the `substr($val, 1)` fallback.
     */
    public function test_extract_literal_escaped_default(): void
    {
        $accessor = new LexerAccessor(new Lexer());

        // Tests an escaped character that is not special (e.g. \@)
        // This forces the `default => substr(...)`
        $result = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::LiteralEscaped,
            '\@',
            []
        ]);

        $this->assertSame('@', $result);
    }

    /**
     * Tests the backreference fallback if v_backref_num is missing.
     */
    public function test_extract_backref_fallback(): void
    {
        $accessor = new LexerAccessor(new Lexer());

        // Forces the `??` for the backref number
        $result = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::Backref,
            '\1',
            [] // No 'v_backref_num' key
        ]);

        $this->assertSame('\1', $result);
    }

    public function test_extract_token_value_default_case(): void
    {
        $accessor = new LexerAccessor(new Lexer());

        // Case where type is T_LITERAL (the global switch default)
        $val = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::Literal,
            'X',
            []
        ]);
        $this->assertSame('X', $val);

        // Case where type is T_LITERAL_ESCAPED but char is not special (the internal match default)
        $val = $accessor->callPrivateMethod('extractTokenValue', [
            TokenType::LiteralEscaped,
            '\@', // @ is not t, n, r, etc.
            []
        ]);
        // The code performs substr($val, 1) -> "@"
        $this->assertSame('@', $val);
    }
}
