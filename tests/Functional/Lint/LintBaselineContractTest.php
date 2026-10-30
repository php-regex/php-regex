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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The baseline file is part of the JSON contract: {"version": 1, "issues"},
 * whose entries {file, line, column, issue_id, message, severity, pattern,
 * pattern_hash} are sorted like the report, ending with a newline. A
 * baseline matches an issue by file, issue_id and pattern, so a message
 * reworded in a minor does not resurface it; a 1.x list, whose entries have
 * no issue_id, is not a baseline at all: reading it is a usage error.
 */
#[Group('documentation')]
final class LintBaselineContractTest extends TestCase
{
    use PcreGates;
    use RunsRegexCli;
    use TemporaryProject;

    private const FILES = [
        'src/b.php' => <<<'PHP'
            <?php

            preg_match('/x{1}/', $s); preg_match('/(b/', $s);

            PHP,
        'src/a.php' => <<<'PHP'
            <?php

            preg_match('/(a/', $s);
            preg_match('/a{1}/', $s);

            PHP,
    ];

    private const LINT = ['lint', 'src', '--format=json', '--jobs=1', '--no-optimize'];

    #[Test]
    public function test_generated_baseline_entries_have_exactly_the_documented_keys(): void
    {
        $this->enterProject(self::FILES);

        [, , $stderr] = $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);

        $baseline = JsonContract::decodeDocument((string) file_get_contents('baseline.json'));
        $this->assertSame(1, $baseline['version'] ?? null, $stderr);
        $entries = JsonContract::asArray($baseline['issues'] ?? null, $stderr);
        $this->assertNotSame([], $entries, $stderr);
        $this->assertTrue(array_is_list($entries));
        JsonContract::assertSnakeCaseKeys($baseline);
        JsonContract::assertShape('baseline', $baseline);

