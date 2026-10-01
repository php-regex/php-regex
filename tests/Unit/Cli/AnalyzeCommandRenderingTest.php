<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Cli;

use PhpRegex\Cli\Command\AnalyzeCommand;
use PhpRegex\Cli\ConsoleStyle;
use PhpRegex\Cli\Output;
use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosConfidence;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalyzeCommandRenderingTest extends TestCase
{
    #[Test]
    public function test_analyze_command_reports_a_redos_analysis_that_could_not_finish(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::Unknown,
            0,
            null,
            ['Analysis incomplete: out of steam'],
            'RuntimeException: out of steam',
            null,
            null,
            RedosConfidence::Low,
            null,
            [],
            null,
            null,
            [],
            RedosMode::Theoretical,
            null,
        );

        $buffer = $this->render($analysis);

        $this->assertStringContainsString('ReDoS error: RuntimeException: out of steam', $buffer);
    }

    #[Test]
    public function test_a_finished_analysis_reports_no_error(): void
    {
        $analysis = new RedosAnalysis(
            RedosSeverity::Safe,
            0,
            null,
            [],
            null,
            null,
            null,
            RedosConfidence::Low,
            null,
            [],
            null,
            null,
            [],
            RedosMode::Theoretical,
            null,
        );

        $this->assertStringNotContainsString('ReDoS error:', $this->render($analysis));
    }

    private function render(RedosAnalysis $analysis): string
    {
        $command = new AnalyzeCommand();
        $output = new Output(false, false);
        $style = new ConsoleStyle($output, false);

        $method = (new \ReflectionClass($command))->getMethod('renderConsoleOutput');

        ob_start();
        $method->invoke($command, $output, $style, '/foo/', new ValidationResult(true, null, 0), $analysis, '');

        return (string) ob_get_clean();
    }
}
