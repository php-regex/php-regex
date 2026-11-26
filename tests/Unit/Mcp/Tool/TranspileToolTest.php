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

namespace PHPRegex\Tests\Unit\Mcp\Tool;

use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranspileToolTest extends TestCase
{
    use RunsRegexCli;

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $cli
     */
    #[Test]
    #[DataProvider('provideTranslations')]
    public function test_the_answer_is_the_cli_document(array $arguments, array $cli, bool $isError): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute($arguments);

        [, $stdout] = $this->runRegex([...$cli, '--json']);

        $this->assertSame($isError, $result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
    }

    /**
     * @return iterable<string, array{arguments: array<string, mixed>, cli: list<string>, isError: bool}>
     */
    public static function provideTranslations(): iterable
    {
        yield 'javascript, /m' => ['arguments' => ['pattern' => '/^a$/m', 'dialect' => 'js'], 'cli' => ['transpile', '/^a$/m', '--target=js'], 'isError' => false];
        yield 'html' => ['arguments' => ['pattern' => '/^[a-z]+$/i', 'dialect' => 'html'], 'cli' => ['transpile', '/^[a-z]+$/i', '--target=html'], 'isError' => false];
        yield 'python 3.11, an atomic group' => ['arguments' => ['pattern' => '/(?>a+)b/', 'dialect' => 'python', 'python_version' => '3.11'], 'cli' => ['transpile', '/(?>a+)b/', '--target=python', '--python-version=3.11'], 'isError' => false];
        yield 'python 3.10, an atomic group refused' => ['arguments' => ['pattern' => '/(?>a+)b/', 'dialect' => 'python', 'python_version' => '3.10'], 'cli' => ['transpile', '/(?>a+)b/', '--target=python', '--python-version=3.10'], 'isError' => true];
        yield 'javascript, an atomic group refused' => ['arguments' => ['pattern' => '/(?>a+)a/', 'dialect' => 'js'], 'cli' => ['transpile', '/(?>a+)a/', '--target=js'], 'isError' => true];
        yield 'javascript, a verb refused' => ['arguments' => ['pattern' => '/a(*SKIP)b/', 'dialect' => 'js'], 'cli' => ['transpile', '/a(*SKIP)b/', '--target=js'], 'isError' => true];
        yield 'an invalid pattern' => ['arguments' => ['pattern' => '/(a/', 'dialect' => 'js'], 'cli' => ['transpile', '/(a/', '--target=js'], 'isError' => true];
        yield 'a semantic error' => ['arguments' => ['pattern' => '/(?<=a+)b/', 'dialect' => 'js'], 'cli' => ['transpile', '/(?<=a+)b/', '--target=js'], 'isError' => true];
    }

    #[Test]
    public function test_the_text_reads_the_literal_then_the_notes(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/^a$/m', 'dialect' => 'js']);

        $this->assertSame('/(?:^|(?<=\n)(?!$))a(?=\n|$)/', $result->structured['literal']);
        $this->assertStringStartsWith($result->structured['literal']."\n", $result->text);
        $this->assertStringContainsString('Note: Applied /m', $result->text);
    }

    #[Test]
    public function test_a_refusal_is_read_in_the_text(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/(?>a+)a/', 'dialect' => 'js']);

        $this->assertTrue($result->isError);
        $this->assertSame('Atomic groups are not supported in JavaScript.', $result->text);
        $this->assertSame(['error' => 'Atomic groups are not supported in JavaScript.', 'stage' => 'pattern'], $result->structured);
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_failure(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['dialect' => 'js']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_a_python_version_that_is_no_string_is_a_usage_failure(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'dialect' => 'python', 'python_version' => 3.11]);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'The "python_version" argument must be a Python release, as "3.11".', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_an_unknown_target_php_is_a_usage_failure(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'dialect' => 'js', 'php_version' => 'eight']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_a_warning_is_read_in_the_text(): void
    {
        // Without u, a Unicode property reads as JavaScript reads it under /u:
        // the flag is added and the addition reported.
        // Oracle: PCRE reads the first byte of "é" as a Latin-1 letter, and the character under u.
        $this->assertSame(1, preg_match('/\p{L}+/', 'é', $bytes));
        $this->assertSame("\xC3", $bytes[0]);
        $this->assertSame(1, preg_match('/\p{L}+/u', 'é', $character));
        $this->assertSame('é', $character[0]);

        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/\p{L}+/', 'dialect' => 'js']);

        $this->assertFalse($result->isError);
        $set = 'Without the u flag "\p{L}" at offset 0 takes one byte in PCRE and one character in this target, so the two differ on a subject holding a character outside ASCII; under u, for UTF-8 subjects, they agree. Add u.';
        $this->assertSame(['Added /u for Unicode property escapes.', $set], $result->structured['warnings']);
        $this->assertStringContainsString("\nWarning: Added /u for Unicode property escapes.\nWarning: ".$set, $result->text);
    }

    #[Test]
    public function test_a_missing_dialect_is_a_usage_failure(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/a/']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertSame('The "dialect" argument is required: one of javascript, js, html-pattern, html, python, py.', $result->structured['error']);
    }

    /**
     * The argument is the dialect, which the 2.0 answer names "dialect";
     * "target" is the PHP versions judged, as in every other tool.
     */
    #[Test]
    public function test_the_dialect_is_its_own_argument_and_key(): void
    {
        $tool = ToolCatalog::create()->get('regex_transpile')->tool;

        foreach (['javascript' => 'javascript', 'js' => 'javascript', 'html-pattern' => 'html-pattern', 'html' => 'html-pattern', 'python' => 'python', 'py' => 'python'] as $name => $dialect) {
            $result = $tool->execute(['pattern' => '/a/', 'dialect' => $name]);

            $this->assertFalse($result->isError, $name);
            $this->assertSame($dialect, $result->structured['dialect'] ?? null, $name);
            $this->assertIsArray($result->structured['target'] ?? null, $name);
        }

        $this->assertTrue($tool->execute(['pattern' => '/a/', 'target' => 'js'])->isError);
    }

    #[Test]
    public function test_a_dialect_the_transpiler_has_no_target_for_is_a_usage_failure(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'dialect' => 'perl']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertSame('The "dialect" argument is required: one of javascript, js, html-pattern, html, python, py.', $result->structured['error']);
    }

    #[Test]
    public function test_a_pattern_the_lexer_or_the_parser_refuses_is_a_pattern_failure(): void
    {
        $tool = ToolCatalog::create()->get('regex_transpile')->tool;

        foreach (['/[/' => 'unclosed character class', '/(?<n/' => 'name'] as $pattern => $word) {
            $result = $tool->execute(['pattern' => $pattern, 'dialect' => 'js']);
            $this->assertTrue($result->isError, $pattern);
            $this->assertSame('pattern', $result->structured['stage'], $pattern);
            $this->assertIsString($result->structured['error']);
            $this->assertStringContainsString($word, strtolower($result->structured['error']), $pattern);
        }
    }

    #[Test]
    public function test_a_python_version_that_is_not_a_release_is_a_usage_failure(): void
    {
        $result = ToolCatalog::create()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'dialect' => 'python', 'python_version' => 'three']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertIsString($result->structured['error']);
        $this->assertStringContainsString('"python_version" must be a Python release', (string) $result->structured['error']);
    }
}