        foreach ($entries as $entry) {
            $this->assertIsArray($entry);
            $this->assertIsString($entry['issue_id']);
            $this->assertContains($entry['severity'], ['error', 'warning', 'info']);
            $this->assertIsInt($entry['column']);
            $this->assertIsString($entry['pattern']);
            $this->assertIsString($entry['pattern_hash']);
        }
    }

    #[Test]
    public function test_generated_baseline_is_sorted_like_the_report(): void
    {
        $this->enterProject(self::FILES);

        $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);

        $baseline = JsonContract::decodeDocument((string) file_get_contents('baseline.json'));
        $keys = [];
        foreach (JsonContract::asArray($baseline['issues'] ?? null) as $entry) {
            $this->assertIsArray($entry);
            $keys[] = [$entry['file'] ?? null, $entry['line'] ?? null, $entry['column'] ?? null, $entry['issue_id'] ?? null];
        }
        $this->assertSame(
            [
                ['src/a.php', 3, 12, 'regex.group.unclosed'],
                ['src/a.php', 4, 12, 'regex.lint.quantifier.useless'],
                ['src/b.php', 3, 12, 'regex.lint.quantifier.useless'],
                ['src/b.php', 3, 38, 'regex.group.unclosed'],
            ],
            $keys,
        );
    }

    #[Test]
    public function test_baseline_matches_by_issue_id_when_the_message_changed(): void
    {
        $this->enterProject(self::FILES);
        $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);

        $baseline = json_decode((string) file_get_contents('baseline.json'), true);
        $this->assertIsArray($baseline);
        $this->assertIsArray($baseline['issues'] ?? null);
        foreach ($baseline['issues'] as $index => $entry) {
            $this->assertIsArray($entry);
            $this->assertArrayHasKey('issue_id', $entry);
            $entry['message'] = 'A wording from an older release.';
            $baseline['issues'][$index] = $entry;
        }
        file_put_contents('baseline.json', json_encode($baseline, \JSON_PRETTY_PRINT)."\n");

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(0, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            $this->assertSame([], JsonContract::asArray($result)['issues'] ?? null, $stdout);
        }
    }

    /**
     * An entry no issue takes any more, in a file the run read, is an error
     * of its own: regex.ignore.unmatched, on the entry's file and line. The
     * issues the other entries still take stay out.
     */
    #[Test]
    public function test_an_entry_that_matches_nothing_in_a_scanned_file_is_reported(): void
    {
        $this->enterProject(self::FILES);
        $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);
        file_put_contents('src/a.php', "<?php\n\npreg_match('/(a/', \$s);\n");

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame(
            [['src/a.php', 4, 'regex.ignore.unmatched', 'error', 'The baseline entry for regex.lint.quantifier.useless matches no issue any more: remove it, or generate the baseline again.']],
            self::issuesOf($stdout),
        );
    }

    /**
     * A run over a part of the project reads no other file: the entries of
     * those files are not reported, and the opt-out silences the report.
     */
    #[Test]
    public function test_an_entry_of_a_file_the_run_did_not_read_is_not_reported(): void
    {
        $this->enterProject(self::FILES);
        $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);
        file_put_contents('src/a.php', "<?php\n\npreg_match('/(a/', \$s);\n");

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src/b.php', '--format=json', '--jobs=1', '--no-optimize', '--baseline=baseline.json']);
        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame([], self::issuesOf($stdout));

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json', '--no-report-unmatched-ignores']);
        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame([], self::issuesOf($stdout));
    }

    /**
     * A baseline written as the 1.x list, well-formed entries included, is
     * refused before any analysis: exit 2, the stage "usage", the one
     * message that says what a baseline is — never a silent pass-all.
     */
    #[Test]
    public function test_a_1x_list_baseline_is_refused_as_a_usage_error(): void
    {
        $this->enterProject(self::FILES);
        $this->runRegex([...self::LINT, '--generate-baseline=generated.json']);

        $generated = json_decode((string) file_get_contents('generated.json'), true);
        $this->assertIsArray($generated);
        $this->assertIsArray($generated['issues'] ?? null);
        $legacy = [];
        foreach ($generated['issues'] as $entry) {
            $this->assertIsArray($entry);
            $legacy[] = ['file' => $entry['file'], 'line' => $entry['line'], 'message' => $entry['message'], 'type' => 'error'];
        }
        file_put_contents('baseline.json', json_encode($legacy, \JSON_PRETTY_PRINT));

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage'], array_keys($document));
        $this->assertSame('The file baseline.json is not a lint baseline: expected {"version": 1, "issues": [...]}, as written by --generate-baseline.', $document['error']);
        $this->assertSame('usage', $document['stage']);
    }

    /**
     * The 1.x list is refused even when the same run means to write a new
     * baseline: the file is read before anything is generated, so nothing
     * is written and no issue slips through unfiltered.
     */
    #[Test]
    public function test_a_1x_list_baseline_is_refused_even_alongside_generate_baseline(): void
    {
        $this->enterProject(self::FILES + ['old.json' => (string) json_encode([['file' => 'src/a.php', 'line' => 3, 'message' => 'Known issue']], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)]);

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=old.json', '--generate-baseline=fresh.json']);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame('The file old.json is not a lint baseline: expected {"version": 1, "issues": [...]}, as written by --generate-baseline.', $document['error']);
        $this->assertSame('usage', $document['stage']);
        $this->assertFileDoesNotExist('fresh.json');
    }

    /**
     * A baseline entry filters its own issue: not another issue of the same
     * pattern, not the same issue on another line. The entry is the one
     * --generate-baseline writes, issue_id and pattern hash included.
     *
     * @param list<array{string, int, string}> $remaining
     */
    #[Test]
    #[DataProvider('provideSingleEntryBaselines')]
    public function test_baseline_entry_filters_its_own_issue_only(string $code, int $line, string $issueId, array $remaining): void
    {
        $this->enterProject(['src/c.php' => $code]);
        $this->runRegex([...self::LINT, '--generate-baseline=generated.json']);

        $kept = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument((string) file_get_contents('generated.json'))['issues'] ?? null) as $entry) {
            $entry = JsonContract::asArray($entry);
            if ($line === ($entry['line'] ?? null) && $issueId === ($entry['issue_id'] ?? null)) {
                $kept[] = $entry;
            }
        }
        $this->assertCount(1, $kept);
        file_put_contents('baseline.json', json_encode(['version' => 1, 'issues' => $kept], \JSON_PRETTY_PRINT));

        [, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame($remaining, $this->reportedIssues($stdout));
    }

    /**
     * The same entries with their issue_id, pattern and pattern_hash taken
     * out — the 1.x shape — make the file a 1.x list, and the run refuses
     * it whatever it holds.
     *
     * @param list<array{string, int, string}> $remaining
     */
    #[Test]
    #[DataProvider('provideSingleEntryBaselines')]
    public function test_a_single_1x_shaped_entry_is_refused(string $code, int $line, string $issueId, array $remaining): void
    {
        $this->enterProject(['src/c.php' => $code]);
        $this->runRegex([...self::LINT, '--generate-baseline=generated.json']);

        $kept = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument((string) file_get_contents('generated.json'))['issues'] ?? null) as $entry) {
            $entry = JsonContract::asArray($entry);
            if ($line === ($entry['line'] ?? null) && $issueId === ($entry['issue_id'] ?? null)) {
                unset($entry['issue_id'], $entry['pattern'], $entry['pattern_hash']);
                $kept[] = $entry;
            }
        }
        $this->assertCount(1, $kept);
        file_put_contents('baseline.json', json_encode($kept, \JSON_PRETTY_PRINT));

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame('usage', JsonContract::decodeDocument($stdout)['stage'] ?? null);
    }

    /**
     * @return iterable<string, array{code: string, line: int, issueId: string, remaining: list<array{string, int, string}>}>
     */
    public static function provideSingleEntryBaselines(): iterable
    {
        // /a{1}(?:b)/ holds two issues, the useless {1} first.
        $twoIssues = "<?php\n\npreg_match('/a{1}(?:b)/', \$s);\n";
        $twoLines = "<?php\n\npreg_match('/a{1}/', \$s);\npreg_match('/a{1}/', \$s);\n";

        yield 'another issue of the same pattern' => [
            'code' => $twoIssues,
            'line' => 3,
            'issueId' => 'regex.lint.quantifier.useless',
            'remaining' => [['src/c.php', 3, 'regex.lint.group.redundant']],
        ];
        yield 'the same issue on the next line' => [
            'code' => $twoLines,
            'line' => 3,
            'issueId' => 'regex.lint.quantifier.useless',
            'remaining' => [['src/c.php', 4, 'regex.lint.quantifier.useless']],
        ];
    }

    /**
     * An empty baseline leaves the report as it is, a result holding both
     * an issue and an optimization included.
     */
    #[Test]
    public function test_empty_baseline_leaves_the_report_unchanged(): void
    {
        $this->enterProject([
            'src/c.php' => "<?php\n\npreg_match('/a{1}[a-zA-Z0-9_]+/', \$s);\n",
            'baseline.json' => "{\"version\": 1, \"issues\": []}\n",
        ]);
        $lint = ['lint', 'src', '--format=json', '--jobs=1'];

        [, $without] = $this->runRegex($lint);
        [, $with] = $this->runRegex([...$lint, '--baseline=baseline.json']);

        $results = JsonContract::asArray(JsonContract::decodeDocument($without)['results'] ?? null);
        $this->assertCount(1, $results, $without);
        $result = JsonContract::asArray($results[0]);
        $this->assertNotSame([], $result['issues'] ?? null);
        $this->assertNotSame([], $result['optimizations'] ?? null);
        $this->assertSame($results, JsonContract::decodeDocument($with)['results'] ?? null);
    }

    /**
     * --generate-baseline replaces a baseline file that already exists.
     */
    #[Test]
    public function test_generate_baseline_overwrites_an_existing_file(): void
    {
        $this->enterProject(self::FILES + ['baseline.json' => "stale\n"]);

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertCount(4, JsonContract::asArray(JsonContract::decodeDocument((string) file_get_contents('baseline.json'))['issues'] ?? null));
    }

    /**
     * A baseline file that holds no baseline is a usage error, as an
     * unreadable one is: a corrupt baseline must not let every issue through
     * or hide them silently. Reading it raises no PHP warning.
     */
    #[Test]
    #[DataProvider('provideBaselinesThatAreNoList')]
    public function test_baseline_that_is_no_list_is_a_usage_error(string $content, string $error): void
    {
        // The broken-document refusal is the one the PCRE2 10.43 and later
        // CI engines report; the 10.42 jobs read the row differently, so
        // it is replayed where 10.43 runs.
        if ('[{"file": ' === $content) {
            $this->requiresPcre2('10.43', $content);
        }

        $this->enterProject(self::FILES + ['baseline.json' => $content]);

        [$exitCode, $stdout, $warnings] = $this->runRegexCollectingWarnings([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame([], $warnings);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage'], array_keys($document));
        $this->assertSame($error, $document['error']);
        $this->assertSame('usage', $document['stage']);
    }

    /**
     * @return iterable<string, array{content: string, error: string}>
     */
    public static function provideBaselinesThatAreNoList(): iterable
    {
        $noBaseline = 'The file baseline.json is not a lint baseline: expected {"version": 1, "issues": [...]}, as written by --generate-baseline.';

        yield 'a JSON string' => ['content' => '"baseline"', 'error' => $noBaseline];
        yield 'a number' => ['content' => '42', 'error' => $noBaseline];
        yield 'null' => ['content' => 'null', 'error' => $noBaseline];
        // The running PHP words the syntax error: PHP 8.6 adds where it is.
        json_decode('[{"file": ');
        yield 'not JSON' => ['content' => '[{"file": ', 'error' => 'The baseline file baseline.json is not valid JSON: '.json_last_error_msg().'.'];
    }

    /**
     * An entry that names no place, a file and a line, makes the baseline
     * unusable, a usage error: a corrupt entry must not let its issue
     * through silently. Reading it raises no PHP warning.
     *
     * @param \Closure(array<mixed>): mixed $malform
     */
    #[Test]
    #[DataProvider('provideMalformedEntries')]
    public function test_baseline_refuses_an_entry_naming_no_place(\Closure $malform): void
    {
        $this->enterProject(self::FILES);
        $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);

        $entries = [];
        $malformed = null;
        foreach (JsonContract::asArray(JsonContract::decodeDocument((string) file_get_contents('baseline.json'))['issues'] ?? null) as $entry) {
            $entry = JsonContract::asArray($entry);
            if ('src/a.php' === ($entry['file'] ?? null) && 3 === ($entry['line'] ?? null)) {
                $malformed = $malform($entry);

                continue;
            }
            $entries[] = $entry;
        }
        $this->assertNotNull($malformed);
        // First, so that the entries after it cannot stand in for it.
        file_put_contents('baseline.json', json_encode(['version' => 1, 'issues' => [$malformed, ...$entries]], \JSON_PRETTY_PRINT));

        [$exitCode, $stdout, $warnings] = $this->runRegexCollectingWarnings([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame([], $warnings);
        $this->assertSame(
            ['error' => 'The file baseline.json is not a lint baseline: expected {"version": 1, "issues": [...]}, as written by --generate-baseline.', 'stage' => 'usage'],
            JsonContract::decodeDocument($stdout),
        );
    }

    /**
     * @return iterable<string, array{malform: \Closure(array<mixed>): mixed}>
     */
    public static function provideMalformedEntries(): iterable
    {
        yield 'an entry that is no object' => ['malform' => static fn (array $entry): string => 'src/a.php:3'];
        yield 'a line given as a string' => ['malform' => static fn (array $entry): array => ['line' => '3'] + $entry];
        yield 'a file given as a number' => ['malform' => static fn (array $entry): array => ['file' => 7] + $entry];
        yield 'an entry without a line' => ['malform' => static function (array $entry): array {
            unset($entry['line']);

            return $entry;
        }];
        yield 'an entry without a file' => ['malform' => static function (array $entry): array {
            unset($entry['file']);

            return $entry;
        }];
    }

    /**
     * Filtering by a baseline leaves every result whole: its column and
     * file_offset stay.
     */
    #[Test]
    public function test_baseline_run_keeps_column_and_file_offset(): void
    {
        $this->enterProject(self::FILES + ['baseline.json' => "{\"version\": 1, \"issues\": []}\n"]);

        [, $without] = $this->runRegex(self::LINT);
        [, $with] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        // A missing key reads "missing", which is no int.
        $positions = static function (array $document): array {
            $positions = [];
            foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
                $result = JsonContract::asArray($result);
                $positions[] = ['column' => $result['column'] ?? 'missing', 'file_offset' => $result['file_offset'] ?? 'missing'];
            }

            return $positions;
        };
        $withoutBaseline = $positions(JsonContract::decodeDocument($without));
        $withBaseline = $positions(JsonContract::decodeDocument($with));

        $this->assertCount(4, $withoutBaseline);
        foreach ($withoutBaseline as $position) {
            $this->assertIsInt($position['column']);
            $this->assertIsInt($position['file_offset']);
        }
        $this->assertSame($withoutBaseline, $withBaseline);
    }

    /**
     * The baseline names a file as the report does, with forward slashes
     * whatever the platform, so that one baseline serves Windows and Unix:
     * a backslash in a file name is read as a separator there, and the
     * baseline written by one run filters the next.
     */
    #[Test]
    public function test_baseline_file_is_the_file_of_the_report(): void
    {
        $this->enterProject(['src/a\\b.php' => "<?php\n\npreg_match('/(a/', \$s);\n"]);

        [, $stdout] = $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);

        $reported = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            $reported[] = JsonContract::asArray($result)['file'] ?? null;
        }
        $baselined = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument((string) file_get_contents('baseline.json'))['issues'] ?? null) as $entry) {
            $baselined[] = JsonContract::asArray($entry)['file'] ?? null;
        }

        $this->assertCount(1, $reported, $stdout);
        // A separator on Windows only: elsewhere a backslash is part of the name.
        $this->assertSame([Path::onWindows() ? 'src/a/b.php' : 'src/a\\b.php'], $reported);
        $this->assertSame(array_map(Path::normalize(...), $reported), $baselined);

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(0, $exitCode, $stdout);
        // The one issue baselined, its result, left with no issue, no
        // optimization and no problem, leaves the report.
        $this->assertSame([], JsonContract::decodeDocument($stdout)['results'] ?? null, $stdout);
    }

    /**
     * A run that finds no pattern still writes the files asked for: the
     * report to --output, an empty baseline to --generate-baseline.
     */
    #[Test]
    public function test_empty_run_still_writes_the_output_and_the_baseline(): void
    {
        $this->enterProject(['empty/README.md' => "nothing to lint\n"]);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'empty', '--json', '--jobs=1', '--output=e.json', '--generate-baseline=eb.json']);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertFileExists('e.json');
        $this->assertSame($stdout, file_get_contents('e.json'));
        $this->assertFileExists('eb.json');
        $this->assertSame(['version' => 1, 'issues' => []], JsonContract::decodeDocument((string) file_get_contents('eb.json')));
    }

    /**
     * A baseline the up-front check found writable that then cannot be
     * written (a full disk, a directory gone): the run stops, exit code 2,
     * the error on stderr and as the envelope, never "Baseline generated".
     *
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideUnwritableBaselineRuns')]
    public function test_baseline_that_cannot_be_written_stops_the_run(array $files, string $format): void
    {
        $this->enterProject($files);
        $path = BaselineStream::register().'baseline.json';
        BaselineStream::$files[$path] = '';

        try {
            [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', '.', '--format='.$format, '--jobs=1', '--no-optimize', '--generate-baseline='.$path]);
        } finally {
            BaselineStream::unregister();
        }

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('Could not write the baseline file: '.$path, $stderr);
        $this->assertStringNotContainsString('Baseline generated', $stdout.$stderr);
        if ('json' === $format) {
            $this->assertSame(
                ['error' => 'Could not write the baseline file: '.$path, 'stage' => 'usage'],
                JsonContract::decodeDocument($stdout),
            );
        } else {
            $this->assertSame('', $stdout);
        }
    }

    /**
     * @return iterable<string, array{files: array<string, string>, format: string}>
     */
    public static function provideUnwritableBaselineRuns(): iterable
    {
        yield 'JSON, patterns found' => ['files' => self::FILES, 'format' => 'json'];
        yield 'JSON, no pattern found' => ['files' => ['empty/README.md' => "nothing to lint\n"], 'format' => 'json'];
        yield 'checkstyle, patterns found' => ['files' => self::FILES, 'format' => 'checkstyle'];
    }

    /**
     * The baseline is read once, by the check that runs before the
     * analysis, and the entries it decoded are the ones the report is
     * filtered with.
     */
    #[Test]
    public function test_baseline_file_is_read_once(): void
    {
        $this->enterProject(self::FILES);
        $this->runRegex([...self::LINT, '--generate-baseline=baseline.json']);
        $path = BaselineStream::register().'baseline.json';
        BaselineStream::$files[$path] = (string) file_get_contents('baseline.json');

        try {
            [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline='.$path]);
        } finally {
            BaselineStream::unregister();
        }

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame(1, BaselineStream::$reads);
        $this->assertSame([], $this->reportedIssues($stdout));
    }

    /**
     * The entries of a file gone since the baseline, under a path the run
     * lints, are stale: reported, as an entry no issue takes. Those of a
     * file outside the paths linted, or under a path excluded, stay
     * silent.
     */
    #[Test]
    public function test_an_entry_of_a_file_gone_under_a_linted_path_is_reported(): void
    {
        $this->enterProject(self::FILES + ['lib/c.php' => "<?php\n\npreg_match('/(c/', \$s);\n", 'src/vendor/d.php' => "<?php\n\npreg_match('/(d/', \$s);\n"]);
        $this->runRegex(['lint', 'src', 'lib', '--format=json', '--jobs=1', '--no-optimize', '--exclude=nothing', '--generate-baseline=baseline.json']);
        unlink('src/a.php');
        unlink('lib/c.php');
        unlink('src/vendor/d.php');

        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame([
            ['src/a.php', 3, 'regex.ignore.unmatched', 'error', 'The baseline entry for regex.group.unclosed matches no issue any more: remove it, or generate the baseline again.'],
            ['src/a.php', 4, 'regex.ignore.unmatched', 'error', 'The baseline entry for regex.lint.quantifier.useless matches no issue any more: remove it, or generate the baseline again.'],
        ], self::issuesOf($stdout));

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src/b.php', '--format=json', '--jobs=1', '--no-optimize', '--baseline=baseline.json']);
        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame([], self::issuesOf($stdout));

        // No file left to read: the stale entries are reported all the same.
        unlink('src/b.php');
        [$exitCode, $stdout] = $this->runRegex([...self::LINT, '--baseline=baseline.json']);
        $this->assertSame(1, $exitCode, $stdout);
        $this->assertCount(4, self::issuesOf($stdout));
    }

    /**
     * @return list<list<mixed>> the file, line, identifier, severity and message of each issue
     */
    private static function issuesOf(string $stdout): array
    {
        $issues = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $issues[] = [$issue['file'] ?? null, $issue['line'] ?? null, $issue['issue_id'] ?? null, $issue['severity'] ?? null, $issue['message'] ?? null];
            }
        }

        return $issues;
    }

    /**
     * The run, and the PHP warnings and notices it raised.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>}
     */
    private function runRegexCollectingWarnings(array $arguments): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            [$exitCode, $stdout] = $this->runRegex($arguments);
        } finally {
            restore_error_handler();
        }

        return [$exitCode, $stdout, $warnings];
    }

    /**
     * The issues of a report, as [file, line, issue_id], in report order.
     *
     * @return list<array{mixed, mixed, mixed}>
     */
    private function reportedIssues(string $stdout): array
    {
        $issues = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $issues[] = [$issue['file'] ?? null, $issue['line'] ?? null, $issue['issue_id'] ?? null];
            }
        }

        return $issues;
    }
}

/**
 * Files held in memory, under a scheme of their own: each reads as a
 * regular file anyone may write, and none can be opened for writing.
 * Counts the times a file is opened for reading.
 */
final class BaselineStream
{
    private const SCHEME = 'regex-baseline-test';

    /**
     * @var array<string, string>
     */
    public static array $files = [];

    public static int $reads = 0;

    /**
     * @var resource|null
     */
    public $context;

    private string $content = '';

    private int $position = 0;

    /**
     * Registers the scheme and gives the prefix of its paths.
     */
    public static function register(): string
    {
        self::$files = [];
        self::$reads = 0;
        stream_wrapper_register(self::SCHEME, self::class);

        return self::SCHEME.'://';
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::SCHEME);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!\array_key_exists($path, self::$files) || 'r' !== $mode[0]) {
            return false;
        }

        self::$reads++;
        $this->content = self::$files[$path];

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->content, $this->position, $count);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen($this->content);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['mode' => 0o100666, 'size' => \strlen($this->content)];
    }

    /**
     * @return array<string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!\array_key_exists($path, self::$files)) {
            return false;
        }

        return ['mode' => 0o100666, 'size' => \strlen(self::$files[$path])];
    }
}
