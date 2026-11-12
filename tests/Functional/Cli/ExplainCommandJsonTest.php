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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `regex explain --json`: the explanation as the facade writes it, one
 * serialisation for the console, the MCP server and a script alike.
 */
final class ExplainCommandJsonTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    public function test_a_valid_pattern_prints_its_explanation_and_exits_0(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runRegex(['explain', '/\d{4}-\d{2}/', '--json']);

        $this->assertSame(0, $exitCode, $stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('explain', $document);
        $this->assertSame('/\d{4}-\d{2}/', $document['pattern']);
        $this->assertSame(Regex::create()->explain('/\d{4}-\d{2}/'), $document['explanation']);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_format_json_is_the_same_as_json(): void
    {
        [, $short] = $this->runRegex(['explain', '/a+/', '--json']);
        [, $long] = $this->runRegex(['explain', '/a+/', '--format=json']);

        $this->assertSame($short, $long);
    }

    #[Test]
    public function test_an_invalid_pattern_stops_with_the_envelope_and_its_validation(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['explain', '/(a/', '--json']);

        $this->assertSame(1, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('error', $document);
        $this->assertSame('pattern', $document['stage']);
        $this->assertSame('regex.group.unclosed', JsonContract::asArray($document['validation'])['error_code']);
    }

    #[Test]
    public function test_a_semantic_error_stops_the_same_way(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['explain', '/(?<=a+)b/', '--json']);

        $this->assertSame(1, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame('pattern', $document['stage']);
        $this->assertFalse(JsonContract::asArray($document['validation'])['is_valid']);
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_error_in_the_envelope(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['explain', '--json']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('usage', JsonContract::decodeDocument($stdout)['stage']);
    }

    #[Test]
    public function test_html_and_console_formats_are_unchanged(): void
    {
        [$exitCode, $html] = $this->runRegex(['explain', '/a+/', '--format=html']);
        $this->assertSame(0, $exitCode);
        $this->assertStringStartsWith('<div class="regex-explain">', $html);

        [$exitCode, $console] = $this->runRegex(['explain', '/a+/']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Regex matches', $console);
        $this->assertStringNotContainsString('{', $console);
    }

    #[Test]
    public function test_an_unknown_format_is_a_usage_error(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runRegex(['explain', '/a+/', '--format=yaml']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Use --format=console, --format=html or --format=json.', $stderr);
    }
}
