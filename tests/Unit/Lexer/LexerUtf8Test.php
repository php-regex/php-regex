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
use PHPRegex\Parser\Token\Token;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LexerUtf8Test extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_lexer_tokenizes_invalid_utf8_in_byte_mode(): void
    {
        // Invalid UTF-8 sequence (0xC3 without a following byte): PCRE
        // accepts this without the /u modifier, so the lexer tokenizes it
        // byte by byte.
        $invalidUtf8 = "abc\xC3";

        $tokens = (new Lexer())->tokenize($invalidUtf8)->getTokens();

        $this->assertCount(5, $tokens); // a, b, c, \xC3, EOF
    }

    #[Test]
    public function test_a_lexer_reads_a_class_in_its_own_mode_after_another_lexer_read_in_the_other(): void
    {
        // The token regexes read a class member as a byte or as a UTF-8
        // character: each lexer reads in the mode of its own pattern,
        // whatever the lexer before it read.
        $values = static fn (array $tokens): array => array_map(static fn (Token $token): string => $token->value, $tokens);
        $positions = static fn (array $tokens): array => array_map(static fn (Token $token): int => $token->position, $tokens);

        $byte = (new Lexer())->tokenize("[\xFF]")->getTokens();
        $utf8 = (new Lexer())->tokenize('[é]', 'u')->getTokens();
        $byteAgain = (new Lexer())->tokenize("[\xFF]")->getTokens();

        $this->assertSame(['[', "\xFF", ']', ''], $values($byte));
        $this->assertSame([0, 1, 2, 3], $positions($byte));
        $this->assertSame(['[', 'é', ']', ''], $values($utf8));
        $this->assertSame([0, 1, 3, 4], $positions($utf8));
        $this->assertSame($values($byte), $values($byteAgain));
        $this->assertSame($positions($byte), $positions($byteAgain));
    }

    #[Test]
    public function test_lexer_throws_on_invalid_utf8_with_u_flag(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessageIsOrContains('Input string is not valid UTF-8');

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
    #[Test]
    #[DataProvider('provideInvalidUtf8InUtfMode')]
    public function test_lexer_reports_the_first_invalid_utf8_byte_in_utf_mode(string $pattern, string $flags, int $offset): void
    {
        $this->assertFalse(@preg_match('/'.$pattern.'/'.$flags, ''));
        $this->assertStringEndsWith('at offset '.$offset, (string) (error_get_last()['message'] ?? ''));

        try {
            (new Lexer())->tokenize($pattern, $flags);
            $this->fail('The pattern is not valid UTF-8 but was read.');
        } catch (LexerException $exception) {
            $this->assertSame(ErrorCode::EncodingInvalidUtf8, $exception->errorCode);
            $this->assertSame($offset, $exception->position);
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
    #[Test]
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
