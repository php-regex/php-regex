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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Output;

use PHPRegex\Linter\LintProgress;
use PHPRegex\Symfony\Output\LintProgressBar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The lint progress on console progress bars: the scan's sub-steps on one
 * line redrawn in place on a terminal, a line each in a log, and the clock
 * redrawn while the run waits on a terminal only.
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
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $this->stream = $stream;
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
        $line = $this->written();
        $progress->endLine();

        $this->assertStringNotContainsString("\n", $line, 'A sub-step starts a line of its own on a terminal: '.json_encode($line));
        $this->assertStringEndsWith("\n", $this->written(), 'The scan\'s line is not ended.');
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
     * In a log each sub-step with a total starts a line of its own, and the
     * walk's counter, redrawn batch after batch, is not drawn at all.
     */
    #[Test]
    public function test_undecorated_scan_writes_a_line_per_sub_step(): void
    {
        $progress = $this->progress(decorated: false);

        self::scan($progress, files: 3, declarations: 2, functions: 1, patterns: 4);
        $progress->endLine();
        $written = $this->written();

        $this->assertStringNotContainsString('found', $written);
        $this->assertStringNotContainsString("\x1b[", $written);
        $this->assertMatchesRegularExpression('/^ *Declarations +2\/2 \[[^\n]*\n *Functions +1\/1 \[[^\n]*\n *Patterns +4\/4 \[[^\n]*\n$/', self::lastFrames($written));
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
        $beforeTick = $this->written();

        $progress->tick();
        $tick = substr($this->written(), \strlen($beforeTick));

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
        $beforeTick = $this->written();

        $progress->tick();
        $progress->tick();

        $this->assertSame($beforeTick, $this->written());
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
        $this->assertSame('', $this->written());

        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $progress->advance();
        $progress->finish();
        $finished = $this->written();
        $progress->tick();

        $this->assertSame($finished, $this->written());
    }

    /**
     * The walk's counter shows what it found so far on a terminal, with
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
        $written = $this->written();

        $this->assertStringContainsString('1234 found', $written);
        $this->assertStringNotContainsString('%', $written);
        $this->assertStringNotContainsString('[', str_replace("\x1b[", '', $written));
        $this->assertSame(1234, $progress->files());
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
        $progress = new LintProgressBar($this->style(false), $optimizations);
        $progress->step(LintProgress::STEP_ANALYSIS, 1);
        $progress->advance();
        $progress->finish();
        if ($optimizations) {
            $progress->step(LintProgress::STEP_OPTIMIZATIONS, 1);
            $progress->advance();
            $progress->finish();
        }
        $written = $this->written();

        $offset = 0;
        foreach ($present as $line) {
            $position = strpos($written, $line, $offset);
            $this->assertNotFalse($position, $line.' is missing: '.$written);
            $offset = $position;
        }
        $this->assertStringNotContainsString($absent, $written);
    }

    /**
     * @return iterable<string, array{optimizations: bool, present: list<string>, absent: string}>
     */
    public static function provideStepNumbers(): iterable
    {
        yield 'with the optimizations' => [
            'optimizations' => true,
            'present' => ['[2/3] Analyzing patterns', '[3/3] Suggesting optimizations'],
            'absent' => '/2]',
        ];
        yield 'without the optimizations' => [
            'optimizations' => false,
            'present' => ['[2/2] Analyzing patterns'],
            'absent' => 'Suggesting optimizations',
        ];
    }

    /**
     * A step with nothing to do draws no bar.
     */
    #[Test]
    #[DataProvider('provideDecorated')]
    public function test_step_with_a_total_of_zero_draws_no_bar(bool $decorated): void
    {
        $progress = $this->progress($decorated);
        $progress->step(LintProgress::STEP_DECLARATIONS, 0);
        $progress->advance();
        $progress->tick();
        $progress->finish();
        $progress->endLine();

        $this->assertSame('', $this->written());
    }

    /**
     * A run stopped mid-step ends the bar's line once: what the command
     * writes next starts a line of its own.
     */
    #[Test]
    #[DataProvider('provideDecorated')]
    public function test_end_line_ends_an_open_line_once(bool $decorated): void
    {
        $progress = $this->progress($decorated);
        $progress->step(LintProgress::STEP_ANALYSIS, 2);
        $progress->advance();

        $progress->endLine();
        $ended = $this->written();
        $progress->endLine();

        $this->assertStringEndsWith("\n", $ended);
        $this->assertSame($ended, $this->written());
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

    /**
     * The bars are drawn on the error output: its decoration decides the
     * redraws, and the lines the bars stand on end there, whatever the
     * standard output is.
     */
    #[Test]
    public function test_bars_follow_the_error_output_not_the_standard_output(): void
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $console = new class($this->stream, $errors) extends StreamOutput implements ConsoleOutputInterface {
            private OutputInterface $error;

            /**
             * @param resource $stdout
             * @param resource $stderr
             */
            public function __construct($stdout, $stderr)
            {
                parent::__construct($stdout, OutputInterface::VERBOSITY_NORMAL, true);
                $this->error = new StreamOutput($stderr, OutputInterface::VERBOSITY_NORMAL, false);
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->error;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->error = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('No section.');
            }
        };

        $progress = new LintProgressBar(new SymfonyStyle(new ArrayInput([]), $console), true);
        $progress->step(LintProgress::STEP_ANALYSIS, 2);
        $progress->advance();
        rewind($errors);
        $before = (string) stream_get_contents($errors);
        $progress->tick();
        rewind($errors);
        $afterTick = (string) stream_get_contents($errors);
        $progress->advance();
        $progress->finish();
        rewind($errors);
        $drawn = (string) stream_get_contents($errors);
        rewind($this->stream);
        $standard = (string) stream_get_contents($this->stream);
        fclose($errors);

        $this->assertSame($before, $afterTick, 'An undecorated error output gets no redraw per tick.');
        $this->assertStringEndsWith("\n", $drawn);
        $this->assertStringContainsString('2/2', $drawn);
        $this->assertStringNotContainsString('2/2', $standard);
        $this->assertStringContainsString('Analyzing patterns', $standard);
        $this->assertStringEndsWith("Analyzing patterns\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $standard));
    }

    private function progress(bool $decorated): LintProgressBar
    {
        return new LintProgressBar($this->style($decorated), true);
    }

    private function style(bool $decorated): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new StreamOutput($this->stream, OutputInterface::VERBOSITY_NORMAL, $decorated));
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
     * The last frame of each line: a log may hold a frame per redraw.
     */
    private static function lastFrames(string $written): string
    {
        $frames = [];
        foreach (explode("\n", rtrim($written, "\n")) as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $label = (string) preg_replace('/^ *(\w+).*$/s', '$1', $line);
            $frames[$label] = $line;
        }

        return implode("\n", $frames)."\n";
    }

    private function written(): string
    {
        rewind($this->stream);
        $content = (string) stream_get_contents($this->stream);
        fseek($this->stream, 0, \SEEK_END);

        return $content;
    }
}
