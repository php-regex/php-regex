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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\PhpVersionGates;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\ClassParser;
use PHPRegex\Parser\Syntax\ParseState;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a character class, from the token after its "[".
 */
final class ClassParserTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_parse_reads_a_range(): void
    {
        $this->assertEquals(
            new CharClassNode(new RangeNode(new LiteralNode('a', 1, 2), new LiteralNode('z', 3, 4), 1, 4), false, 0, 5),
            self::parse('[a-z]'),
        );
    }

    #[Test]
    public function test_parse_reads_a_negation_and_several_members(): void
    {
        $this->assertEquals(
            new CharClassNode(new AlternationNode([new LiteralNode('a', 2, 3), new CharTypeNode('d', 3, 5)], 2, 5), true, 0, 6),
            self::parse('[^a\d]'),
        );
    }

    #[Test]
    public function test_parse_reads_a_trailing_hyphen_as_a_member(): void
    {
        $this->assertEquals(
            new CharClassNode(new AlternationNode([new LiteralNode('a', 1, 2), new LiteralNode('-', 2, 3)], 1, 3), false, 0, 4),
            self::parse('[a-]'),
        );
    }

    /**
     * Without UTF mode PCRE reads a class byte by byte: "é" ends the range
     * on its first byte, and the "-" after it starts a range from its last.
     */
    #[Test]
    public function test_parse_cuts_a_multibyte_range_end_into_bytes_without_utf(): void
    {
        $class = self::parse('[a-é-z]');

        $this->assertEquals(
            new CharClassNode(new AlternationNode([
                new RangeNode(new LiteralNode('a', 1, 2), new LiteralNode("\xC3", 3, 4), 1, 4),
                new RangeNode(new LiteralNode("\xA9", 4, 5), new LiteralNode('z', 6, 7), 4, 7),
            ], 1, 7), false, 0, 8),
            $class,
        );
    }

    /**
     * The bytes between the first and the last of a character of three
     * bytes are members of their own: "[a-€-z]" is a to 0xE2, 0x82, then
     * 0xAC to z.
     */
    #[Test]
    public function test_parse_keeps_the_middle_bytes_of_a_range_end_as_members(): void
    {
        $this->assertEquals(
            new CharClassNode(new AlternationNode([
                new RangeNode(new LiteralNode('a', 1, 2), new LiteralNode("\xE2", 3, 4), 1, 4),
                new LiteralNode("\x82", 4, 5),
                new RangeNode(new LiteralNode("\xAC", 5, 6), new LiteralNode('z', 7, 8), 5, 8),
            ], 1, 8), false, 0, 9),
            self::parse('[a-€-z]'),
        );
    }

    /**
     * Where no range is read, the "-" is a member, read again from where
     * the range would have started.
     */
    #[Test]
    #[DataProvider('provideHyphensLeftAsMembers')]
    public function test_parse_reads_the_hyphen_again_as_a_member_where_no_range_forms(string $pattern, string $release, CharClassNode $expected): void
    {
        $this->assertEquals($expected, self::parse($pattern, '', new PcreTarget(80500, $release)));
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, expected: CharClassNode}>
     */
    public static function provideHyphensLeftAsMembers(): iterable
    {
        // A code point past a byte is refused as it is read, before the "-".
        yield 'after a code point PCRE refuses' => [
            'pattern' => '[\x{100}-a]',
            'release' => '10.49',
            'expected' => new CharClassNode(new AlternationNode([
                new CharLiteralNode('\x{100}', 0x100, CharLiteralType::Unicode, 1, 8),
                new LiteralNode('-', 8, 9),
                new LiteralNode('a', 9, 10),
            ], 1, 10), false, 0, 11),
        ];
        // Before PCRE2 10.45 a type the class ends after keeps its "-".
        yield 'after a type, before the "]", before 10.45' => [
            'pattern' => '[\w-]',
            'release' => '10.44',
            'expected' => new CharClassNode(new AlternationNode([new CharTypeNode('w', 1, 3), new LiteralNode('-', 3, 4)], 1, 4), false, 0, 5),
        ];
        // From 10.45 the end is read first, and a code point PCRE refuses
        // there is reported as itself.
        yield 'before a code point PCRE refuses, from 10.45' => [
            'pattern' => '[\w-\x{100}]',
            'release' => '10.49',
            'expected' => new CharClassNode(new AlternationNode([
                new CharTypeNode('w', 1, 3),
                new LiteralNode('-', 3, 4),
                new CharLiteralNode('\x{100}', 0x100, CharLiteralType::Unicode, 4, 11),
            ], 1, 11), false, 0, 12),
        ];
    }

    #[Test]
    public function test_parse_leaves_quote_mode_as_the_class_ends_it(): void
    {
        $state = self::state('[\Qa]\E]');

        (new ClassParser($state))->parse();

        $this->assertFalse($state->inQuoteMode);
        $this->assertTrue($state->stream->isAtEnd());
    }

    #[Test]
    #[DataProvider('provideRefusedRanges')]
    public function test_parse_refuses_a_range_pcre_refuses(string $pattern, ErrorCode $code): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf('/'.$pattern.'/') ?? 'compiles');

        try {
            self::parse($pattern);
            $this->fail('No error for '.$pattern);
        } catch (ParserException $error) {
            $this->assertSame([$code, $pcre['offset']], [$error->errorCode, $error->position], 'Oracle: '.$pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode}>
     */
    public static function provideRefusedRanges(): iterable
    {
        // PCRE2 10.49: "/[a-\d]/" at offset 5, "/[\d-z]/" at 4.
        yield 'a range to a type' => ['pattern' => '[a-\d]', 'code' => ErrorCode::RangeInvalidBounds];
        yield 'a range to a POSIX class' => ['pattern' => '[a-[:digit:]]', 'code' => ErrorCode::RangeInvalidBounds];
        yield 'a range to a property' => ['pattern' => '[a-\pL]', 'code' => ErrorCode::RangeInvalidBounds];
    }

    #[Test]
    public function test_parse_wants_the_closing_bracket(): void
    {
        // The lexer refuses "[a" itself: the tokens are those of a class
        // read apart, cut short.
        $tokens = [new Token(TokenType::CharClassOpen, '[', 0), new Token(TokenType::Literal, 'a', 1), new Token(TokenType::Eof, '', 2)];
        $stream = new TokenStream($tokens, '[a');
        $stream->advance();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Expected "]" to close character class');

        (new ClassParser(new ParseState($stream, '[a', new PcreTarget(80500, '10.49'), false, false)))->parse();
    }

    #[Test]
    #[DataProvider('provideWordBoundaries')]
    public function test_word_boundary_reads_the_two_posix_word_boundaries(string $pattern, GroupType $side): void
    {
        $state = self::state($pattern);

        $this->assertEquals(
            new GroupNode(
                new SequenceNode([new AssertionNode('b', 1, 1), new GroupNode(new CharTypeNode('w', 2, 6), $side, null, null, 1, 6)], 1, 6),
                GroupType::NonCapturing,
                null,
                null,
                0,
                7,
            ),
            (new ClassParser($state))->wordBoundary(),
        );
        $this->assertTrue($state->stream->isAtEnd());
    }

    /**
     * @return iterable<string, array{pattern: string, side: GroupType}>
     */
    public static function provideWordBoundaries(): iterable
    {
        yield 'the start of a word' => ['pattern' => '[[:<:]]', 'side' => GroupType::LookaheadPositive];
        yield 'the end of a word' => ['pattern' => '[[:>:]]', 'side' => GroupType::LookbehindPositive];
    }

    #[Test]
    public function test_word_boundary_leaves_any_other_class(): void
    {
        $state = self::state('[[:alpha:]]');

        $this->assertNull((new ClassParser($state))->wordBoundary());
        $this->assertSame(1, $state->stream->getPosition());
    }

    /**
     * Read alone, a class gives what the whole parser gives for it, for
     * each release and mode.
     */
    #[Test]
    #[DataProvider('provideClasses')]
    public function test_parse_reads_what_the_whole_parser_reads(string $pattern, string $flags): void
    {
        foreach ([[70300, '10.32'], [80000, '10.35'], [80400, '10.44'], [80400, '10.45'], [80500, '10.49']] as [$php, $release]) {
            $target = new PcreTarget($php, $release);

            try {
                $expected = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release])->parse('/'.$pattern.'/'.$flags)->pattern;
            } catch (ParserException $error) {
                $expected = [$error->errorCode, $error->position, $error->getMessage()];
            }

            try {
                $actual = self::parse($pattern, $flags, $target);
            } catch (ParserException $error) {
                $actual = [$error->errorCode, $error->position, $error->getMessage()];
            }

            $this->assertEquals($expected, $actual, \sprintf('%s/%s on %s', $pattern, $flags, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, flags: string}>
     */
    public static function provideClasses(): iterable
    {
        yield 'a range' => ['pattern' => '[a-z]', 'flags' => ''];
        yield 'a type before a hyphen' => ['pattern' => '[\d-z]', 'flags' => ''];
        yield 'a type before a hyphen at the end' => ['pattern' => '[\w-]', 'flags' => ''];
        yield 'a quote skipped before the hyphen' => ['pattern' => '[z\E-a]', 'flags' => ''];
        yield 'a type, a quote, a hyphen' => ['pattern' => '[\w\E-a]', 'flags' => ''];
        yield 'a quoted range end' => ['pattern' => '[a-\Qcz\E]', 'flags' => ''];
        yield 'an empty quote after the hyphen' => ['pattern' => '[a-\Q\E]', 'flags' => ''];
        yield 'an escape that ends a range' => ['pattern' => '[a-\j]', 'flags' => ''];
        yield 'a range to a named character' => ['pattern' => '[a-\N{U+41}]', 'flags' => 'u'];
        yield 'a bare \N ending a range' => ['pattern' => '[a-\N]', 'flags' => ''];
        yield 'a code point past a byte' => ['pattern' => '[\x{100}-\x{200}]', 'flags' => ''];
        yield 'a nested bracket' => ['pattern' => '[a[]', 'flags' => ''];
        yield 'a POSIX class' => ['pattern' => '[[:alpha:]-z]', 'flags' => ''];
        yield 'a multibyte range end in UTF mode' => ['pattern' => '[a-é-z]', 'flags' => 'u'];
        yield 'a multibyte range end, then no range' => ['pattern' => '[a-éb]', 'flags' => ''];
        yield 'a backslash-k' => ['pattern' => '[\k]', 'flags' => ''];
        yield 'an octal escape past a byte' => ['pattern' => '[\400-a]', 'flags' => ''];
        yield 'a negation after a quote' => ['pattern' => '[\E^a]', 'flags' => ''];
    }

    private static function parse(string $pattern, string $flags = '', ?PcreTarget $target = null): CharClassNode
    {
        return (new ClassParser(self::state($pattern, $flags, $target)))->parse();
    }

    private static function state(string $pattern, string $flags = '', ?PcreTarget $target = null): ParseState
    {
        $target ??= new PcreTarget(80500, '10.49');
        $stream = (new Lexer($target))->tokenize($pattern, $flags);
        $stream->advance();

        return new ParseState($stream, $pattern, $target, str_contains($flags, 'u'), PhpVersionGates::badEscapeIsLiteral($target->phpVersionId, $flags));
    }
}
