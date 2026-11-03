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
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute($arguments);

        [, $stdout] = $this->runRegex([...$cli, '--json']);

        $this->assertSame($isError, $result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
    }

    /**
     * @return iterable<string, array{arguments: array<string, mixed>, cli: list<string>, isError: bool}>
     */
    public static function provideTranslations(): iterable
    {
        yield 'javascript, /m' => ['arguments' => ['pattern' => '/^a$/m', 'target' => 'js'], 'cli' => ['transpile', '/^a$/m', '--target=js'], 'isError' => false];
        yield 'html' => ['arguments' => ['pattern' => '/^[a-z]+$/i', 'target' => 'html'], 'cli' => ['transpile', '/^[a-z]+$/i', '--target=html'], 'isError' => false];
        yield 'python 3.11, an atomic group' => ['arguments' => ['pattern' => '/(?>a+)b/', 'target' => 'python', 'python_version' => '3.11'], 'cli' => ['transpile', '/(?>a+)b/', '--target=python', '--python-version=3.11'], 'isError' => false];
        yield 'python 3.10, an atomic group refused' => ['arguments' => ['pattern' => '/(?>a+)b/', 'target' => 'python', 'python_version' => '3.10'], 'cli' => ['transpile', '/(?>a+)b/', '--target=python', '--python-version=3.10'], 'isError' => true];
        yield 'javascript, an atomic group refused' => ['arguments' => ['pattern' => '/(?>a+)b/', 'target' => 'js'], 'cli' => ['transpile', '/(?>a+)b/', '--target=js'], 'isError' => true];
        yield 'javascript, a verb refused' => ['arguments' => ['pattern' => '/a(*SKIP)b/', 'target' => 'js'], 'cli' => ['transpile', '/a(*SKIP)b/', '--target=js'], 'isError' => true];
        yield 'an invalid pattern' => ['arguments' => ['pattern' => '/(a/', 'target' => 'js'], 'cli' => ['transpile', '/(a/', '--target=js'], 'isError' => true];
        yield 'a semantic error' => ['arguments' => ['pattern' => '/(?<=a+)b/', 'target' => 'js'], 'cli' => ['transpile', '/(?<=a+)b/', '--target=js'], 'isError' => true];
    }

    #[Test]
    public function test_the_text_reads_the_literal_then_the_notes(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/^a$/m', 'target' => 'js']);

        $this->assertSame('/(?:^|(?<=\n)(?!$))a(?=\n|$)/', $result->structured['literal']);
        $this->assertStringStartsWith($result->structured['literal']."\n", $result->text);
        $this->assertStringContainsString('Note: Applied /m', $result->text);
    }

    #[Test]
    public function test_a_refusal_is_read_in_the_text(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/(?>a+)b/', 'target' => 'js']);

        $this->assertTrue($result->isError);
        $this->assertSame('Atomic groups are not supported in JavaScript.', $result->text);
        $this->assertSame(['error' => 'Atomic groups are not supported in JavaScript.', 'stage' => 'pattern'], $result->structured);
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['target' => 'js']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_a_python_version_that_is_no_string_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'target' => 'python', 'python_version' => 3.11]);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'The "python_version" argument must be a Python release, as "3.11".', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_an_unknown_target_php_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'target' => 'js', 'php_version' => 'eight']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_a_warning_is_read_in_the_text(): void
    {
        // Without u, a Unicode property reads as JavaScript reads it under /u:
        // the flag is added and the addition reported.
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/\p{L}+/', 'target' => 'js']);

        $this->assertFalse($result->isError);
        $this->assertSame(['Added /u for Unicode property escapes.'], $result->structured['warnings']);
        $this->assertStringContainsString("\nWarning: Added /u for Unicode property escapes.", $result->text);
    }

    #[Test]
    public function test_a_missing_target_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/a/']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertSame('The "target" argument is required: js, html or python.', $result->structured['error']);
    }

    #[Test]
    public function test_a_python_version_that_is_not_a_release_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_transpile')->tool->execute(['pattern' => '/a/', 'target' => 'python', 'python_version' => 'three']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertIsString($result->structured['error']);
        $this->assertStringContainsString('"python_version" must be a Python release', (string) $result->structured['error']);
    }
}
