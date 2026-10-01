<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint\Formatter;

use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\Formatter\ConsoleFormatter;
use PhpRegex\Linter\Formatter\LinkFormatter;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PhpRegex\Linter\Formatter\RelativePathHelper;
use PhpRegex\Linter\LintReport;
use PhpRegex\Parser\RegexParser;
use PhpRegex\Symfony\Output\SymfonyConsoleFormatter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * An invalid pattern is shown with the character at fault under a caret:
 * the validation carries the snippet apart from its message, and both
 * console formatters draw it under the message.
 */
final class ConsoleCaretSnippetTest extends TestCase
{
    #[Test]
    public function test_the_console_formatter_draws_the_caret_under_the_fault(): void
    {
        $output = (new ConsoleFormatter(config: new OutputConfiguration(ansi: false)))->format($this->report('/(?<=a+)b/'));

        $this->assertCaretUnder('(', $output);
    }

    #[Test]
    public function test_the_caret_points_at_the_delimiter_that_ends_the_pattern_early(): void
    {
        $output = (new ConsoleFormatter(config: new OutputConfiguration(ansi: false)))->format($this->report('/ab/c/'));

        $this->assertStringContainsString('ab/c/', $output);
        $this->assertCaretUnder('/', $output);
    }

    #[Test]
    public function test_the_symfony_console_formatter_draws_the_caret_under_the_fault(): void
    {
        if (!class_exists(OutputFormatter::class)) {
            $this->markTestSkipped('Symfony Console is not installed.');
        }

        $formatter = new SymfonyConsoleFormatter(
            new AnalysisService(RegexParser::create()),
            new LinkFormatter(null, new RelativePathHelper()),
            false,
        );

        // The formatter writes Symfony Console tags: rendered, as a terminal does.
        $this->assertCaretUnder('(', (string) (new OutputFormatter(false))->format($formatter->format($this->report('/(?<=a+)b/'))));
    }

    private function report(string $pattern): LintReport
    {
        $validation = Regex::create(['cache' => null])->validate($pattern);
        $this->assertFalse($validation->isValid);
        $this->assertNotNull($validation->caretSnippet);

        return new LintReport([[
            'file' => 'test.php',
            'line' => 3,
            'pattern' => $pattern,
            'issues' => [[
                'type' => 'error',
                'message' => (string) $validation->error,
                'file' => 'test.php',
                'line' => 3,
                'position' => $validation->offset,
                'validation' => $validation,
            ]],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);
    }

    /**
     * The line above the caret holds, in the caret's column, the character
     * at fault.
     */
    private function assertCaretUnder(string $character, string $output): void
    {
        $lines = explode(\PHP_EOL, $output);
        foreach ($lines as $index => $line) {
            // Columns in characters: the arrow before the snippet is one
            // character of three bytes.
            $column = mb_strpos($line, '^');
            if (false !== $column && 1 === preg_match('/^\s*\^\s*$/', $line) && $index > 0) {
                $this->assertSame($character, mb_substr($lines[$index - 1], $column, 1), $output);

                return;
            }
        }

        $this->fail("No caret line in:\n".$output);
    }
}
