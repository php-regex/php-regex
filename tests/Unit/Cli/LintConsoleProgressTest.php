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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\LintConsoleProgress;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\LintProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint run's steps on the console: the status lines and the progress
 * frames on the error stream, the scan's sub-steps on one line, and the
 * counts the footer reads, shown or not.
 */
final class LintConsoleProgressTest extends TestCase
{
    /**
     * @var resource
     */
    private $errors;

    protected function setUp(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $this->errors = $errors;
    }

    protected function tearDown(): void
    {
        fclose($this->errors);
    }

    /**
     * A tick redraws the line, the count unchanged: the clock moves while
     * the run waits on its workers.
     */
    #[Test]
    public function test_tick_redraws_the_progress_line_without_advancing(): void
    {
        $progress = $this->progress(ansi: true, show: true);
        $progress->step(LintProgress::STEP_ANALYSIS, 3);
        $progress->advance();
        $beforeTick = $this->errors();

        $progress->tick();
        $tick = substr($this->errors(), \strlen($beforeTick));

        $this->assertMatchesRegularExpression('/^\r +1\/3 \[#{9}-{19}\]  33% +\d{2}:\d{2}$/', $tick);
        $this->assertStringNotContainsString('2/3', $tick);
    }

    /**
     * Steps not shown draw nothing, a tick included: the progress never
     * started.
     */
    #[Test]
    public function test_tick_draws_nothing_when_the_steps_are_not_shown(): void
    {
        $progress = $this->progress(ansi: true, show: false);
        $progress->step(LintProgress::STEP_ANALYSIS, 3);
        $progress->advance();
        $progress->tick();
        $progress->tick();
        $progress->finish();

        $this->assertSame('', $this->errors());
    }

