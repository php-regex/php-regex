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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/findings-report, run on a corpus of throwaway repositories and the
 * JSON parts the real linter writes for them: what the site receives (one
 * index, one file and one page per repository with findings), what it
 * leaves out (INFO, clean repositories, repositories gone from the
 * corpus), and that a day without change gives the same bytes.
 *
 * @phpstan-type Target array{php: string, pcre: string, source: string}
 * @phpstan-type Repo array{repo: string, owner: string, slug: string, target: Target, sha: string, committed: string, findings: int, fail: int, warn: int, rules: list<string>}
 * @phpstan-type Index array{library: array{version: string}, totals: array{repos: int, findings: int, fail: int, warn: int}, owners: array<string, array{avatar: string, profile: string}>, repos: list<Repo>}
 * @phpstan-type Issue array{level: string, rule: string, message: string, hint: string|null, docs: string|null}
 * @phpstan-type Line array{number: int, focus: bool, runs: list<array{string, string}>}
 * @phpstan-type Witness array{prefix: string, pump: string, suffix: string, n: int, length: int|null, pcre: string, limit: int}
 * @phpstan-type Finding array{id: string, file: string, line: int, column: int, pattern: string, source: string|null, location: string|null, level: string, issues: list<Issue>, rewrite: array{optimized: string, changes: list<string>}|null, witness: Witness|null, subject: string, permalink: string, snippet: list<Line>}
 * @phpstan-type RepoFile array{repo: string, sha: string, target: Target, findings: list<Finding>}
 */
#[Group('documentation')]
final class FindingsReportTest extends TestCase
{
    private const REPORT = PlaygroundDriver::ROOT.'/bin/findings-report';
    private const LINTER = PlaygroundDriver::ROOT.'/bin/regex';
    private const AVATARS = __DIR__.'/Fixtures/findings';

    private const SOURCE = <<<'PHP'
        <?php

        // Größe: a comment with multibyte letters, kept as it is.
        function check(string $name): bool
        {
            return 1 === preg_match('/^(a+)+$/', $name);
        }

        function tag(string $tag): bool
        {
            return 1 === preg_match('#^v\d+#is', $tag);
        }

        function pairs(string $text): bool
        {
            return 1 === preg_match('/^(?:ab)(cd)*$/', $text);
        }
        PHP;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/phpregex-findings-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/parts', 0o777, true);
        mkdir($this->dir.'/out/assets/findings/avatars', 0o777, true);
        // Offline, the report keeps the avatars already on disk, at the
        // size the list draws them twice over: no image library needed.
        foreach (['acme', 'calm', 'gone'] as $owner) {
            copy(self::AVATARS.'/avatar-48.png', $this->dir.'/out/assets/findings/avatars/'.$owner.'.png');
        }

        $this->repository('acme/tool', ['src/Check.php' => self::SOURCE."\n"]);
        $this->repository('calm/clean', ['src/Date.php' => "<?php\n\npreg_match('/^\\d{4}-\\d{2}-\\d{2}\$/D', \$x);\n"]);
        $this->lint('acme/tool');
        $this->lint('calm/clean');
        // A part left by a repository the corpus no longer holds.
        copy($this->dir.'/parts/acme__tool.json', $this->dir.'/parts/gone__away.json');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    #[Test]
    public function test_the_index_lists_each_repository_with_findings_and_its_target(): void
    {
        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $index = $this->index();
        $this->assertSame(['acme/tool'], array_column($index['repos'], 'repo'), 'A clean repository, and one gone from the corpus, are not listed.');
        $repo = $index['repos'][0];
        $this->assertSame('acme', $repo['owner']);
        $this->assertSame('acme__tool', $repo['slug']);
        $this->assertSame(['php' => '8.1', 'pcre' => $repo['target']['pcre'], 'source' => $repo['target']['source']], $repo['target']);
        $this->assertMatchesRegularExpression('~^10\.\d+$~D', $repo['target']['pcre'], 'Each project says the PCRE2 it was judged for.');
        $this->assertSame(1, $repo['fail']);
        $this->assertSame(1, $repo['warn']);
        $this->assertSame(2, $repo['findings']);
        $this->assertSame($this->head('acme/tool'), $repo['sha']);
        $this->assertContains('regex.lint.flag.useless.s', $repo['rules'], 'The filter reads the rules without opening the repository.');
        $this->assertSame(['repos' => 1, 'findings' => 2, 'fail' => 1, 'warn' => 1], $index['totals']);
        $this->assertSame(['acme' => ['avatar' => '/assets/findings/avatars/acme.png', 'profile' => 'https://github.com/acme']], $index['owners']);
        $this->assertArrayNotHasKey('commit', $index['library'], 'A commit of the library would move the index every day the corpus did not.');
    }

