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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Output;

use Illuminate\Console\OutputStyle;
use PHPRegex\Laravel\Output\LintProgressBar;
use PHPRegex\Linter\LintProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * regex:lint's progress on a console progress bar: the scan's sub-steps on
 * one line redrawn in place on a terminal, a line each in a log, and the
 * clock redrawn while the run waits on a terminal only.
 *
 * The clock is a fake one, set as the bars' elapsed placeholder: it moves
 * on every redraw, so a redraw shows as a new frame without the test
 * waiting for the wall clock to cross a second.
 */
final class LintProgressBarTest extends TestCase
{
    /**
     * Cursor sequences the console writes to redraw a line in place.
     */
    private const CLEAR_LINE = "\x1b[1G\x1b[2K";

    /**
     * @var resource
     */
    private $stream;

    private ?\Closure $elapsed = null;

    private int $clock = 0;

    protected function setUp(): void
    {
        $this->stream = self::memory();
        $elapsed = ProgressBar::getPlaceholderFormatterDefinition('elapsed');
        $this->elapsed = null === $elapsed ? null : $elapsed(...);
        $this->clock = 0;
        ProgressBar::setPlaceholderFormatterDefinition('elapsed', fn (): string => 'clock'.++$this->clock);
    }

    protected function tearDown(): void
    {
        if (null !== $this->elapsed) {
            ProgressBar::setPlaceholderFormatterDefinition('elapsed', $this->elapsed);
        }
        fclose($this->stream);
    }

    /**
     * On a terminal the scan's sub-steps take one line over in turn: the
     * line is cleared before the next is drawn, and no line ends until the
     * scan does.
     */
    #[Test]
    public function test_decorated_scan_redraws_its_sub_steps_on_one_line(): void
    {
        $progress = $this->progress(decorated: true);

        self::scan($progress, files: 3, declarations: 2, functions: 1, patterns: 4);
        $line = self::read($this->stream);
        $progress->close();

        $this->assertStringNotContainsString("\n", $line, 'A sub-step starts a line of its own on a terminal: '.json_encode($line));
        $this->assertStringEndsWith("\n", self::read($this->stream), 'The scan\'s line is not ended.');
        $this->assertMatchesRegularExpression('/Finding files +3 found clock\d+/', $line);
        $this->assertMatchesRegularExpression('/Declarations +2\/2 \[/', $line);
        $this->assertMatchesRegularExpression('/Functions +1\/1 \[/', $line);
        $this->assertMatchesRegularExpression('/Patterns +4\/4 \[/', $line);
        $previous = 0;
        foreach (['Finding files', 'Declarations', 'Functions', 'Patterns'] as $label) {
            $position = strpos($line, $label, $previous);
            $this->assertNotFalse($position, $label.' is not drawn after the sub-step before it.');
            if ('Finding files' !== $label) {
                $this->assertStringContainsString(self::CLEAR_LINE, substr($line, $previous, $position - $previous), 'The line is not cleared before '.$label.'.');
            }
            $previous = $position;
        }
    }

    /**
     * In a log each sub-step with a total starts a line of its own, in
     * the order the scan runs them, with no escape sequence.
     */
    #[Test]
    public function test_undecorated_scan_writes_a_line_per_sub_step(): void
    {
        $progress = $this->progress(decorated: false);

        self::scan($progress, files: 3, declarations: 2, functions: 1, patterns: 4);
        $progress->close();
        $written = self::read($this->stream);

        $this->assertStringNotContainsString("\x1b[", $written);
        $this->assertMatchesRegularExpression('/^ *Declarations +2\/2 \[[^\n]*$/m', $written);
        $this->assertMatchesRegularExpression('/^ *Functions +1\/1 \[[^\n]*$/m', $written);
        $this->assertMatchesRegularExpression('/^ *Patterns +4\/4 \[[^\n]*$/m', $written);
        $this->assertLessThan(strpos($written, 'Functions'), strpos($written, 'Declarations'));
        $this->assertLessThan(strpos($written, 'Patterns'), strpos($written, 'Functions'));
    }

    /**
     * In a log the walk's counter, redrawn batch after batch with no total,
     * adds no line per batch: a CI log of a large project would get
     * hundreds of "found" lines. The batches here come 50 ms apart, past
     * the bar's own redraw throttle, as they do on a large walk.
     */
    #[Test]
    public function test_undecorated_finding_files_adds_no_line_per_batch(): void
    {
        $progress = $this->progress(decorated: false);

        $progress->step(LintProgress::STEP_FILES, null);
        for ($batch = 0; $batch < 5; ++$batch) {
            usleep(50_000);
            $progress->advance(1000);
        }
        $progress->finish();
        $progress->close();
        $written = self::read($this->stream);

        $this->assertLessThanOrEqual(1, substr_count($written, 'found'), 'A line per batch of files found in a log: '.json_encode($written));
        $this->assertSame(5000, $progress->filesFound());
    }

