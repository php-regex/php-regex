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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\Callout;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading "(?C...)": its number or string, and where PCRE refuses one.
 */
final class CalloutTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCallouts')]
    public function test_read_gives_the_number_or_the_string(string $pattern, string $value, int|string|null $identifier, bool $isString): void
    {
        $node = Callout::read(new Token(TokenType::Callout, $value, 0), $pattern, new PcreTarget(80400, '10.49'));

        $this->assertSame([$identifier, $isString, 0, \strlen($pattern)], [$node->identifier, $node->isStringIdentifier, $node->startPosition, $node->endPosition]);
    }

    /**
     * @return iterable<string, array{pattern: string, value: string, identifier: int|string|null, isString: bool}>
     */
    public static function provideCallouts(): iterable
    {
        yield 'no argument' => ['pattern' => '(?C)', 'value' => '', 'identifier' => null, 'isString' => false];
        yield 'a number' => ['pattern' => '(?C12)', 'value' => '12', 'identifier' => 12, 'isString' => false];
        yield 'a quoted string' => ['pattern' => '(?C"ab")', 'value' => '"ab"', 'identifier' => 'ab', 'isString' => true];
        yield 'a braced string' => ['pattern' => '(?C{ab})', 'value' => '{ab}', 'identifier' => 'ab', 'isString' => true];
        // A doubled closing delimiter stands for itself.
        yield 'a doubled delimiter' => ['pattern' => '(?C{a}}b})', 'value' => '{a}}b}', 'identifier' => 'a}b', 'isString' => true];
        yield 'an empty string' => ['pattern' => '(?C``)', 'value' => '``', 'identifier' => '', 'isString' => true];
    }

    #[Test]
    #[DataProvider('provideRefusedCallouts')]
    public function test_read_refuses_what_pcre_refuses(string $pattern, string $value, string $release, ErrorCode $code, int $offset, string $message): void
    {
        try {
            Callout::read(new Token(TokenType::Callout, $value, 0), $pattern, new PcreTarget(80400, $release));
            $this->fail('No error for '.$pattern);
        } catch (ParserException $error) {
            $this->assertSame([$code, $offset, $message], [$error->errorCode, $error->position, $error->getMessage()]);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, value: string, release: string, code: ErrorCode, offset: int, message: string}>
     */
    public static function provideRefusedCallouts(): iterable
    {
        // PCRE2 10.49: "/(?Cab)/" at offset 4, "/(?C"a"b)/" at 6,
        // "/(?C"ab)/" at 3, "/(?C12x)/" at 5, "/(?C256)/" at 6.
        yield 'no delimiter' => [
            'pattern' => '(?Cab)', 'value' => 'ab', 'release' => '10.49', 'code' => ErrorCode::CalloutInvalidDelimiter, 'offset' => 4,
            'message' => 'Invalid callout argument: ab at position 4: a callout takes a number, or a string opened by one of ` \' " ^ % # $ {.',
        ];
        yield 'no delimiter, before 10.47' => [
            'pattern' => '(?Cab)', 'value' => 'ab', 'release' => '10.44', 'code' => ErrorCode::CalloutInvalidDelimiter, 'offset' => 3,
            'message' => 'Invalid callout argument: ab at position 3: a callout takes a number, or a string opened by one of ` \' " ^ % # $ {.',
        ];
        yield 'text after the string' => [
            'pattern' => '(?C"a"b)', 'value' => '"a"b', 'release' => '10.49', 'code' => ErrorCode::CalloutUnclosed, 'offset' => 6,
            'message' => 'Invalid callout argument: "a"b at position 6: ")" is expected after the callout argument.',
        ];
        yield 'a string never closed' => [
            'pattern' => '(?C"ab)', 'value' => '"ab)', 'release' => '10.49', 'code' => ErrorCode::CalloutUnclosedString, 'offset' => 3,
            'message' => 'Invalid callout argument: "ab) at position 3: the callout string is not closed by its delimiter.',
        ];
        yield 'text after the number' => [
            'pattern' => '(?C12x)', 'value' => '12x', 'release' => '10.49', 'code' => ErrorCode::CalloutUnclosed, 'offset' => 5,
            'message' => 'Invalid callout argument: 12x at position 5: ")" is expected after the callout argument.',
        ];
        yield 'a number too big and text' => [
            'pattern' => '(?C256x)', 'value' => '256x', 'release' => '10.49', 'code' => ErrorCode::CalloutOutOfRange, 'offset' => 6,
            'message' => 'Invalid callout argument: 256x at position 6: the callout number is greater than 255.',
        ];
        yield 'many digits and text' => [
            'pattern' => '(?C0001x)', 'value' => '0001x', 'release' => '10.49', 'code' => ErrorCode::CalloutUnclosed, 'offset' => 7,
            'message' => 'Invalid callout argument: 0001x at position 7: ")" is expected after the callout argument.',
        ];
    }

    /**
     * @param array{0: int, 1: ErrorCode}|null $fault
     */
    #[Test]
    #[DataProvider('provideFaults')]
    public function test_fault_is_where_pcre_stops_reading_the_callout(string $pattern, int $start, string $release, ?array $fault): void
    {
        $this->assertSame($fault, Callout::fault($pattern, $start, new PcreTarget(80400, $release)));
    }

    /**
     * @return iterable<string, array{pattern: string, start: int, release: string, fault: array{int, ErrorCode}|null}>
     */
    public static function provideFaults(): iterable
    {
        yield 'a number' => ['pattern' => '(?C1)', 'start' => 0, 'release' => '10.49', 'fault' => null];
        yield 'a string' => ['pattern' => 'a(?C"x")', 'start' => 1, 'release' => '10.49', 'fault' => null];
        yield 'a doubled delimiter' => ['pattern' => '(?C{a}}b})', 'start' => 0, 'release' => '10.49', 'fault' => null];
        yield 'no argument' => ['pattern' => '(?C)', 'start' => 0, 'release' => '10.49', 'fault' => null];
        yield 'the pattern ends after "(?C"' => ['pattern' => '(?C', 'start' => 0, 'release' => '10.49', 'fault' => [3, ErrorCode::GroupUnclosed]];
        yield 'a number past 255' => ['pattern' => 'a(?C256)', 'start' => 1, 'release' => '10.49', 'fault' => [7, ErrorCode::CalloutOutOfRange]];
        yield 'the largest number' => ['pattern' => '(?C255', 'start' => 0, 'release' => '10.49', 'fault' => [6, ErrorCode::CalloutUnclosed]];
        yield 'no delimiter' => ['pattern' => '(?Cab)', 'start' => 0, 'release' => '10.49', 'fault' => [4, ErrorCode::CalloutInvalidDelimiter]];
        yield 'no delimiter, before 10.47' => ['pattern' => '(?Cab)', 'start' => 0, 'release' => '10.46', 'fault' => [3, ErrorCode::CalloutInvalidDelimiter]];
        yield 'a string never closed' => ['pattern' => '(?C"ab', 'start' => 0, 'release' => '10.49', 'fault' => [3, ErrorCode::CalloutUnclosedString]];
        yield 'a doubled delimiter the pattern ends in' => ['pattern' => '(?C"a""', 'start' => 0, 'release' => '10.49', 'fault' => [3, ErrorCode::CalloutUnclosedString]];
        yield 'text after the string' => ['pattern' => '(?C"a"b)', 'start' => 0, 'release' => '10.49', 'fault' => [6, ErrorCode::CalloutUnclosed]];
        yield 'the pattern ends after the string' => ['pattern' => '(?C"a"', 'start' => 0, 'release' => '10.49', 'fault' => [6, ErrorCode::CalloutUnclosed]];
    }

    /**
     * The running engine stops where the library says, on its own release.
     */
    #[Test]
    #[DataProvider('provideRunningEngineCases')]
    public function test_the_fault_is_that_of_the_running_engine(string $pattern, int $start): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf('/'.$pattern.'/') ?? 'compiles');

        $this->assertSame($pcre['offset'], Callout::fault($pattern, $start, PcreTarget::runtime())[0] ?? null, 'Oracle: '.$pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, start: int}>
     */
    public static function provideRunningEngineCases(): iterable
    {
        yield 'a number past 255' => ['pattern' => 'a(?C256)', 'start' => 1];
        yield 'no delimiter' => ['pattern' => '(?Cab)', 'start' => 0];
        yield 'a string never closed' => ['pattern' => '(?C"ab)', 'start' => 0];
        yield 'text after the string' => ['pattern' => '(?C"a"b)', 'start' => 0];
        yield 'text after the number' => ['pattern' => '(?C12x)', 'start' => 0];
        yield 'the pattern ends after "(?C"' => ['pattern' => '(?C', 'start' => 0];
    }

    #[Test]
    public function test_problem_says_what_is_wrong_in_words(): void
    {
        $this->assertSame('the callout number is greater than 255', Callout::problem(ErrorCode::CalloutOutOfRange));
        $this->assertSame('a callout takes a number, or a string opened by one of ` \' " ^ % # $ {', Callout::problem(ErrorCode::CalloutInvalidDelimiter));
        $this->assertSame('the callout string is not closed by its delimiter', Callout::problem(ErrorCode::CalloutUnclosedString));
        $this->assertSame('")" is expected after the callout argument', Callout::problem(ErrorCode::CalloutUnclosed));
        $this->assertSame('")" is expected to close the callout', Callout::problem(ErrorCode::GroupUnclosed));
    }

    #[Test]
    #[DataProvider('provideStringDelimiters')]
    public function test_every_string_delimiter_closes_with_its_own_or_its_pair(string $opening, string $closing): void
    {
        $value = $opening.'x'.$closing;
        $pattern = '(?C'.$value.')';

        $this->assertSame('x', Callout::read(new Token(TokenType::Callout, $value, 0), $pattern, PcreTarget::runtime())->identifier);
        $this->assertNull(Callout::fault($pattern, 0, PcreTarget::runtime()), 'Oracle: '.$pattern);
        $this->assertNull(PcreMessageCodes::warningOf('/'.$pattern.'/'), 'Oracle: '.$pattern);
    }

    /**
     * @return iterable<string, array{opening: string, closing: string}>
     */
    public static function provideStringDelimiters(): iterable
    {
        foreach (Callout::STRING_DELIMITERS as $opening => $closing) {
            yield $opening => ['opening' => (string) $opening, 'closing' => $closing];
        }
    }
}
