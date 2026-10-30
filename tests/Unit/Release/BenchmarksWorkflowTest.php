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

namespace PHPRegex\Tests\Unit\Release;

use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The benchmark workflow keeps two jobs apart: the smoke job runs every
 * subject once and gates; the table job measures the corpus subjects, on a
 * pull request against its base on the same runner, and never gates. The
 * scripts of its steps run here as GitHub runs them.
 */
#[RequiresOperatingSystem('Linux|Darwin')]
#[Group('documentation')]
final class BenchmarksWorkflowTest extends TestCase
{
    private string $scratch = '';

    protected function tearDown(): void
    {
        if ('' !== $this->scratch) {
            TemporaryDirectory::remove($this->scratch);
        }
    }

    #[Test]
    public function test_the_smoke_job_runs_every_subject_once_and_stops_on_error(): void
    {
        $job = self::job('benchmarks');

        $this->assertStringContainsString('name: Benchmarks (one run of each)', $job);
        $this->assertStringContainsString('run: tools/phpbench/vendor/bin/phpbench run --iterations=1 --revs=1 --progress=none --stop-on-error', $job);
        $this->assertStringNotContainsString('continue-on-error', $job, 'The smoke job gates.');
    }

    #[Test]
    public function test_the_table_job_never_gates_and_skips_dependabot(): void
    {
        $job = self::job('table');

        $this->assertStringContainsString('name: Benchmarks (table)', $job);
        $this->assertMatchesRegularExpression('~^    continue-on-error: true$~m', $job, 'The job, not a step, continues on error: the workflow conclusion stays the smoke job\'s.');
        $this->assertMatchesRegularExpression('~^    timeout-minutes: 30$~m', $job);
        $this->assertMatchesRegularExpression('~^    if: .*dependabot\[bot\]~m', $job);
    }

    #[Test]
    public function test_the_workflow_only_reads_the_repository(): void
    {
        $workflow = self::workflow();

        $this->assertSame(1, substr_count($workflow, 'permissions:'));
        $this->assertStringContainsString("permissions:\n  contents: read\n", $workflow);
        $this->assertStringNotContainsString(': write', $workflow);
    }

    /**
     * @return iterable<string, array{path: string, measured: bool}>
     */
    public static function provideChangedPaths(): iterable
    {
        yield 'the library' => ['path' => 'src/Parser/Lexer.php', 'measured' => true];
        yield 'a benchmark' => ['path' => 'tests/Benchmark/ParserBench.php', 'measured' => true];
        yield 'benchmark data' => ['path' => 'tests/Benchmark/data/parser/deep-nesting.php', 'measured' => true];
        yield 'the documentation alone' => ['path' => 'docs/maintainers.md', 'measured' => false];
        yield 'a unit test alone' => ['path' => 'tests/Unit/Lexer/LexerTest.php', 'measured' => false];
        yield 'the workflow alone' => ['path' => '.github/workflows/benchmarks.yml', 'measured' => false];
    }

    #[Test]
    #[DataProvider('provideChangedPaths')]
    public function test_a_pull_request_is_measured_when_the_measured_code_changes(string $path, bool $measured): void
    {
        [$code, $output] = $this->runStep('Decide whether the pull request is measured', $this->repositoryChanging($path));

        $this->assertSame(0, $code, $output);
        $this->assertSame('measure='.($measured ? 'true' : 'false')."\n", (string) file_get_contents($this->scratch.'/output'));
        $this->assertStringContainsString($measured ? 'measured' : 'not measured', $output);
    }

    #[Test]
    public function test_the_measuring_steps_run_only_on_a_measured_pull_request(): void
    {
        $job = self::job('table');

        foreach (['Check out the base commit', 'Decide whether the pull request is measured'] as $name) {
            $this->assertStringContainsString("if: github.event_name == 'pull_request'", self::stepBody($job, $name), $name);
        }
        foreach (['Install the base commit', 'Measure the base commit', 'Measure the pull request'] as $name) {
            $this->assertStringContainsString("if: steps.changes.outputs.measure == 'true'", self::stepBody($job, $name), $name);
        }
        foreach (['Measure the branch', 'Keep the numbers'] as $name) {
            $this->assertStringContainsString("if: github.event_name != 'pull_request'", self::stepBody($job, $name), $name);
        }
    }

