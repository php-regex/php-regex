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
use PHPRegex\Toolkit\OutputFormat;
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
    public function test_highlight_refuses_a_format_it_has_no_highlighter_for(string|OutputFormat $format): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('expected console or html');

        Regex::create()->highlight('/a+/', $format);
    }

    /**
     * @return iterable<string, array{string|OutputFormat}>
     */
    public static function provideRefusedHighlightFormats(): iterable
    {
        yield 'an unknown string' => ['bogus'];
        yield 'text, which only explain() writes' => ['text'];
        yield 'the text case' => [OutputFormat::Text];
    }

    #[Test]
    public function test_highlight_writes_each_format_it_takes(): void
    {
        $this->assertStringContainsString('<span', Regex::create()->highlight('/a+/', OutputFormat::Html));
        $this->assertStringContainsString('<span', Regex::create()->highlight('/a+/', 'html'));
        $this->assertStringContainsString("\e[", Regex::create()->highlight('/a+/', 'console'));
    }

    #[Test]
    #[DataProvider('provideRefusedExplainFormats')]
    public function test_explain_names_the_formats_it_takes(string|OutputFormat $format): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('expected text or html');

        Regex::create()->explain('/a+/', $format);
    }

    /**
     * @return iterable<string, array{string|OutputFormat}>
     */
    public static function provideRefusedExplainFormats(): iterable
    {
        yield 'an unknown string' => ['bogus'];
        yield 'the console case' => [OutputFormat::Console];
    }

    #[Test]
    public function test_transpile_names_the_targets_it_takes(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('Unknown transpile target: bogus. Expected one of: html, html-pattern, javascript, js, py, python.');

        Regex::create()->transpile('/a+/', 'bogus');
    }
}
