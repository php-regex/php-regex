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

use PHPRegex\Cli\Output;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function test_color_helpers_respect_ansi_flag(): void
    {
        $output = new Output(true, false);

        $this->assertSame(Output::GREEN.'OK'.Output::RESET, $output->success('OK'));
        $this->assertSame(Output::RED.'ERR'.Output::RESET, $output->error('ERR'));
        $this->assertSame(Output::YELLOW.'WARN'.Output::RESET, $output->warning('WARN'));
        $this->assertSame(Output::BLUE.'INFO'.Output::RESET, $output->info('INFO'));
        $this->assertSame(Output::BOLD.'BOLD'.Output::RESET, $output->bold('BOLD'));
        $this->assertSame(Output::GRAY.'DIM'.Output::RESET, $output->dim('DIM'));

        $output->setAnsi(false);

        $this->assertSame('OK', $output->success('OK'));
        $this->assertSame('ERR', $output->error('ERR'));
        $this->assertSame('WARN', $output->warning('WARN'));
        $this->assertSame('INFO', $output->info('INFO'));
        $this->assertSame('BOLD', $output->bold('BOLD'));
        $this->assertSame('DIM', $output->dim('DIM'));
    }

    public function test_badge_formats_output_based_on_ansi(): void
    {
        $output = new Output(false, false);

        $this->assertSame('PASS', $output->badge('PASS', Output::WHITE, Output::BG_GREEN));

        $output->setAnsi(true);

        $this->assertStringContainsString('PASS', $output->badge('PASS', Output::WHITE, Output::BG_GREEN));
    }

    public function test_write_respects_quiet_mode(): void
    {
        $output = new Output(false, true);

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->write("Should not appear\n");
        });

        $this->assertSame('', $buffer);
    }

    public function test_progress_outputs_when_ansi_enabled(): void
    {
        $output = new Output(true, false);

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->progressStart(3);
            $output->progressAdvance();
            $output->progressFinish();
        });

        $this->assertStringContainsString('1/3', $buffer);
        $this->assertStringContainsString('3/3', $buffer);
    }

    public function test_format_elapsed_handles_hours_and_minutes(): void
    {
        $output = new Output(false, false);
        $method = new \ReflectionMethod(Output::class, 'formatElapsed');

        $this->assertSame('01:01:01', $method->invoke($output, 3661));
        $this->assertSame('00:05', $method->invoke($output, 5));
    }

    public function test_is_quiet_returns_quiet_flag(): void
    {
        $output = new Output(false, true);
        $this->assertTrue($output->isQuiet());

        $output->setQuiet(false);
        $this->assertFalse($output->isQuiet());
    }

    public function test_progress_start_sets_progress_active_to_false_when_total_is_zero_or_negative(): void
    {
        $output = new Output(true, false);

        $output->progressStart(0);
        $this->assertFalse($this->getProgressActive($output));

        $output->progressStart(-1);
        $this->assertFalse($this->getProgressActive($output));
    }

    public function test_progress_start_sets_progress_active_to_false_when_quiet(): void
    {
        $output = new Output(true, true);

        $output->progressStart(10);
        $this->assertFalse($this->getProgressActive($output));
    }

    private function getProgressActive(Output $output): bool
    {
        $property = new \ReflectionProperty(Output::class, 'progressActive');

        return (bool) $property->getValue($output);
    }

    private function captureOutput(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
