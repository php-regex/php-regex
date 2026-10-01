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

namespace PhpRegex\Tests\Unit\Lexer;

use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Lexer;
use PHPUnit\Framework\TestCase;

final class LexerUtf8Test extends TestCase
{
    public function test_lexer_tokenizes_invalid_utf8_in_byte_mode(): void
    {
        // Invalid UTF-8 sequence (0xC3 without a following byte): PCRE
        // accepts this without the /u modifier, so the lexer tokenizes it
        // byte by byte.
        $invalidUtf8 = "abc\xC3";

        $tokens = (new Lexer())->tokenize($invalidUtf8)->getTokens();

        $this->assertCount(5, $tokens); // a, b, c, \xC3, EOF
    }

    public function test_lexer_throws_on_invalid_utf8_with_u_flag(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Input string is not valid UTF-8');

        (new Lexer())->tokenize("abc\xC3", 'u');
    }
}