    #[Test]
    public function test_info_level_issues_are_left_out(): void
    {
        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $patterns = array_column($this->repoFile()['findings'], 'pattern');
        $this->assertNotContains('/^(?:ab)(cd)*$/', $patterns, 'A pattern with INFO only is advice, not a finding.');
        foreach ($this->repoFile()['findings'] as $finding) {
            foreach ($finding['issues'] as $issue) {
                $this->assertContains($issue['level'], ['fail', 'warn']);
            }
        }
    }

    #[Test]
    public function test_a_finding_carries_the_linters_words_rule_link_and_rewrite(): void
    {
        $this->report();

        $finding = $this->finding('#^v\d+#is');
        $this->assertSame('src/Check.php', $finding['file']);
        $this->assertSame(11, $finding['line']);
        $this->assertSame([[
            'level' => 'warn',
            'rule' => 'regex.lint.flag.useless.s',
            'message' => "Flag 's' is useless: the pattern contains no unescaped dot outside a character class.",
            'hint' => null,
            'docs' => 'https://php-regex.com/reference/rules/#useless-flag-s-dotall',
        ]], $finding['issues']);
        $this->assertSame('#^v\d+#i', $finding['rewrite']['optimized'] ?? null, 'The optimizer\'s own rewrite, as the linter printed it.');
        $this->assertNull($finding['witness']);
        $this->assertSame('', $finding['subject'], 'Nothing breaks a lint finding: the text field starts empty.');
    }

    #[Test]
    public function test_a_redos_finding_carries_the_raw_witness_and_the_replay(): void
    {
        $this->report();

        $finding = $this->finding('/^(a+)+$/');
        $this->assertSame('fail', $finding['issues'][0]['level']);
        $this->assertSame('regex.lint.redos', $finding['issues'][0]['rule']);
        // The replay gave up at 16 pumps under its 100 000 limit; the text
        // field gets four times that, enough for PHP's default limit.
        $witness = $finding['witness'];
        $this->assertNotNull($witness);
        $this->assertSame(['prefix' => '', 'pump' => 'a', 'suffix' => '!', 'n' => 64], array_intersect_key($witness, array_flip(['prefix', 'pump', 'suffix', 'n'])));
        $this->assertSame(str_repeat('a', 64).'!', $finding['subject'], 'Edit a copy puts this in the text field: the witness, raw.');
        $this->assertFalse(@preg_match($finding['pattern'], $finding['subject']), 'PHP gives up on it under its default limit.');
        $this->assertSame(17, $witness['length'], 'The length at which the replay gave up.');
        $this->assertSame(100000, $witness['limit']);
        $this->assertMatchesRegularExpression('~^10\.\d+$~D', $witness['pcre']);
    }

    #[Test]
    public function test_the_snippet_is_cut_from_the_clone_at_its_commit(): void
    {
        $this->report();

        $finding = $this->finding('/^(a+)+$/');
        $this->assertSame(\sprintf('https://github.com/acme/tool/blob/%s/src/Check.php#L3-L9', $this->head('acme/tool')), $finding['permalink']);
        $lines = [];
        foreach ($finding['snippet'] as $line) {
            $lines[$line['number']] = implode('', array_column($line['runs'], 0));
            $this->assertSame(6 === $line['number'], $line['focus']);
        }
        $this->assertSame(range(3, 9), array_keys($lines));
        $this->assertSame('// Größe: a comment with multibyte letters, kept as it is.', $lines[3], 'Multibyte text comes through byte for byte.');
        $this->assertSame("    return 1 === preg_match('/^(a+)+$/', \$name);", $lines[6]);
        $classes = implode(' ', array_column($finding['snippet'][3]['runs'], 1));
        $this->assertStringContainsString('pg-snippet-pattern', $classes);
        $this->assertStringContainsString('pg-tok-', $classes, 'The literal speaks the token colors of the pattern field.');
    }

