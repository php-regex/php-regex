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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spaces or tabs right after the "U+" of "\N{U+...}".
 *
 * From PCRE2 10.43, the release that brought padded braces, padding may only
 * run up to the closing brace: "\N{U+ }" compiles, and any other character
 * after the padding is error 167. PCRE2 10.48 reports it past that
 * character (or at the end of the pattern), 10.44 on it. Before 10.43
 * (10.40 and 10.42, which PHP 8.2 and 8.3 bundle), padding there is refused
 * outright, on the first padding character.
 */
final class NamedCodePointPaddingTest extends TestCase
{
    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideRefusedPadding')]
    public function test_validate_refuses_padding_not_closed_by_a_brace(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        foreach (self::readersOfPcre1043() as $regex) {
            $result = $regex->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
            $this->assertSame(ErrorCode::UnicodeInvalidDigit, $result->errorCode);
            $this->assertContains($result->offset, $offsets, \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideRefusedPadding(): iterable
    {
        yield 'padding up to the end' => ['pattern' => '/\\N{U+ /u', 'offsets' => [6, 5]];
        yield 'padding then a digit' => ['pattern' => '/\\N{U+ 4/u', 'offsets' => [7, 6]];
        yield 'padding then a letter and a brace' => ['pattern' => '/\\N{U+ z}/u', 'offsets' => [7, 6]];
        yield 'padding then a digit and a brace' => ['pattern' => '/\\N{U+ 41}/u', 'offsets' => [7, 6]];
        yield 'padding then the end of a class' => ['pattern' => '/[\\N{U+ ]/u', 'offsets' => [8, 7]];
        yield 'padding before the end of a class under x' => ['pattern' => '/\\N{U+ ~/ux', 'offsets' => [7, 6]];
    }

    #[Test]
    #[DataProvider('providePaddingClosedByABrace')]
    public function test_validate_accepts_padding_closed_by_a_brace_from_pcre2_10_43(string $pattern): void
    {
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=')) {
            $this->assertSame(0, @preg_match($pattern, ''), \sprintf('%s compiles from PCRE2 10.43.', $pattern));
        }

        foreach (self::readersOfPcre1043() as $regex) {
            $this->assertTrue($regex->validate($pattern)->isValid, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePaddingClosedByABrace(): iterable
    {
        yield 'space' => ['pattern' => '/\\N{U+ }/u'];
        yield 'space, tab and space' => ['pattern' => "/\\N{U+ \t }/u"];
        yield 'in a class' => ['pattern' => '/[\\N{U+ }]/u'];
    }

    #[Test]
    public function test_validate_refuses_any_padding_after_u_plus_before_php_8_4(): void
    {
        // pcre2test 10.40 and 10.42: "\N{U+ }" is error 167 at offset 5, on
        // the space; "[\N{U+ }]" at 6.
        foreach (['/\\N{U+ }/u' => 5, '/\\N{U+ 41}/u' => 5, '/[\\N{U+ }]/u' => 6] as $pattern => $offset) {
            foreach ([80200, 80300] as $phpVersion) {
                $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

                $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP %d.', $pattern, $phpVersion));
                $this->assertSame($offset, $result->offset, $pattern);
            }
        }
    }

    /**
     * PHP 8.4 as a target, which bundles PCRE2 10.44, and the running PHP
     * when the PCRE2 it links is 10.43 or newer.
     *
     * @return list<\PhpRegex\Toolkit\Regex>
     */
    private static function readersOfPcre1043(): array
    {
        $readers = [Regex::create(['cache' => null, 'php_version' => 80400])];
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=')) {
            $readers[] = Regex::create(['cache' => null]);
        }

        return $readers;
    }
}
