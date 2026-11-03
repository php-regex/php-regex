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
use PHPRegex\Parser\Printer\FormatRefusal;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormatToolTest extends TestCase
{
    use RunsRegexCli;

    private const DATE = '/^(?<year>\d{4})-(?<month>\d{2})$/';

    private const DATE_AT_24 = <<<'REGEX'
        /
            ^
            (?<year>\d{4})
            -
            (?<month>\d{2})
            $
        /x
        REGEX;

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $cli
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_the_answer_is_the_cli_document(array $arguments, array $cli): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute($arguments);

        [, $stdout] = $this->runRegex([...$cli, '--json']);

        $this->assertFalse($result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
        $this->assertIsString($result->structured['formatted']);
        $this->assertSame(0, strpos($result->text, (string) $result->structured['formatted']));
    }

    /**
     * @return iterable<string, array{arguments: array<string, mixed>, cli: list<string>}>
     */
    public static function provideFormats(): iterable
    {
        yield 'laid out at 24' => ['arguments' => ['pattern' => self::DATE, 'width' => 24], 'cli' => ['format', self::DATE, '--width=24']];
        yield 'fits at 80' => ['arguments' => ['pattern' => self::DATE], 'cli' => ['format', self::DATE]];
        yield 'compacted' => ['arguments' => ['pattern' => self::DATE_AT_24, 'compact' => true], 'cli' => ['format', self::DATE_AT_24, '--compact']];
        yield 'a refusal' => ['arguments' => ['pattern' => '/a{ 1,2 }(?:bcdefghij)/', 'width' => 10], 'cli' => ['format', '/a{ 1,2 }(?:bcdefghij)/', '--width=10']];
    }

    #[Test]
    public function test_the_laid_out_pattern_is_the_cli_output(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['pattern' => self::DATE, 'width' => 24]);

        $this->assertSame(self::DATE_AT_24, $result->structured['formatted']);
        $this->assertTrue($result->structured['changed']);
    }

    #[Test]
    public function test_a_refusal_is_read_in_the_text(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['pattern' => '/a{ 1,2 }(?:bcdefghij)/', 'width' => 10]);

        $this->assertSame('spaced_quantifier', $result->structured['refusal']);
        $this->assertStringContainsString('left as written', $result->text);
        $this->assertIsString($result->structured['formatted']);
        $this->assertSame($result->structured['formatted']."\nNote: ".FormatRefusal::SpacedQuantifier->note(), $result->text, 'The pattern, then the note on its own line.');
    }

    /**
     * The width defaults to 80, the CLI's: a pattern of 80 characters fits
     * and comes back as written, one of 81 is laid out; and 1 is a width.
     */
    #[Test]
    public function test_the_width_defaults_to_eighty(): void
    {
        $tool = ToolCatalog::v1()->get('regex_format')->tool;
        $eighty = '/'.str_repeat('a', 78).'/';
        $eightyOne = '/'.str_repeat('a', 79).'/';

        $this->assertFalse($tool->execute(['pattern' => $eighty])->structured['changed']);
        $this->assertTrue($tool->execute(['pattern' => $eightyOne])->structured['changed']);
        $this->assertFalse($tool->execute(['pattern' => $eightyOne, 'width' => 81])->structured['changed']);
        $this->assertFalse($tool->execute(['pattern' => '/a/', 'width' => 1])->isError, 'One is the smallest width.');
    }

    /**
     * What the lexer or the limits refuse is the pattern's fault, reported
     * in the envelope at the pattern stage like a syntax error.
     */
    #[Test]
    public function test_a_pattern_the_lexer_or_the_limits_refuse_is_a_pattern_failure(): void
    {
        $tool = ToolCatalog::v1()->get('regex_format')->tool;

        $unclosed = $tool->execute(['pattern' => '/[/']);
        $this->assertTrue($unclosed->isError);
        $this->assertSame('pattern', $unclosed->structured['stage']);
        $this->assertIsString($unclosed->structured['error']);
        $this->assertStringContainsString('nclosed character class', $unclosed->structured['error']);

        $tooLong = $tool->execute(['pattern' => '/'.str_repeat('a', 200_000).'/']);
        $this->assertTrue($tooLong->isError);
        $this->assertSame('pattern', $tooLong->structured['stage']);
        $this->assertIsString($tooLong->structured['error']);
        $this->assertStringContainsString('length', $tooLong->structured['error']);
    }

    #[Test]
    public function test_an_invalid_pattern_is_the_envelope_without_validation(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['pattern' => '/(a/']);

        [, $stdout] = $this->runRegex(['format', '/(a/', '--json']);

        $this->assertTrue($result->isError);
        $this->assertSame(JsonContract::decodeDocument($stdout), $result->structured);
        $this->assertSame(['error', 'stage'], array_keys($result->structured));
    }

    #[Test]
    public function test_a_missing_pattern_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['width' => 24]);

        $this->assertTrue($result->isError);
        $this->assertSame('usage', $result->structured['stage']);
    }

    #[Test]
    public function test_compact_that_is_no_boolean_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['pattern' => '/a/', 'compact' => 'yes']);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'The "compact" argument must be true or false.', 'stage' => 'usage'], $result->structured);
    }

    #[Test]
    public function test_a_php_that_ships_pcre1_is_refused(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['pattern' => '/a/', 'php_version' => '7.1']);

        $this->assertTrue($result->isError);
        $this->assertSame('target', $result->structured['stage']);
    }

    #[Test]
    public function test_a_width_below_one_is_a_usage_failure(): void
    {
        $result = ToolCatalog::v1()->get('regex_format')->tool->execute(['pattern' => '/a/', 'width' => 0]);

        $this->assertTrue($result->isError);
        $this->assertSame(['error' => 'The "width" argument must be a positive integer.', 'stage' => 'usage'], $result->structured);
    }
}
