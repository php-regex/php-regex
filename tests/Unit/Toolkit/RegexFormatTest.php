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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Printer\FormatRefusal;
use PHPRegex\Parser\Printer\FormatResult;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * format() and compact() on the facade, next to optimize() and explain():
 * a pattern string in, a FormatResult out, so a caller can tell a pattern
 * that was refused from one that was already fine.
 */
final class RegexFormatTest extends TestCase
{
    private const URL = '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#i';

    private const URL_FORMATTED = <<<'REGEX'
        #
            ^
            (?<scheme>https?)
            ://
            (?:
                (?<user>[^:@/]+)
                (?::(?<pass>[^@/]*))?
                @
            )?
            (?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)
            (?::(?<port>\d+))?
            (?<path>/[^?\#]*)?
            (?:\?(?<query>[^\#]*))?
            (?:\#(?<fragment>.*))?
            $
        #ix
        REGEX;

    /**
     * 47 bytes: it fits the default width and breaks at 24.
     */
    private const DATE = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/';

    private const DATE_AT_24 = <<<'REGEX'
        /
            ^
            (?<year>\d{4})
            -
            (?<month>\d{2})
            -
            (?<day>\d{2})
            $
        /x
        REGEX;

    private const LAID_OUT = <<<'REGEX'
        /
            (?<year>\d{4})   # year
            -
            (?<month>\d{2})  # month
        /x
        REGEX;

    /**
     * 91 bytes, not under x: too long to fit, and a{ 1,2 } is a quantifier
     * from PCRE2 10.43 on but a literal before it, so x cannot be added.
     */
    private const SPACED_QUANTIFIER = '/a{ 1,2 }bcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJ/';

    #[Test]
    public function test_format_lays_a_long_pattern_out_over_lines(): void
    {
        $result = Regex::create()->format(self::URL);

        $this->assertInstanceOf(FormatResult::class, $result);
        $this->assertSame(self::URL_FORMATTED, $result->pattern);
        $this->assertTrue($result->changed);
        $this->assertNull($result->refusal);
    }

    #[Test]
    public function test_format_returns_a_fitting_pattern_byte_for_byte(): void
    {
        $result = Regex::create()->format(self::DATE);

        $this->assertSame(self::DATE, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertNull($result->refusal);
    }

    #[Test]
    public function test_format_breaks_at_the_width_it_is_given(): void
    {
        $result = Regex::create()->format(self::DATE, 24);

        $this->assertSame(self::DATE_AT_24, $result->pattern);
        $this->assertTrue($result->changed);
    }

    #[Test]
    public function test_format_of_its_own_output_changes_nothing(): void
    {
        $regex = Regex::create();

        $again = $regex->format($regex->format(self::URL)->pattern);

        $this->assertSame(self::URL_FORMATTED, $again->pattern);
        $this->assertFalse($again->changed);
    }

    #[Test]
    public function test_compact_puts_a_laid_out_pattern_on_one_line(): void
    {
        $result = Regex::create()->compact(self::LAID_OUT);

        $this->assertInstanceOf(FormatResult::class, $result);
        $this->assertSame('/(?<year>\d{4})-(?<month>\d{2})/', $result->pattern);
        $this->assertTrue($result->changed);
        $this->assertNull($result->refusal);
    }

    #[Test]
    public function test_compact_undoes_format(): void
    {
        $regex = Regex::create();

        $this->assertSame(self::URL, $regex->compact(self::URL_FORMATTED)->pattern);
    }

    #[Test]
    public function test_compact_leaves_a_one_line_pattern_unchanged(): void
    {
        $result = Regex::create()->compact(self::URL);

        $this->assertSame(self::URL, $result->pattern);
        $this->assertFalse($result->changed);
    }

    #[Test]
    public function test_format_refuses_a_spaced_quantifier_and_says_why(): void
    {
        $this->assertSame(0, preg_match(self::SPACED_QUANTIFIER, ''), 'The pattern compiles: the refusal is the formatter\'s, not PCRE\'s.');

        $result = Regex::create()->format(self::SPACED_QUANTIFIER);

        $this->assertSame(self::SPACED_QUANTIFIER, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::SpacedQuantifier, $result->refusal);
    }

    #[Test]
    #[DataProvider('providePatternsThatDoNotParse')]
    public function test_format_lets_a_pattern_that_does_not_parse_raise(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $this->expectException(ParserException::class);

        Regex::create()->format($pattern);
    }

    #[Test]
    #[DataProvider('providePatternsThatDoNotParse')]
    public function test_compact_lets_a_pattern_that_does_not_parse_raise(string $pattern): void
    {
        $this->expectException(ParserException::class);

        Regex::create()->compact($pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsThatDoNotParse(): iterable
    {
        yield 'an unclosed group' => ['pattern' => '/(a/'];
    }

    #[Test]
    #[DataProvider('provideWidthsBelowOne')]
    public function test_format_refuses_a_width_below_one(int $width): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        Regex::create()->format(self::DATE, $width);
    }

    /**
     * @return iterable<string, array{width: int}>
     */
    public static function provideWidthsBelowOne(): iterable
    {
        yield 'zero' => ['width' => 0];
        yield 'negative' => ['width' => -1];
    }

    #[Test]
    public function test_format_accepts_a_width_of_one(): void
    {
        $result = Regex::create()->format('/ab/', 1);

        $this->assertTrue($result->changed);
        $this->assertSame("/\n    ab\n/x", $result->pattern);
    }

    #[Test]
    public function test_format_and_compact_are_instance_methods_named_like_the_rest_of_the_facade(): void
    {
        $format = new \ReflectionMethod(Regex::class, 'format');
        $compact = new \ReflectionMethod(Regex::class, 'compact');

        foreach ([$format, $compact] as $method) {
            $this->assertTrue($method->isPublic(), $method->name);
            $this->assertFalse($method->isStatic(), $method->name);
            $this->assertSame(FormatResult::class, (string) $method->getReturnType(), $method->name);
            $this->assertSame('regex', $method->getParameters()[0]->getName(), $method->name);
            $this->assertSame('string', (string) $method->getParameters()[0]->getType(), $method->name);
        }

        $this->assertSame(2, $format->getNumberOfParameters());
        $this->assertSame('width', $format->getParameters()[1]->getName());
        $this->assertSame('int', (string) $format->getParameters()[1]->getType());
        $this->assertSame(80, $format->getParameters()[1]->getDefaultValue());
        $this->assertSame(1, $compact->getNumberOfParameters());
    }
}