    #[Test]
    public function test_a_finding_keeps_its_id_when_its_line_moves(): void
    {
        $this->report();
        $before = $this->finding('/^(a+)+$/');
        $this->assertMatchesRegularExpression('~^f-[0-9a-f]{10}$~D', $before['id']);

        $this->repository('acme/tool', ['src/Check.php' => "<?php\n\n".substr(self::SOURCE, \strlen("<?php\n"))."\n"], false);
        $this->lint('acme/tool');
        $this->report();
        $after = $this->finding('/^(a+)+$/');

        $this->assertSame(7, $after['line']);
        $this->assertSame($before['id'], $after['id'], 'A shared #f- link survives a line added above it.');
    }

    #[Test]
    public function test_one_page_per_repository_and_none_for_a_repository_gone(): void
    {
        mkdir($this->dir.'/out/_findings', 0o777, true);
        file_put_contents($this->dir.'/out/_findings/old__gone.md', "---\n---\n");

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['acme__tool.md'], array_map(basename(...), glob($this->dir.'/out/_findings/*.md') ?: []), 'Yesterday\'s pages of repositories without findings go.');
        $page = (string) file_get_contents($this->dir.'/out/_findings/acme__tool.md');
        $this->assertStringContainsString("layout: landing\n", $page);
        $this->assertStringContainsString("permalink: /findings/acme/tool/\n", $page);
        $this->assertStringContainsString("title: \"acme/tool: 2 regex findings\"\n", $page);
        $this->assertStringContainsString("findings_repo: acme/tool\n", $page);
        $this->assertStringContainsString("image: /assets/img/findings-social.png\n", $page);
        $this->assertStringContainsString('{% include finding.html %}', $page);
        $this->assertFileDoesNotExist($this->dir.'/out/assets/findings/repos/gone__away.json');
    }

    /**
     * bin/corpus-lint runs from the checkout: its parts name the files
     * as corpus/<vendor>/<repo>/…, relative to the corpus's parent.
     */
    #[Test]
    public function test_a_part_naming_its_files_relative_to_the_checkout_is_read(): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        file_put_contents($part, str_replace($this->dir.'/corpus/', 'corpus/', (string) file_get_contents($part)));

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertSame('src/Check.php', $this->finding('/^(a+)+$/')['file']);
    }

    #[Test]
    public function test_a_day_without_change_writes_the_same_bytes(): void
    {
        $this->report();
        $first = $this->snapshot();
        $this->report();

        $this->assertSame($first, $this->snapshot(), 'The daily run gives no diff when the corpus did not move.');
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function test_avatars_are_48_pixels_and_those_of_owners_gone_go(): void
    {
        copy(self::AVATARS.'/avatar-100.png', $this->dir.'/out/assets/findings/avatars/acme.png');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $size = getimagesize($this->dir.'/out/assets/findings/avatars/acme.png');
        $this->assertIsArray($size);
        $this->assertSame([48, 48], [$size[0], $size[1]], 'Twice the size the list draws, whatever GitHub sent.');
        $this->assertFileDoesNotExist($this->dir.'/out/assets/findings/avatars/gone.png', 'An owner without findings keeps no avatar.');
    }

    #[Test]
    public function test_a_repository_named_like_a_file_keeps_its_findings(): void
    {
        $this->repository('acme/notes.md', ['src/Check.php' => self::SOURCE."\n"]);
        $this->lint('acme/notes.md');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($this->dir.'/out/assets/findings/repos/acme__notes~md.json');
        $this->assertFileExists($this->dir.'/out/_findings/acme__notes~md.md');
    }

    /**
     * A repository named like a PHP file (scrivo/highlight.php) is no PHP
     * file of the site: its files spell the dot as "~", which no GitHub
     * name holds; its address keeps the name as GitHub writes it.
     */
    #[Test]
    public function test_a_repository_named_like_a_php_file_gets_files_that_are_not(): void
    {
        $this->repository('acme/tool.php', ['src/Check.php' => self::SOURCE."\n"]);
        $this->lint('acme/tool.php');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertFileExists($this->dir.'/out/assets/findings/repos/acme__tool~php.json');
        $this->assertFileExists($this->dir.'/out/_findings/acme__tool~php.md');
        $this->assertFileDoesNotExist($this->dir.'/out/assets/findings/repos/acme__tool.php.json');
        $this->assertContains('acme__tool~php', array_column($this->index()['repos'], 'slug'));
        $this->assertStringContainsString("permalink: /findings/acme/tool.php/\n", (string) file_get_contents($this->dir.'/out/_findings/acme__tool~php.md'));
    }

    #[Test]
    public function test_a_corpus_without_the_parts_repositories_is_refused_and_yesterday_stays(): void
    {
        $this->report();
        mkdir($this->dir.'/empty');

        [$exit, $output] = $this->report($this->dir.'/empty');

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('corpus', $output);
        $this->assertFileExists($this->dir.'/out/_findings/acme__tool.md', 'A wrong corpus never empties the site.');
        $this->assertFileExists($this->dir.'/out/assets/findings/repos/acme__tool.json');
    }

    #[Test]
    public function test_a_symlinked_corpus_reads_parts_relative_to_the_checkout(): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        file_put_contents($part, str_replace($this->dir.'/corpus/', 'corpus/', (string) file_get_contents($part)));
        // The clones live on another disk; corpus/ points there.
        mkdir($this->dir.'/disk/data', 0o777, true);
        rename($this->dir.'/corpus', $this->dir.'/disk/data/clones');
        symlink($this->dir.'/disk/data/clones', $this->dir.'/corpus');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertSame('src/Check.php', $this->finding('/^(a+)+$/')['file']);
    }

    #[Test]
    public function test_a_part_older_than_its_clone_is_refused(): void
    {
        $this->repository('acme/tool', ['src/Check.php' => "<?php\n// moved\n".substr(self::SOURCE, \strlen("<?php\n"))."\n"], false);
        touch($this->dir.'/parts/acme__tool.json', time() - 3600);

        [$exit, $output] = $this->report();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('acme__tool', $output);
        $this->assertStringContainsString('bin/corpus-lint', $output, 'Findings judged on yesterday\'s code are never shown under today\'s commit.');
    }

    #[Test]
    public function test_a_part_naming_another_commit_than_its_clone_is_refused(): void
    {
        $this->stampHead(str_repeat('0', 40));

        [$exit, $output] = $this->report();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('acme__tool', $output);
        $this->assertStringContainsString('bin/corpus-lint', $output);
    }

    #[Test]
    public function test_a_part_naming_its_clones_commit_is_read_whatever_touched_the_clone(): void
    {
        $this->stampHead(trim((string) shell_exec('git -C '.escapeshellarg($this->dir.'/corpus/acme/tool').' rev-parse HEAD')));
        touch($this->dir.'/parts/acme__tool.json', time() - 3600);
        touch($this->dir.'/corpus/acme/tool/.git/index');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
    }

    #[Test]
    public function test_a_witness_spelled_otherwise_than_the_library_builds_it_is_left_out(): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        file_put_contents($part, str_replace('"pump": "a"', '"pump": "b"', (string) file_get_contents($part)));

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $finding = $this->finding('/^(a+)+$/');
        $this->assertNull($finding['witness'], 'The card never spells a string the text field would not get.');
        $this->assertSame('', $finding['subject']);
    }

    #[Test]
    public function test_the_literal_marked_is_the_one_at_the_findings_column(): void
    {
        $this->repository('acme/twice', ['src/Twice.php' => "<?php\n\nfunction twice(\$x)\n{\n    return str_contains(\$x, '/^(a+)+\$/') && 1 === preg_match('/^(a+)+\$/', \$x);\n}\n"]);
        $this->lint('acme/twice');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $file = json_decode((string) file_get_contents($this->dir.'/out/assets/findings/repos/acme__twice.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($file);
        $this->assertIsArray($file['findings']);
        $this->assertIsArray($file['findings'][0]);
        $finding = $file['findings'][0];
        $this->assertIsArray($finding['snippet']);
        foreach ($finding['snippet'] as $line) {
            $this->assertIsArray($line);
            if (true !== $line['focus']) {
                continue;
            }
            $this->assertIsArray($line['runs']);
            $offset = 0;
            foreach ($line['runs'] as $run) {
                $this->assertIsArray($run);
                $this->assertIsString($run[0]);
                if ('' !== $run[1]) {
                    break;
                }
                $offset += \strlen($run[0]);
            }
            $this->assertSame($finding['column'], $offset + 1, 'The literal the linter judged, not a string that looks like it.');
        }
    }

    #[Test]
    public function test_the_target_names_composer_json_or_the_fallback_never_a_path(): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        $report = json_decode((string) file_get_contents($part), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertIsArray($report['target']);
        $report['target']['source'] = '/tmp/John Doe/corpus/acme/tool/composer.json require.php';
        file_put_contents($part, json_encode($report, \JSON_THROW_ON_ERROR));
        $this->report();
        $this->assertSame('composer.json require.php', $this->index()['repos'][0]['target']['source']);

        $report['target']['source'] = '--php-version';
        file_put_contents($part, json_encode($report, \JSON_THROW_ON_ERROR));
        $this->report();
        $this->assertSame('fallback: no require.php', $this->index()['repos'][0]['target']['source'], 'The log\'s own words for a repository without a floor.');
    }

    #[Test]
    public function test_a_missing_avatar_offline_is_refused(): void
    {
        unlink($this->dir.'/out/assets/findings/avatars/acme.png');

        [$exit, $output] = $this->report();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('acme', $output);
        $this->assertStringContainsString('avatar', $output);
        $this->assertFileDoesNotExist($this->dir.'/out/assets/findings/index.json', 'A refusal writes nothing.');
    }

    /**
     * @param array<string, string> $files
     */
    private function repository(string $name, array $files, bool $init = true): void
    {
        $root = $this->dir.'/corpus/'.$name;
        foreach ($files as $path => $content) {
            if (!is_dir(\dirname($root.'/'.$path))) {
                mkdir(\dirname($root.'/'.$path), 0o777, true);
            }
            file_put_contents($root.'/'.$path, $content);
        }
        $git = 'git -C '.escapeshellarg($root).' ';
        $who = '-c user.email=t@e.st -c user.name=t ';
        shell_exec(($init ? $git.'init -q && ' : '').$git.'add -A && '.$git.$who.'commit -q -m change 2>&1');
    }

    private function lint(string $name): void
    {
        $part = $this->dir.'/parts/'.str_replace('/', '__', $name).'.json';
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', self::LINTER, 'lint', $this->dir.'/corpus/'.$name, '--php-version', '8.1', '--redos', '--redos-mode=confirmed', '--output', $part, '--format=json'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->assertFileExists($part);
    }

    private function stampHead(string $head): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        $report = json_decode((string) file_get_contents($part), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $report['head'] = $head;
        file_put_contents($part, json_encode($report, \JSON_THROW_ON_ERROR));
    }

    private function head(string $name): string
    {
        return trim((string) shell_exec('git -C '.escapeshellarg($this->dir.'/corpus/'.$name).' rev-parse HEAD'));
    }

    /**
     * @return array{int, string}
     */
    private function report(?string $corpus = null): array
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', self::REPORT, '--offline', '--parts='.$this->dir.'/parts', '--corpus='.($corpus ?? $this->dir.'/corpus'), '--out='.$this->dir.'/out'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * @return Index
     */
    private function index(): array
    {
        $this->assertFileExists($this->dir.'/out/assets/findings/index.json');
        /** @var Index $data */
        $data = json_decode((string) file_get_contents($this->dir.'/out/assets/findings/index.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return RepoFile
     */
    private function repoFile(): array
    {
        $this->assertFileExists($this->dir.'/out/assets/findings/repos/acme__tool.json');
        /** @var RepoFile $data */
        $data = json_decode((string) file_get_contents($this->dir.'/out/assets/findings/repos/acme__tool.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return Finding
     */
    private function finding(string $pattern): array
    {
        foreach ($this->repoFile()['findings'] as $finding) {
            if ($finding['pattern'] === $pattern) {
                return $finding;
            }
        }
        $this->fail('No finding for '.$pattern);
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];
        foreach (['assets/findings/index.json', 'assets/findings/repos/acme__tool.json', '_findings/acme__tool.md'] as $path) {
            $files[$path] = (string) file_get_contents($this->dir.'/out/'.$path);
        }

        return $files;
    }
}
