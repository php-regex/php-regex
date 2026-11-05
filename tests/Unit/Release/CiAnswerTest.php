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
 * bin/ci-answer, run against a scratch repository and a gh that answers
 * from a list of runs: which run answers for HEAD in one workflow, when a
 * pull request ran only the workflows its changes could affect.
 */
// The scripts under bin/ are bash scripts, started without a shell.
#[RequiresOperatingSystem('Linux|Darwin')]
#[Group('documentation')]
final class CiAnswerTest extends TestCase
{
    /**
     * A workflow that judges the code: it ignores the prose.
     */
    private const CODE = <<<'YAML'
        name: Tests

        on:
          pull_request:
            branches: [ 2.x ]
            # Not for the prose alone.
            paths-ignore:
              - 'docs/**'
              - '**/*.md'
              - '.coderabbit.yaml'
          workflow_dispatch:

        jobs:
          tests:
            runs-on: ubuntu-latest
            steps:
              - run: echo "paths-ignore: - 'src/**'"
        YAML;

    /**
     * A workflow that names what it reads, a package README aside.
     */
    private const SCOPED = <<<'YAML'
        name: Demo

        on:
          pull_request:
            branches: [2.x]
            paths:
              - 'src/**'
              - '!src/*/README.md'
              - "composer.json"
              - 'bin/[a-z]+-check'
              - 'tools/colou?r.txt'
          schedule:
            - cron: '47 6 * * 1'
        YAML;

    /**
     * A workflow that reads every change.
     */
    private const EVERYTHING = <<<'YAML'
        name: Spelling

        on:
          pull_request:
            branches: [ 2.x ]
          workflow_dispatch:
        YAML;

    /**
     * A workflow whose filter names a character outside ASCII before "?".
     */
    private const ACCENTED = <<<'YAML'
        name: Accented

        on:
          pull_request:
            paths:
              - 'src/café?.php'
        YAML;

    /**
     * A workflow whose filter leaves one ASCII letter out by range.
     */
    private const RANGED = <<<'YAML'
        name: Ranged

        on:
          pull_request:
            paths:
              - 'src/**'
              - '!src/[a-z].php'
        YAML;

    private string $root = '';