    #[Test]
    public function test_the_base_is_measured_in_its_own_tree_and_the_pull_request_against_it(): void
    {
        $job = self::job('table');

        $base = self::stepBody($job, 'Measure the base commit');
        $this->assertStringContainsString('working-directory: base', $base);
        $this->assertStringContainsString('phpbench run --group=table --dump-file="$RUNNER_TEMP/base.xml"', $base);

        $pullRequest = self::stepBody($job, 'Measure the pull request');
        $this->assertStringNotContainsString('working-directory', $pullRequest);
        $this->assertStringContainsString('phpbench run --group=table --file="$RUNNER_TEMP/base.xml" --report=table --tolerate-failure', $pullRequest);
        // the marks go to the error stream: both streams reach the table file
        $this->assertStringContainsString('--no-ansi 2>&1 | tee "$RUNNER_TEMP/table.txt"', $pullRequest);

        $branch = self::stepBody($job, 'Measure the branch');
        $this->assertStringContainsString('phpbench run --group=table --report=table --dump-file=', $branch);
        $this->assertStringContainsString('| tee "$RUNNER_TEMP/table.txt"', $branch);
        $this->assertStringNotContainsString('--file=', $branch);
        $this->assertStringNotContainsString('--tolerate-failure', $branch, 'Nothing is asserted without a base.');

        // a pipe into tee only fails on a crash of phpbench under bash with pipefail, which a one-line step gets only when asked
        foreach ([$pullRequest, $branch] as $step) {
            $this->assertStringContainsString('shell: bash', $step);
        }
    }

    #[Test]
    public function test_the_base_is_installed_as_the_action_installs_the_tree(): void
    {
        $install = self::stepBody(self::job('table'), 'Install the base commit');

        $this->assertStringContainsString('working-directory: base', $install);
        $this->assertStringContainsString('composer install --prefer-dist --no-interaction --no-progress', $install);
        $this->assertStringContainsString('composer install -d tools/phpbench --no-interaction --no-progress', $install);
    }

    /**
     * @return iterable<string, array{event: string, sentence: string}>
     */
    public static function provideSummaries(): iterable
    {
        yield 'a pull request' => ['event' => 'pull_request', 'sentence' => 'Advisory. The base and the pull request were measured one after the other on the same runner, which still varies between the two passes; a ✘ marks a subject more than 30 % past its base. Nothing is required of the author.'];
        yield 'a push' => ['event' => 'push', 'sentence' => 'Measured once on a shared runner, no comparison; the numbers are kept as an artifact.'];
        yield 'the schedule' => ['event' => 'schedule', 'sentence' => 'Measured once on a shared runner, no comparison; the numbers are kept as an artifact.'];
    }

    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_the_summary_holds_the_sentence_and_the_table(string $event, string $sentence): void
    {
        $table = "+-----------+------------+\n| subject   | mode       |\n+-----------+------------+\n| benchParse | 145.268ms |\n+-----------+------------+\n";
        [$code, $output] = $this->runStep('Write the table to the job summary', ['GITHUB_EVENT_NAME' => $event, 'TABLE' => $table]);

        $this->assertSame(0, $code, $output);
        $summary = (string) file_get_contents($this->scratch.'/summary');
        $this->assertStringStartsWith("## Benchmarks\n\n".$sentence."\n\n```\n", $summary);
        $this->assertStringEndsWith("```\n", $summary);
        $this->assertStringContainsString("\n".$table.'```', $summary);
    }

