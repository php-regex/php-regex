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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tool's structured answer is the document `regex validate --json`
 * prints: one serialisation, read by the CLI guide and by a client alike.
 */
final class ValidateToolTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    public function test_a_valid_pattern_answers_the_cli_document(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a+/']);

        [, $stdout] = $this->runRegex(['validate', '/a+/', '--json']);

        $this->assertFalse($result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
        $this->assertTrue(JsonContract::asArray($result->structured['validation'])['is_valid']);
        $this->assertSame('Valid.', $result->text);
    }

    #[Test]
    public function test_an_invalid_pattern_is_a_verdict_not_a_tool_error(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/(a/']);

        [, $stdout] = $this->runRegex(['validate', '/(a/', '--json']);

        $this->assertFalse($result->isError, 'An invalid pattern is what validate reports on, never a failure of the tool.');
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
        $validation = JsonContract::asArray($result->structured['validation']);
        $this->assertSame('regex.group.unclosed', $validation['error_code']);
        $this->assertSame(2, $validation['offset']);
        $this->assertStringContainsString('Expected ) at end of input', $result->text);
    }

    #[Test]
    public function test_a_hint_is_read_in_the_text(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a{2,1}/']);

        $validation = JsonContract::asArray($result->structured['validation']);
        $this->assertFalse($validation['is_valid']);
        $this->assertSame($validation['hint'], substr((string) strrchr($result->text, "\n"), 1));
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute([]);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertSame('The "pattern" argument is required: a delimited pattern, as /…/flags.', $result->structured['error']);
    }

    #[Test]
    public function test_an_unknown_target_is_a_usage_failure_naming_the_argument(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a/', 'php_version' => 'eight']);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
        $this->assertSame('Invalid option: "php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".', $result->structured['error']);
    }

    #[Test]
    public function test_a_php_that_ships_pcre1_is_refused_as_the_cli_refuses_it(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a/', 'php_version' => '7.2']);

        $this->assertTrue($result->isError);
        $this->assertSame('target', $result->structured['stage']);
        $this->assertSame('"php_version" names PHP 7.2: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.', $result->structured['error']);
    }

    #[Test]
    public function test_a_pattern_over_the_library_limit_is_not_analyzed(): void
    {
        $result = ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/'.str_repeat('a', 100_001).'/']);

        $this->assertFalse($result->isError);
        $this->assertSame('limit', JsonContract::asArray($result->structured['validation'])['category']);
        $this->assertStringStartsWith('Not analyzed: ', $result->text);
    }
}
