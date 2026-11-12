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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `regex validate --json`: the verdict as the shared validation object,
 * one serialisation for the console, the MCP server and a script alike.
 */
final class ValidateCommandJsonTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    public function test_a_valid_pattern_prints_its_document_and_exits_0(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runRegex(['validate', '/a+/', '--json']);

        $this->assertSame(0, $exitCode, $stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        $this->assertSame('/a+/', $document['pattern']);
        $this->assertTrue(JsonContract::asArray($document['validation'])['is_valid']);
        $this->assertSame('', $stderr);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideJsonSpellings')]
    public function test_json_is_asked_either_way(array $arguments): void
    {
        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(0, $exitCode);
        JsonContract::assertShape('validate', JsonContract::decodeDocument($stdout));
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideJsonSpellings(): iterable
    {
        yield '--json' => ['arguments' => ['validate', '/a+/', '--json']];
        yield '--format=json' => ['arguments' => ['validate', '/a+/', '--format=json']];
        yield '--format json before the pattern' => ['arguments' => ['validate', '--format', 'json', '/a+/']];
        yield 'quiet' => ['arguments' => ['--quiet', 'validate', '/a+/', '--json']];
    }

    #[Test]
    public function test_an_invalid_pattern_is_the_verdict_with_exit_1(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['validate', '/(a/', '--json']);

        $this->assertSame(1, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        $this->assertArrayNotHasKey('error', $document, 'An invalid pattern is the verdict, never the envelope.');
        $validation = JsonContract::asArray($document['validation']);
        $this->assertFalse($validation['is_valid']);
        $this->assertSame('regex.group.unclosed', $validation['error_code']);
        $this->assertSame(2, $validation['offset']);
    }

    #[Test]
    public function test_a_pattern_past_a_library_limit_is_not_analyzed(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['validate', '/'.str_repeat('a', 100_001).'/', '--json']);

        $this->assertSame(1, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame('limit', JsonContract::asArray($document['validation'])['category']);
    }

    #[Test]
    public function test_the_target_is_judged_for(): void
    {
        [$onEight, $stdout] = $this->runRegex(['--php-version=8.0', 'validate', '/\p{Script=Latin}/u', '--json']);
        $this->assertSame(1, $onEight);
        $this->assertFalse(JsonContract::asArray(JsonContract::decodeDocument($stdout)['validation'])['is_valid']);

        [$onEightTwo, $stdout] = $this->runRegex(['--php-version=8.2', 'validate', '/\p{Script=Latin}/u', '--json']);
        $this->assertSame(0, $onEightTwo);
        $this->assertTrue(JsonContract::asArray(JsonContract::decodeDocument($stdout)['validation'])['is_valid']);
    }

    #[Test]
    public function test_an_unknown_format_is_a_usage_error(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runRegex(['validate', '/a+/', '--format=yaml']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Invalid value for --format. Use console or json.', $stderr);
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_error_in_the_envelope(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['validate', '--json']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('usage', JsonContract::decodeDocument($stdout)['stage']);
    }

    #[Test]
    public function test_the_console_output_is_unchanged(): void
    {
        [$exitCode, $stdout] = $this->runRegex(['validate', '/a+/']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Status: OK', $stdout);
        $this->assertStringNotContainsString('{', $stdout);
    }
}
