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

use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pull request that changes the code runs one workflow, ci.yml, in three
 * stages, each waiting for the one before: the quick checks, then the suite
 * once on the reference PHP beside what needs no matrix, then the matrix. A
 * pull request that fails early stops there, and its runners go to the next
 * one. The last job reads every other and names the stage that stopped the
 * run: a job the stage before it stopped is skipped, which GitHub counts as
 * passed.
 */
final class PullRequestStagesTest extends TestCase
{
    /**
     * The workflows ci.yml calls, by the job that calls each.
     */
    private const CALLED = [
        'static-analysis' => 'static-analysis.yml',
        'backward-compatibility' => 'backward-compatibility.yml',
        'packages' => 'packages.yml',
        'benchmarks' => 'benchmarks.yml',
        'coverage' => 'coverage.yml',
        'tests' => 'tests.yml',
        'engines' => 'engines.yml',
        'twin' => 'php74-twin.yml',
    ];

    private const QUICK = ['static-analysis', 'backward-compatibility'];

    private string $root = '';

    protected function tearDown(): void
    {
        if ('' !== $this->root) {
            TemporaryDirectory::remove($this->root);
            $this->root = '';
        }
    }

    #[Test]
    public function test_the_jobs_are_the_three_stages_and_the_verdict(): void
    {
        $this->assertSame(
            ['static-analysis', 'backward-compatibility', 'scope', 'reference', 'packages', 'benchmarks', 'coverage', 'tests', 'engines', 'twin', 'verdict'],
            array_keys(self::jobs()),
        );
    }

    #[Test]
    public function test_the_quick_checks_wait_for_nothing(): void
    {
        foreach ([...self::QUICK, 'scope'] as $job) {
            $this->assertSame([], self::needs($job), $job);
        }
    }

    /**
     * @return iterable<string, array{job: string}>
     */
    public static function provideSecondStageJobs(): iterable
    {
        foreach (['reference', 'packages', 'benchmarks', 'coverage'] as $job) {
            yield $job => ['job' => $job];
        }
    }

    #[Test]
    #[DataProvider('provideSecondStageJobs')]
    public function test_the_second_stage_waits_for_the_quick_checks(string $job): void
    {
        $this->assertSame(self::QUICK, self::needs($job));
        $this->assertStringNotContainsString("\n    if:", "\n".self::jobs()[$job], 'A condition of its own would let the job start after a failed stage.');
    }

    #[Test]
    public function test_the_matrix_waits_for_the_reference(): void
    {
        $this->assertSame(['reference'], self::needs('tests'));
        $this->assertSame(['reference'], self::needs('engines'));
        $this->assertSame(['reference', 'scope'], self::needs('twin'));
        foreach (['tests', 'engines'] as $job) {
            $this->assertStringNotContainsString("\n    if:", "\n".self::jobs()[$job], $job);
        }
        // No status function in the condition: GitHub then adds "the jobs
        // before passed" to it.
        $this->assertMatchesRegularExpression("~^    if: needs\\.scope\\.outputs\\.twin == 'true'$~m", self::jobs()['twin']);
    }

    #[Test]
    public function test_each_stage_runs_the_workflow_that_holds_its_jobs(): void
    {
        $jobs = self::jobs();
        foreach (self::CALLED as $job => $workflow) {
            $this->assertMatchesRegularExpression('~^    uses: \./\.github/workflows/'.preg_quote($workflow, '~').'$~m', $jobs[$job], $job);
            $this->assertStringNotContainsString('secrets:', $jobs[$job], 'A pull request from a fork has no secret to hand over.');
        }
    }

