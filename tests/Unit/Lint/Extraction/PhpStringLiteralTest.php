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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A string literal is read as PHP reads it: each expected value below is
 * the one PHP compiled from the same literal in this file.
 */
final class PhpStringLiteralTest extends TestCase
{
    /**
     * @return iterable<string, array{literal: string, expected: string}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'an unknown escape is kept, regex escapes with it' => ['literal' => '"/\d+\.x\/y/"', 'expected' => "/\d+\.x\/y/"];
        yield 'the control escapes' => ['literal' => '"\n\t\r\v\e\f"', 'expected' => "\n\t\r\v\e\f"];
        yield 'backslash, dollar and quote' => ['literal' => '"\\\\ \$ \""', 'expected' => '\\ $ "'];
        yield 'a single quote is no escape in double quotes' => ['literal' => '"\\\'"', 'expected' => "\\'"];
        yield 'octal' => ['literal' => '"\101\0\7"', 'expected' => "\101\0\7"];
        yield 'an 8 is no octal digit' => ['literal' => '"\8"', 'expected' => "\8"];
        yield 'hexadecimal, one or two digits' => ['literal' => '"\x41\x4"', 'expected' => "\x41\x4"];
        yield 'a \x with no digit is kept' => ['literal' => '"\xZZ"', 'expected' => "\xZZ"];
        yield 'an uppercase \X is hexadecimal too' => ['literal' => '"\X41\X4\XZ"', 'expected' => "\X41\X4\XZ"];
        yield 'a \x{ is no PHP escape' => ['literal' => '"\x{41}"', 'expected' => "\x{41}"];
        yield 'unicode' => ['literal' => '"\u{1F600}\u{41}\u{e9}"', 'expected' => "\u{1F600}\u{41}\u{e9}"];
        yield 'unicode at each UTF-8 length, a surrogate included' => ['literal' => '"\u{7F}\u{80}\u{7FF}\u{800}\u{D800}\u{FFFF}\u{10000}\u{10FFFF}"', 'expected' => "\u{7F}\u{80}\u{7FF}\u{800}\u{D800}\u{FFFF}\u{10000}\u{10FFFF}"];
        yield 'a \u{} PHP refuses to compile, in a half-typed file, is kept as written' => ['literal' => '"\u{}\u{zz}\u{41"', 'expected' => '\u{}\u{zz}\u{41'];
        yield 'octal past \377 keeps its low byte' => ['literal' => '"\400\777"', 'expected' => "\0\377"];
        yield 'a \u with no brace is kept' => ['literal' => '"\u00e9"', 'expected' => "\u00e9"];
        yield 'a brace is kept' => ['literal' => '"\{"', 'expected' => "\{"];
        yield 'single quotes read \\\\ and \\\' only' => ['literal' => "'/\\d\\'\\\\x\\n/'", 'expected' => '/\d\'\\x\n/'];
    }

    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_a_literal_is_read_as_php_reads_it(string $literal, string $expected): void
    {
        $this->assertSame($expected, PhpStringLiteral::decode($literal));
    }

    /**
     * @return iterable<string, array{literal: string}>
     */
    public static function provideHeredocs(): iterable
    {
        yield 'a heredoc keeps the backslash of \\"' => ['literal' => "<<<RE\n    /a\\\"b\\d\\\\\\$\\x41\\101\\u{42}\\t/\n    RE"];
        yield 'a nowdoc reads no escape' => ['literal' => "<<<'RE'\n    /a\\\"b\\d\\\\\\'\$x\\n/\n    RE"];
        yield 'the closing indentation leaves every line' => ['literal' => "<<<RE\n    /a/\n      b\n    RE"];
        yield 'a whitespace-only line may be indented less' => ['literal' => "<<<RE\n    /a/\n  \n\n    b\n    RE"];
        yield 'tabs' => ['literal' => "<<<RE\n\t\t/a/\n\t\t\tb\n\t\tRE"];
        yield 'escapes are read once the indentation is gone' => ['literal' => "<<<RE\n    /a\\n  b/\n    RE"];
        yield 'CRLF' => ['literal' => "<<<RE\r\n    /a/\r\n    b\r\n    RE"];
        yield 'CR' => ['literal' => "<<<RE\r    /a/\r    b\r    RE"];
        yield 'an empty body' => ['literal' => "<<<RE\n    RE"];
        yield 'a quoted label' => ['literal' => "<<<\"RE\"\n/a\\x41/\nRE"];
        yield 'an uppercase \X in a heredoc' => ['literal' => "<<<RE\n    /\\X41/\n    RE"];
        yield 'a blank line before the closing marker' => ['literal' => "<<<RE\n    /a/\n\n    RE"];
        yield 'a binary prefix' => ['literal' => "b<<<'RE'\n  /a\\x41/\n  RE"];
    }

    #[Test]
    #[DataProvider('provideHeredocs')]
    public function test_a_heredoc_is_read_as_php_reads_it(string $literal): void
    {
        $file = tempnam(sys_get_temp_dir(), 'regex-heredoc-');
        $this->assertIsString($file);

        try {
            file_put_contents($file, "<?php\nreturn ".$literal.";\n");
            $expected = include $file;
        } finally {
            unlink($file);
        }

        $this->assertIsString($expected);
        $this->assertSame($expected, PhpStringLiteral::decodeHeredoc(...$this->heredocParts($literal)));
    }

    /**
     * @return iterable<string, array{literal: string}>
     */
    public static function provideHeredocsPhpRefuses(): iterable
    {
        yield 'a line indented less than the closing marker' => ['literal' => "<<<RE\n    /a/\n  b\n    RE"];
        yield 'tabs and spaces mixed in the body' => ['literal' => "<<<RE\n    /a/\n  \t\n    RE"];
        yield 'tabs and spaces mixed in the closing marker' => ['literal' => "<<<RE\n \t/a/\n \tRE"];
    }

    #[Test]
    #[DataProvider('provideHeredocsPhpRefuses')]
    public function test_a_heredoc_php_refuses_is_not_read(string $literal): void
    {
        $this->assertNull(PhpStringLiteral::decodeHeredoc(...$this->heredocParts($literal)));
    }

    #[Test]
    public function test_a_token_that_is_no_quoted_literal_is_not_read(): void
    {
        $this->assertNull(PhpStringLiteral::decode('x'));
        $this->assertNull(PhpStringLiteral::decode('abc'));
        $this->assertNull(PhpStringLiteral::decode('"'));
    }

    /**
     * The opening token, raw body and closing token of a heredoc, as the
     * tokenizer gives them.
     *
     * @return array{string, string, string}
     */
    private function heredocParts(string $literal): array
    {
        $opening = '';
        $body = '';
        $closing = '';
        foreach (token_get_all("<?php\n".$literal.";\n") as $token) {
            if (!\is_array($token)) {
                continue;
            }

            match ($token[0]) {
                \T_START_HEREDOC => $opening = $token[1],
                \T_ENCAPSED_AND_WHITESPACE => $body .= $token[1],
                \T_END_HEREDOC => $closing = $token[1],
                default => null,
            };
        }

        $this->assertNotSame('', $opening);
        $this->assertNotSame('', $closing);

        return [$opening, $body, $closing];
    }
}
