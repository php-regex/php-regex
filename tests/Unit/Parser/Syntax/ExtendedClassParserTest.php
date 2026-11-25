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
use PHPRegex\Parser\ParserOptions;
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
        // Where the range is refused, at the end of the POSIX item, its name
        // is not read yet; past a range that ended, the "-" is a member and
        // the name is judged.
        yield 'a range to an unknown POSIX class' => ['pattern' => '/(?[[a-[:foo:]])/', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 13];
        yield 'a range to an unknown POSIX class past a lone \E' => ['pattern' => '/(?[[a\E-[:foo:]])/', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 15];
        yield 'a range to an unknown POSIX class past empty quotes' => ['pattern' => '/(?[[a-\Q\E[:foo:]])/', 'code' => ErrorCode::RangeInvalidBounds, 'offset' => 17];
        yield 'an unknown POSIX class after a range that ended' => ['pattern' => '/(?[[a-c-[:foo:]])/', 'code' => ErrorCode::PosixInvalid, 'offset' => 15];
        // A range the pattern or the fault ends before its end is never
        // judged.
        yield 'class escape starting a range the pattern ends in' => ['pattern' => '/(?[[b\d-\E\c/u', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 12];
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
     * After an operand, past the spaces and tabs it skips and a lone "\E",
     * PCRE takes an operator, a ")" or the "]" that closes the class. What
     * would start an operand, a class, an escape, a "(" or a "!", is refused
     * as an expression with no operator before it; any other character as
     * one no extended class takes, a newline or a "#" under "x" included.
     * Each row is the error preg_match() reports on PHP 8.4.27 with PCRE2
     * 10.49.
     */
    #[Test]
    #[DataProvider('provideWhatFollowsAnOperand')]
    public function test_what_follows_an_operand_is_refused_as_pcre_names_it(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertTheEngineRefusesAt($offset, $pattern);

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideWhatFollowsAnOperand(): iterable
    {
        $character = ErrorCode::ExtendedClassUnexpectedCharacter;
        $operand = ErrorCode::ExtendedClassMissingOperator;

        yield 'a letter after a class escape' => ['pattern' => '/(?[\da])/', 'code' => $character, 'offset' => 6];
        yield 'a letter after a class escape and a space' => ['pattern' => '/(?[\d a])/', 'code' => $character, 'offset' => 7];
        yield 'a letter after a class escape and a lone \E' => ['pattern' => '/(?[\d\Ea])/', 'code' => $character, 'offset' => 8];
        yield 'a newline after a class escape' => ['pattern' => "/(?[\\d\na])/", 'code' => $character, 'offset' => 6];
        yield 'a byte above ASCII after a class escape' => ['pattern' => '/(?[\dé])/', 'code' => $character, 'offset' => 6];
        yield 'a character above ASCII after a class escape, under u' => ['pattern' => '/(?[\dé])/u', 'code' => $character, 'offset' => 7];
        yield 'a letter after a nested class' => ['pattern' => '/(?[[a]b])/', 'code' => $character, 'offset' => 7];
        yield 'a letter after a nested class and two spaces' => ['pattern' => '/(?[[a]  a])/', 'code' => $character, 'offset' => 9];
        yield 'a letter after a braced property' => ['pattern' => '/(?[\p{L} a])/', 'code' => $character, 'offset' => 10];
        yield 'a letter after a property letter' => ['pattern' => '/(?[\pLa])/', 'code' => $character, 'offset' => 7];
        yield 'a letter after a POSIX item' => ['pattern' => '/(?[[:alpha:]a])/', 'code' => $character, 'offset' => 13];
        yield 'a letter after a parenthesised expression' => ['pattern' => '/(?[(\d)a])/', 'code' => $character, 'offset' => 8];
        yield 'a letter after an operand in parentheses' => ['pattern' => '/(?[(\d a)])/', 'code' => $character, 'offset' => 8];
        yield 'a letter after a complement' => ['pattern' => '/(?[!\d a])/', 'code' => $character, 'offset' => 8];
        yield 'a comment start after a class escape, under x' => ['pattern' => '/(?[\d #c])/x', 'code' => $character, 'offset' => 7];
        yield 'a letter after a class escape and a space, under xx' => ['pattern' => '/(?[\d a])/xx', 'code' => $character, 'offset' => 7];

        // What would start an operand.
        yield 'a nested class after a class escape' => ['pattern' => '/(?[\d[a]])/', 'code' => $operand, 'offset' => 6];
        yield 'a class escape after a class escape' => ['pattern' => '/(?[\d\w])/', 'code' => $operand, 'offset' => 7];
        yield 'a POSIX item after a class escape' => ['pattern' => '/(?[\d[:alpha:]])/', 'code' => $operand, 'offset' => 14];
        yield 'a parenthesis after a class escape' => ['pattern' => '/(?[\d(\w)])/', 'code' => $operand, 'offset' => 6];
        yield 'a complement after a class escape' => ['pattern' => '/(?[\d!\w])/', 'code' => $operand, 'offset' => 6];
    }

    /**
     * A nested class the pattern leaves open is read item by item to the
     * end of the pattern: what PCRE refuses there, a "\c" or an escape the
     * pattern ends in, a range whose end is the last item, comes before the
     * missing "]". Nor does a "]" PCRE never reads close a POSIX item:
     * "[a-[:foo:" ranges from "a" to "[". Each row is the error
     * preg_match() reports on PHP 8.4.27 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideFaultsAtTheEndOfAClassLeftOpen')]
    public function test_a_fault_at_the_end_of_a_nested_class_left_open_is_reported(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertTheEngineRefusesAt($offset, $pattern);

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideFaultsAtTheEndOfAClassLeftOpen(): iterable
    {
        $invalidRange = ErrorCode::RangeInvalidBounds;

        yield 'a "\c" the pattern ends in' => ['pattern' => '/(?[[\c/', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 6];
        yield 'a "\c" the pattern ends in, under u' => ['pattern' => '/(?[[\c/u', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 6];
        yield 'a "\c" the pattern ends in, in parentheses' => ['pattern' => '/(?[([\c/', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 7];
        yield 'a "\c" before a character past ASCII, under u' => ['pattern' => '/(?[[\cé/u', 'code' => ErrorCode::ControlCharInvalid, 'offset' => 8];
        yield 'a range to a class escape the pattern ends in' => ['pattern' => '/(?[[a-\d/', 'code' => $invalidRange, 'offset' => 8];
        yield 'a range to a class escape the pattern ends in, under x' => ['pattern' => '/(?[[a-\d/x', 'code' => $invalidRange, 'offset' => 8];
        yield 'a range to a class escape after a space, under xx' => ['pattern' => '/(?[[a- \d/xx', 'code' => $invalidRange, 'offset' => 9];
        yield 'a range to a property the pattern ends in' => ['pattern' => '/(?[[a-\pL/', 'code' => $invalidRange, 'offset' => 9];
        yield 'a range to a POSIX class the pattern ends in' => ['pattern' => '/(?[[a-[:alpha:]/', 'code' => $invalidRange, 'offset' => 15];
        yield 'a range from a property to a POSIX class' => ['pattern' => '/(?[[\p{Lu}|-[:alpha:]/', 'code' => $invalidRange, 'offset' => 21];
        yield 'a range in a class after an operator' => ['pattern' => '/(?[\d+[a-\d/', 'code' => $invalidRange, 'offset' => 11];
        yield 'a range to an unknown POSIX class the pattern ends in' => ['pattern' => '/(?[[a-[:foo:]/', 'code' => $invalidRange, 'offset' => 13];
        yield 'an unknown POSIX class the pattern ends in, after a range that ended' => ['pattern' => '/(?[[a-c-[:foo:]/', 'code' => ErrorCode::PosixInvalid, 'offset' => 15];
        yield 'a range to a POSIX item no "]" closes' => ['pattern' => '/(?[[a-[:foo:/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
        yield 'a range from a class escape to a POSIX item no "]" closes' => ['pattern' => '/(?[[\d-[:foo:/', 'code' => $invalidRange, 'offset' => 7];
        yield 'a range to "\x{" the pattern ends in' => ['pattern' => '/(?[[\d-\x{/', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 10];
        yield 'a range to "\x" the pattern ends in' => ['pattern' => '/(?[[\d-\x/', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 9];
        yield 'a range to "\x{41" the pattern ends in' => ['pattern' => '/(?[[\d-\x{41/', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 12];
        yield 'a range to "\o{7" the pattern ends in' => ['pattern' => '/(?[[\d-\o{7/', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 11];
        // The escape ending a range is read before the range is judged: the
        // lexer's "z-o" or "z-x" is no range of PCRE's.
        yield 'a range from "z" to "\o{7", then text' => ['pattern' => '/(?[[z-\o{7)/', 'code' => ErrorCode::OctalInvalidDigit, 'offset' => 11];
        yield 'a range from "z" to "\x{4" the pattern ends in' => ['pattern' => '/(?[[z-\x{4/', 'code' => ErrorCode::UnicodeInvalidDigit, 'offset' => 10];
        yield 'a range from "z" to "\x{" the pattern ends in' => ['pattern' => '/(?[[z-\x{/', 'code' => ErrorCode::EscapeDigitsMissing, 'offset' => 9];
        yield 'a reversed range before an escape PCRE refuses' => ['pattern' => '/(?[[z-a\x{zz}/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];

        // Nothing refused before the end: the "]" is missing.
        yield 'a letter the pattern ends in' => ['pattern' => '/(?[[a/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 5];
        yield 'a quote the pattern ends in' => ['pattern' => '/(?[[a\Q/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 7];
        yield 'a reversed range the pattern ends in' => ['pattern' => '/(?[[z-a/', 'code' => ErrorCode::RangeReversed, 'offset' => 7];
    }

    /**
     * A nested class past the depth limit is refused on its "[", before
     * PCRE reads its members, whether it closes or not: a property name or
     * a class it leaves open, or a fault at its end, comes after the limit.
     * At the limit what PCRE refuses in it comes first, and a POSIX class
     * in place of a nested one opens no level. Each row is the error
     * preg_match() reports on PHP 8.4.27 with PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideNestedClassesAroundTheDepthLimit')]
    public function test_a_nested_class_past_the_depth_limit_is_refused_before_its_members(string $pattern, ErrorCode $code, int $offset): void
    {
        $this->assertTheEngineRefusesAt($offset, $pattern);

        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame([$code, $offset], [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, offset: int}>
     */
    public static function provideNestedClassesAroundTheDepthLimit(): iterable
    {
        $past = '/(?['.str_repeat('(', 14);
        $at = '/(?['.str_repeat('(', 13);
        $tooDeep = ErrorCode::ExtendedClassNestedTooDeep;

        yield 'a class past the limit whose property name never closes' => ['pattern' => $past.'[\p{L]/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class past the limit that never closes' => ['pattern' => $past.'[a/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class past the limit that never closes, holding a POSIX class' => ['pattern' => $past.'[[:alpha:]/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class past the limit that ends in "\c"' => ['pattern' => $past.'[\c/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class past the limit that ends in a range to a class escape' => ['pattern' => $past.'[a-\d/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class past the limit that ends in a quote' => ['pattern' => $past.'[\Qa/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class past the limit that closes' => ['pattern' => $past.'[\p{L}]/', 'code' => $tooDeep, 'offset' => 17];
        yield 'a class at the limit whose property name never closes' => ['pattern' => $at.'[\p{L]/', 'code' => ErrorCode::UnicodePropertyMalformed, 'offset' => 22];
        yield 'a class at the limit that never closes' => ['pattern' => $at.'[a/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 18];
        yield 'a class at the limit that never closes, holding a POSIX class' => ['pattern' => $at.'[[:alpha:]/', 'code' => ErrorCode::CharclassUnclosed, 'offset' => 26];
        yield 'a POSIX class where a level past the limit would open' => ['pattern' => $past.'[:alpha:]/', 'code' => ErrorCode::ExtendedClassUnclosed, 'offset' => 26];
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
            ParserOptions::DEFAULT_MAX_RECURSION_DEPTH,
            static fn (array $tokens, bool $counted): NodeInterface => (new TokenParser(ParserOptions::DEFAULT_MAX_RECURSION_DEPTH, $target))
                ->parse(new TokenStream($tokens, $body), '', '/', \strlen($body))->pattern,
            static function (int $at, int $parentheses): void {},
        );

        return $parser->parse($token);
    }
}