    /**
     * A called workflow runs inside the run of ci.yml, whose name is then
     * its github.workflow: a concurrency group built from that name would be
     * the caller's own group, and GitHub cancels a run that waits for itself.
     */
    #[Test]
    public function test_a_called_workflow_starts_by_call_and_by_hand_and_keeps_a_group_of_its_own(): void
    {
        $groups = [];
        foreach (self::CALLED as $workflow) {
            $source = self::workflow($workflow);
            $this->assertSame(1, preg_match('~^on:\n((?:  .*\n)+)~m', $source, $on), $workflow);
            $this->assertStringContainsString("\n  workflow_call:", "\n".$on[1], $workflow);
            $this->assertStringContainsString("\n  workflow_dispatch:", "\n".$on[1], $workflow);
            $this->assertStringNotContainsString("\n  pull_request:", "\n".$on[1], $workflow.' is started by ci.yml on a pull request, not by itself.');
            $this->assertStringNotContainsString("\n  push:", "\n".$on[1], $workflow);

            $this->assertSame(1, preg_match('~^concurrency:\n(?:  #.*\n)*  group: (.+)\n  cancel-in-progress: true$~m', $source, $concurrency), $workflow);
            $this->assertStringNotContainsString('github.workflow', $concurrency[1], $workflow);
            $this->assertMatchesRegularExpression('~^[a-z][a-z0-9-]*-\$\{\{ github\.event_name \}\}-\$\{\{ github\.ref \}\}$~', $concurrency[1], $workflow);
            $groups[] = $concurrency[1];
        }

        $this->assertSame($groups, array_unique($groups), 'Two called workflows in one group would cancel each other.');
        $this->assertStringContainsString('group: ${{ github.workflow }}-${{ github.event_name }}-${{ github.ref }}', self::workflow('ci.yml'));
    }

    /**
     * Everything that judges the code starts from ci.yml: no other workflow
     * a pull request starts calls one of the staged workflows around it.
     */
    #[Test]
    public function test_only_ci_calls_the_staged_workflows(): void
    {
        $callers = [];
        foreach (glob(self::root().'/.github/workflows/*.yml') ?: [] as $path) {
            if (1 === preg_match('~^    uses: \./\.github/workflows/~m', (string) file_get_contents($path))) {
                $callers[] = basename($path);
            }
        }

        $this->assertSame(['ci.yml'], $callers);
    }

    #[Test]
    public function test_the_reference_is_the_cell_the_matrix_leaves_out(): void
    {
        $reference = self::jobs()['reference'];
        $tests = self::workflow('tests.yml');

        $this->assertMatchesRegularExpression('~^    name: Reference \(PHP 8\.4, prefer-stable, ubuntu-latest\)$~m', $reference);
        $this->assertMatchesRegularExpression('~^    runs-on: ubuntu-latest$~m', $reference);
        $this->assertStringNotContainsString('continue-on-error', $reference);
        // The cell as tests.yml runs it: the same host packages, the same
        // PHP, dependencies and tools, the same command.
        foreach ([
            'run: sudo apt-get update && sudo apt-get install -y --no-install-recommends libpcre2-dev pcre2-utils',
            'uses: ./.github/actions/setup-php-project',
            'ini-values: phar.readonly=0',
            'tools: phpunit phpstan phpbench',
            'run: tools/phpunit/vendor/bin/phpunit --colors=always',
        ] as $line) {
            $this->assertStringContainsString($line, $reference);
            $this->assertStringContainsString($line, $tests);
        }
        $this->assertMatchesRegularExpression("~^          php-version: '8\\.4'$~m", $reference);
        $this->assertMatchesRegularExpression('~^          dependencies: prefer-stable$~m', $reference);

        // ci.yml says it has run the cell, and tests.yml then leaves it out;
        // run by itself, on Mondays or by hand, tests.yml runs every cell.
        $this->assertMatchesRegularExpression('~^    with:\n      after-reference: true$~m', self::jobs()['tests']);
        $this->assertMatchesRegularExpression('~^  workflow_call:\n    inputs:\n      after-reference:\n(?:        .*\n)*?        type: boolean\n(?:        .*\n)*?        default: false$~m', $tests);
        $this->assertStringContainsString(
            "        exclude:\n          - os: \${{ inputs.after-reference && 'ubuntu-latest' || 'no host' }}\n            php: '8.4'\n            dependency: prefer-stable\n",
            $tests,
        );
    }

    #[Test]
    public function test_the_verdict_reads_every_other_job_unless_the_run_was_cancelled(): void
    {
        $jobs = self::jobs();
        $others = array_values(array_diff(array_keys($jobs), ['verdict']));

        $this->assertSame($others, self::needs('verdict'), 'A job the verdict does not read can fail a pull request that stays green.');
        $this->assertMatchesRegularExpression('~^    name: CI$~m', $jobs['verdict']);
        // A newer push cancels the run: nothing is left to say about it.
        $this->assertMatchesRegularExpression('~^    if: \$\{\{ !cancelled\(\) \}\}$~m', $jobs['verdict']);
        foreach ($others as $job) {
            $this->assertStringContainsString('${{ needs.'.$job.'.result }}', $jobs['verdict'], $job);
        }
    }

