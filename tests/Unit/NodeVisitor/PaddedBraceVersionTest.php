<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * Spaces padding the inside of \x{...}, \o{...}, \N{U+...} and a \N{n}
 * repeat arrived in PCRE2 10.43: pcre2test 10.40 and 10.42 refuse every
 * padded row below (errors 137, 164, 167), 10.44 accepts them. php-src
 * bundles 10.40 in PHP 8.2, 10.42 in 8.3 and 10.44 from 8.4.
 */
final class PaddedBraceVersionTest extends TestCase
{
    #[Test]
    #[DataProvider('providePaddedEscapes')]
    public function test_padded_escape_is_refused_before_php_8_4(string $pattern): void
    {
        foreach (['8.2', '8.3'] as $phpVersion) {
            $this->assertFalse(Regex::create(['php_version' => $phpVersion])->validate($pattern)->isValid, \sprintf('%s does not compile on the PCRE2 PHP %s bundles.', $pattern, $phpVersion));
        }
    }

    #[Test]
    #[DataProvider('providePaddedEscapes')]
    public function test_padded_escape_is_accepted_from_php_8_4(string $pattern): void
    {
        foreach (['8.4', '8.5'] as $phpVersion) {
            $result = Regex::create(['php_version' => $phpVersion])->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s compiles on the PCRE2 PHP %s bundles: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    #[Test]
    public function test_a_space_before_u_plus_is_refused_as_a_name(): void
    {
        // pcre2test 10.40 and 10.42: "\N{" then a space is a name, error 137
        // past the "\N", as for "\N{foo}".
        $regex = Regex::create(['cache' => null, 'php_version' => '8.2']);

        $this->assertSame(2, $regex->validate('/\\N{ U+1234 }/u')->offset);
        $this->assertSame(4, $regex->validate('/ab\\N{ U+41}/u')->offset);
        $this->assertSame('regex.escape.unsupported', $regex->validate('/ab\\N{ U+41}/u')->errorCode);
        $this->assertSame(6, $regex->validate('/a\\N{U+ 41}/u')->offset);
        $this->assertSame('regex.unicode.invalid_digit', $regex->validate('/a\\N{U+ 41}/u')->errorCode);

        // Elsewhere the first space is reported.
        $this->assertSame(3, $regex->validate('/\\x{ 41}/')->offset);
        $this->assertSame('regex.unicode.invalid_digit', $regex->validate('/\\x{ 41}/')->errorCode);
        $this->assertSame(3, $regex->validate('/\\o{ 101}/')->offset);
        $this->assertSame('regex.octal.invalid_digit', $regex->validate('/\\o{ 101}/')->errorCode);
    }

    #[Test]
    #[DataProvider('providePlainEscapes')]
    public function test_unpadded_escape_is_accepted_everywhere(string $pattern): void
    {
        foreach (['8.2', '8.3', '8.4'] as $phpVersion) {
            $this->assertTrue(Regex::create(['php_version' => $phpVersion])->validate($pattern)->isValid, $pattern.' on '.$phpVersion);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePaddedEscapes(): iterable
    {
        yield 'padded octal: /\\o{ 101 }/' => ['pattern' => '/\\o{ 101 }/'];
        yield 'trailing space in hex: /\\x{41 }/' => ['pattern' => '/\\x{41 }/'];
        yield 'leading space in hex: /\\x{ 41}/' => ['pattern' => '/\\x{ 41}/'];
        yield 'padded hex in a class: /[\\x{ 41 }]/' => ['pattern' => '/[\\x{ 41 }]/'];
        yield 'trailing space in a code point: /\\N{U+41 }/u' => ['pattern' => '/\\N{U+41 }/u'];
        yield 'space before U+: /\\N{ U+41}/u' => ['pattern' => '/\\N{ U+41}/u'];
        yield 'padded repeat of \\N: /\\N{4 }/' => ['pattern' => '/\\N{4 }/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePlainEscapes(): iterable
    {
        yield 'hex: /\\x{41}/' => ['pattern' => '/\\x{41}/'];
        yield 'octal: /\\o{101}/' => ['pattern' => '/\\o{101}/'];
        yield 'code point: /\\N{U+41}/u' => ['pattern' => '/\\N{U+41}/u'];
        yield 'repeat of \\N: /\\N{4}/' => ['pattern' => '/\\N{4}/'];
    }
}
