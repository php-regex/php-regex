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

use PHPRegex\Tests\Support\FrontMatter;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Tests\TestUtils\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
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
            'docs' => 'https://php-regex.com/reference/rules/useless-flag-s-dotall/',
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

    /**
     * A replay that gave up only past what the text field takes would be
     * published cut, and the cut string may not give up: no witness then.
     */
    #[Test]
    public function test_a_replay_longer_than_the_text_field_publishes_no_witness(): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        $replayed = (string) preg_replace('/"input_length": \d+/', '"input_length": 12000', (string) file_get_contents($part));
        $this->assertStringContainsString('"input_length": 12000', $replayed);
        file_put_contents($part, $replayed);

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $finding = $this->finding('/^(a+)+$/');
        $this->assertNull($finding['witness']);
        $this->assertSame('', $finding['subject']);
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
    public function test_many_findings_share_source_indexes_without_mixing_files_or_literals(): void
    {
        $results = [];
        foreach (['First', 'Second'] as $name) {
            $source = "<?php\n";
            for ($i = 0; $i < 100; $i++) {
                // The second spelling wins by column, even though both decode
                // to the same pattern. CRLF and UTF-8 keep byte offsets honest.
                $text = '/* é '.$name.' */ preg_match(\'/a/s\', $x); preg_match("/a/s", $x);';
                $source .= $text."\r\n";
                $column = strpos($text, '"/a/s"');
                $this->assertNotFalse($column);
                $results[] = [
                    'file' => $this->dir.'/corpus/acme/tool/src/'.$name.'.php',
                    'line' => $i + 2,
                    'column' => $column + 1,
                    'pattern' => '/a/s',
                    'issues' => [['severity' => 'warning', 'issue_id' => 'regex.lint.flag.useless.s']],
                ];
            }
            $this->repository('acme/tool', ['src/'.$name.'.php' => $source], false);
        }
        // Alternate files so reuse cannot depend on adjacent report rows.
        usort($results, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        file_put_contents($this->dir.'/parts/acme__tool.json', json_encode([
            'head' => $this->head('acme/tool'),
            'target' => ['php' => '8.1', 'pcre' => '10.42'],
            'results' => $results,
        ], \JSON_THROW_ON_ERROR));

        [$exit, $output] = $this->report();
        $this->assertSame(0, $exit, $output);
        $findings = $this->repoFile()['findings'];
        $this->assertCount(200, $findings);
        foreach ($findings as $finding) {
            $focus = array_values(array_filter($finding['snippet'], static fn (array $line): bool => $line['focus']))[0];
            $plain = '';
            foreach ($focus['runs'] as [$text, $class]) {
                if (str_contains($class, 'pg-snippet-pattern')) {
                    break;
                }
                $plain .= $text;
            }
            $this->assertSame($finding['column'], \strlen($plain) + 1);
            $this->assertStringContainsString(pathinfo($finding['file'], \PATHINFO_FILENAME), $plain);
        }
    }

    /**
     * A file full of string literals with one finding in it: only the
     * literals on the finding's line are decoded, so the byte maps of the
     * others never fill the memory.
     */
    #[Test]
    public function test_only_the_literals_on_a_findings_line_are_decoded(): void
    {
        $source = "<?php\n\npreg_match('/a/s', \$x);\n";
        for ($i = 0; $i < 2000; $i++) {
            $source .= "\$table[] = '".str_repeat('b', 2500)."';\n";
        }
        $this->repository('acme/tool', ['src/Table.php' => $source], false);
        file_put_contents($this->dir.'/parts/acme__tool.json', json_encode([
            'head' => $this->head('acme/tool'),
            'target' => ['php' => '8.1', 'pcre' => '10.42'],
            'results' => [[
                'file' => $this->dir.'/corpus/acme/tool/src/Table.php',
                'line' => 3,
                'column' => 12,
                'pattern' => '/a/s',
                'issues' => [['severity' => 'warning', 'issue_id' => 'regex.lint.flag.useless.s']],
            ]],
        ], \JSON_THROW_ON_ERROR));

        // Decoding every literal holds a byte map of about 80 MB.
        [$exit, $output] = $this->report(null, ['memory_limit' => '64M']);

        $this->assertSame(0, $exit, $output);
        $findings = $this->repoFile()['findings'];
        $this->assertCount(1, $findings);
        $focus = array_values(array_filter($findings[0]['snippet'], static fn (array $line): bool => $line['focus']))[0];
        $plain = '';
        foreach ($focus['runs'] as [$text, $class]) {
            if (str_contains($class, 'pg-snippet-pattern')) {
                break;
            }
            $plain .= $text;
        }
        $this->assertSame('preg_match(', $plain);
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

    /**
     * On Windows the linter names files with backslashes: the report reads
     * them as the same files.
     */
    #[Test]
    public function test_a_part_naming_its_files_with_backslashes_is_read(): void
    {
        $part = $this->dir.'/parts/acme__tool.json';
        $report = json_decode((string) file_get_contents($part), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertIsArray($report['results']);
        $results = [];
        foreach ($report['results'] as $result) {
            $this->assertIsArray($result);
            $this->assertIsString($result['file']);
            $results[] = ['file' => str_replace('/', '\\', $result['file'])] + $result;
        }
        $report['results'] = $results;
        file_put_contents($part, json_encode($report, \JSON_THROW_ON_ERROR));

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertSame('src/Check.php', $this->finding('/^(a+)+$/')['file'], 'A path from the repository, with forward slashes: the permalink needs them.');
    }

    /**
     * Outside Windows a backslash is a character of a file name: the
     * report reads that very file, and links it as it is named.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_file_whose_name_holds_a_backslash_is_read_as_named(): void
    {
        $this->repository('acme/odd', ['src/we\\ird.php' => "<?php\n\nreturn preg_match('/^(a+)+\$/', \$x);\n"]);
        $this->lint('acme/odd');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $file = json_decode((string) file_get_contents($this->dir.'/out/assets/findings/repos/acme__odd.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($file);
        $this->assertIsArray($file['findings']);
        $this->assertIsArray($file['findings'][0]);
        $this->assertSame('src/we\\ird.php', $file['findings'][0]['file']);
        $this->assertIsString($file['findings'][0]['permalink']);
        $this->assertStringContainsString('/src/we%5Cird.php#L', (string) $file['findings'][0]['permalink']);
    }

    /**
     * A link inside a clone that leads out of it is not read, even when the
     * file it leads to is named like a path of the clone ("tool\\secret.php"
     * next to "tool/").
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_link_out_of_the_clone_is_not_read(): void
    {
        file_put_contents($this->dir.'/corpus/acme/tool\\secret.php', "<?php\n\nreturn preg_match('/^(b+)+\$/', \$x);\n");
        symlink($this->dir.'/corpus/acme/tool\\secret.php', $this->dir.'/corpus/acme/tool/src/link.php');
        $part = $this->dir.'/parts/acme__tool.json';
        $report = json_decode((string) file_get_contents($part), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertIsArray($report['results']);
        $this->assertIsArray($report['results'][0]);
        $report['results'][] = ['file' => $this->dir.'/corpus/acme/tool/src/link.php', 'line' => 3, 'column' => 19, 'pattern' => '/^(b+)+$/'] + $report['results'][0];
        file_put_contents($part, json_encode($report, \JSON_THROW_ON_ERROR));

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $this->assertNotContains('/^(b+)+$/', array_column($this->repoFile()['findings'], 'pattern'), 'Nothing outside the clone reaches the site.');
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
    public function test_a_write_that_fails_stops_the_report(): void
    {
        // A file where the findings' directory should be.
        file_put_contents($this->dir.'/out/assets/findings/repos', 'not a directory');

        [$exit, $output] = $this->report();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('cannot', $output);
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
     * A page opens on the finding the playground opens on: the field of
     * the tool holds that pattern from the first paint. Under the tool it
     * lists every finding, for a reader without the script and for a
     * search engine: where it stands, its pattern, what the linter says,
     * the page of each rule, and the link that replays it.
     */
    #[Test]
    public function test_a_page_opens_on_its_first_finding_and_lists_them_all(): void
    {
        $this->report();

        $page = (string) file_get_contents($this->dir.'/out/_findings/acme__tool.md');
        $first = $this->finding('/^(a+)+$/');
        $second = $this->finding('#^v\d+#is');
        $this->assertStringContainsString('finding_pattern: '.json_encode($first['pattern'], \JSON_UNESCAPED_SLASHES)."\n", $page, 'The field of the tool opens on the first finding.');
        $this->assertLessThan((int) strpos($page, 'id="finding-'.$second['id'].'"'), (int) strpos($page, 'id="finding-'.$first['id'].'"'), 'The list follows the order of the tool.');

        foreach ([$first, $second] as $finding) {
            $row = $this->between($page, '<li class="finding-row is-'.$finding['level'].'" id="finding-'.$finding['id'].'">', '</ul></li>');
            $this->assertStringContainsString('<a class="finding-row-replay" href="#'.$finding['id'].'">', $row, 'A finding replays in the tool above.');
            $this->assertStringContainsString('<code class="finding-row-place">'.$this->shown($finding['file'].':'.$finding['line']).'</code>', $row);
            $this->assertStringContainsString('<code class="finding-row-pattern">'.$this->shown($finding['pattern']).'</code>', $row);
            foreach ($finding['issues'] as $issue) {
                $this->assertStringContainsString('<a class="finding-row-rule" href="'.$this->docs($issue['docs']).'">'.$this->shown($issue['rule']).'</a>', $row, 'An issue links to the page of its rule.');
                $this->assertStringContainsString('<span class="finding-row-message">'.$this->shown($issue['message']).'</span>', $row, "The linter's own words.");
            }
        }
        $this->assertSame(2, substr_count($page, '<li class="finding-row '), 'Every finding, once.');
        $this->assertStringNotContainsString('<details', $page, 'A short list does not fold.');
    }

    /**
     * The page opens on the card of its first finding, drawn by the report
     * as the script draws it once the findings have travelled: the page
     * shows it at the first paint, and nothing under it moves when the
     * script runs. The finding here is a proven ReDoS: its witness and the
     * outcome of its replay stand on the card, and no rewrite does.
     */
    #[Test]
    public function test_a_page_opens_on_the_card_of_its_first_finding(): void
    {
        $this->report();

        $front = FrontMatter::of($this->dir.'/out/_findings/acme__tool.md');
        $card = $front['finding_card'] ?? null;
        $this->assertIsString($card);
        $this->assertStringNotContainsString("\n", (string) $card, 'No whitespace the script does not write: a space between two inline boxes is a space on the page.');

        $sha = $this->head('acme/tool');
        $this->assertStringContainsString('<div class="pg-finding-head"><a class="pg-finding-avatar" href="https://github.com/acme"><img src="/assets/findings/avatars/acme.png" alt="acme on GitHub" width="24" height="24"></a><span class="pg-finding-repo">acme/tool</span><a class="pg-snippet-permalink" href="https://github.com/acme/tool/blob/'.$sha.'/src/Check.php#L3-L9">View on GitHub at '.substr($sha, 0, 7).'</a></div>', (string) $card);
        $this->assertStringContainsString('<p class="pg-finding-target"><span class="visually-hidden">Judged for </span><span class="pg-finding-engine">PHP 8.1</span><span class="pg-finding-engine">PCRE2 10.39</span><span class="pg-finding-asof">code as of '.date('Y-m-d').'</span></p>', (string) $card, 'A fallback of the PHP version is the pipeline\'s business, not the visitor\'s.');
        $this->assertStringContainsString('<p class="pg-snippet-file">src/Check.php:6 · preg_match()</p>', (string) $card);
        $this->assertStringContainsString('<pre class="pg-snippet" aria-label="The code at the clone\'s commit"><span class="pg-snippet-line"><span class="pg-snippet-number">3</span>// Größe: a comment with multibyte letters, kept as it is.</span>', (string) $card, 'A line of the code, its number, in the page\'s own words for the commit.');
        $this->assertStringContainsString('<span class="pg-snippet-line is-focus"><span class="pg-snippet-number">6</span>    return 1 === preg_match(<span class="pg-snippet-pattern pg-snippet-quote">&#039;</span><span class="pg-snippet-pattern pg-tok-meta">/</span>', (string) $card, 'The line of the finding, its literal in the colors of the field.');
        $this->assertStringContainsString('<li class="pg-finding-issue-row is-warn"><span class="pg-finding-level">WARN</span><span class="pg-finding-message">Nested quantifiers in capturing group 1 &quot;(a+)&quot; can cause catastrophic backtracking.</span><span class="pg-finding-hint">Consider atomic groups (?&gt;...) or possessive quantifiers — verify the rewrite still matches everything you need.</span><a class="pg-finding-rule" href="https://php-regex.com/reference/rules/nested-quantifiers-redos-risk/">regex.lint.quantifier.nested</a></li>', (string) $card, 'The linter\'s words, its hint, the link of its rule as the linter gives it.');
        $this->assertStringContainsString('<div class="pg-rewrite" hidden><p class="pg-rewrite-title">Suggested rewrite, by the optimizer</p><pre class="pg-rewrite-diff"></pre></div>', (string) $card, 'No rewrite was measured for this one: its block stands hidden, as the script leaves it.');
        $this->assertStringContainsString('<p class="pg-witness"><span class="pg-witness-label">Witness: </span><code class="pg-witness-pump">"a"</code> × 64 + <code>"!"</code></p>', (string) $card, 'A proven ReDoS: the string that breaks the pattern, the repeated part marked.');
        $this->assertMatchesRegularExpression('~<p class="pg-finding-outcome">Replayed on PCRE2 10\.\d+ \(JIT off, backtrack limit 100000\): preg_match\(\) gives up from length 17\.</p>$~', $card, 'Where the corpus run\'s replay gave up, in the log\'s own sentence.');
    }

    /**
     * A lint finding the optimizer rewrites shows the rewrite on its card;
     * nothing breaks it, so the witness and the outcome stay hidden.
     */
    #[Test]
    public function test_the_card_of_a_finding_the_optimizer_rewrites_shows_the_rewrite(): void
    {
        $this->repository('acme/fix', ['src/Fix.php' => "<?php\n\npreg_match('#^v\\d+#is', \$tag);\n"]);
        $this->lint('acme/fix');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $front = FrontMatter::of($this->dir.'/out/_findings/acme__fix.md');
        $card = $front['finding_card'] ?? null;
        $this->assertIsString($card);
        $this->assertStringContainsString('<li class="pg-finding-issue-row is-warn"><span class="pg-finding-level">WARN</span><span class="pg-finding-message">Flag &#039;s&#039; is useless: the pattern contains no unescaped dot outside a character class.</span><a class="pg-finding-rule" href="https://php-regex.com/reference/rules/useless-flag-s-dotall/">regex.lint.flag.useless.s</a></li>', (string) $card, 'No hint: no line for one.');
        $this->assertStringContainsString("<div class=\"pg-rewrite\"><p class=\"pg-rewrite-title\">Suggested rewrite, by the optimizer</p><pre class=\"pg-rewrite-diff\"><span class=\"pg-fix-del\">- #^v\d+#is</span>\n<span class=\"pg-fix-add\">+ #^v\d+#i</span>\n<span class=\"pg-rewrite-change\">Removed a flag nothing in the pattern reads.</span></pre></div>", (string) $card);
        $this->assertStringEndsWith('<p class="pg-witness" hidden></p><p class="pg-finding-outcome" hidden></p>', $card, 'Nothing breaks a lint finding.');
    }

    /**
     * A repository with hundreds of findings lists them all: the end of
     * the list folds, and stays on the page.
     */
    #[Test]
    public function test_the_end_of_a_long_list_folds(): void
    {
        $lines = '';
        for ($i = 0; 45 > $i; $i++) {
            $lines .= 'function tag'.$i."(string \$tag): bool { return 1 === preg_match('#^v\\\\d+#is', \$tag); }\n";
        }
        $this->repository('acme/many', ['src/Many.php' => "<?php\n\n".$lines]);
        $this->lint('acme/many');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $page = (string) file_get_contents($this->dir.'/out/_findings/acme__many.md');
        $this->assertSame(45, substr_count($page, '<li class="finding-row '), 'Every finding is on the page.');
        $fold = (int) strpos($page, '<details class="findings-more"><summary>5 more findings</summary>');
        $this->assertGreaterThan(0, $fold, 'The end of the list folds.');
        $this->assertSame(40, substr_count(substr($page, 0, $fold), '<li class="finding-row '), 'Forty stand open.');
    }

    /**
     * What each rule met in the corpus, for the page of the rule: how
     * many findings, in how many projects, and a few to open.
     */
    #[Test]
    public function test_the_findings_of_each_rule_are_counted_for_the_page_of_the_rule(): void
    {
        $this->report();

        $this->assertFileExists($this->dir.'/out/_data/finding_rules.json');
        $rules = json_decode((string) file_get_contents($this->dir.'/out/_data/finding_rules.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($rules);
        $second = $this->finding('#^v\d+#is');
        $this->assertSame(['findings' => 1, 'projects' => 1, 'docs' => '/reference/rules/useless-flag-s-dotall/', 'examples' => [['repo' => 'acme/tool', 'id' => $second['id'], 'file' => 'src/Check.php', 'line' => 11, 'pattern' => '#^v\d+#is']]], $rules['regex.lint.flag.useless.s'] ?? null);
        $redos = $rules['regex.lint.redos'] ?? null;
        $this->assertIsArray($redos);
        $this->assertSame('/reference/rules/catastrophic-backtracking/', $redos['docs'] ?? null);
        $this->assertArrayNotHasKey('regex.lint.quantifier.useless', $rules, 'A rule nothing met is not listed.');
        $names = array_keys($rules);
        sort($names, \SORT_STRING);
        $this->assertSame($names, array_keys($rules), 'The rules stand in the order of their identifiers: a day without change gives the same bytes.');
    }

    /**
     * A pattern or a message with two braces in a row is text on the page:
     * the template engine that builds the site reads none of it.
     */
    #[Test]
    public function test_braces_the_template_engine_would_read_are_written_as_entities(): void
    {
        $this->repository('acme/braces', ['src/Braces.php' => "<?php\n\nfunction braces(\$x)\n{\n    return 1 === preg_match('/{{2}.*/i', \$x);\n}\n"]);
        $this->lint('acme/braces');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $page = (string) file_get_contents($this->dir.'/out/_findings/acme__braces.md');
        $this->assertStringContainsString("finding_pattern: \"/{{2}.*/i\"\n", $page, 'The front matter is JSON: there the braces are text.');
        $list = $this->between($page, '<section class="findings-list"', '</section>');
        $this->assertStringContainsString('<code class="finding-row-pattern">/&#123;&#123;2&#125;.*/i</code>', $list);
        $this->assertSame(0, preg_match('~\{[%{]~', $list), 'Nothing in the list opens a tag of the template engine.');
    }

    /**
     * A pattern that holds a control character the page cannot carry — a
     * carriage return, which the site's converter drops, a NUL byte, which
     * no page holds — is written as the escape PCRE2 gives it: the same
     * pattern, read the same by the engine. The tab and the line feed
     * travel as they are.
     */
    #[Test]
    public function test_a_control_character_a_page_cannot_carry_is_written_as_its_escape(): void
    {
        $this->repository('acme/control', ['src/Control.php' => "<?php\n\npreg_match(\"/[\\r\\n\\x00]+\\t/\", \$text);\n"]);
        $this->lint('acme/control');

        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $file = json_decode((string) file_get_contents($this->dir.'/out/assets/findings/repos/acme__control.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($file);
        $findings = $file['findings'] ?? null;
        $this->assertIsList($findings);
        $first = $findings[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame("/[\r\n\x00]+\t/", $first['pattern'] ?? null, 'The report keeps the pattern as the engine reads it.');
        $page = (string) file_get_contents($this->dir.'/out/_findings/acme__control.md');
        $this->assertStringContainsString("finding_pattern: \"/[\\\\x0D\\n\\\\x00]+\\t/\"\n", $page, 'The field of the tool opens on it, a control character as its escape: the front matter is JSON, where the line feed is an escape too.');
        $list = $this->between($page, '<section class="findings-list"', '</section>');
        $this->assertStringContainsString("<code class=\"finding-row-pattern\">/[\\x0D\n\\x00]+\t/</code>", $list, 'And so does its row, the line feed and the tab as they are.');
        $this->assertSame(0, preg_match('/[\x00\r]/', $page), 'Nothing of them stands raw on the page.');
    }

    /**
     * The page of a rule opens on findings a reader takes in at a glance:
     * short patterns. A rule that meets none takes the shortest of the
     * longer ones, up to what the list of a page shows; one whose every
     * finding is longer still has its figures and nothing to open.
     */
    #[Test]
    public function test_a_rule_opens_on_short_patterns_then_on_the_shortest_long_ones(): void
    {
        // 99 bytes, 300, 7, 99: digits only, so that the flag is what is useless.
        $this->repository('acme/long', ['src/Long.php' => "<?php\n\n"
            ."function long(\$x)\n{\n"
            ."    return 1 === preg_match('/".str_repeat('1', 96)."1/i', \$x)\n"
            ."        || 1 === preg_match('/".str_repeat('1', 297)."1/m', \$x)\n"
            ."        || 1 === preg_match('/(123)/i', \$x)\n"
            ."        || 1 === preg_match('/".str_repeat('1', 96)."1/x', \$x);\n}\n"]);
        $this->lint('acme/long');
        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $short = $this->rulesMet('regex.lint.flag.useless.i')['examples'];
        $this->assertIsList($short);
        $this->assertCount(1, $short, 'A rule opens on a short pattern, though a longer one came first.');
        $this->assertSame(['repo' => 'acme/long', 'file' => 'src/Long.php', 'line' => 7, 'pattern' => '/(123)/i'], self::withoutId($short[0]));

        $longer = $this->rulesMet('regex.lint.flag.useless.x')['examples'];
        $this->assertIsList($longer);
        $this->assertCount(1, $longer, 'A rule with no short pattern takes the shortest of the longer ones.');
        $this->assertSame(['repo' => 'acme/long', 'file' => 'src/Long.php', 'line' => 8, 'pattern' => '/'.str_repeat('1', 96).'1/x'], self::withoutId($longer[0]));

        $this->assertSame(
            ['findings' => 1, 'projects' => 1, 'docs' => '/reference/rules/useless-flag-m-multiline/', 'examples' => []],
            $this->rulesMet('regex.lint.flag.useless.m'),
            'A rule whose every finding is longer than the list shows has its figures and nothing to open.',
        );
    }

    /**
     * A rule met in several projects opens on one finding of each, in the
     * order the index lists the projects, before a second of the same one:
     * the page shows how far the rule reaches.
     */
    #[Test]
    public function test_a_rule_opens_on_a_finding_of_each_project_before_a_second_of_one(): void
    {
        // A project the index lists before the two others: it holds a finding that fails.
        $this->repository('acme/first', ['src/First.php' => "<?php\n\n"
            ."function first(\$x)\n{\n"
            ."    return 1 === preg_match('/^(a+)+\$/', \$x)\n"
            ."        || 1 === preg_match('/abc/s', \$x)\n"
            ."        || 1 === preg_match('/def/s', \$x);\n}\n"]);
        $this->repository('acme/long', ['src/Long.php' => "<?php\n\n"
            ."function long(\$x)\n{\n"
            ."    return 1 === preg_match('/ghi/s', \$x)\n"
            ."        || 1 === preg_match('/jkl/s', \$x);\n}\n"]);
        $this->lint('acme/first');
        $this->lint('acme/long');
        [$exit, $output] = $this->report();

        $this->assertSame(0, $exit, $output);
        $index = $this->index();
        $this->assertSame(['acme/first', 'acme/tool', 'acme/long'], array_column($index['repos'], 'repo'), 'The index lists the projects the figures follow.');
        $met = $this->rulesMet('regex.lint.flag.useless.s');

        $this->assertSame(3, $met['projects']);
        $this->assertSame(['/abc/s', '#^v\d+#is', '/ghi/s', '/def/s', '/jkl/s'], array_column($met['examples'], 'pattern'), 'One finding of each project, then the next of each.');
        $this->assertSame(['acme/first', 'acme/tool', 'acme/long', 'acme/first', 'acme/long'], array_column($met['examples'], 'repo'));
    }

    /**
     * What a rule met in the corpus, as the report writes it for the site.
     *
     * @return array{findings: int, projects: int, docs: string|null, examples: list<mixed>}
     */
    private function rulesMet(string $id): array
    {
        $json = (string) file_get_contents($this->dir.'/out/_data/finding_rules.json');
        $rules = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($rules);
        $met = $rules[$id] ?? null;
        $this->assertIsArray($met, $id.' met something in the corpus.');
        $findings = $met['findings'] ?? null;
        $projects = $met['projects'] ?? null;
        $docs = $met['docs'] ?? null;
        $examples = $met['examples'] ?? null;
        $this->assertIsInt($findings);
        $this->assertIsInt($projects);
        if (null !== $docs) {
            $this->assertIsString($docs);
        }
        $this->assertIsList($examples);

        return ['findings' => $findings, 'projects' => $projects, 'docs' => $docs, 'examples' => $examples];
    }

    /**
     * A finding to open, as the page reads it: all but the identifier, which
     * the hash of the finding decides.
     *
     * @return array<int|string, mixed>
     */
    private static function withoutId(mixed $finding): array
    {
        self::assertIsArray($finding);
        self::assertIsString($finding['id'] ?? null, 'The finding keeps the identifier its page anchors.');

        return array_diff_key($finding, ['id' => true]);
    }

    /**
     * A text of a finding as the page writes it: its HTML entities, and
     * a brace as an entity too.
     */
    private function shown(string $text): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * The link of an issue as the page writes it: a path of the site.
     */
    private function docs(?string $url): string
    {
        $this->assertNotNull($url);

        return (string) preg_replace('~^https://php-regex\.com~', '', (string) $url);
    }

    private static function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($haystack, $to, $start + \strlen($from));
        self::assertNotFalse($end, $to);

        return substr($haystack, $start, $end - $start);
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
            ProcessPipes::descriptors(),
            $pipes,
        );
        $this->assertIsResource($process);
        ProcessPipes::drain($pipes);
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
     * @param array<string, string> $ini
     *
     * @return array{int, string}
     */
    private function report(?string $corpus = null, array $ini = []): array
    {
        $settings = [];
        foreach ($ini as $name => $value) {
            $settings[] = '-d';
            $settings[] = $name.'='.$value;
        }
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', ...$settings, self::REPORT, '--offline', '--parts='.$this->dir.'/parts', '--corpus='.($corpus ?? $this->dir.'/corpus'), '--out='.$this->dir.'/out'],
            ProcessPipes::descriptors(),
            $pipes,
        );
        $this->assertIsResource($process);
        $output = implode('', ProcessPipes::drain($pipes));
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
        foreach (['assets/findings/index.json', 'assets/findings/repos/acme__tool.json', '_findings/acme__tool.md', '_data/finding_rules.json'] as $path) {
            $files[$path] = (string) file_get_contents($this->dir.'/out/'.$path);
        }

        return $files;
    }
}
