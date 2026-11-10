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
 * One check answers for a pull request, and its name is CI. ci.yml reports
 * it at the end of its stages when the pull request changes the code. A
 * pull request that changes nothing but what ci.yml ignores does not start
 * it, and a required check nothing reports stays pending: ci-prose.yml
 * starts on those very paths and reports CI for that pull request, and for
 * no other, since one that changes the code as well is ci.yml's to answer.
 * When nothing says which of the two a pull request is, CI fails and says
 * so: a check that stayed pending would say nothing.
 */
#[Group('documentation')]
final class PullRequestRequiredCheckTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ('' !== $this->root) {
            TemporaryDirectory::remove($this->root);
            $this->root = '';
        }
    }

    #[Test]
    public function test_the_prose_workflow_starts_on_the_paths_the_code_workflow_ignores(): void
    {
        $code = self::pullRequestBlock('ci.yml');
        $prose = self::pullRequestBlock('ci-prose.yml');

        $ignored = self::listUnder('paths-ignore', $code);
        $this->assertNotSame([], $ignored);
        $this->assertSame($ignored, self::listUnder('paths', $prose), 'A path one list holds and the other does not is a pull request with two answers, or with none.');
        $this->assertStringNotContainsString("\n    paths-ignore:", "\n".$prose);
        $this->assertStringNotContainsString("\n    paths:", "\n".$code);
        $this->assertSame(1, preg_match('~^    branches: .+$~m', $code, $branches));
        $this->assertStringContainsString($branches[0]."\n", $prose, 'The two workflows start on the pull requests of the same branch.');
        $this->assertStringNotContainsString("\n    types:", "\n".$code.$prose, 'The two workflows start on the same events of a pull request.');
    }

    /**
     * The step reads the list of ci.yml as it stands in the pull request,
     * and knows a literal path, "*" and "**": a pattern that negates, or
     * that names a set or a repetition, would be read as something else.
     */
    #[Test]
    public function test_the_ignored_paths_are_written_as_the_step_reads_them(): void
    {
        $block = self::pullRequestBlock('ci.yml');
        $ignored = self::listUnder('paths-ignore', $block);
        foreach ($ignored as $pattern) {
            $this->assertMatchesRegularExpression('~^[\w./-]+(?:\*{1,2}[\w./-]*)*$~', $pattern);
        }
        $this->assertSame(1, preg_match('~^    paths-ignore:\n((?:      .*\n)+)~m', $block, $list));
        $this->assertCount(substr_count($list[1], "\n"), $ignored, 'Every line under paths-ignore is a path between single quotes.');

        $scope = self::jobs()['scope'];
        $this->assertMatchesRegularExpression("~^      - name: Checkout the list of the code workflow\n        uses: actions/checkout@.+\n        with:\n          persist-credentials: false\n          sparse-checkout: \\.github/workflows/ci\\.yml\n          sparse-checkout-cone-mode: false$~m", $scope);
        $this->assertMatchesRegularExpression('~^    permissions:\n      contents: read\n      pull-requests: read$~m', $scope);
        $this->assertMatchesRegularExpression('~^      code: \$\{\{ steps\.changed\.outputs\.code \}\}$~m', $scope);
        $this->assertStringContainsString('PULL_REQUEST: ${{ github.event.pull_request.number }}', $scope);
    }

    /**
     * @return iterable<string, array{files: list<string>|null, code: string}>
     */
    public static function providePullRequests(): iterable
    {
        // Each path ci.yml ignores, alone: "**" as two directories deep,
        // "*" as one name.
        foreach (self::listUnder('paths-ignore', self::pullRequestBlock('ci.yml')) as $pattern) {
            yield 'ignored: '.$pattern => ['files' => [str_replace(['**', '*'], ['guides/a b/page.md', 'Parser'], $pattern)], 'code' => 'false'];
        }

        yield 'the site, a changelog and a setting' => ['files' => ['docs/index.md', 'src/Parser/CHANGELOG.md', '_typos.toml'], 'code' => 'false'];
        yield 'a source file' => ['files' => ['src/Parser/Lexer.php'], 'code' => 'true'];
        yield 'a source file among the prose' => ['files' => ['docs/index.md', 'src/Parser/Lexer.php', 'README.md'], 'code' => 'true'];
        yield 'a workflow' => ['files' => ['CONTRIBUTING.md', '.github/workflows/ci.yml'], 'code' => 'true'];
        yield 'a README below a package' => ['files' => ['src/Parser/Doc/README.md'], 'code' => 'true'];
        yield 'a README above the packages' => ['files' => ['src/README.md'], 'code' => 'true'];
        yield 'a name that starts like the site' => ['files' => ['docs.php'], 'code' => 'true'];
        yield 'a name that ends like a README' => ['files' => ['tests/README.md'], 'code' => 'true'];
        yield 'a name that holds a README' => ['files' => ['README.md.php'], 'code' => 'true'];
        yield 'a dot read as any character' => ['files' => ['READMExmd'], 'code' => 'true'];
        yield 'a file moved out of the code' => ['files' => ["docs/notes.md\tsrc/Parser/notes.md"], 'code' => 'true'];
        yield 'a file moved into the code' => ['files' => ["src/Parser/notes.md\tdocs/notes.md"], 'code' => 'true'];
        yield 'a file moved within the prose' => ['files' => ["docs/guides/notes.md\tdocs/notes.md"], 'code' => 'false'];
        // The limit is GitHub's count of files, not of names.
        yield 'half as many files as GitHub lists, each moved' => ['files' => array_fill(0, 1500, "docs/guides/notes.md\tdocs/notes.md"), 'code' => 'false'];
        yield 'a source file in as many files as GitHub lists' => ['files' => [...array_fill(0, 2999, 'docs/index.md'), 'src/Parser/Lexer.php'], 'code' => 'true'];
        yield 'as many files as GitHub lists, none of the code' => ['files' => array_fill(0, 3000, 'docs/index.md'), 'code' => 'unknown'];
        yield 'no file' => ['files' => [], 'code' => 'unknown'];
        yield 'a list GitHub does not give' => ['files' => null, 'code' => 'unknown'];
    }

    /**
     * One file outside the list is enough to know that the code changes,
     * and the whole list to know that it does not: a list that is empty,
     * that GitHub does not give or cuts at three thousand files says
     * neither.
     *
     * @param list<string>|null $files the files of the pull request as GitHub lists them, a moved one as its name, a tab and the name it had; null when GitHub does not answer
     */
    #[Test]
    #[DataProvider('providePullRequests')]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_the_step_says_whether_a_file_is_outside_what_the_code_workflow_ignores(?array $files, string $code): void
    {
        // One line a file: its name, a tab, and the name it had before a
        // move or nothing.
        $this->assertStringContainsString('--jq \'.[] | "\(.filename)\t\(.previous_filename // "")"\'', self::jobs()['scope']);
        $this->prepare($files);

        [$exit, $output] = $this->runStep(self::jobs()['scope'], 'Read what the pull request changes', ['PULL_REQUEST' => '7']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame("code=$code\n", (string) file_get_contents($this->root.'/output'));
        $this->assertStringContainsString('api --paginate repos/php-regex/php-regex/pulls/7/files', (string) file_get_contents($this->root.'/gh.log'));
    }

    /**
     * Without the list of ci.yml, nothing says what it stays out of.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_nothing_is_known_when_the_code_workflow_names_no_ignored_path(): void
    {
        $this->prepare(['docs/index.md'], "on:\n  pull_request:\n    branches: [ 2.x ]\n  workflow_dispatch:\n");

        [$exit, $output] = $this->runStep(self::jobs()['scope'], 'Read what the pull request changes', ['PULL_REQUEST' => '7']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame("code=unknown\n", (string) file_get_contents($this->root.'/output'));
    }

    /**
     * A path GitHub reads and the step does not would be a file of the code
     * to the step and one ci.yml ignores to GitHub: neither workflow would
     * report CI for a pull request that changes it alone.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_nothing_is_known_when_the_step_cannot_read_every_ignored_path(): void
    {
        $this->prepare(['docs/index.md', 'NOTICE'], "on:\n  pull_request:\n    branches: [ 2.x ]\n    paths-ignore:\n      - 'docs/**'\n      - \"NOTICE\"\n  workflow_dispatch:\n");

        [$exit, $output] = $this->runStep(self::jobs()['scope'], 'Read what the pull request changes', ['PULL_REQUEST' => '7']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame("code=unknown\n", (string) file_get_contents($this->root.'/output'));
    }

    /**
     * The name of the check is CI for the prose alone. A pull request that
     * changes the code as well starts both workflows, and the last job of
     * ci.yml is created only once its stages are over: a CI reported here
     * would be the only one for as long as they run, and a job that is
     * skipped passes, so the job runs under another name. Whatever else
     * the scope says, or when it says nothing, the job is CI and runs.
     */
    #[Test]
    public function test_ci_is_reported_for_the_prose_alone(): void
    {
        $jobs = self::jobs();

        $this->assertSame(['scope', 'verdict'], array_keys($jobs));
        $this->assertMatchesRegularExpression('~^    name: CI$~m', self::jobs('ci.yml')['verdict'], 'The check ci.yml reports.');
        $this->assertMatchesRegularExpression("~^    name: \\$\\{\\{ needs\\.scope\\.outputs\\.code == 'true' && 'Left to the code checks' \\|\\| 'CI' \\}\\}$~m", $jobs['verdict']);
        $this->assertMatchesRegularExpression('~^    needs: scope$~m', $jobs['verdict']);
        // A scope that failed would otherwise skip the job, and a skipped
        // CI passes.
        $this->assertMatchesRegularExpression('~^    if: \$\{\{ !cancelled\(\) \}\}$~m', $jobs['verdict']);
        $this->assertStringNotContainsString('continue-on-error', $jobs['scope'].$jobs['verdict']);
        $this->assertDoesNotMatchRegularExpression('~^    name: CI$~m', $jobs['scope']);
    }

    /**
     * @return iterable<string, array{code: string, exit: int, said: string}>
     */
    public static function provideAnswers(): iterable
    {
        $unread = 'The files of this pull request could not be read in full: nothing says whether ci.yml answers for it. Running this job again reads them again.';

        yield 'the prose alone' => ['code' => 'false', 'exit' => 0, 'said' => 'This pull request changes nothing the code checks read.'];
        yield 'the code as well' => ['code' => 'true', 'exit' => 0, 'said' => 'This pull request changes the code as well: the CI check is the one ci.yml reports.'];
        yield 'files that could not be read' => ['code' => 'unknown', 'exit' => 1, 'said' => $unread];
        yield 'a scope that said nothing' => ['code' => '', 'exit' => 1, 'said' => $unread];
    }

    #[Test]
    #[DataProvider('provideAnswers')]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_the_job_says_which_workflow_answers(string $code, int $exit, string $said): void
    {
        $verdict = self::jobs()['verdict'];
        $this->assertStringContainsString('CODE: ${{ needs.scope.outputs.code }}', $verdict);
        $this->root = TemporaryDirectory::create('php-regex-required-check');

        [$status, $output, $summary] = $this->runStep($verdict, 'Say which workflow answers', ['CODE' => $code]);

        $this->assertSame($exit, $status, $output);
        $this->assertSame($said."\n", $summary);
        $this->assertSame(0 === $exit ? $said."\n" : $said."\n::error title=CI::".$said."\n", $output, 'The run page shows why CI failed without opening the job.');
    }

    /**
     * A scratch checkout holding ci.yml, and a gh that lists the files
     * from a file, or fails as GitHub does when it does not answer.
     *
     * @param list<string>|null $files
     */
    private function prepare(?array $files, ?string $workflow = null): void
    {
        $this->root = TemporaryDirectory::create('php-regex-required-check');
        mkdir($this->root.'/.github/workflows', 0o777, true);
        file_put_contents($this->root.'/.github/workflows/ci.yml', $workflow ?? self::workflow('ci.yml'));
        mkdir($this->root.'/fake');
        file_put_contents($this->root.'/fake/gh', "#!/usr/bin/env bash\necho \"\$*\" >> \"\$GH_LOG\"\n[ -f \"\$GH_FILES\" ] || { echo 'HTTP 502' >&2; exit 1; }\ncat \"\$GH_FILES\"\n");
        chmod($this->root.'/fake/gh', 0o755);
        if (null !== $files) {
            $lines = array_map(static fn (string $file): string => str_contains($file, "\t") ? $file : $file."\t", $files);
            file_put_contents($this->root.'/listed', [] === $lines ? '' : implode("\n", $lines)."\n");
        }
    }

    /**
     * Runs the script of one step of a job as the runner does, under
     * "bash -e", in the scratch checkout, with the files GitHub gives a
     * step to write to.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string, string} the exit code, what the step printed, and its summary
     */
    private function runStep(string $job, string $step, array $env): array
    {
        $this->assertSame(1, preg_match('~^      - name: '.preg_quote($step, '~').'\n(?:        .*\n)*?        run: \|\n((?:          .*\n|\n)+)~m', $job, $match), 'The job has a "'.$step.'" step.');
        $script = (string) preg_replace('~^          ~m', '', $match[1]);
        $this->assertStringNotContainsString('${{', $script, 'A step reads the run through its environment, never inside its script.');
        // One pipe for both streams: a step that fills the pipe of its
        // errors while its output is being read would wait for ever.
        file_put_contents($this->root.'/step.sh', "exec 2>&1\n".$script);
        touch($this->root.'/summary');
        touch($this->root.'/output');

        $env += [
            'PATH' => $this->root.'/fake:'.getenv('PATH'),
            'GH_FILES' => $this->root.'/listed',
            'GH_LOG' => $this->root.'/gh.log',
            'GITHUB_REPOSITORY' => 'php-regex/php-regex',
            'GITHUB_STEP_SUMMARY' => $this->root.'/summary',
            'GITHUB_OUTPUT' => $this->root.'/output',
            'RUNNER_TEMP' => $this->root,
        ];
        $process = proc_open(['bash', '--noprofile', '--norc', '-e', '-o', 'pipefail', $this->root.'/step.sh'], [1 => ['pipe', 'w']], $pipes, $this->root, $env);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output, (string) file_get_contents($this->root.'/summary')];
    }

    /**
     * The lines of the list under a key of the pull request block, as
     * "      - 'docs/**'".
     *
     * @return list<string>
     */
    private static function listUnder(string $key, string $block): array
    {
        if (1 !== preg_match('~^    '.preg_quote($key, '~').":\n((?:      - '[^']+'\n)+)~m", $block, $match)) {
            return [];
        }
        preg_match_all("~^      - '([^']+)'$~m", $match[1], $items);

        return $items[1];
    }

    private static function pullRequestBlock(string $workflow): string
    {
        self::assertSame(1, preg_match('~^  pull_request:\n((?:    .*\n)+)~m', self::workflow($workflow), $match), $workflow.' runs on a pull request.');

        return $match[1];
    }

    /**
     * @return array<string, string> the body of each job of the workflow, by job id
     */
    private static function jobs(string $name = 'ci-prose.yml'): array
    {
        $workflow = self::workflow($name);
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
        $path = \dirname(__DIR__, 3).'/.github/workflows/'.$name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
