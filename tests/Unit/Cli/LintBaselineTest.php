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

use PHPRegex\Cli\CliException;
use PHPRegex\Cli\Command\LintBaseline;
use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The baseline file as read and written on its own: what it refuses to
 * load, what a filtered result keeps, and the paths it holds when the
 * working directory cannot shorten them.
 *
 * @phpstan-import-type LintIssue from LintReport
 * @phpstan-import-type LintResult from LintReport
 */
final class LintBaselineTest extends TestCase
{
    private const WRAPPER = 'lint-baseline-unreadable';

    private string $directory = '';

    private string $previousDirectory = '';

    protected function setUp(): void
    {
        $this->previousDirectory = (string) getcwd();
        $this->directory = sys_get_temp_dir().'/regex-lint-baseline-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        if (\in_array(self::WRAPPER, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::WRAPPER);
        }
        if (is_dir($this->directory)) {
            foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
                unlink($this->directory.'/'.$file);
            }
            rmdir($this->directory);
        }
    }

    /**
     * A file that exists but cannot be read is said so, apart from one
     * that is missing: a stream wrapper stands for it, whoever runs the
     * test.
     */
    #[Test]
    public function test_load_reports_a_baseline_file_it_cannot_read(): void
    {
        $wrapper = new class {
            /**
             * @var resource|null
             */
            public $context;

            /**
             * @return array<string, int>
             */
            public function url_stat(string $path, int $flags): array
            {
                return ['mode' => 0o100644, 'size' => 2];
            }

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                return false;
            }
        };
        $this->assertTrue(stream_wrapper_register(self::WRAPPER, $wrapper::class));
        $file = self::WRAPPER.'://baseline.json';
        $this->assertTrue(is_file($file));

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Could not read the baseline file: '.$file);

        LintBaseline::load($file);
    }

    /**
     * A missing file is said missing, apart from one that cannot be read.
     */
    #[Test]
    public function test_load_reports_a_missing_baseline_file(): void
    {
        $file = $this->directory.'/missing.json';

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('Baseline file not found: '.$file);

        LintBaseline::load($file);
    }

    #[Test]
    #[DataProvider('provideFilesThatAreNoBaseline')]
    public function test_load_rejects_a_file_that_is_no_baseline(string $content): void
    {
        $file = $this->directory.'/baseline.json';
        file_put_contents($file, $content);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage(\sprintf('The file %s is not a lint baseline: expected {"version": 1, "issues": [...]}, as written by --generate-baseline.', $file));

        LintBaseline::load($file);
    }

    /**
     * @return iterable<string, array{content: string}>
     */
    public static function provideFilesThatAreNoBaseline(): iterable
    {
        yield '1.x list holding a number' => ['content' => '[1]'];
        yield '1.x entry without a message' => ['content' => '[{"file": "a.php", "line": 3}]'];
        yield '1.x entry without a file' => ['content' => '[{"line": 3, "message": "m"}]'];
        yield '1.x entry whose line is a string' => ['content' => '[{"file": "a.php", "line": "3", "message": "m"}]'];
        yield 'document without a version' => ['content' => '{"issues": []}'];
        yield 'document whose issues are an object' => ['content' => '{"version": 1, "issues": {}}'];
        yield 'entry holding a number' => ['content' => '{"version": 1, "issues": [1]}'];
        yield 'entry without a line' => ['content' => '{"version": 1, "issues": [{"issueId": "r", "file": "a.php", "patternHash": "h"}]}'];
        yield 'entry without an issue identifier' => ['content' => '{"version": 1, "issues": [{"file": "a.php", "patternHash": "h", "line": 3}]}'];
        yield 'entry without a file' => ['content' => '{"version": 1, "issues": [{"issueId": "r", "patternHash": "h", "line": 3}]}'];
        yield 'entry without a pattern hash' => ['content' => '{"version": 1, "issues": [{"issueId": "r", "file": "a.php", "line": 3}]}'];
    }

    #[Test]
    #[DataProvider('provideOtherVersions')]
    public function test_load_rejects_a_baseline_of_another_version(string $version): void
    {
        $file = $this->directory.'/baseline.json';
        file_put_contents($file, '{"version": '.$version.', "issues": []}');

        $this->expectException(CliException::class);
        $this->expectExceptionMessage(\sprintf('The baseline file %s is not of version 1, the one this version reads: regenerate it with --generate-baseline.', $file));

        LintBaseline::load($file);
    }

    /**
     * @return iterable<string, array{version: string}>
     */
    public static function provideOtherVersions(): iterable
    {
        yield 'the version before' => ['version' => '0'];
        yield 'the version after' => ['version' => '2'];
        yield 'the version as a string' => ['version' => '"1"'];
        yield 'no version' => ['version' => 'null'];
    }

    /**
     * Of a result where the baseline knows one issue, the other issue
     * stays, with its problem entry; the problem entry of an optimization
     * stays even when its message is the one of the known issue, and the
     * result keeps its column and file offset.
     */
    #[Test]
    public function test_filter_keeps_what_the_baseline_does_not_know_in_a_result(): void
    {
        $known = self::issue('regex.lint.known', 'Known issue', 'error');
        $unknown = self::issue('regex.lint.unknown', 'Unknown issue', 'warning');
        $optimization = new Diagnostic(DiagnosticType::Optimization, LintSeverity::Info, 'Known issue');
        $knownProblem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, 'Known issue');
        $unknownProblem = new Diagnostic(DiagnosticType::Lint, LintSeverity::Warning, 'Unknown issue');

        $baseline = $this->baselineOf(self::report([self::lintResult([$known], [$knownProblem])]));

        $filtered = $baseline->filter(self::report([
            self::lintResult([$known, $unknown], [$optimization, $knownProblem, $unknownProblem]),
        ]));

        $this->assertSame([self::lintResult([$unknown], [$optimization, $unknownProblem])], $filtered->results);
        $this->assertSame(['errors' => 0, 'warnings' => 1, 'optimizations' => 0], $filtered->stats);
    }

    /**
     * With "/" as the working directory there is no prefix to take off: an
     * absolute path is written, and matched, as it is.
     */
    #[Test]
    public function test_generate_keeps_an_absolute_path_when_the_working_directory_is_the_root(): void
    {
        $this->assertTrue(chdir('/'));

        $report = self::report([self::lintResult([self::issue('regex.lint.known', 'Known issue', 'error', '/srv/app/a.php')], [])]);
        $baseline = $this->baselineOf($report);

        $this->assertSame(['/srv/app/a.php'], self::filesOf(LintBaseline::generate($report)));
        $this->assertSame([], $baseline->filter($report)->results);
    }

    /**
     * With no working directory to read (it was removed), a path is written
     * with its backslashes turned to slashes and nothing taken off.
     */
    #[Test]
    public function test_generate_keeps_the_path_when_the_working_directory_is_gone(): void
    {
        $gone = $this->directory.'/gone';
        mkdir($gone);
        chdir($gone);
        rmdir($gone);
        $this->assertFalse(getcwd());

        $report = self::report([self::lintResult([self::issue('regex.lint.known', 'Known issue', 'error', 'C:\\app\\src\\a.php')], [])]);

        try {
            $generated = LintBaseline::generate($report);
        } finally {
            chdir($this->previousDirectory);
        }

        $this->assertSame(['C:/app/src/a.php'], self::filesOf($generated));
    }

    /**
     * An entry takes out an issue of the same rule, in the same file, on
     * the same pattern bytes, whatever its line; a difference in any of the
     * three keeps the issue, and so does a rule and a file that only run
     * together into the same text.
     *
     * @param array<mixed> $entry
     */
    #[Test]
    #[DataProvider('provideEntries')]
    public function test_filter_takes_out_an_issue_only_for_its_own_entry(array $entry, bool $known): void
    {
        $report = self::report([self::lintResult([self::issue('regex.lint.known', 'Known issue', 'error')], [])]);

        $filtered = $this->baselineFrom(['version' => 1, 'issues' => [$entry]])->filter($report);

        $this->assertSame($known ? [] : $report->results, $filtered->results);
    }

    /**
     * A file name that is no valid UTF-8 is written with its stray bytes
     * spelled "\xHH": a hand-written entry naming it behind a "." segment
     * still matches, its spelling read back before the path is resolved.
     */
    #[Test]
    public function test_filter_matches_a_hand_written_entry_for_a_file_name_that_is_no_utf8(): void
    {
        $issue = self::issue('regex.lint.known', 'Known issue', 'error', "src/\xFF.php");
        $report = self::report([self::lintResult([$issue], [])]);
        $entry = ['file' => './src/\xFF.php'] + self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 3);

        $filtered = $this->baselineFrom(['version' => 1, 'issues' => [$entry]])->filter($report);

        $this->assertSame([], $filtered->results);
    }

    /**
     * @return iterable<string, array{entry: array<mixed>, known: bool}>
     */
    public static function provideEntries(): iterable
    {
        yield 'the same rule, file and pattern' => ['entry' => self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 3), 'known' => true];
        yield 'the same rule, file and pattern on another line' => ['entry' => self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 9), 'known' => true];
        yield 'another rule' => ['entry' => self::entryFor('regex.lint.other', 'src/a.php', '/a+b/', 3), 'known' => false];
        yield 'another file' => ['entry' => self::entryFor('regex.lint.known', 'src/b.php', '/a+b/', 3), 'known' => false];
        yield 'another pattern' => ['entry' => self::entryFor('regex.lint.known', 'src/a.php', '/a+c/', 3), 'known' => false];
        yield 'a rule and a file that run together into the same text' => ['entry' => self::entryFor('regex.lint.knownsrc', '/a.php', '/a+b/', 3), 'known' => false];
        // A hand-written entry names the file as it likes: its "." and ".."
        // segments are resolved as those of the issue are.
        yield 'the same file behind a leading dot segment' => ['entry' => ['file' => './src/a.php'] + self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 3), 'known' => true];
        yield 'the same file behind a parent segment' => ['entry' => ['file' => 'src/../src/a.php'] + self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 3), 'known' => true];
        yield 'the same file behind a doubled separator' => ['entry' => ['file' => 'src//a.php'] + self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 3), 'known' => true];
        yield 'another file behind a leading dot segment' => ['entry' => ['file' => './src/b.php'] + self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 3), 'known' => false];
    }

    /**
     * An issue still on the line of an entry takes that entry first: a new
     * issue of the same rule, file and pattern above it is reported.
     */
    #[Test]
    public function test_filter_gives_an_entry_to_the_issue_on_its_line(): void
    {
        $baseline = $this->baselineFrom(['version' => 1, 'issues' => [self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 20)]]);

        $filtered = $baseline->filter(self::report([self::lintResult([self::issueOnLine(10), self::issueOnLine(20)], [])]));

        $this->assertSame([10], array_column($filtered->results[0]['issues'] ?? [], 'line'));
    }

    /**
     * One entry takes out one issue: of two issues off its line, the first
     * is known and the second reported.
     */
    #[Test]
    public function test_filter_gives_an_entry_to_one_issue_only(): void
    {
        $baseline = $this->baselineFrom(['version' => 1, 'issues' => [self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 5)]]);

        $filtered = $baseline->filter(self::report([self::lintResult([self::issueOnLine(10), self::issueOnLine(20)], [])]));

        $this->assertSame([20], array_column($filtered->results[0]['issues'] ?? [], 'line'));
    }

    /**
     * More entries than issues: the one issue off their lines takes one
     * entry, the entries left take nothing, and nothing is reported.
     */
    #[Test]
    public function test_filter_leaves_the_entries_no_issue_is_left_for(): void
    {
        $baseline = $this->baselineFrom(['version' => 1, 'issues' => [
            self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 5),
            self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 30),
            self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', 40),
        ]]);

        $filtered = $baseline->filter(self::report([self::lintResult([self::issueOnLine(10)], [])]));

        $this->assertSame([], $filtered->results);
    }

    /**
     * The entries of one rule, file and pattern are aligned with its
     * issues in line order, as a diff aligns two versions of a file: the
     * issues no entry is aligned with are the inserted copies, and they are
     * the ones reported; an entry no issue is aligned with is a deleted
     * copy, and reports nothing. An issue still on the line of an entry
     * does not take that entry when lines inserted above have moved the
     * copies below it.
     *
     * @param list<int> $entries the lines of the entries
     * @param list<int> $issues  the lines of the issues, in report order
     * @param list<int> $left    the lines of the issues reported
     */
    #[Test]
    #[DataProvider('provideAlignments')]
    public function test_filter_aligns_the_entries_with_the_issues_in_line_order(array $entries, array $issues, array $left, bool $oneResultPerIssue): void
    {
        $baseline = $this->baselineFrom(['version' => 1, 'issues' => array_map(
            static fn (int $line): array => self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', $line),
            $entries,
        )]);
        $found = array_map(self::issueOnLine(...), $issues);
        $results = $oneResultPerIssue
            ? array_map(static fn (array $issue): array => self::lintResult([$issue], []), $found)
            : [self::lintResult($found, [])];

        $filtered = $baseline->filter(self::report($results));

        $reported = [];
        foreach ($filtered->results as $result) {
            $reported = [...$reported, ...array_column($result['issues'], 'line')];
        }
        $this->assertSame($left, $reported);
    }

    /**
     * Past the work an alignment may take, the entries take the last copies
     * in line order and the first ones are reported: as many as the copies
     * outnumber the entries, whatever the lines.
     */
    #[Test]
    public function test_filter_reports_the_first_copies_when_the_alignment_is_too_large(): void
    {
        $baseline = $this->baselineFrom(['version' => 1, 'issues' => array_map(
            static fn (int $line): array => self::entryFor('regex.lint.known', 'src/a.php', '/a+b/', $line),
            range(1, 1000),
        )]);
        $found = array_map(self::issueOnLine(...), range(1, 2000));

        $filtered = $baseline->filter(self::report([self::lintResult($found, [])]));

        $reported = [];
        foreach ($filtered->results as $result) {
            $reported = [...$reported, ...array_column($result['issues'], 'line')];
        }
        $this->assertSame(range(1, 1000), $reported);
    }

    /**
     * @return iterable<string, array{entries: list<int>, issues: list<int>, left: list<int>, oneResultPerIssue: bool}>
     */
    public static function provideAlignments(): iterable
    {
        $cases = [
            // Ten lines holding a copy inserted above line 10 move the three
            // baselined copies to 20, 30 and 40: the copy on line 5 is new,
            // though two issues sit on the lines of two entries.
            'a block inserted above three copies' => ['entries' => [10, 20, 30], 'issues' => [5, 20, 30, 40], 'left' => [5]],
            'a block inserted above two copies' => ['entries' => [10, 20], 'issues' => [3, 20, 30], 'left' => [3]],
            'a block inserted above two copies five lines apart' => ['entries' => [10, 15], 'issues' => [2, 15, 20], 'left' => [2]],
            // One copy inserted on line 5 moves the copies below it down one
            // line, a second on line 25 moves the last one down one more.
            'two copies inserted between three' => ['entries' => [10, 20, 30], 'issues' => [5, 11, 21, 25, 32], 'left' => [5, 25]],
            // The alignment follows the lines, not the order the issues are
            // reported in.
            'a block inserted above three copies, issues reported bottom up' => ['entries' => [10, 20, 30], 'issues' => [40, 30, 20, 5], 'left' => [5]],
        ];

        foreach ($cases as $name => $case) {
            yield $name.', one result' => $case + ['oneResultPerIssue' => false];
            yield $name.', one result per issue' => $case + ['oneResultPerIssue' => true];
        }
    }

    /**
     * A ".." segment above the root of an absolute path is dropped, as the
     * file system reads it; one above the start of a relative path is kept.
     */
    #[Test]
    #[DataProvider('provideParentSegmentsAboveTheStart')]
    public function test_generate_resolves_a_parent_segment_above_the_start_of_a_path(string $file, string $written): void
    {
        chdir($this->directory);

        $this->assertSame([$written], self::filesOf(LintBaseline::generate(self::report([self::lintResult([self::issue('regex.lint.known', 'Known issue', 'error', $file)], [])]))));
    }

    /**
     * @return iterable<string, array{file: string, written: string}>
     */
    public static function provideParentSegmentsAboveTheStart(): iterable
    {
        yield 'above the root' => ['file' => '/../srv/app/a.php', 'written' => '/srv/app/a.php'];
        yield 'twice above the root' => ['file' => '/../../srv/app/a.php', 'written' => '/srv/app/a.php'];
        yield 'above the root of a drive' => ['file' => 'C:\\..\\app\\a.php', 'written' => 'C:/app/a.php'];
        yield 'above the start of a relative path' => ['file' => '../app/a.php', 'written' => '../app/a.php'];
    }

    /**
     * A 1.x entry is matched on its file, its line and its message, each
     * kept apart: values that only run together into the same text are
     * another issue.
     *
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('provideLegacyEntries')]
    public function test_filter_matches_a_legacy_entry_on_file_line_and_message(array $entry, bool $known): void
    {
        $issue = ['type' => 'error', 'message' => 'm3', 'file' => 'src/v1', 'line' => 23, 'issueId' => 'regex.lint.known'];
        $report = self::report([self::lintResult([$issue], [])]);

        $filtered = $this->baselineFrom([$entry])->filter($report);

        $this->assertSame($known ? [] : $report->results, $filtered->results);
    }

    /**
     * @return iterable<string, array{entry: array<string, mixed>, known: bool}>
     */
    public static function provideLegacyEntries(): iterable
    {
        yield 'the same file, line and message' => ['entry' => ['file' => 'src/v1', 'line' => 23, 'message' => 'm3'], 'known' => true];
        yield 'another file' => ['entry' => ['file' => 'src/v2', 'line' => 23, 'message' => 'm3'], 'known' => false];
        yield 'another message' => ['entry' => ['file' => 'src/v1', 'line' => 23, 'message' => 'm4'], 'known' => false];
        yield 'a file and a line that run together' => ['entry' => ['file' => 'src/v12', 'line' => 3, 'message' => 'm3'], 'known' => false];
        yield 'a line and a message that run together' => ['entry' => ['file' => 'src/v1', 'line' => 2, 'message' => '3m3'], 'known' => false];
    }

    /**
     * A string that is no valid UTF-8 is written with each byte that is no
     * part of a UTF-8 character spelled "\xHH", and every valid character
     * of any width kept: a lead byte at either end of its range, a
     * character cut short, an overlong form and a surrogate are told apart.
     */
    #[Test]
    #[DataProvider('provideTextsThatAreNoUtf8')]
    public function test_generate_spells_each_byte_that_is_no_part_of_a_utf8_character(string $text, string $spelled): void
    {
        $data = json_decode(LintBaseline::generate(self::report([self::lintResult([self::issue('regex.lint.known', $text, 'error')], [])])), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertIsArray($data['issues'] ?? null);

        $this->assertSame([$spelled], array_column($data['issues'], 'message'));
    }

    /**
     * @return iterable<string, array{text: string, spelled: string}>
     */
    public static function provideTextsThatAreNoUtf8(): iterable
    {
        yield 'a two-byte character, then a stray byte' => ['text' => "é\xFF", 'spelled' => 'é\xFF'];
        yield 'a stray byte, then a two-byte character' => ['text' => "\xFFé", 'spelled' => '\xFFé'];
        yield 'the lowest two-byte lead' => ['text' => "\u{A0}\xFF", 'spelled' => "\u{A0}\\xFF"];
        yield 'the highest two-byte lead' => ['text' => "\u{7FF}\xFF", 'spelled' => "\u{7FF}\\xFF"];
        yield 'a three-byte character, then a stray byte' => ['text' => "€\xFF", 'spelled' => '€\xFF'];
        yield 'a stray byte, then a three-byte character' => ['text' => "\xFF€", 'spelled' => '\xFF€'];
        yield 'the lowest three-byte lead' => ['text' => "\u{800}\xFF", 'spelled' => "\u{800}\\xFF"];
        yield 'the highest three-byte lead' => ['text' => "\u{FFFD}\xFF", 'spelled' => "\u{FFFD}\\xFF"];
        yield 'a four-byte character, then a stray byte' => ['text' => "\u{1F600}\xFF", 'spelled' => "\u{1F600}\\xFF"];
        yield 'a stray byte, then a four-byte character' => ['text' => "\xFF\u{1F600}", 'spelled' => "\\xFF\u{1F600}"];
        yield 'the highest four-byte lead' => ['text' => "\u{100000}\xFF", 'spelled' => "\u{100000}\\xFF"];
        yield 'a three-byte character cut short' => ['text' => "a\xE2\x82", 'spelled' => 'a\xE2\x82'];
        yield 'an overlong form' => ['text' => "\xC0\x80", 'spelled' => '\xC0\x80'];
        yield 'a surrogate' => ['text' => "\xED\xA0\x80", 'spelled' => '\xED\xA0\x80'];
        yield 'a continuation byte alone' => ['text' => "a\x80b", 'spelled' => 'a\x80b'];
    }

    /**
     * An issue is named by its identifier; the error code of its
     * validation stands in only when it has none.
     */
    #[Test]
    public function test_generate_names_an_issue_by_its_identifier_before_its_error_code(): void
    {
        $validation = new ValidationResult(false, 'Reference to a group that does not exist', errorCode: ErrorCode::BackrefMissingGroup);
        $named = self::issue('regex.lint.known', 'Known issue', 'error') + ['validation' => $validation];
        $unnamed = ['type' => 'error', 'message' => 'Invalid pattern', 'file' => 'src/a.php', 'line' => 3, 'validation' => $validation];

        $data = json_decode(LintBaseline::generate(self::report([self::lintResult([$named, $unnamed], [])])), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertIsArray($data['issues'] ?? null);

        $this->assertSame(['regex.lint.known', ErrorCode::BackrefMissingGroup->value], array_column($data['issues'], 'issueId'));
    }

    private function baselineOf(LintReport $report): LintBaseline
    {
        $file = $this->directory.'/baseline.json';
        file_put_contents($file, LintBaseline::generate($report));

        return LintBaseline::load($file);
    }

    /**
     * @param array<mixed> $document
     */
    private function baselineFrom(array $document): LintBaseline
    {
        $file = $this->directory.'/baseline.json';
        file_put_contents($file, json_encode($document, \JSON_THROW_ON_ERROR));

        return LintBaseline::load($file);
    }

    /**
     * The entry generate() writes for one issue.
     *
     * @return array<mixed>
     */
    private static function entryFor(string $issueId, string $file, string $pattern, int $line): array
    {
        $issue = self::issue($issueId, 'Known issue', 'error', $file);
        $issue['line'] = $line;
        $result = self::lintResult([$issue], []);
        $result['pattern'] = $pattern;

        $data = json_decode(LintBaseline::generate(self::report([$result])), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['issues'] ?? null);
        self::assertIsArray($data['issues'][0] ?? null);

        return $data['issues'][0];
    }

    /**
     * @return array{type: string, message: string, file: string, line: int, issueId: string}
     */
    private static function issueOnLine(int $line): array
    {
        $issue = self::issue('regex.lint.known', 'Known issue', 'error');
        $issue['line'] = $line;

        return $issue;
    }

    /**
     * @return list<mixed>
     */
    private static function filesOf(string $generated): array
    {
        $data = json_decode($generated, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['issues'] ?? null);

        return array_column($data['issues'], 'file');
    }

    /**
     * @param list<LintResult> $results
     */
    private static function report(array $results): LintReport
    {
        return new LintReport($results, LintStatsCounter::count($results));
    }

    /**
     * @return array{type: string, message: string, file: string, line: int, issueId: string}
     */
    private static function issue(string $issueId, string $message, string $type, string $file = 'src/a.php'): array
    {
        return ['type' => $type, 'message' => $message, 'file' => $file, 'line' => 3, 'issueId' => $issueId];
    }

    /**
     * @param list<LintIssue>  $issues
     * @param list<Diagnostic> $problems
     *
     * @return LintResult
     */
    private static function lintResult(array $issues, array $problems): array
    {
        return [
            'file' => $issues[0]['file'] ?? 'src/a.php',
            'line' => 3,
            'column' => 12,
            'fileOffset' => 40,
            'source' => 'preg_match',
            'pattern' => '/a+b/',
            'location' => null,
            'issues' => $issues,
            'optimizations' => [],
            'problems' => $problems,
        ];
    }
}