    /**
     * On a terminal the walk's counter shows what it found so far, with
     * no bar and no percentage.
     */
    #[Test]
    public function test_decorated_finding_files_counts_what_was_found(): void
    {
        $progress = $this->progress(decorated: true);
        $progress->step(LintProgress::STEP_FILES, null);
        $progress->advance(1000);
        $progress->advance(234);
        $progress->finish();
        $written = self::read($this->stream);

        $this->assertMatchesRegularExpression('/Finding files +1234 found clock\d+/', $written);
        $this->assertStringNotContainsString('%', $written);
        $this->assertStringNotContainsString('[', str_replace("\x1b[", '', $written));
        $this->assertSame(1234, $progress->filesFound());
    }

    /**
     * On a terminal a tick redraws the bar: the clock moves, the count
     * does not.
     */
    #[Test]
    public function test_decorated_tick_redraws_the_clock(): void
    {
        $progress = $this->progress(decorated: true);
        $progress->step(LintProgress::STEP_ANALYSIS, 3);
        $progress->advance();
        $beforeTick = self::read($this->stream);

        $progress->tick();
        $tick = substr(self::read($this->stream), \strlen($beforeTick));

        $this->assertMatchesRegularExpression('/1\/3 \[[^\]]*\] +33% +clock\d+/', $tick);
        $this->assertStringNotContainsString('2/3', $tick);
    }

    /**
     * In a log a tick writes nothing, though the clock would read on: a
     * line per tick would flood it.
     */
    #[Test]
    public function test_undecorated_tick_writes_nothing(): void
    {
        $progress = $this->progress(decorated: false);
        $progress->step(LintProgress::STEP_ANALYSIS, 3);
        $progress->advance();
        $beforeTick = self::read($this->stream);

        $progress->tick();
        $progress->tick();

        $this->assertSame($beforeTick, self::read($this->stream));
    }

    /**
     * A tick before any step, or once the step has finished, draws
     * nothing: no bar is running.
     */
    #[Test]
    public function test_tick_without_a_running_bar_writes_nothing(): void
    {
        $progress = $this->progress(decorated: true);
        $progress->tick();
        $this->assertSame('', self::read($this->stream));

        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $progress->advance();
        $progress->finish();
        $finished = self::read($this->stream);
        $progress->tick();

        $this->assertSame($finished, self::read($this->stream));
    }

    /**
     * The step numbers count the optimizations as a step of their own,
     * only when the run suggests them.
     *
     * @param list<string> $present
     */
    #[Test]
    #[DataProvider('provideStepNumbers')]
    public function test_headings_number_the_steps(int $steps, array $present, string $absent): void
    {
        $progress = new LintProgressBar(self::style($this->stream, false), $steps);
        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $progress->advance();
        $progress->finish();
        if (3 === $steps) {
            $progress->step(LintProgress::STEP_OPTIMIZATIONS, 1);
            $progress->advance();
            $progress->finish();
        }
        $written = self::read($this->stream);

        $offset = 0;
        foreach ($present as $line) {
            $position = strpos($written, $line, $offset);
            $this->assertNotFalse($position, $line.' is missing: '.$written);
            $offset = $position;
        }
        $this->assertStringNotContainsString($absent, $written);
    }

    /**
     * @return iterable<string, array{steps: int, present: list<string>, absent: string}>
     */
    public static function provideStepNumbers(): iterable
    {
        yield 'with the optimizations' => [
            'steps' => 3,
            'present' => ['[2/3] Analyzing patterns', '[3/3] Suggesting optimizations'],
            'absent' => '/2]',
        ];
        yield 'without the optimizations' => [
            'steps' => 2,
            'present' => ['[2/2] Analyzing patterns'],
            'absent' => 'Suggesting optimizations',
        ];
    }

    /**
     * A step with nothing to do draws no bar, as on the binary's console
     * and the Symfony command: no "0/0" line.
     */
    #[Test]
    #[DataProvider('provideEmptySteps')]
    public function test_step_with_a_total_of_zero_draws_no_bar(string $step, bool $decorated): void
    {
        $progress = $this->progress($decorated);
        $progress->step($step, 0);
        $progress->tick();
        $progress->finish();
        $progress->close();

        $this->assertStringNotContainsString('0/0', self::read($this->stream));
        $this->assertStringNotContainsString('%', self::read($this->stream));
    }

    /**
     * @return iterable<string, array{step: string, decorated: bool}>
     */
    public static function provideEmptySteps(): iterable
    {
        yield 'no declaration, on a terminal' => ['step' => LintProgress::STEP_DECLARATIONS, 'decorated' => true];
        yield 'no declaration, in a log' => ['step' => LintProgress::STEP_DECLARATIONS, 'decorated' => false];
        yield 'no pattern to analyse, on a terminal' => ['step' => LintProgress::STEP_ANALYSIS, 'decorated' => true];
        yield 'no pattern to analyse, in a log' => ['step' => LintProgress::STEP_ANALYSIS, 'decorated' => false];
    }

