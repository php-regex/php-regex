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

namespace PHPRegex\Tests\Functional\Regression;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchitecturalFixesTest extends TestCase
{
    #[DataProvider('provideUnicodeEscapeOffsets')]
    public function test_lexer_unicode_escape_offsets(
        string $pattern,
        int $expectedPosition,
        int $expectedLength,
        string $expectedValue
    ): void {
        $lexer = new Lexer();
        $stream = $lexer->tokenize($pattern);
        $unicodeToken = null;

        foreach ($stream->getTokens() as $token) {
            if (TokenType::Unicode === $token->type) {
                $unicodeToken = $token;

                break;
            }
        }

        $this->assertInstanceOf(Token::class, $unicodeToken);
        $this->assertSame($expectedPosition, $unicodeToken->position);
        $this->assertSame($expectedLength, \strlen($unicodeToken->value));
        $this->assertSame($expectedValue, $unicodeToken->value);
    }

    public static function provideUnicodeEscapeOffsets(): \Iterator
    {
        yield 'escape-at-start' => ['\\x41', 0, 4, '\\x41'];
        yield 'escape-after-literal' => ['a\\x41b', 1, 4, '\\x41'];
    }

    #[DataProvider('provideUtf8CompilationCases')]
    public function test_compiler_utf8_round_trip(string $pattern, string $expected): void
    {
        $compiled = $this->compile($pattern);

        $this->assertSame($expected, $compiled);
        $this->assertStringNotContainsString('\\xC3', $compiled);
        $this->assertStringNotContainsString('\\xA9', $compiled);
    }

    public static function provideUtf8CompilationCases(): \Iterator
    {
        yield 'literal-e-acute' => ["/\u{00E9}/u", "/\u{00E9}/u"];
        yield 'unicode-escape' => ['/\\x{00E9}/u', '/\\x{00E9}/u'];
    }

    #[DataProvider('provideCliPatternTokens')]
    public function test_cli_pattern_detection(string $token, bool $expected): void
    {
        $command = new HelpCommand();
        $method = new \ReflectionMethod($command, 'isPatternToken');

        $this->assertSame($expected, $method->invoke($command, $token));
    }

    public static function provideCliPatternTokens(): \Iterator
    {
        yield 'hash-delimiter' => ['#abc#i', true];
        yield 'slash-delimiter-flags' => ['/regex/i', true];
        yield 'quoted-pattern' => ["'/regex/i'", true];
        yield 'non-pattern' => ['regex', false];
    }

    private function compile(string $pattern): string
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new PatternPrinter();

        return $ast->accept($visitor);
    }
}