    /**
     * @return iterable<string, array{results: array<string, string>, twin: string, exit: int, said: list<string>}>
     */
    public static function provideRuns(): iterable
    {
        $passed = array_fill_keys(['static-analysis', 'backward-compatibility', 'scope', 'reference', 'packages', 'benchmarks', 'coverage', 'tests', 'engines', 'twin'], 'success');
        $stoppedAfter = static fn (array $ran): array => $ran + array_fill_keys(array_keys($passed), 'skipped');

        yield 'every stage passed' => ['results' => $passed, 'twin' => 'true', 'exit' => 0, 'said' => ['Every stage passed.']];
        yield 'the twin is not read by the pull request' => [
            'results' => ['twin' => 'skipped'] + $passed,
            'twin' => 'false',
            'exit' => 0,
            'said' => ['Every stage passed.', '| 3 | php74 twin | not read by this pull request |'],
        ];
        yield 'a quick check failed' => [
            'results' => $stoppedAfter(['static-analysis' => 'failure', 'backward-compatibility' => 'success', 'scope' => 'success']),
            'twin' => 'true',
            'exit' => 1,
            'said' => ['Stopped at stage 1, the quick checks: Static analysis failed. Stages 2 and 3 did not start.', '| 2 | Reference | not started |', '| 3 | Engines | not started |'],
        ];
        yield 'the reference failed' => [
            'results' => ['reference' => 'failure', 'tests' => 'skipped', 'engines' => 'skipped', 'twin' => 'skipped'] + $passed,
            'twin' => 'true',
            'exit' => 1,
            'said' => ['Stopped at stage 2, the reference: Reference failed. Stage 3, the matrix, did not start.', '| 3 | Tests | not started |'],
        ];
        yield 'the reference and the mutations failed' => [
            'results' => ['reference' => 'failure', 'coverage' => 'failure', 'tests' => 'skipped', 'engines' => 'skipped', 'twin' => 'skipped'] + $passed,
            'twin' => 'false',
            'exit' => 1,
            'said' => ['Stopped at stage 2, the reference: Reference, Coverage failed. Stage 3, the matrix, did not start.'],
        ];
        yield 'the mutations failed beside a green reference' => [
            'results' => ['coverage' => 'failure'] + $passed,
            'twin' => 'true',
            'exit' => 1,
            'said' => ['Every stage ran. Failed: Coverage (stage 2).'],
        ];
        yield 'cells of the matrix failed' => [
            'results' => ['tests' => 'failure', 'engines' => 'failure'] + $passed,
            'twin' => 'true',
            'exit' => 1,
            'said' => ['Every stage ran. Failed: Tests (stage 3), Engines (stage 3).'],
        ];
        yield 'a job was cancelled by hand' => [
            'results' => ['engines' => 'cancelled'] + $passed,
            'twin' => 'true',
            'exit' => 1,
            'said' => ['Every stage ran. Failed: Engines (stage 3).', '| 3 | Engines | cancelled |'],
        ];
        yield 'the twin did not run though the pull request changes what it reads' => [
            'results' => ['twin' => 'skipped'] + $passed,
            'twin' => 'true',
            'exit' => 1,
            'said' => ['Not started, though nothing failed: php74 twin.'],
        ];
        yield 'the scope could not be read' => [
            'results' => ['scope' => 'failure', 'twin' => 'skipped'] + $passed,
            'twin' => '',
            'exit' => 1,
            'said' => ['Every stage ran. Failed: Scope (stage 1).'],
        ];
    }

    /**
     * @param array<string, string> $results
     * @param list<string>          $said
     */
    #[Test]
    #[DataProvider('provideRuns')]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_the_verdict_names_the_stage_that_stopped_the_run(array $results, string $twin, int $exit, array $said): void
    {
        $verdict = self::jobs()['verdict'];
        $env = ['TWIN_READ' => $twin];
        foreach ($results as $job => $result) {
            $name = strtoupper(str_replace('-', '_', $job));
            $this->assertStringContainsString($name.': ${{ needs.'.$job.'.result }}', $verdict);
            $env[$name] = $result;
        }
        $this->assertStringContainsString('TWIN_READ: ${{ needs.scope.outputs.twin }}', $verdict);

        [$code, $output, $summary] = $this->runStep($verdict, 'Name the stage that stopped the run', $env);

        $this->assertSame($exit, $code, $output.$summary);
        foreach ($said as $line) {
            $this->assertStringContainsString($line, $summary);
        }
        if (0 === $exit) {
            $this->assertStringNotContainsString('::error', $output);
        } else {
            $this->assertStringContainsString('::error title=CI::'.$said[0], $output, 'The run page shows the sentence without opening the job.');
        }
    }