    /**
     * A run stopped mid-step ends the bar's line once: what the command
     * writes next starts a line of its own.
     */
    #[Test]
    #[DataProvider('provideDecorated')]
    public function test_close_ends_an_open_line_once(bool $decorated): void
    {
        $progress = $this->progress($decorated);
        $progress->step(LintProgress::STEP_ANALYSIS, 2);
        $progress->advance();

        $progress->close();
        $ended = self::read($this->stream);
        $progress->close();

        $this->assertStringEndsWith("\n", $ended);
        $this->assertSame($ended, self::read($this->stream));
    }

    /**
     * @return iterable<string, array{decorated: bool}>
     */
    public static function provideDecorated(): iterable
    {
        yield 'on a terminal' => ['decorated' => true];
        yield 'in a log' => ['decorated' => false];
    }

    /**
     * On a console with an error output the bars and their line ends go
     * there; the headings stay with the report.
     */
    #[Test]
    public function test_bars_are_drawn_on_the_error_output_of_a_console(): void
    {
        $errors = self::memory();
        $console = self::console($this->stream, new StreamOutput($errors, OutputInterface::VERBOSITY_NORMAL, true));
        $progress = new LintProgressBar(new OutputStyle(new ArrayInput([]), $console), 3);

        self::scan($progress, files: 1, declarations: 1, functions: 1, patterns: 1);
        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $progress->advance();
        $progress->finish();
        $progress->close();
        $standard = self::read($this->stream);
        $error = self::read($errors);
        fclose($errors);

        $this->assertStringContainsString('[2/3] Analyzing patterns', $standard);
        $this->assertStringNotContainsString('Patterns ', $standard);
        $this->assertStringNotContainsString('1/1', $standard);
        $this->assertStringContainsString('Patterns ', $error);
        $this->assertStringContainsString('1/1', $error);
        $this->assertStringNotContainsString('Analyzing patterns', $error);
    }

    /**
     * The footer's process count is the most workers a step started, 1
     * when none did.
     *
     * @param list<int> $announced
     */
    #[Test]
    #[DataProvider('provideWorkers')]
    public function test_workers_started_keeps_the_most(array $announced, int $expected): void
    {
        $progress = $this->progress(decorated: false);
        foreach ($announced as $count) {
            $progress->workers($count);
        }

        $this->assertSame($expected, $progress->workersStarted());
    }

    /**
     * @return iterable<string, array{announced: list<int>, expected: int}>
     */
    public static function provideWorkers(): iterable
    {
        yield 'no step forked' => ['announced' => [], 'expected' => 1];
        yield 'the most of several steps' => ['announced' => [2, 4, 3], 'expected' => 4];
        yield 'a count below one' => ['announced' => [0], 'expected' => 1];
    }

    private function progress(bool $decorated): LintProgressBar
    {
        return new LintProgressBar(self::style($this->stream, $decorated), 3);
    }

    /**
     * @param resource $stream
     */
    private static function style($stream, bool $decorated): OutputStyle
    {
        return new OutputStyle(new ArrayInput([]), new StreamOutput($stream, OutputInterface::VERBOSITY_NORMAL, $decorated));
    }

    /**
     * A console whose standard and error outputs are kept apart in memory.
     *
     * @param resource $stream
     */
    private static function console($stream, OutputInterface $errorOutput): ConsoleOutputInterface
    {
        return new class($stream, $errorOutput) extends StreamOutput implements ConsoleOutputInterface {
            /**
             * @param resource $stream
             */
            public function __construct($stream, private OutputInterface $errorOutput)
            {
                parent::__construct($stream, OutputInterface::VERBOSITY_NORMAL, true);
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errorOutput;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->errorOutput = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('No section in this test.');
            }
        };
    }

    /**
     * The scan as the run reports it: the walk's counter, then each
     * sub-step's bar.
     */
    private static function scan(LintProgress $progress, int $files, int $declarations, int $functions, int $patterns): void
    {
        $progress->step(LintProgress::STEP_FILES, null);
        $progress->advance($files);
        $progress->finish();
        foreach ([LintProgress::STEP_DECLARATIONS => $declarations, LintProgress::STEP_FUNCTIONS => $functions, LintProgress::STEP_PATTERNS => $patterns] as $step => $total) {
            $progress->step($step, $total);
            $progress->advance($total);
            $progress->finish();
        }
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('No memory stream.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function read($stream): string
    {
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fseek($stream, 0, \SEEK_END);

        return $content;
    }
}
