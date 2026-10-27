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
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\ExplainFormat;
use PHPRegex\Toolkit\HighlightFormat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A format or a target the facade does not know is refused, and the error
 * names the values it takes: no call falls back silently to another output.
 */
final class RegexFormatArgumentsTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideRefusedHighlightFormats')]
    public function test_highlight_refuses_a_format_it_has_no_highlighter_for(string $format): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('expected console or html');

        Regex::create()->highlight('/a+/', $format);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideRefusedHighlightFormats(): iterable
    {
        yield 'an unknown string' => ['format' => 'bogus'];
        yield 'text, which only explain() writes' => ['format' => 'text'];
        yield 'Text, the name of a case of explain()' => ['format' => 'Text'];
        yield 'cli, which no release took' => ['format' => 'cli'];
        yield 'auto, which no release took' => ['format' => 'auto'];
    }

    #[Test]
    public function test_highlight_writes_each_format_it_takes(): void
    {
        $this->assertStringContainsString('<span', Regex::create()->highlight('/a+/', HighlightFormat::Html));
        $this->assertStringContainsString("\e[", Regex::create()->highlight('/a+/', HighlightFormat::Console));
        $this->assertSame(Regex::create()->highlight('/a+/', HighlightFormat::Console), Regex::create()->highlight('/a+/'));
        $this->assertStringContainsString('<span', Regex::create()->highlight('/a+/', 'html'));
        $this->assertStringContainsString("\e[", Regex::create()->highlight('/a+/', 'console'));
    }

    #[Test]
    #[DataProvider('provideRefusedExplainFormats')]
    public function test_explain_names_the_formats_it_takes(string $format): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('expected text or html');

        Regex::create()->explain('/a+/', $format);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideRefusedExplainFormats(): iterable
    {
        yield 'an unknown string' => ['format' => 'bogus'];
        yield 'console, which only highlight() writes' => ['format' => 'console'];
    }

    #[Test]
    public function test_explain_writes_each_format_it_takes(): void
    {
        $this->assertStringContainsString('one or more', Regex::create()->explain('/a+/', ExplainFormat::Text));
        $this->assertStringContainsString('<div class="regex-explain">', Regex::create()->explain('/a+/', ExplainFormat::Html));
        $this->assertSame(Regex::create()->explain('/a+/', ExplainFormat::Html), Regex::create()->explain('/a+/', 'html'));
        $this->assertSame(Regex::create()->explain('/a+/', ExplainFormat::Text), Regex::create()->explain('/a+/'));
    }

    /**
     * Each method has its own formats: the case one does not write cannot
     * be named, while the string 1.x took is still read.
     *
     * @param class-string<\BackedEnum> $enum
     * @param list<string>              $values
     */
    #[Test]
    #[DataProvider('provideFormatEnums')]
    public function test_each_method_takes_its_own_format_enum(string $method, string $enum, array $values, string $default): void
    {
        $this->assertSame($values, array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases()));

        $format = (new \ReflectionMethod(Regex::class, $method))->getParameters()[1];

        $this->assertSame('format', $format->getName());
        $this->assertSame($enum.'|string', (string) $format->getType());
        $this->assertSame($enum::from($default), $format->getDefaultValue());
    }

    /**
     * @return iterable<string, array{method: string, enum: class-string<\BackedEnum>, values: list<string>, default: string}>
     */
    public static function provideFormatEnums(): iterable
    {
        yield 'explain' => ['method' => 'explain', 'enum' => ExplainFormat::class, 'values' => ['text', 'html'], 'default' => 'text'];
        yield 'highlight' => ['method' => 'highlight', 'enum' => HighlightFormat::class, 'values' => ['console', 'html'], 'default' => 'console'];
    }

    #[Test]
    public function test_one_format_enum_for_both_methods_is_gone(): void
    {
        $this->assertFalse(enum_exists('PHPRegex\\Toolkit\\OutputFormat'));
    }

    #[Test]
    public function test_transpile_names_the_targets_it_takes(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Unknown transpile target: bogus. Expected one of: html, html-pattern, javascript, js, py, python.');

        Regex::create()->transpile('/a+/', 'bogus');
    }
}
