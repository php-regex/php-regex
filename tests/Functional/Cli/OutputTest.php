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
use PHPRegex\Tests\Support\TerminalScreen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    #[Test]
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

    #[Test]
    public function test_badge_formats_output_based_on_ansi(): void
    {
        $output = new Output(false, false);

        $this->assertSame('PASS', $output->badge('PASS', Output::WHITE, Output::BG_GREEN));

        $output->setAnsi(true);

        $this->assertStringContainsString('PASS', $output->badge('PASS', Output::WHITE, Output::BG_GREEN));
    }

    #[Test]
    public function test_write_respects_quiet_mode(): void
    {
        $output = new Output(false, true);

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->write("Should not appear\n");
        });

        $this->assertSame('', $buffer);
    }

    /**
     * A document is printed even under quiet mode, and the output remembers
     * it was: a fatal error after it must not print a second document.
     */
    #[Test]
    #[DataProvider('provideQuietModes')]
    public function test_has_written_document_after_a_document(bool $quiet): void
    {
        $output = new Output(false, $quiet);
        $this->assertFalse($output->hasWrittenDocument());

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->writeDocument("{}\n");
        });

        $this->assertSame("{}\n", $buffer);
        $this->assertTrue($output->hasWrittenDocument());
    }

    /**
     * @return iterable<string, array{quiet: bool}>
     */
    public static function provideQuietModes(): iterable
    {
        yield 'not quiet' => ['quiet' => false];
        yield 'quiet' => ['quiet' => true];
    }

    /**
     * Status lines and errors are no document: after them a JSON run has
     * still printed nothing a program reads.
     */
    #[Test]
    public function test_has_written_document_ignores_status_lines_and_errors(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $output = new Output(false, false, '#', '-', $errors);

        $this->captureOutput(static function () use ($output): void {
            $output->write("a status line\n");
            $output->writeError("an error\n");
        });
        fclose($errors);

        $this->assertFalse($output->hasWrittenDocument());
    }

    /**
     * The progress is drawn on stderr: stdout keeps the report alone, so a
     * report redirected to a file holds no progress frame.
     */
    #[Test]
    public function test_progress_outputs_when_ansi_enabled(): void
    {
        [$output, $errors] = $this->outputWithErrorStream(true, false);

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->progressStart(3);
            $output->progressAdvance();
            $output->progressFinish();
        });
        $progress = $this->readStream($errors);

        $this->assertStringContainsString('1/3', $progress);
        $this->assertStringContainsString('3/3', $progress);
        $this->assertSame('', $buffer, 'The progress is not drawn on stdout.');
    }

    /**
     * A tick redraws the line with the time spent, the count unchanged: the
     * clock moves while the run waits on its workers.
     */
    #[Test]
    public function test_progress_tick_redraws_the_elapsed_time_without_changing_the_count(): void
    {
        [$output, $errors] = $this->outputWithErrorStream(true, false);

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->progressStart(3);
            $output->progressAdvance();
        });
        $beforeTick = $this->readStream($errors);

        // Past the first second: the elapsed column reads 00:01 at least,
        // whatever the precision the clock is measured with.
        usleep(1_100_000);
        $buffer .= $this->captureOutput(static function () use ($output): void {
            $output->progressTick();
        });
        $tick = substr($this->readStream($errors), \strlen($beforeTick));

        $this->assertMatchesRegularExpression('/ 1\/3 \[#{9}-{19}\]  33%    00:00$/', $beforeTick);
        $this->assertMatchesRegularExpression('/^\r {13}1\/3 \[#{9}-{19}\]  33%    00:0[1-9]$/', $tick);
        $this->assertStringNotContainsString('2/3', $tick);
        $this->assertSame('', $buffer);
    }

    /**
     * A step whose total is unknown, the walk that finds the files, shows a
     * counter: what was found so far, with no bar and no percentage.
     */
    #[Test]
    public function test_progress_without_a_total_counts_what_was_found(): void
    {
        [$output, $errors] = $this->outputWithErrorStream(true, false);

        $buffer = $this->captureOutput(static function () use ($output): void {
            $output->progressStart(null);
            $output->progressLabel('Finding files');
            $output->progressAdvance(1000);
            $output->progressAdvance(234);
            $output->progressTick();
            $output->progressFinish();
        });
        $progress = $this->readStream($errors);

        $this->assertMatchesRegularExpression('/Finding files\n[^\e]*\r +1234 found +\d{2}:\d{2}/', $progress);
        $this->assertStringContainsString('1000 found', $progress);
        $this->assertStringNotContainsString('%', $progress);
        $this->assertStringNotContainsString('[', implode("\n", TerminalScreen::render($progress)), 'No bar.');
        $this->assertStringNotContainsString('/', $progress, 'No total: no "current/total" count.');
        $this->assertSame('', $buffer);
    }

    /**
     * The sub-step's name stands on a line of its own above the bar, so
     * that the bar starts where an unnamed step's does.
     */
    #[Test]
    public function test_progress_label_stands_above_the_bar(): void
    {
        [$output, $errors] = $this->outputWithErrorStream(true, false);
        [$unnamed, $unnamedErrors] = $this->outputWithErrorStream(true, false);

        $this->captureOutput(static function () use ($output, $unnamed): void {
            $output->progressStart(10);
            $output->progressLabel('Patterns');
            $output->progressAdvance(3);
            $unnamed->progressStart(10);
            $unnamed->progressAdvance(3);
        });
        $screen = TerminalScreen::render($this->readStream($errors));
        $unnamedScreen = TerminalScreen::render($this->readStream($unnamedErrors));

        $this->assertCount(2, $screen, (string) json_encode($screen, \JSON_UNESCAPED_UNICODE));
        $this->assertSame('   Patterns', $screen[0]);
        $this->assertStringStartsWith(' '.str_pad('3/10', 15, ' ', \STR_PAD_LEFT).' [########--------------------]  30% ', $screen[1]);
        $this->assertSame($unnamedScreen[0], $screen[1], 'The named bar starts where the unnamed one does.');
    }

    /**
     * A new sub-step takes over both lines in place: its name replaces the
     * last one, above a bar of its own.
     */
    #[Test]
    public function test_progress_new_label_replaces_the_last_one_above_the_bar(): void
    {
        [$output, $errors] = $this->outputWithErrorStream(true, false);

        $this->captureOutput(static function () use ($output): void {
            $output->progressLabel('Declarations');
            $output->progressStart(2);
            $output->progressAdvance(2);
            $output->progressLabel('Patterns');
            $output->progressStart(4);
            $output->progressAdvance(1);
            $output->progressFinish();
        });
        $stream = $this->readStream($errors);
        $screen = TerminalScreen::render($stream);

        $this->assertStringContainsString('Declarations', $stream);
        $this->assertSame('   Patterns', $screen[0], (string) json_encode($screen, \JSON_UNESCAPED_UNICODE));
        $this->assertStringStartsWith(' '.str_pad('4/4', 15, ' ', \STR_PAD_LEFT).' [############################] 100% ', $screen[1]);
        $this->assertSame(['', ''], \array_slice($screen, 2), 'The finished progress is followed by a blank line.');
    }

    /**
     * Without ANSI (a log, a pipe) or under quiet, no progress at all, on
     * either stream: neither the frames nor a line per tick.
     */
    #[Test]
    #[DataProvider('provideSilentProgress')]
    public function test_progress_writes_nothing_when_not_ansi_or_quiet(bool $ansi, bool $quiet, ?int $total): void
    {
        [$output, $errors] = $this->outputWithErrorStream($ansi, $quiet);

        $buffer = $this->captureOutput(static function () use ($output, $total): void {
            $output->progressStart($total);
            $output->progressLabel('Declarations');
            $output->progressAdvance();
            $output->progressTick();
            $output->progressAdvance(2);
            $output->progressTick();
            $output->progressFinish();
        });

        $this->assertSame('', $buffer);
        $this->assertSame('', $this->readStream($errors));
    }

    /**
     * @return iterable<string, array{ansi: bool, quiet: bool, total: int|null}>
     */
    public static function provideSilentProgress(): iterable
    {
        yield 'not ansi, with a total' => ['ansi' => false, 'quiet' => false, 'total' => 5];
        yield 'not ansi, total unknown' => ['ansi' => false, 'quiet' => false, 'total' => null];
        yield 'quiet with ansi, with a total' => ['ansi' => true, 'quiet' => true, 'total' => 5];
        yield 'quiet with ansi, total unknown' => ['ansi' => true, 'quiet' => true, 'total' => null];
        yield 'quiet without ansi' => ['ansi' => false, 'quiet' => true, 'total' => 5];
    }

    #[Test]
    public function test_format_elapsed_handles_hours_and_minutes(): void
    {
        $output = new Output(false, false);
        $method = new \ReflectionMethod(Output::class, 'formatElapsed');

        $this->assertSame('01:01:01', $method->invoke($output, 3661));
        $this->assertSame('00:05', $method->invoke($output, 5));
    }

    #[Test]
    public function test_is_quiet_returns_quiet_flag(): void
    {
        $output = new Output(false, true);
        $this->assertTrue($output->isQuiet());

        $output->setQuiet(false);
        $this->assertFalse($output->isQuiet());
    }

    #[Test]
    public function test_progress_start_sets_progress_active_to_false_when_total_is_zero_or_negative(): void
    {
        $output = new Output(true, false);

        $output->progressStart(0);
        $this->assertFalse($this->getProgressActive($output));

        $output->progressStart(-1);
        $this->assertFalse($this->getProgressActive($output));
    }

    #[Test]
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

    /**
     * An output whose error stream, where the progress is drawn, is kept in
     * memory.
     *
     * @return array{Output, resource}
     */
    private function outputWithErrorStream(bool $ansi, bool $quiet): array
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        return [new Output($ansi, $quiet, '#', '-', $errors), $errors];
    }

    /**
     * Everything written to the stream so far.
     *
     * @param resource $stream
     */
    private function readStream($stream): string
    {
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fseek($stream, 0, \SEEK_END);

        return $content;
    }

    private function captureOutput(callable $callback): string
    {
        ob_start();

        try {
            $callback();
        } finally {
            $buffer = (string) ob_get_clean();
        }

        return $buffer;
    }
}
