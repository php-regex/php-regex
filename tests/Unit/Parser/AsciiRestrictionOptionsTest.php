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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 10.43 added the ASCII-restriction options: "a" alone, or followed by
 * one of "D", "S", "W", "P", "T", anywhere among the letters of "(?...)".
 * PHP 8.4 bundles 10.44 and takes them; PHP 8.2 and 8.3 bundle 10.40 and
 * 10.42, which refuse them on the "a" (error 111, pcre2test).
 *
 * Refused offsets: PCRE2 10.48 reports past the letter it cannot read, 10.44
 * on it.
 */
final class AsciiRestrictionOptionsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAsciiOptions')]
    public function test_validate_accepts_ascii_options_from_pcre2_10_43(string $pattern): void
    {
        if (self::runningPcre1043()) {
            $this->assertNotFalse(@preg_match($pattern, ''), \sprintf('%s should compile.', $pattern));
        }

        foreach (self::readersOfPcre1043() as $regex) {
            $result = $regex->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
        }
    }

    #[Test]
    #[DataProvider('provideAsciiOptions')]
    public function test_validate_refuses_ascii_options_before_php_8_4(string $pattern): void
    {
        foreach ([80200, 80300] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP %d.', $pattern, $phpVersion));
        }
    }

    /**
     * @param list<int> $offsets
     */
    #[Test]
    #[DataProvider('provideMalformedAsciiOptions')]
    public function test_validate_refuses_malformed_ascii_options(string $pattern, array $offsets): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        foreach (self::readersOfPcre1043() as $regex) {
            $result = $regex->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
            $this->assertContains($result->offset, $offsets, \sprintf('%s reported at offset %s, PCRE2 reports %s.', $pattern, var_export($result->offset, true), implode(' or ', $offsets)));
        }
    }

    #[Test]
    #[DataProvider('provideAsciiOptionsWithX')]
    public function test_compiling_keeps_the_modifiers_next_to_ascii_options(string $pattern): void
    {
        // "(?aDx)" still turns x on: the space and the comment go.
        $compiled = Regex::create(['php_version' => 80400])->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame($pattern, $compiled);
        if (self::runningPcre1043()) {
            foreach (['ab', 'a b'] as $subject) {
                $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), $compiled);
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAsciiOptionsWithX(): iterable
    {
        yield 'x after an ASCII option' => ['pattern' => "/^(?aDx)a b # c\n$/"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAsciiOptions(): iterable
    {
        yield 'a alone' => ['pattern' => '/(?a)/'];
        yield 'a with D' => ['pattern' => '/(?aD)/'];
        yield 'a with S' => ['pattern' => '/(?aS)/'];
        yield 'a with W' => ['pattern' => '/(?aW)/'];
        yield 'a with P' => ['pattern' => '/(?aP)/'];
        yield 'a with T' => ['pattern' => '/(?aT)/'];
        yield 'two options' => ['pattern' => '/(?aDaS)/'];
        yield 'twice a' => ['pattern' => '/(?aa)/'];
        yield 'scoped' => ['pattern' => '/(?aD:x)/'];
        yield 'turned off' => ['pattern' => '/(?-aD)/'];
        yield 'with other letters' => ['pattern' => '/(?iaDm)/'];
        yield 'turning i off' => ['pattern' => '/(?a-i)/'];
        yield 'after a caret' => ['pattern' => '/(?^aDi)/'];
        yield 'nothing turned off' => ['pattern' => '/(?a-)/'];
        yield 'x still read after a' => ['pattern' => '/(?ax)#)/'];
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: list<int>}>
     */
    public static function provideMalformedAsciiOptions(): iterable
    {
        yield 'unknown letter after a' => ['pattern' => '/(?aX)/', 'offsets' => [4, 3]];
        yield 'D without a' => ['pattern' => '/(?D)/', 'offsets' => [3, 2]];
        yield 'two class letters' => ['pattern' => '/(?aDS)/', 'offsets' => [5, 4]];
        yield 'two class letters turned off' => ['pattern' => '/(?-aDS)/', 'offsets' => [6, 5]];
    }

    private static function runningPcre1043(): bool
    {
        return version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');
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
        if (self::runningPcre1043()) {
            $readers[] = Regex::create(['cache' => null]);
        }

        return $readers;
    }
}