    /**
     * The scan's sub-steps share one line under the step's status line:
     * each takes the line over in place, and the scan ends it once.
     */
    #[Test]
    public function test_scan_sub_steps_share_one_line(): void
    {
        $progress = $this->progress(ansi: true, show: true);

        $this->scan($progress, files: 3, declarations: 2, functions: 1, patterns: 4);
        $progress->endScan();
        $errors = self::withoutColours($this->errors());

        $this->assertStringStartsWith("  [1/3] Scanning files\n\r", $errors);
        $this->assertStringEndsWith("\n\n", $errors);
        $line = substr($errors, \strlen("  [1/3] Scanning files\n"), -2);
        $this->assertStringNotContainsString("\n", $line, 'A sub-step starts a line of its own: '.json_encode($errors));
        $this->assertSame(1, substr_count($errors, 'Scanning files'));
        $this->assertMatchesRegularExpression('/\r   Finding files +3 found +\d{2}:\d{2}/', $line);
        $this->assertMatchesRegularExpression('/\r   Declarations +2\/2 \[#{28}\] 100%/', $line);
        $this->assertMatchesRegularExpression('/\r   Functions +1\/1 \[#{28}\] 100%/', $line);
        $this->assertMatchesRegularExpression('/\r   Patterns +4\/4 \[#{28}\] 100%/', $line);
        $positions = array_map(static fn (string $label): int|false => strpos($line, $label), ['Finding files', 'Declarations', 'Functions', 'Patterns']);
        $this->assertNotContains(false, $positions, 'A sub-step label is missing from the line.');
        $order = array_map(intval(...), $positions);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'The sub-steps are drawn out of order.');
    }

    /**
     * The scan's line is ended once: the next step, or a second call,
     * writes no more line ends.
     */
    #[Test]
    public function test_end_scan_ends_the_line_once(): void
    {
        $progress = $this->progress(ansi: true, show: true);
        $this->scan($progress, files: 1, declarations: 1, functions: 0, patterns: 1);

        $progress->endScan();
        $ended = $this->errors();
        $progress->endScan();

        $this->assertSame($ended, $this->errors());
    }

    /**
     * The analysis starting while the scan's line is open ends it first:
     * its status line never trails the scan's last frame.
     */
    #[Test]
    public function test_analysis_step_ends_the_scan_line_first(): void
    {
        $progress = $this->progress(ansi: true, show: true);
        $this->scan($progress, files: 1, declarations: 1, functions: 0, patterns: 1);

        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $errors = self::withoutColours($this->errors());

        $this->assertMatchesRegularExpression('/\n  \[2\/3\] Analyzing patterns\n\r +0\/1 /', $errors);
    }

    /**
     * A step with nothing to do draws no bar: the line keeps what it shows.
     *
     * @param list<string> $absent
     */
    #[Test]
    #[DataProvider('provideEmptySteps')]
    public function test_step_with_a_total_of_zero_starts_no_progress(string $step, array $absent): void
    {
        $progress = $this->progress(ansi: true, show: true);
        $progress->step($step, 0);
        $progress->advance();
        $progress->tick();
        $progress->finish();
        $errors = self::withoutColours($this->errors());

        $this->assertStringNotContainsString("\r", $errors, 'A progress line was drawn: '.json_encode($errors));
        foreach ($absent as $text) {
            $this->assertStringNotContainsString($text, $errors);
        }
    }

    /**
     * @return iterable<string, array{step: string, absent: list<string>}>
     */
    public static function provideEmptySteps(): iterable
    {
        yield 'no declaration to read' => ['step' => LintProgress::STEP_DECLARATIONS, 'absent' => ['Declarations', '0/0']];
        yield 'no function to read' => ['step' => LintProgress::STEP_FUNCTIONS, 'absent' => ['Functions', '0/0']];
        yield 'no pattern file to read' => ['step' => LintProgress::STEP_PATTERNS, 'absent' => ['Patterns', '0/0']];
        yield 'no pattern to analyse' => ['step' => LintProgress::STEP_ANALYSIS, 'absent' => ['0/0', '%']];
        yield 'no optimization to suggest' => ['step' => LintProgress::STEP_OPTIMIZATIONS, 'absent' => ['0/0', '%']];
    }

    /**
     * No file found: the walk's counter is the scan's only sub-step, and
     * the footer counts no file.
     */
    #[Test]
    public function test_scan_of_no_file_ends_the_finding_files_line(): void
    {
        $progress = $this->progress(ansi: true, show: true);

        $this->scan($progress, files: 0, declarations: 0, functions: 0, patterns: 0);
        $progress->endScan();
        $errors = self::withoutColours($this->errors());

        $this->assertMatchesRegularExpression('/^  \[1\/3\] Scanning files\n(?:\r   Finding files +0 found +\d{2}:\d{2})+\n\n$/', $errors);
        $this->assertSame(0, $progress->files());
    }

    /**
     * The step numbers count the optimizations as a step of their own,
     * only when the run suggests them.
     *
     * @param list<string> $present
     */
    #[Test]
    #[DataProvider('provideStepNumbers')]
    public function test_status_lines_number_the_steps(bool $optimizations, array $present, string $absent): void
    {
        $progress = new LintConsoleProgress(new Output(false, false, '#', '-', $this->errors), true, $optimizations);
        $this->scan($progress, files: 1, declarations: 1, functions: 0, patterns: 1);
        $progress->endScan();
        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $progress->advance();
        $progress->finish();
        if ($optimizations) {
            $progress->step(LintProgress::STEP_OPTIMIZATIONS, 1);
            $progress->advance();
            $progress->finish();
        }

        // Without ANSI the status lines are plain and no frame is drawn.
        $this->assertSame(implode('', array_map(static fn (string $line): string => '  '.$line."\n", $present)), $this->errors());
        $this->assertStringNotContainsString($absent, $this->errors());
    }

    /**
     * @return iterable<string, array{optimizations: bool, present: list<string>, absent: string}>
     */
    public static function provideStepNumbers(): iterable
    {
        yield 'with the optimizations' => [
            'optimizations' => true,
            'present' => ['[1/3] Scanning files', '[2/3] Analyzing patterns', '[3/3] Suggesting optimizations'],
            'absent' => '/2]',
        ];
        yield 'without the optimizations' => [
            'optimizations' => false,
            'present' => ['[1/2] Scanning files', '[2/2] Analyzing patterns'],
            'absent' => 'Suggesting optimizations',
        ];
    }

    /**
     * The footer's process count is the most workers a step started, 1
     * when no step started any; a step with fewer does not lower it.
     *
     * @param list<int> $announced
     */
    #[Test]
    #[DataProvider('provideWorkers')]
    public function test_processes_keeps_the_most_workers_started(array $announced, int $expected): void
    {
        $progress = $this->progress(ansi: false, show: false);
        foreach ($announced as $count) {
            $progress->workers($count);
        }

        $this->assertSame($expected, $progress->processes());
    }

    /**
     * @return iterable<string, array{announced: list<int>, expected: int}>
     */
    public static function provideWorkers(): iterable
    {
        yield 'no step forked' => ['announced' => [], 'expected' => 1];
        yield 'one step' => ['announced' => [3], 'expected' => 3];
        yield 'the most of several steps' => ['announced' => [2, 4, 3], 'expected' => 4];
        yield 'a step with fewer after' => ['announced' => [4, 1], 'expected' => 4];
        yield 'a count below one' => ['announced' => [0], 'expected' => 1];
    }

    /**
     * The files counted are those read for their patterns, whether the
     * steps are shown or not: not the files found, nor the declarations.
     */
    #[Test]
    #[DataProvider('provideShown')]
    public function test_files_counts_the_files_read_for_their_patterns(bool $show): void
    {
        $progress = $this->progress(ansi: true, show: $show);

        $this->scan($progress, files: 7, declarations: 9, functions: 2, patterns: 5);

        $this->assertSame(5, $progress->files());
    }

    /**
     * @return iterable<string, array{show: bool}>
     */
    public static function provideShown(): iterable
    {
        yield 'shown' => ['show' => true];
        yield 'not shown' => ['show' => false];
    }

    /**
     * Steps not shown write nothing at all, the status lines included.
     */
    #[Test]
    public function test_steps_not_shown_write_nothing(): void
    {
        $progress = $this->progress(ansi: true, show: false);

        $this->scan($progress, files: 2, declarations: 2, functions: 1, patterns: 2);
        $progress->endScan();
        $progress->step(LintProgress::STEP_ANALYSIS, 2);
        $progress->advance(2);
        $progress->finish();
        $progress->step(LintProgress::STEP_OPTIMIZATIONS, 2);
        $progress->advance(2);
        $progress->finish();

        $this->assertSame('', $this->errors());
    }

    private function progress(bool $ansi, bool $show): LintConsoleProgress
    {
        return new LintConsoleProgress(new Output($ansi, false, '#', '-', $this->errors), $show, true);
    }

    /**
     * The scan as the run reports it: the walk's counter, then each
     * sub-step's bar; a sub-step with nothing to read is announced with a
     * total of 0, the functions not at all.
     */
    private function scan(LintConsoleProgress $progress, int $files, int $declarations, int $functions, int $patterns): void
    {
        $progress->step(LintProgress::STEP_FILES, null);
        if ($files > 0) {
            $progress->advance($files);
        }
        $progress->finish();

        foreach ([LintProgress::STEP_DECLARATIONS => $declarations, LintProgress::STEP_FUNCTIONS => $functions, LintProgress::STEP_PATTERNS => $patterns] as $step => $total) {
            if (LintProgress::STEP_FUNCTIONS === $step && 0 === $total) {
                continue;
            }
            $progress->step($step, $total);
            if ($total > 0) {
                $progress->advance($total);
            }
            $progress->finish();
        }
    }

    private function errors(): string
    {
        rewind($this->errors);
        $content = (string) stream_get_contents($this->errors);
        fseek($this->errors, 0, \SEEK_END);

        return $content;
    }

    private static function withoutColours(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
    }
}
