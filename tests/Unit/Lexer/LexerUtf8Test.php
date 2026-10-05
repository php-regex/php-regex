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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{pattern: string, flags: string, offset: int}>
     */
    public static function provideInvalidUtf8InUtfMode(): iterable
    {
        yield 'under the u flag' => ['pattern' => "a\xC3\xA9\xFF", 'flags' => 'u', 'offset' => 3];
        yield 'under a leading (*UTF)' => ['pattern' => "(*UTF)a\xFF", 'flags' => '', 'offset' => 7];
        yield 'under (*UTF) after another setting' => ['pattern' => "(*CR)(*UTF)\xFF", 'flags' => '', 'offset' => 11];
    }

    /**
     * The first byte that starts no UTF-8 character is where PCRE stops,
     * "UTF-8 error: illegal byte (0xfe or 0xff) at offset N".
     */
    #[DataProvider('provideInvalidUtf8InUtfMode')]
    public function test_lexer_reports_the_first_invalid_utf8_byte_in_utf_mode(string $pattern, string $flags, int $offset): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/'.$flags, ''));
        $this->assertStringEndsWith('at offset '.$offset, (string) (error_get_last()['message'] ?? ''));

        try {
            (new Lexer())->tokenize($pattern, $flags);
            $this->fail('The pattern is not valid UTF-8 but was read.');
        } catch (LexerException $exception) {
            $this->assertSame(ErrorCode::EncodingInvalidUtf8, $exception->getErrorCode());
            $this->assertSame($offset, $exception->getPosition());
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideControlEscapesBeforeAMultibyteCharacter(): iterable
    {
        yield 'under the u flag' => ['pattern' => "/\\c\xC3\xA9/u"];
        yield 'under a leading (*UTF)' => ['pattern' => "/(*UTF)\\c\xC3\xA9/"];
        yield 'in byte mode' => ['pattern' => "/\\c\xC3\xA9/"];
    }

    /**
     * "\c" before a character that is not printable ASCII: PCRE reports
     * the offset where it stopped, past the whole character in UTF mode
     * and past its first byte otherwise.
     */
    #[DataProvider('provideControlEscapesBeforeAMultibyteCharacter')]
    public function test_validate_reports_a_control_escape_where_pcre_does(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''));
        $this->assertSame(1, preg_match('/at offset (\d+)$/', (string) (error_get_last()['message'] ?? ''), $engine), 'Oracle: PCRE gives an offset.');

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame((int) $engine[1], $result->offset);
    }
}
