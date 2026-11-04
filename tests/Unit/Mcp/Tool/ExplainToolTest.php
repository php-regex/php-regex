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

final class ExplainToolTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    public function test_the_explanation_is_the_cli_document(): void
    {
        $result = ToolCatalog::v1()->get('regex_explain')->tool->execute(['pattern' => '/\d{4}-\d{2}/']);

        [, $stdout] = $this->runRegex(['explain', '/\d{4}-\d{2}/', '--json']);

        $this->assertFalse($result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
        $this->assertSame(['pattern', 'explanation'], array_keys($result->structured));
        $this->assertSame($result->structured['explanation'], $result->text);
        $this->assertStringContainsString('Regex matches', $result->text);
    }

    #[Test]
    public function test_an_invalid_pattern_is_the_envelope_with_its_validation(): void
    {
        $result = ToolCatalog::v1()->get('regex_explain')->tool->execute(['pattern' => '/(a/']);

        [, $stdout] = $this->runRegex(['explain', '/(a/', '--json']);

        $this->assertTrue($result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
        $this->assertSame('pattern', $result->structured['stage']);
        $this->assertSame('regex.group.unclosed', JsonContract::asArray($result->structured['validation'])['error_code']);
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_explain')->tool->execute([]);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_an_unknown_target_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_explain')->tool->execute(['pattern' => '/a/', 'pcre_version' => 'ten']);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'Invalid option: "pcre_version" must be a PCRE2 release like "10.44", not "ten".', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_a_semantic_error_is_refused_like_a_syntax_error(): void
    {
        // Parses, but PCRE refuses it: an explanation of what it would match
        // would explain a pattern PHP never runs.
        $result = ToolCatalog::v1()->get('regex_explain')->tool->execute(['pattern' => '/(?<=a+)b/']);

        $this->assertTrue($result->isError);
        $this->assertSame('pattern', $result->structured['stage']);
        $this->assertFalse(JsonContract::asArray($result->structured['validation'])['is_valid']);
    }
}