    /**
     * @return iterable<string, array{files: list<string|array{string, string}>|null, pullRequest: string, twin: string}>
     */
    public static function providePullRequests(): iterable
    {
        yield 'the parser' => ['files' => ['src/Parser/Lexer.php'], 'pullRequest' => '7', 'twin' => 'true'];
        yield 'another package' => ['files' => ['src/Linter/Linter.php', 'composer.json'], 'pullRequest' => '7', 'twin' => 'false'];
        yield 'a package whose name starts like the parser' => ['files' => ['src/ParserExtras/A.php'], 'pullRequest' => '7', 'twin' => 'false'];
        yield 'a test' => ['files' => ['src/Linter/Linter.php', 'tests/Unit/LinterTest.php'], 'pullRequest' => '7', 'twin' => 'true'];
        yield 'the build that writes the twin' => ['files' => ['build/generate.sh'], 'pullRequest' => '7', 'twin' => 'true'];
        yield 'the twin workflow' => ['files' => ['.github/workflows/php74-twin.yml'], 'pullRequest' => '7', 'twin' => 'true'];
        yield 'the workflow that stages it' => ['files' => ['.github/workflows/ci.yml'], 'pullRequest' => '7', 'twin' => 'true'];
        yield 'another workflow' => ['files' => ['.github/workflows/tests.yml'], 'pullRequest' => '7', 'twin' => 'false'];
        yield 'a file moved out of the parser' => ['files' => [['src/Linter/Moved.php', 'src/Parser/Moved.php']], 'pullRequest' => '7', 'twin' => 'true'];
        yield 'as many files as GitHub lists' => ['files' => array_fill(0, 3000, 'src/Linter/Linter.php'), 'pullRequest' => '7', 'twin' => 'true'];
        // Many names under the twin's paths, read to the end under pipefail.
        yield 'many tests' => ['files' => array_fill(0, 2999, 'tests/Unit/LinterTest.php'), 'pullRequest' => '7', 'twin' => 'true'];
        // A move is one file of the list under two names.
        yield 'one file fewer than GitHub lists, one of them moved' => ['files' => [...array_fill(0, 2998, 'src/Linter/Linter.php'), ['src/Linter/New.php', 'src/Linter/Old.php']], 'pullRequest' => '7', 'twin' => 'false'];
        yield 'a list GitHub does not give' => ['files' => null, 'pullRequest' => '7', 'twin' => 'true'];
        yield 'a run by hand' => ['files' => ['src/Linter/Linter.php'], 'pullRequest' => '', 'twin' => 'true'];
    }

