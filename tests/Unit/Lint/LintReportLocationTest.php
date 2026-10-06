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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a lint result says it is: the file as every report shows it, the
 * column only when the source knows it, the identifier of an invalid
 * pattern, and one order whatever order the patterns came in.
 */
final class LintReportLocationTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    #[DataProvider('provideFileNames')]
    public function test_file_is_shown_relative_under_the_working_directory_and_absolute_elsewhere(string $file, string $expected): void
    {
        $directory = $this->enterProject(['src/a.php' => "<?php\n"]);
        $file = str_replace('{cwd}', $directory, $file);
        $expected = str_replace(['{cwd}', '{parent}'], [$directory, \dirname($directory)], $expected);

        $results = self::lint([new PatternOccurrence('/(a/', $file, 1, 'preg_match')])->results;

        $this->assertCount(1, $results);
        $this->assertSame($expected, $results[0]['file']);
        $this->assertSame($expected, $results[0]['issues'][0]['file'] ?? null);
    }

    /**
     * @return iterable<string, array{file: string, expected: string}>
     */
    public static function provideFileNames(): iterable
    {
        yield 'relative' => ['file' => 'src/a.php', 'expected' => 'src/a.php'];
        yield 'with a leading dot' => ['file' => './src/a.php', 'expected' => 'src/a.php'];
        yield 'with a dot and a doubled slash inside' => ['file' => 'src/./x//../a.php', 'expected' => 'src/a.php'];
        yield 'absolute, under the working directory' => ['file' => '{cwd}/src/a.php', 'expected' => 'src/a.php'];
        yield 'absolute, elsewhere' => ['file' => '/elsewhere/./lib/../a.php', 'expected' => '/elsewhere/a.php'];
        yield 'relative, up out of the working directory' => ['file' => '../outside/a.php', 'expected' => '{parent}/outside/a.php'];
        yield 'a drive path' => ['file' => 'C:/project/./a.php', 'expected' => 'C:/project/a.php'];
        // ".." stops at a root: the drive's, not the working directory's.
        yield 'a drive path climbing past its root' => ['file' => 'C:/../a.php', 'expected' => 'C:/a.php'];
        yield 'a lower-case drive' => ['file' => 'c:/../a.php', 'expected' => 'c:/a.php'];
        yield 'a digit before the colon, no drive' => ['file' => '1:/../a.php', 'expected' => 'a.php'];
        // A separator on Windows only: elsewhere a backslash is part of the name.
        yield 'a backslash' => ['file' => 'src/a\\b.php', 'expected' => '\\' === \DIRECTORY_SEPARATOR ? 'src/a/b.php' : 'src/a\\b.php'];
        yield 'a stream URL' => ['file' => 'file:///elsewhere/./a.php', 'expected' => 'file:///elsewhere/./a.php'];
        yield 'not a path' => ['file' => 'Symfony routes', 'expected' => 'Symfony routes'];
        yield 'no file' => ['file' => '', 'expected' => ''];
    }

    #[Test]
    public function test_file_reached_through_a_link_is_shown_relative(): void
    {
        $directory = $this->enterProject(['src/a.php' => "<?php\n"]);
        $link = $this->makeProject().'/link';
        $this->assertTrue(symlink($directory, $link));

        $results = self::lint([new PatternOccurrence('/(a/', $link.'/src/a.php', 1, 'preg_match')])->results;

        $this->assertSame('src/a.php', $results[0]['file'] ?? null);
    }

    /**
     * From the root of the filesystem, every absolute file lies under the
     * working directory.
     */
    #[Test]
    public function test_file_is_shown_relative_to_the_root_as_working_directory(): void
    {
        $this->enterProject();
        $workingDirectory = (string) getcwd();
        chdir('/');

        try {
            $results = self::lint([new PatternOccurrence('/(a/', '/elsewhere/a.php', 1, 'preg_match')])->results;
        } finally {
            chdir($workingDirectory);
        }

        $this->assertSame('elsewhere/a.php', $results[0]['file'] ?? null);
    }

    /**
     * An optimization names its file as the issues do: one result for the
     * pattern, whichever way the path was given.
     */
    #[Test]
    public function test_optimization_file_is_shown_as_the_issue_file(): void
    {
        $this->enterProject(['src/a.php' => "<?php\n"]);
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));

        $results = $service->analyze(
            [new PatternOccurrence('/x{1}[a-zA-Z0-9_]+/', './src/a.php', 1, 'preg_match')],
            new LintRequest([], [], 1),
        )->results;

        $this->assertCount(1, $results);
        $this->assertSame('src/a.php', $results[0]['file']);
        $this->assertNotSame([], $results[0]['issues']);
        $this->assertCount(1, $results[0]['optimizations']);
        $this->assertSame('src/a.php', $results[0]['optimizations'][0]['file'] ?? null);
    }

    /**
     * Inside a result the issues come by position, then by identifier,
     * whatever order the rules found them in.
     *
     * @param list<array{int|null, string|null}> $expected
     */
    #[Test]
    #[DataProvider('provideIssueOrders')]
    public function test_issues_come_out_by_position_then_identifier(string $pattern, array $expected): void
    {
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));

        $results = $service->analyze(
            [new PatternOccurrence($pattern, 'a.php', 1, 'preg_match')],
            new LintRequest([], [], 1, checkOptimizations: false),
        )->results;

        $this->assertCount(1, $results);
        $this->assertSame($expected, array_map(static fn (array $issue): array => [$issue['position'] ?? null, $issue['issueId'] ?? null], array_values($results[0]['issues'])));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<array{int|null, string|null}>}>
     */
    public static function provideIssueOrders(): iterable
    {
        // The duplicate branch is found at 6 before the group at 0.
        yield 'by position' => [
            'pattern' => '/(?:a)|(?:a)/',
            'expected' => [[0, 'regex.lint.group.redundant'], [6, 'regex.lint.alternation.duplicateDisjunction']],
        ];
        yield 'by identifier on one position' => [
            'pattern' => '/(b+)+$/',
            'expected' => [[0, 'regex.lint.group.quantifiedCapture'], [0, 'regex.lint.quantifier.nested']],
        ];
    }

    #[Test]
    public function test_invalid_pattern_is_known_by_its_error_code_and_has_no_invented_column(): void
    {
        $results = self::lint([new PatternOccurrence('/(a/', 'a.php', 3, 'preg_match')])->results;

        $this->assertNull($results[0]['column'] ?? null);
        $this->assertSame('regex.group.unclosed', $results[0]['issues'][0]['issueId'] ?? null);
        $this->assertNull($results[0]['issues'][0]['column'] ?? null);
    }

    #[Test]
    public function test_results_and_their_issues_come_out_in_one_order(): void
    {
        $occurrences = [
            new PatternOccurrence('/(b+)+$/', 'b.php', 1, 'preg_match', column: 1, fileOffset: 6),
            new PatternOccurrence('/x{1}/', 'a.php', 2, 'preg_match', column: 30, fileOffset: 40),
            new PatternOccurrence('/(a/', 'a.php', 2, 'preg_match', column: 12, fileOffset: 22),
            new PatternOccurrence('/(c/', 'a.php', 2, 'preg_match'),
            new PatternOccurrence('/(d/', 'B.php', 9, 'preg_match', column: 1, fileOffset: 3),
        ];

        $forward = self::lint($occurrences)->results;
        $backward = self::lint(array_reverse($occurrences))->results;

        $locations = array_map(static fn (array $result): array => [$result['file'], $result['line'], $result['column'] ?? null], $forward);
        $this->assertSame([['B.php', 9, 1], ['a.php', 2, null], ['a.php', 2, 12], ['a.php', 2, 30], ['b.php', 1, 1]], $locations);
        // The same results, built afresh: equal, not the same objects.
        $this->assertEquals($forward, $backward);

        // (b+)+$: a nested-quantifier warning and a quantified-capture info,
        // both at position 0, ordered by identifier.
        $issues = array_map(static fn (array $issue): array => [$issue['position'] ?? null, $issue['issueId'] ?? null], $forward[4]['issues']);
        $sorted = $issues;
        sort($sorted);
        $this->assertGreaterThan(1, \count($issues));
        $this->assertSame($sorted, $issues);
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     */
    private static function lint(array $occurrences): LintReport
    {
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));

        return $service->analyze($occurrences, new LintRequest([], [], 1, checkOptimizations: false));
    }
}
