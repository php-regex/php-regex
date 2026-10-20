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
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\ExtendedClassParser;
use PHPRegex\Parser\Syntax\ParseState;
use PHPRegex\Parser\Syntax\TokenParser;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?[...])", PCRE2 10.45, read apart from the rest of the pattern: the
 * expression from the text, each operand read as a class or an escape, the
 * first operand PCRE refuses reported before what is wrong further on.
 */
final class ExtendedClassParserTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_parse_reads_the_class_as_the_parser_does(string $body): void
    {
        $expected = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49'])->parse('/'.$body.'/')->pattern;

        $this->assertInstanceOf(ExtendedCharClassNode::class, $expected);
        $this->assertEquals($expected, self::parse($body));
    }

    /**
     * @return iterable<string, array{body: string}>
     */
    public static function provideClasses(): iterable
    {
        yield 'a union' => ['body' => '(?[ [a] + [b] ])'];
        yield 'an escape and a difference' => ['body' => '(?[ \d - [5] ])'];
        yield 'a complement in parentheses' => ['body' => '(?[ !([a] & [b]) ])'];
        yield 'a POSIX class' => ['body' => '(?[ [:alpha:] ])'];
    }

    /**
     * PCRE reads and judges each operand before it meets what is wrong
     * further on: the reversed range, not the missing operand.
     *
     * Oracle (PHP 8.4.26, PCRE2 10.49): "/(?[ [z-a] + ])/" is refused with
     * "range out of order in character class at offset 8".
     */
    #[Test]
    public function test_an_operand_refused_before_the_fault_is_reported_first(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Invalid range "z-a"');

        try {
            self::parse('(?[ [z-a] + ])');
        } catch (ParserException $error) {
            $this->assertSame(ErrorCode::RangeReversed, $error->errorCode);
            $this->assertSame(8, $error->position);

            throw $error;
        }
    }

    private static function parse(string $body): NodeInterface
    {
        $target = new PcreTarget(80400, '10.49');
        $stream = (new Lexer($target))->tokenize($body);
        $token = $stream->current();
        $state = new ParseState($stream, $body, $target, false, false);
        $parser = new ExtendedClassParser(
            $state,
            '',
            RegexParser::DEFAULT_MAX_RECURSION_DEPTH,
            static fn (array $tokens, bool $counted): NodeInterface => (new TokenParser(RegexParser::DEFAULT_MAX_RECURSION_DEPTH, $target))
                ->parse(new TokenStream($tokens, $body), '', '/', \strlen($body))->pattern,
            static function (int $at, int $parentheses): void {},
        );

        return $parser->parse($token);
    }
}