    #[Test]
    public function test_the_branch_numbers_are_kept_as_an_artifact(): void
    {
        $keep = self::stepBody(self::job('table'), 'Keep the numbers');

        $uses = [];
        preg_match_all('~uses: (actions/upload-artifact@\S+)~', (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/packages.yml'), $uses);
        $this->assertNotSame([], $uses[1], 'The packages workflow uploads the phar with a pinned action.');
        $this->assertStringContainsString('uses: '.$uses[1][0], $keep);
        $this->assertStringContainsString('retention-days: 90', $keep);
        $this->assertStringContainsString('name: benchmarks-${{ github.sha }}', $keep);
    }

    /**
     * A git repository whose head commit changes one file of the base: the
     * environment the deciding step reads.
     *
     * @return array<string, string>
     */
    private function repositoryChanging(string $path): array
    {
        $this->scratch = sys_get_temp_dir().'/php-regex-benchmarks-workflow-'.bin2hex(random_bytes(6));
        mkdir($this->scratch.'/work', 0o777, true);
        $run = function (string $command): string {
            exec('cd '.escapeshellarg($this->scratch.'/work').' && '.$command.' 2>&1', $lines, $code);
            $this->assertSame(0, $code, $command.': '.implode("\n", $lines));

            return trim(implode("\n", $lines));
        };
        $run('git init -q && git config user.email t@example.com && git config user.name t && git config commit.gpgsign false');
        $run('echo base > README.md && git add README.md && git commit -q -m base');
        $base = $run('git rev-parse HEAD');
        $run('mkdir -p '.escapeshellarg(\dirname($path)).' && echo change > '.escapeshellarg($path).' && git add -A && git commit -q -m head');
        $head = $run('git rev-parse HEAD');

        return ['BASE_SHA' => $base, 'HEAD_SHA' => $head];
    }

    /**
     * Runs the script of one step as GitHub runs it, in the scratch work
     * directory, with files to hold what the step writes to GITHUB_OUTPUT
     * and to GITHUB_STEP_SUMMARY.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runStep(string $name, array $env): array
    {
        if ('' === $this->scratch) {
            $this->scratch = sys_get_temp_dir().'/php-regex-benchmarks-workflow-'.bin2hex(random_bytes(6));
            mkdir($this->scratch.'/work', 0o777, true);
        }
        mkdir($this->scratch.'/temp');
        file_put_contents($this->scratch.'/output', '');
        file_put_contents($this->scratch.'/summary', '');
        if (isset($env['TABLE'])) {
            file_put_contents($this->scratch.'/temp/table.txt', $env['TABLE']);
            unset($env['TABLE']);
        }
        file_put_contents($this->scratch.'/step.sh', self::step($name));
        $process = proc_open(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', $this->scratch.'/step.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->scratch.'/work',
            $env + ['PATH' => (string) getenv('PATH'), 'RUNNER_TEMP' => $this->scratch.'/temp', 'GITHUB_OUTPUT' => $this->scratch.'/output', 'GITHUB_STEP_SUMMARY' => $this->scratch.'/summary', 'HOME' => (string) getenv('HOME')],
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * The shell script of the step with this name, whatever its indentation.
     */
    private static function step(string $name): string
    {
        if (1 === preg_match('~^( *)- name: '.preg_quote($name, '~').'\n(?:\1  (?!run:).*\n)*\1  run: \|\n((?:(?:\1    .*)?\n)+)~m', self::workflow(), $match)) {
            return (string) preg_replace('~^ {'.(\strlen($match[1]) + 4).'}~m', '', $match[2]);
        }
        self::fail('No step named "'.$name.'" with a script.');
    }

    /**
     * The lines of the step with this name, up to the next step.
     */
    private static function stepBody(string $job, string $name): string
    {
        if (1 === preg_match('~^( *)- name: '.preg_quote($name, '~').'\n((?:(?!\1- ).*\n)*)~m', $job, $match)) {
            return $match[2];
        }
        self::fail('No step named "'.$name.'".');
    }

    /**
     * The body of the job with this key.
     */
    private static function job(string $key): string
    {
        if (1 === preg_match('~^  '.preg_quote($key, '~').':\n((?:(?:    .*)?\n)*)~m', self::workflow(), $match)) {
            return $match[1];
        }
        self::fail('No job "'.$key.'".');
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/benchmarks.yml');
    }
}
