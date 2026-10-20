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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\RepeatCount;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where PCRE refuses the numbers of a count, and a quantifier with nothing
 * to repeat, for each release.
 */
final class RepeatCountTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCountFaults')]
    public function test_fault_offset_is_where_pcre_stops_reading_the_count(string $count, int $position, string $release, ?int $offset): void
    {
        $this->assertSame($offset, RepeatCount::faultOffset(new Token(TokenType::Quantifier, $count, $position), new PcreTarget(80400, $release)));
    }

    /**
     * @return iterable<string, array{count: string, position: int, release: string, offset: int|null}>
     */
    public static function provideCountFaults(): iterable
    {
        // PCRE2 10.49 reports "/a{2,1}/" at offset 5, past the maximum.
        yield 'out of order' => ['count' => '{2,1}', 'position' => 1, 'release' => '10.49', 'offset' => 5];
        yield 'out of order, before 10.47' => ['count' => '{2,1}', 'position' => 1, 'release' => '10.44', 'offset' => 5];
        // "/a{1,65536}/": past the whole maximum.
        yield 'a maximum too big' => ['count' => '{1,65536}', 'position' => 1, 'release' => '10.49', 'offset' => 9];
        // "/a{700000}/": past the whole number from 10.45, past the digit
        // that takes it over 65535 before.
        yield 'a minimum too big' => ['count' => '{700000}', 'position' => 1, 'release' => '10.45', 'offset' => 8];
        yield 'a minimum too big, before 10.45' => ['count' => '{700000}', 'position' => 1, 'release' => '10.44', 'offset' => 7];
        // From 10.43 a count may be padded: "/a{ 2 , 1 }/" at offset 8.
        yield 'padded, out of order' => ['count' => '{ 2 , 1 }', 'position' => 1, 'release' => '10.49', 'offset' => 8];
        yield 'padded, before 10.43' => ['count' => '{ 2 , 1 }', 'position' => 1, 'release' => '10.42', 'offset' => null];
        yield 'open, from 10.43' => ['count' => '{,2}', 'position' => 1, 'release' => '10.43', 'offset' => null];
        yield 'open, too big' => ['count' => '{,65536}', 'position' => 1, 'release' => '10.43', 'offset' => 8];
        yield 'open, before 10.43' => ['count' => '{,65536}', 'position' => 1, 'release' => '10.42', 'offset' => null];
        yield 'within' => ['count' => '{2,3}', 'position' => 1, 'release' => '10.49', 'offset' => null];
        yield 'equal bounds' => ['count' => '{3,3}', 'position' => 1, 'release' => '10.49', 'offset' => null];
        yield 'no maximum' => ['count' => '{2,}', 'position' => 1, 'release' => '10.49', 'offset' => null];
        yield 'not a count' => ['count' => '*', 'position' => 1, 'release' => '10.49', 'offset' => null];
        yield 'empty braces' => ['count' => '{}', 'position' => 1, 'release' => '10.49', 'offset' => null];
        yield 'lazy, out of order' => ['count' => '{2,1}?', 'position' => 0, 'release' => '10.49', 'offset' => 4];
    }

    #[Test]
    #[DataProvider('provideCountCodes')]
    public function test_fault_code_tells_a_number_too_big_from_numbers_out_of_order(string $count, ErrorCode $code): void
    {
        $this->assertSame($code, RepeatCount::faultCode($count));
    }

    /**
     * @return iterable<string, array{count: string, code: ErrorCode}>
     */
    public static function provideCountCodes(): iterable
    {
        yield 'out of order' => ['count' => '{2,1}', 'code' => ErrorCode::QuantifierInvalidRange];
        yield 'the largest number' => ['count' => '{65535,1}', 'code' => ErrorCode::QuantifierInvalidRange];
        yield 'one past it' => ['count' => '{65536}', 'code' => ErrorCode::QuantifierTooBig];
        yield 'leading zeros' => ['count' => '{0000065535,1}', 'code' => ErrorCode::QuantifierInvalidRange];
        yield 'six digits' => ['count' => '{100000}', 'code' => ErrorCode::QuantifierTooBig];
        yield 'the maximum too big' => ['count' => '{1,99999}', 'code' => ErrorCode::QuantifierTooBig];
    }

    #[Test]
    #[DataProvider('provideNothingToRepeat')]
    public function test_nothing_to_repeat_is_reported_before_a_lazy_or_possessive_suffix(string $quantifier, int $position, string $release, int $offset): void
    {
        $this->assertSame($offset, RepeatCount::nothingToRepeatOffset(new Token(TokenType::Quantifier, $quantifier, $position), new PcreTarget(80400, $release)));
    }

    /**
     * @return iterable<string, array{quantifier: string, position: int, release: string, offset: int}>
     */
    public static function provideNothingToRepeat(): iterable
    {
        // PCRE2 10.49: "/*/" and "/*+/" at offset 1, "/{2}/" and "/{2}?/" at 3.
        yield 'a star' => ['quantifier' => '*', 'position' => 0, 'release' => '10.49', 'offset' => 1];
        yield 'a possessive star' => ['quantifier' => '*+', 'position' => 0, 'release' => '10.49', 'offset' => 1];
        yield 'a count' => ['quantifier' => '{2}', 'position' => 0, 'release' => '10.49', 'offset' => 3];
        yield 'a lazy count' => ['quantifier' => '{2}?', 'position' => 0, 'release' => '10.49', 'offset' => 3];
        yield 'a star, before 10.47' => ['quantifier' => '*', 'position' => 0, 'release' => '10.44', 'offset' => 0];
        yield 'a lazy count, before 10.47' => ['quantifier' => '{2}?', 'position' => 0, 'release' => '10.44', 'offset' => 2];
        // The numbers are read first: "{2,1}" is refused past them.
        yield 'a count out of order' => ['quantifier' => '{2,1}', 'position' => 0, 'release' => '10.49', 'offset' => 4];
    }

    #[Test]
    #[DataProvider('provideTooBig')]
    public function test_too_big_offset_is_past_the_number_from_pcre2_10_45(string $digits, string $release, ?int $offset): void
    {
        $this->assertSame($offset, RepeatCount::tooBigOffset($digits, 2, new PcreTarget(80400, $release)));
    }

    /**
     * @return iterable<string, array{digits: string, release: string, offset: int|null}>
     */
    public static function provideTooBig(): iterable
    {
        yield 'the largest number' => ['digits' => '65535', 'release' => '10.49', 'offset' => null];
        yield 'leading zeros' => ['digits' => '0065535', 'release' => '10.49', 'offset' => null];
        yield 'one past it' => ['digits' => '65536', 'release' => '10.49', 'offset' => 7];
        yield 'one past it, before 10.45' => ['digits' => '65536', 'release' => '10.44', 'offset' => 7];
        yield 'over at the fifth digit' => ['digits' => '700000', 'release' => '10.45', 'offset' => 8];
        yield 'over at the fifth digit, before 10.45' => ['digits' => '700000', 'release' => '10.44', 'offset' => 7];
        yield 'none' => ['digits' => '', 'release' => '10.49', 'offset' => null];
    }

    /**
     * The running engine says the same for the target it is.
     */
    #[Test]
    #[DataProvider('provideRunningEngineCases')]
    public function test_the_offsets_are_those_of_the_running_engine(string $pattern, string $count, int $position): void
    {
        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $token = new Token(TokenType::Quantifier, $count, $position);
        $target = PcreTarget::runtime();

        $this->assertSame($pcre['offset'], RepeatCount::faultOffset($token, $target) ?? RepeatCount::nothingToRepeatOffset($token, $target), 'Oracle: '.$pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, count: string, position: int}>
     */
    public static function provideRunningEngineCases(): iterable
    {
        yield 'out of order' => ['pattern' => '/a{2,1}/', 'count' => '{2,1}', 'position' => 1];
        yield 'a maximum too big' => ['pattern' => '/a{1,65536}/', 'count' => '{1,65536}', 'position' => 1];
        yield 'a minimum too big' => ['pattern' => '/a{700000}/', 'count' => '{700000}', 'position' => 1];
        yield 'nothing to repeat' => ['pattern' => '/*/', 'count' => '*', 'position' => 0];
        yield 'a lazy count with nothing to repeat' => ['pattern' => '/{2}?/', 'count' => '{2}?', 'position' => 0];
    }
}