    /**
     * @var list<string>
     */
    private array $runs = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-regex-ci-answer-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/repository/.github/workflows', 0o777, true);
        mkdir($this->root.'/fake');
        file_put_contents($this->root.'/fake/gh', <<<'BASH'
            #!/usr/bin/env bash
            # gh run list --commit <sha> --workflow <file> …: the run the list holds.
            # A run is "<sha> <workflow> <status> <conclusion> [<event>]", a
            # pull request's by default.
            sha=""; workflow=""; event=""
            while [ $# -gt 0 ]; do
                case "$1" in
                    --commit) sha=$2; shift ;;
                    --workflow) workflow=$2; shift ;;
                    --event) event=$2; shift ;;
                esac
                shift
            done
            echo "$sha $workflow${event:+ $event}" >> "$GH_LOG"
            awk -v sha="$sha" -v workflow="$workflow" -v event="$event" '$1 == sha && $2 == workflow && (event == "" || event == ($5 == "" ? "pull_request" : $5)) { print $3 " " $4; exit }' "$GH_RUNS"

            BASH);
        chmod($this->root.'/fake/gh', 0o755);
        file_put_contents($this->root.'/repository/.github/workflows/tests.yml', self::CODE."\n");
        file_put_contents($this->root.'/repository/.github/workflows/demo.yml', self::SCOPED."\n");
        file_put_contents($this->root.'/repository/.github/workflows/spelling.yml', self::EVERYTHING."\n");
        file_put_contents($this->root.'/repository/.github/workflows/accented.yml', self::ACCENTED."\n");
        file_put_contents($this->root.'/repository/.github/workflows/ranged.yml', self::RANGED."\n");
        $this->git('init', '-q', '-b', '2.x');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    #[Test]
    public function test_a_run_on_head_answers_for_it(): void
    {
        $head = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($head, 'tests.yml', 'completed success');

        $this->assertSame('run '.$this->short($head).' completed success', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_merge_answers_with_the_run_on_the_head_of_its_pull_request(): void
    {
        $this->commitFiles(['src/A.php' => 'a']);
        $pullRequest = $this->branchCommit('feature', ['src/B.php' => 'b']);
        $this->merge('feature');
        $this->ranOn($pullRequest, 'tests.yml', 'completed success');

        $this->assertSame('run '.$this->short($pullRequest).' completed success', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_commit_the_workflow_ignores_answers_with_the_run_before_it(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $this->commitFiles(['docs/guide.md' => 'prose', 'README.md' => 'prose']);
        $this->commitFiles(['src/Parser/NOTES.md' => 'prose', '.coderabbit.yaml' => 'reviews: {}']);

        $this->assertSame('run '.$this->short($code).' completed success', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_merge_of_ignored_changes_answers_with_the_run_before_it(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $this->branchCommit('prose', ['docs/guide.md' => 'prose']);
        $this->merge('prose');

        $this->assertSame('run '.$this->short($code).' completed success', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_commit_that_changes_what_the_workflow_reads_without_a_run_is_unread(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $unread = $this->commitFiles(['docs/guide.md' => 'prose', 'src/B.php' => 'b']);
        $this->commitFiles(['docs/other.md' => 'prose']);

        $this->assertSame('unread '.$this->short($unread).' src/B.php', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_merge_that_changes_what_the_workflow_reads_without_a_run_is_unread(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $this->branchCommit('feature', ['src/B.php' => 'b']);
        $merge = $this->merge('feature');

        $this->assertSame('unread '.$this->short($merge).' src/B.php', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_run_that_did_not_pass_answers_as_it_stands(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed failure');
        $this->commitFiles(['docs/guide.md' => 'prose']);

        $this->assertSame('run '.$this->short($code).' completed failure', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_change_to_the_workflow_itself_is_read_by_it(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $edit = $this->commitFiles(['.github/workflows/tests.yml' => self::CODE."\n# edited\n"]);

        $this->assertSame('unread '.$this->short($edit).' .github/workflows/tests.yml', $this->answer('tests.yml'));
    }

    /**
     * @return iterable<string, array{path: string, read: bool}>
     */
    public static function provideScopedChanges(): iterable
    {
        yield 'a source file' => ['path' => 'src/Parser/Lexer.php', 'read' => true];
        yield 'the manifest, quoted twice' => ['path' => 'composer.json', 'read' => true];
        yield 'a package README, negated' => ['path' => 'src/Parser/README.md', 'read' => false];
        yield 'a nested README, which the negation does not reach' => ['path' => 'src/Parser/Doc/README.md', 'read' => true];
        yield 'the prose' => ['path' => 'docs/guide.md', 'read' => false];
        yield 'a source file named outside ASCII' => ['path' => 'src/Parser/é.php', 'read' => true];
        // GitHub's filter syntax: "[]" is one character of a set or range,
        // "+" one or more of the character before it, "?" zero or one.
        yield 'a set repeated by +' => ['path' => 'bin/playground-check', 'read' => true];
        yield 'a character the set leaves out' => ['path' => 'bin/Playground-check', 'read' => false];
        yield 'an empty repetition' => ['path' => 'bin/-check', 'read' => false];
        yield 'the optional character left out' => ['path' => 'tools/color.txt', 'read' => true];
        yield 'the optional character once' => ['path' => 'tools/colour.txt', 'read' => true];
        yield 'the optional character twice' => ['path' => 'tools/colouur.txt', 'read' => false];
        yield 'any character where ? stands' => ['path' => 'tools/colo-r.txt', 'read' => false];
    }

    #[Test]
    #[DataProvider('provideScopedChanges')]
    public function test_a_scoped_workflow_reads_its_paths_with_the_last_pattern_deciding(string $path, bool $read): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'demo.yml', 'completed success');
        $change = $this->commitFiles([$path => 'changed']);

        $expected = $read ? 'unread '.$this->short($change).' '.$path : 'run '.$this->short($code).' completed success';
        $this->assertSame($expected, $this->answer('demo.yml'));
    }

    #[Test]
    public function test_a_workflow_without_paths_reads_every_change(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'spelling.yml', 'completed success');
        $prose = $this->commitFiles(['docs/guide.md' => 'prose']);

        $this->assertSame('unread '.$this->short($prose).' docs/guide.md', $this->answer('spelling.yml'));
    }

    #[Test]
    public function test_the_first_commit_without_a_run_is_unread(): void
    {
        $root = $this->commitFiles(['src/A.php' => 'a']);
        $this->commitFiles(['docs/guide.md' => 'prose']);

        // The first commit adds every file, the workflows first in tree order.
        $this->assertSame('unread '.$this->short($root).' .github/workflows/accented.yml', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_the_walk_stops_before_the_workflow_existed(): void
    {
        $this->commitFiles(['src/A.php' => 'a']);
        $this->commitFiles(['docs/guide.md' => 'prose']);

        $this->assertSame('none 1', $this->answer('missing.yml'));
    }

    #[Test]
    public function test_the_walk_stops_at_its_limit(): void
    {
        $this->commitFiles(['src/A.php' => 'a']);
        $this->commitFiles(['docs/one.md' => 'prose']);
        $this->commitFiles(['docs/two.md' => 'prose']);

        $this->assertSame('none 2', $this->answer('tests.yml', '--limit', '2'));
    }

    #[Test]
    public function test_the_walk_asks_no_further_than_the_run_that_answers(): void
    {
        $this->commitFiles(['src/A.php' => 'a']);
        $code = $this->commitFiles(['src/B.php' => 'b']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $prose = $this->commitFiles(['docs/guide.md' => 'prose']);

        $this->answer('tests.yml');

        $asked = file($this->root.'/gh.log', \FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertSame([$prose.' tests.yml', $code.' tests.yml'], $asked);
    }

    #[Test]
    public function test_a_merge_resolved_by_hand_is_not_answered_by_the_run_on_its_pull_request(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $pullRequest = $this->branchCommit('feature', ['src/B.php' => 'b']);
        $this->ranOn($pullRequest, 'tests.yml', 'completed success');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'merge', '-q', '--no-ff', '--no-commit', 'feature');
        file_put_contents($this->root.'/repository/src/B.php', 'b, then changed in the merge');
        $merge = $this->commitFiles([], 'Merge feature');

        $this->assertSame('unread '.$this->short($merge).' src/B.php', $this->answer('tests.yml'));
    }

    #[Test]
    public function test_a_merge_is_answered_by_a_pull_request_run_alone(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'tests.yml', 'completed success');
        $branch = $this->branchCommit('feature', ['src/B.php' => 'b']);
        $this->ranOn($branch, 'tests.yml', 'completed success', 'workflow_dispatch');
        $merge = $this->merge('feature');

        $this->assertSame('unread '.$this->short($merge).' src/B.php', $this->answer('tests.yml'));
    }

    /**
     * Matching runs in the C locale whatever the caller's: a filter that
     * holds a character outside ASCII cannot be read byte by byte as GitHub
     * reads it, so the workflow is taken to read every change.
     *
     * @return iterable<string, array{path: string}>
     */
    public static function provideChangesUnderAnAccentedFilter(): iterable
    {
        yield 'the name without the optional character' => ['path' => 'src/caf.php'];
        yield 'a file the filter does not name' => ['path' => 'docs/guide.md'];
    }

    #[Test]
    #[DataProvider('provideChangesUnderAnAccentedFilter')]
    public function test_a_filter_outside_ascii_reads_every_change(string $path): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'accented.yml', 'completed success');
        $change = $this->commitFiles([$path => 'changed']);

        $this->assertSame('unread '.$this->short($change).' '.$path, $this->answer('accented.yml'));
    }

    #[Test]
    public function test_a_range_is_ascii_whatever_the_callers_locale(): void
    {
        $code = $this->commitFiles(['src/A.php' => 'a']);
        $this->ranOn($code, 'ranged.yml', 'completed success');
        $change = $this->commitFiles(['src/dzs.php' => 'changed']);

        // In a Hungarian locale "dzs" is one letter between "a" and "z".
        [$exit, $output] = $this->ciAnswer(['ranged.yml'], ['LC_ALL' => 'hu_HU.UTF-8', 'LANG' => 'hu_HU.UTF-8']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('unread '.$this->short($change).' src/dzs.php', rtrim($output, "\n"));
    }

    #[Test]
    public function test_a_workflow_is_required(): void
    {
        [$code, $output] = $this->ciAnswer([]);

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('Usage', $output);
    }

    /**
     * @param array<string, string> $files
     */
    private function commitFiles(array $files, string $message = 'change'): string
    {
        foreach ($files as $path => $content) {
            $file = $this->root.'/repository/'.$path;
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }
            file_put_contents($file, $content);
        }
        $this->git('add', '-A');
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--no-verify', '-m', $message);

        return $this->git('rev-parse', 'HEAD');
    }

    /**
     * @param array<string, string> $files
     */
    private function branchCommit(string $branch, array $files): string
    {
        $this->git('checkout', '-q', '-b', $branch);
        $head = $this->commitFiles($files);
        $this->git('checkout', '-q', '2.x');

        return $head;
    }

    private function merge(string $branch): string
    {
        $this->git('-c', 'user.name=t', '-c', 'user.email=t@t', 'merge', '-q', '--no-ff', '--no-verify', '-m', 'Merge '.$branch, $branch);

        return $this->git('rev-parse', 'HEAD');
    }

    private function ranOn(string $sha, string $workflow, string $state, string $event = 'pull_request'): void
    {
        $this->runs[] = $sha.' '.$workflow.' '.$state.' '.$event;
    }

    private function short(string $sha): string
    {
        return $this->git('rev-parse', '--short', $sha);
    }

    private function answer(string ...$arguments): string
    {
        [$code, $output] = $this->ciAnswer(array_values($arguments));
        $this->assertSame(0, $code, $output);

        return rtrim($output, "\n");
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $locale
     *
     * @return array{int, string}
     */
    private function ciAnswer(array $arguments, array $locale = []): array
    {
        file_put_contents($this->root.'/runs', implode("\n", $this->runs)."\n");
        $env = $locale + [
            'PATH' => $this->root.'/fake:'.getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'GH_RUNS' => $this->root.'/runs',
            'GH_LOG' => $this->root.'/gh.log',
        ];
        $command = [\dirname(__DIR__, 3).'/bin/ci-answer', '--root', $this->root.'/repository', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function git(string ...$arguments): string
    {
        $output = [];
        exec('git -C '.escapeshellarg($this->root.'/repository').' '.implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1', $output);

        return implode("\n", $output);
    }
}
