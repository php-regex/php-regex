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
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
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

    /**
     * PCRE judges each range of a nested class as it reads it: a reversed
     * range comes before what is wrong further on in the class. Each row
     * is the error preg_match() reports on PHP 8.4.27 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideRangesBeforeAFault')]
    public function test_a_reversed_range_before_a_fault_in_its_class_is_reported_first(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertTheEngineRefusesAt($offset, $pattern);

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($code, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideRangesBeforeAFault(): iterable
    {
        yield 'reversed range before a range no class takes' => ['pattern' => '/(?[[^]--[a-\d]])/', 'code' => ErrorCode::RangeReversed, 'offset' => 8];
        yield 'reversed range before a range no class takes, side by side' => ['pattern' => '/(?[[z-ab-\d]])/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'reversed range before an escape the lexer refuses' => ['pattern' => '/(?[[z-a\x{zz}]])/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'escape no class takes as a range start' => ['pattern' => '/(?[[\X-\d]])/', 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 6];
        yield 'range no class takes before an escape PCRE refuses' => ['pattern' => "/(?[[a-\d\c\u{E9}])/", 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'range no class takes after a member, before an escape PCRE refuses' => ['pattern' => "/(?[[ab-\d\c\u{E9}])/", 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 9];
        yield 'class escape starting a range before an escape PCRE refuses' => ['pattern' => "/(?[[\d-z\c\u{E9}])/", 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        yield 'two ranges no class takes before an escape PCRE refuses' => ['pattern' => "/(?[[a-\db-\d\c\u{E9}])/", 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'range no class takes before a quote that swallows the end' => ['pattern' => '/(?[[a-\d\Q]])/', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 8];
        yield 'reversed range, then a range no class takes, before an escape PCRE refuses' => ['pattern' => "/(?[[z-ab-\d\c\u{E9}])/", 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'class escape starting a range down to a hyphen, before an escape PCRE refuses' => ['pattern' => "/(?[[\w--\c\u{E9}])/x", 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        yield 'reversed range down to a hyphen, before an escape PCRE refuses' => ['pattern' => "/(?[[a--\c\u{E9}]-[b])/", 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        // The end of the range is read before the range is judged: an escape
        // PCRE refuses there comes first, a POSIX item does not.
        yield 'class escape starting a range whose hex end PCRE refuses' => ['pattern' => '/(?[[\d-\x{zz}]])/', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 11];
        yield 'class escape starting a range whose octal end PCRE refuses' => ['pattern' => '/(?[[\d-\o{8}]])/', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 11];
        yield 'class escape starting a range whose hex end PCRE refuses, then a quote' => ['pattern' => '/(?[[\d-\x{zz}\Q/', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 11];
        yield 'class escape starting a range a POSIX class ends' => ['pattern' => '/(?[[\d-[:foo:]]])/', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 7];
        // A range the pattern or the fault ends before its end is never
        // judged.
        yield 'class escape starting a range the pattern ends in' => ['pattern' => '/(?[[b\d-\E\c/u', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 12];
        yield 'class escape starting a range a quote then an escape PCRE refuses end' => ['pattern' => "/(?[[\d-\E\c\u{E9}])/", 'code' => ErrorCode::ControlCharInvalid, 'offset' => 12];
        // A refusal that is no range's stays the member's.
        yield 'escape no class takes before an escape PCRE refuses' => ['pattern' => "/(?[[\X\c\u{E9}])/", 'code' => ErrorCode::CharclassInvalidEscape, 'offset' => 6];
    }

    /**
     * A "[" quoted by "\Q" is text, and opens no POSIX item: PCRE reaches
     * the end of the class that the quote leaves open. Each row is the error
     * preg_match() reports on PHP 8.4.27 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideQuotedBrackets')]
    public function test_a_quoted_bracket_opens_no_posix_item_before_a_fault(string $pattern, int $offset): void
    {
        $this->assertTheEngineRefusesAt($offset, $pattern);

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame(ErrorCode::CharclassUnclosed, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideQuotedBrackets(): iterable
    {
        yield 'quoted unknown POSIX class' => ['pattern' => '/(?[[\Q[:foo:]]])/', 'offset' => 16];
        yield 'quoted collating element' => ['pattern' => '/(?[[\Q[.a.]]])/', 'offset' => 14];
        yield 'quoted equivalence class under u' => ['pattern' => '/(?[[\Q[=a=]^]-[b])/u', 'offset' => 18];
        yield 'quoted unknown POSIX class after a member, under x' => ['pattern' => '/(?[[b\Q[:foo:]\B]-[b])/x', 'offset' => 22];
    }

    /**
     * The braced name of a "\p{" or "\P{" is read as PCRE2 reads it, on
     * past the "]" of a nested class, a "\Q" or a "-", up to its "}", the
     * first character no name holds, the end of the pattern, or the most
     * characters a name takes: nothing past where PCRE gives up on it is
     * read, as an operand or as a member, and a "]" before its "}" closes
     * no class. What PCRE refuses before it comes first. Each row is the
     * error preg_match() reports on PHP 8.4.27 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('providePropertyNames')]
    public function test_a_braced_property_name_is_read_as_far_as_pcre_reads_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertTheEngineRefusesAt($offset, $pattern);

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function providePropertyNames(): iterable
    {
        $malformed = ErrorCode::UnicodePropertyMalformed;
        $unknown = ErrorCode::UnicodePropertyInvalid;

        // In a nested class: what the lexer reads past "\p" as members, a
        // range quoted out of order among them, PCRE reads as the name.
        yield 'nested class, a quote then a byte no name holds' => ['pattern' => '/(?[[\p{-\Q\cé]])/', 'code' => $malformed, 'offset' => 13];
        yield 'nested class, a quote then a character no name holds, under u' => ['pattern' => '/(?[[\p{-\Q\cé]])/u', 'code' => $malformed, 'offset' => 14];
        yield 'nested class, a quote then a byte no name holds, under x' => ['pattern' => '/(?[[\p{-\Q\cé]])/x', 'code' => $malformed, 'offset' => 13];
        yield 'nested class, a quote then the end of the pattern' => ['pattern' => '/(?[[\p{-\Q\c]])/', 'code' => $malformed, 'offset' => 15];
        yield 'nested class, \P then a quote' => ['pattern' => '/(?[[\P{-\Qa]])/', 'code' => $malformed, 'offset' => 14];
        yield 'nested class, a quoted range out of order' => ['pattern' => '/(?[[\p{-\Qz-a]])/', 'code' => $malformed, 'offset' => 16];
        yield 'nested class, after a member' => ['pattern' => '/(?[[a\p{-\Q\cé]])/', 'code' => $malformed, 'offset' => 14];
        yield 'nested class, before an operator' => ['pattern' => '/(?[[\p{-\Q\cé]]+[a])/', 'code' => $malformed, 'offset' => 13];
        yield 'nested class, a short name then a quote to the end' => ['pattern' => '/(?[[\p{'.str_repeat('a', 40).'\Q]])/', 'code' => $malformed, 'offset' => 52];
        yield 'nested class, a reversed range before' => ['pattern' => '/(?[[z-a\p{-\Qa]])/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        // Nor is what follows the extended class read: PCRE reads it as the
        // name too.
        yield 'nested class, a name read past the extended class and a ")"' => ['pattern' => '/(?[[\p{]])])(b)/', 'code' => $malformed, 'offset' => 15];
        yield 'nested class, a name read past the extended class and a POSIX class' => ['pattern' => '/(?[[a\p{L])[:alpha:]])\1/', 'code' => $malformed, 'offset' => 24];
        yield 'nested class, a name read to the end of the extended class' => ['pattern' => '/(?[[\p{-a]])/', 'code' => $malformed, 'offset' => 12];

        // As an operand: the escape reads the name the same way.
        yield 'operand, a quote then a byte no name holds' => ['pattern' => '/(?[\p{-\Q\cé])/', 'code' => $malformed, 'offset' => 12];
        yield 'operand, a quote then a character no name holds, under u' => ['pattern' => '/(?[\p{-\Q\cé])/u', 'code' => $malformed, 'offset' => 13];
        yield 'operand, a space then a byte no name holds' => ['pattern' => '/(?[\p{L é])/', 'code' => $malformed, 'offset' => 9];
        yield 'operand, a space then a character no name holds, under u' => ['pattern' => '/(?[\p{L é])/u', 'code' => $malformed, 'offset' => 10];
        yield 'operand, \P then a byte no name holds' => ['pattern' => '/(?[\P{Lé])/', 'code' => $malformed, 'offset' => 8];
        yield 'operand, a caret then a byte no name holds' => ['pattern' => '/(?[\p{^é])/', 'code' => $malformed, 'offset' => 8];
        yield 'operand, a name past the most characters it takes' => ['pattern' => '/(?[\p{'.str_repeat('a', 60).'])/', 'code' => $malformed, 'offset' => 55];
        yield 'operand, a name the "]" takes to the most characters' => ['pattern' => '/(?[\p{'.str_repeat('a', 48).'])/', 'code' => $malformed, 'offset' => 55];
        yield 'operand, a quote then the end of the pattern' => ['pattern' => '/(?[\p{-\Q\c])/', 'code' => $malformed, 'offset' => 13];
        yield 'operand after an operand' => ['pattern' => '/(?[\d\p{L é])/', 'code' => $malformed, 'offset' => 11];
        yield 'operand after an operator' => ['pattern' => '/(?[\d+\p{L é])/', 'code' => $malformed, 'offset' => 12];
        yield 'operand after a complement' => ['pattern' => '/(?[!\p{L é])/', 'code' => $malformed, 'offset' => 10];
        yield 'operand in parentheses' => ['pattern' => '/(?[(\p{L é))/', 'code' => $malformed, 'offset' => 10];
        yield 'operand after a class with a reversed range' => ['pattern' => '/(?[[z-a]+\p{L é])/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];

        // A "}" closes the name past the "]" that would close the class.
        yield 'nested class, a "]" in the name' => ['pattern' => '/(?[[\p{]}])/', 'code' => $unknown, 'offset' => 9];
        yield 'nested class, a "]" in the name, under u' => ['pattern' => '/(?[[\p{L]}])/u', 'code' => $unknown, 'offset' => 10];
        yield 'nested class, a "]" in the name, before an operator' => ['pattern' => '/(?[[\p{L]}]+[a])/', 'code' => $unknown, 'offset' => 10];
        yield 'nested class, \P with a "]" in the name, after a member' => ['pattern' => '/(?[[a\P{Lu]}]]+[a])/', 'code' => $unknown, 'offset' => 12];
        yield 'nested class, a name closed past the extended class' => ['pattern' => '/(?[[\p{^]])x}/', 'code' => $unknown, 'offset' => 13];
        yield 'nested class, a name closed past an operator and a class' => ['pattern' => '/(?[[\p{L]] + [b])x}/', 'code' => $unknown, 'offset' => 19];
        yield 'nested class, a "]" then a byte no name holds' => ['pattern' => '/(?[[\p{L]é}])/', 'code' => $malformed, 'offset' => 10];
        yield 'nested class, a "]" then a character no name holds' => ['pattern' => '/(?[[\p{L]] ! [b])x}/', 'code' => $malformed, 'offset' => 12];
    }

    /**
     * PHP first, where it links the PCRE2 10.49 the rows were read on: a
     * PCRE2 before 10.45 refuses "(?[" itself, and one before 10.47 reports
     * most errors on the character at fault.
     */
    private function assertTheEngineRefusesAt(int $offset, string $pattern): void
    {
        if ('10.49' === PcreTarget::runtime()->pcreVersion) {
            $this->assertSame($offset, PhpErrorOffset::of($pattern), 'Oracle on PCRE2 10.49: '.$pattern);
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
