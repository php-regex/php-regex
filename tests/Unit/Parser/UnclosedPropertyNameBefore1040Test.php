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
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A braced property name left open: PCRE2 10.39 reads at most 31 bytes of
 * it, spaces, "_" and "-" included, past a leading "^"; 10.40 reads up to
 * 49 characters, skipping spaces, "_" and "-". Each offset is preg_match()'s
 * on php:8.1-cli (PCRE2 10.39) and php:8.2-cli (10.40).
 */
final class UnclosedPropertyNameBefore1040Test extends TestCase
{
    #[Test]
    #[DataProvider('provideUnclosedNames')]
    public function test_an_unclosed_name_is_malformed_where_the_release_stops_reading(string $pattern, int $before, int $from): void
    {
        foreach (['10.39' => $before, '10.40' => $from] as $release => $offset) {
            $validation = RegexParser::create(['php_version' => 80100, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

            $this->assertFalse($validation->isValid, $pattern.' on PCRE2 '.$release);
            $this->assertSame(ErrorCode::UnicodePropertyMalformed, $validation->errorCode, $pattern.' on PCRE2 '.$release);
            $this->assertSame($offset, $validation->offset, $pattern.' on PCRE2 '.$release);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, before: int, from: int}>
     */
    public static function provideUnclosedNames(): iterable
    {
        $a = static fn (int $count): string => str_repeat('a', $count);

        yield 'short name, to the end' => ['pattern' => '/\p{'.$a(20).'/', 'before' => 23, 'from' => 23];
        yield 'thirty-one letters' => ['pattern' => '/\p{'.$a(31).'/', 'before' => 34, 'from' => 34];
        yield 'sixty letters' => ['pattern' => '/\p{'.$a(60).'/', 'before' => 34, 'from' => 52];
        yield 'negated' => ['pattern' => '/\P{^'.$a(40).'/', 'before' => 35, 'from' => 44];
        yield 'in a class' => ['pattern' => '/[\p{'.$a(40).'/', 'before' => 35, 'from' => 44];
        yield 'after a letter' => ['pattern' => '/x\p{'.$a(40).'/', 'before' => 35, 'from' => 44];
        yield 'spaces count before 10.40' => ['pattern' => '/\p{a b '.$a(40).'/', 'before' => 34, 'from' => 47];
        yield 'underscore and hyphen count before 10.40' => ['pattern' => '/\p{a_b-'.$a(40).'/', 'before' => 34, 'from' => 47];
        yield 'leading space' => ['pattern' => '/\p{ '.$a(40).'/', 'before' => 34, 'from' => 44];
        yield 'space after the caret' => ['pattern' => '/\p{^ '.$a(40).'/', 'before' => 35, 'from' => 45];
        yield 'bytes before 10.40' => ['pattern' => '/\p{'.str_repeat('é', 20).'/u', 'before' => 34, 'from' => 43];
    }
}
