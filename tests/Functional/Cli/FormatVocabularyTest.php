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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every command calls its terminal rendering "console": --format takes the
 * same word for explain, diagram and highlight as for analyze or lint, the
 * help says which values each command takes, and a refused value is named
 * with the ones that work.
 */
final class FormatVocabularyTest extends TestCase
{
    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideAcceptedFormats')]
    public function test_a_command_takes_the_formats_it_names(array $arguments): void
    {
        [$code, $output] = self::regex($arguments);

        $this->assertSame(0, $code, $output);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideAcceptedFormats(): iterable
    {
        yield 'explain, console' => [['explain', '/a+/', '--format=console']];
        yield 'explain, html' => [['explain', '/a+/', '--format=html']];
        yield 'diagram, console' => [['diagram', '/a+/', '--format=console']];
        yield 'diagram, svg' => [['diagram', '/a+/', '--format=svg']];
        yield 'highlight, console' => [['highlight', '/a+/', '--format=console']];
        yield 'highlight, html' => [['highlight', '/a+/', '--format=html']];
        yield 'highlight, auto' => [['highlight', '/a+/', '--format=auto']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRefusedFormats')]
    public function test_a_refused_format_names_the_ones_the_command_takes(array $arguments, string $message): void
    {
        [$code, $output] = self::regex($arguments);

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString($message, $output);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideRefusedFormats(): iterable
    {
        yield 'explain, text' => [['explain', '/a+/', '--format=text'], "Unsupported format 'text'. Use --format=console or --format=html."];
        yield 'diagram, text' => [['diagram', '/a+/', '--format=text'], "Unsupported format 'text'. Use --format=console or --format=svg."];
        yield 'diagram, ascii' => [['diagram', '/a+/', '--format=ascii'], "Unsupported format 'ascii'. Use --format=console or --format=svg."];
        yield 'highlight, cli' => [['highlight', '/a+/', '--format=cli'], "Unsupported format 'cli'. Use --format=auto, --format=console or --format=html."];
    }

    #[Test]
    #[DataProvider('provideHelpLines')]
    public function test_the_help_lists_the_formats_the_command_takes(string $command, string $line): void
    {
        [$code, $output] = self::regex(['help', $command]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString($line, $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideHelpLines(): iterable
    {
        yield 'explain' => ['explain', 'Output format (console, html)'];
        yield 'diagram' => ['diagram', 'Output format (console, svg)'];
        yield 'highlight' => ['highlight', 'Output format (auto, console, html)'];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private static function regex(array $arguments): array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3).'/src/Cli/bin/regex', '--no-ansi', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