    /**
     * @param list<string|array{string, string}>|null $files the files of the pull request as GitHub lists them, a moved one as its name and the one it had; null when GitHub does not answer
     */
    #[Test]
    #[DataProvider('providePullRequests')]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_the_twin_runs_when_the_pull_request_changes_what_it_reads(?array $files, string $pullRequest, string $twin): void
    {
        $scope = self::jobs()['scope'];
        $this->assertMatchesRegularExpression('~^    permissions:\n      pull-requests: read$~m', $scope);
        $this->assertMatchesRegularExpression('~^      twin: \$\{\{ steps\.changed\.outputs\.twin \}\}$~m', $scope);
        $this->assertStringContainsString('PULL_REQUEST: ${{ github.event.pull_request.number }}', $scope);

        $this->root = TemporaryDirectory::create('php-regex-stages');
        mkdir($this->root.'/fake');
        // A gh that answers with the files of a page as GitHub gives them,
        // read through the step's own --jq filter, or fails as GitHub does
        // when it does not answer.
        file_put_contents($this->root.'/fake/gh', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$GH_LOG\"\n[ -f \"\$GH_FILES\" ] || { echo 'HTTP 502' >&2; exit 1; }\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = --jq ] && filter=\$2; shift; done\njq -r \"\$filter\" \"\$GH_FILES\"\n");
        chmod($this->root.'/fake/gh', 0o755);
        if (null !== $files) {
            $page = array_map(static fn (string|array $file): array => \is_array($file) ? ['filename' => $file[0], 'previous_filename' => $file[1], 'status' => 'renamed'] : ['filename' => $file, 'status' => 'modified'], $files);
            file_put_contents($this->root.'/files', json_encode($page, \JSON_THROW_ON_ERROR));
        }

        [$code, $output] = $this->runStep($scope, 'Read what the pull request changes', [
            'PATH' => $this->root.'/fake:'.getenv('PATH'),
            'GH_FILES' => $this->root.'/files',
            'GH_LOG' => $this->root.'/gh.log',
            'GITHUB_REPOSITORY' => 'php-regex/php-regex',
            'PULL_REQUEST' => $pullRequest,
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertSame("twin=$twin\n", (string) file_get_contents($this->root.'/output'));
        if ('' === $pullRequest) {
            $this->assertFileDoesNotExist($this->root.'/gh.log', 'A run by hand has no pull request to ask about.');
        } else {
            $this->assertStringContainsString('api --paginate repos/php-regex/php-regex/pulls/7/files', (string) file_get_contents($this->root.'/gh.log'));
        }
    }

    /**
     * Runs the script of one step of a job as the runner does, under
     * "bash -e", with the files GitHub gives a step to write to.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string, string} the exit code, what the step printed, and its summary
     */
    private function runStep(string $job, string $step, array $env): array
    {
        if ('' === $this->root) {
            $this->root = TemporaryDirectory::create('php-regex-stages');
        }
        $this->assertSame(1, preg_match('~^      - name: '.preg_quote($step, '~').'\n(?:        .*\n)*?        run: \|\n((?:          .*\n|\n)+)~m', $job, $match), 'The job has a "'.$step.'" step.');
        $script = (string) preg_replace('~^          ~m', '', $match[1]);
        $this->assertStringNotContainsString('${{', $script, 'A step reads the run through its environment, never inside its script.');
        file_put_contents($this->root.'/step.sh', $script);
        touch($this->root.'/summary');
        touch($this->root.'/output');

        $env += [
            'PATH' => (string) getenv('PATH'),
            'GITHUB_STEP_SUMMARY' => $this->root.'/summary',
            'GITHUB_OUTPUT' => $this->root.'/output',
            'RUNNER_TEMP' => $this->root,
        ];
        $process = proc_open(['bash', '--noprofile', '--norc', '-e', '-o', 'pipefail', $this->root.'/step.sh'], ProcessPipes::descriptors(), $pipes, $this->root, $env);
        $this->assertIsResource($process);
        $output = implode('', ProcessPipes::drain($pipes));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, (string) file_get_contents($this->root.'/summary')];
    }

    /**
     * @return list<string> the jobs this one waits for
     */
    private static function needs(string $job): array
    {
        $body = self::jobs()[$job] ?? '';
        if (1 === preg_match('~^    needs: \[ ([a-z0-9, -]+) \]$~m', $body, $match)) {
            return explode(', ', $match[1]);
        }
        if (1 === preg_match('~^    needs: ([a-z0-9-]+)$~m', $body, $match)) {
            return [$match[1]];
        }
        if (1 === preg_match('~^    needs:\n((?:      - [a-z0-9-]+\n)+)~m', $body, $match)) {
            preg_match_all('~^      - ([a-z0-9-]+)$~m', $match[1], $items);

            return $items[1];
        }
        self::assertStringNotContainsString("\n    needs:", "\n".$body, $job.' names what it waits for in a form read here.');

        return [];
    }

    /**
     * @return array<string, string> the body of each job of ci.yml, by job id
     */
    private static function jobs(): array
    {
        $workflow = self::workflow('ci.yml');
        $jobs = substr($workflow, (int) strpos($workflow, "\njobs:\n"));
        preg_match_all('~^  ([a-z][a-z0-9-]*):\n((?:(?:    .*)?\n)*)~m', $jobs, $matches, \PREG_SET_ORDER);

        $bodies = [];
        foreach ($matches as $match) {
            $bodies[$match[1]] = $match[2];
        }

        return $bodies;
    }

    private static function workflow(string $name): string
    {
        $path = self::root().'/.github/workflows/'.$name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
