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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 knows the alphabetic assertions and script runs, "(*pla:...)",
 * "(*atomic:...)", "(*sr:...)" and the others, in lowercase only: any other
 * spelling is an unknown verb, refused at the colon (PHP on PCRE2 10.42 and
 * 10.48 agree on every offset below).
 */
final class AlphabeticNameCaseTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOtherCases')]
    public function test_validate_refuses_an_alphabetic_name_not_in_lowercase(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame(ErrorCode::VerbInvalid, $result->errorCode, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideOtherCases(): iterable
    {
        yield 'uppercase lookahead' => ['pattern' => '/(*PLA:a)/', 'offset' => 5];
        yield 'mixed-case lookahead' => ['pattern' => '/(*pLa:a)/', 'offset' => 5];
        yield 'empty uppercase lookahead' => ['pattern' => '/(*PLA:)/', 'offset' => 5];
        yield 'long uppercase name' => ['pattern' => '/(*Positive_Lookahead:a)/', 'offset' => 20];
        yield 'capitalized atomic group' => ['pattern' => '/(*Atomic:a)/', 'offset' => 8];
        yield 'uppercase script run' => ['pattern' => '/(*SR:a)/', 'offset' => 4];
        yield 'capitalized script run' => ['pattern' => '/(*Sr:a)/', 'offset' => 4];
        yield 'uppercase long script run' => ['pattern' => '/a(*SCRIPT_RUN:a)/', 'offset' => 13];
    }

    #[Test]
    public function test_validate_accepts_the_lowercase_names(): void
    {
        foreach (['/(*pla:a)/', '/(*atomic:a)/', '/(*sr:a)/', '/(*script_run:a)/', '/(*positive_lookahead:a)/'] as $pattern) {
            $this->assertSame(0, @preg_match($pattern, ''), \sprintf('%s should compile.', $pattern));
            $this->assertTrue(Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
        }
    }
}
