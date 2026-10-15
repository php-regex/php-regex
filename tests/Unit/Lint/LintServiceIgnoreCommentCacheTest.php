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
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The 1.x marker "// @regex-lint-ignore" no longer drops an issue: a
 * pattern is left out by "// @regex-ignore-next-line" on the line above it,
 * which the extraction reads. The analysis no longer reads a PHP file for
 * its comments.
 *
 * The occurrences carry no offset in the file, so the analysis never reads
 * the source for the invisible-character rule: any read counted in the
 * PHPRegex\Linter namespace would be a comment check.
 */
final class LintServiceIgnoreCommentCacheTest extends TestCase
{
    use TemporaryProject;

    private const SOURCE = "<?php\n// @regex-lint-ignore\npreg_match('/(a+)+/', \$s);\npreg_match('/(b+)+/', \$s);\npreg_match('/(c+)+/', \$s);\n";

    protected function setUp(): void
    {
        LintFunctionOverrides::reset();
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_analyze_reports_the_issue_below_a_1x_lint_ignore_comment(): void
    {
        $project = $this->makeProject(['lib/a.php' => self::SOURCE]);
        $file = $project.'/lib/a.php';

        $report = self::service()->analyze(self::occurrences($file), self::request($project));

        $this->assertSame([[$file, 3], [$file, 4], [$file, 5]], self::locations($report->results));
    }

    #[Test]
    public function test_analyze_never_reads_a_file_for_its_comments(): void
    {
        $project = $this->makeProject([
            'lib/a.php' => self::SOURCE,
            'lib/b.php' => "<?php\npreg_match('/(d+)+/', \$s);\n// @regex-lint-ignore\npreg_match('/(e+)+/', \$s);\n",
        ]);
        $a = $project.'/lib/a.php';
        $b = $project.'/lib/b.php';
        LintFunctionOverrides::$countFileGetContents = true;

        $report = self::service()->analyze([
            ...self::occurrences($a),
            new PatternOccurrence('/(d+)+/', $b, 2, 'preg_match'),
            new PatternOccurrence('/(e+)+/', $b, 4, 'preg_match'),
        ], self::request($project));

        $this->assertSame([[$a, 3], [$a, 4], [$a, 5], [$b, 2], [$b, 4]], self::locations($report->results));
        $this->assertSame([], LintFunctionOverrides::$fileGetContentsCalls);
    }

    private static function service(): LintService
    {
        return new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));
    }

    private static function request(string $project): LintRequest
    {
        return new LintRequest([$project], [], 0, checkOptimizations: false, analysisWorkers: 1);
    }

    /**
     * @return list<PatternOccurrence>
     */
    private static function occurrences(string $file): array
    {
        return [
            new PatternOccurrence('/(a+)+/', $file, 3, 'preg_match'),
            new PatternOccurrence('/(b+)+/', $file, 4, 'preg_match'),
            new PatternOccurrence('/(c+)+/', $file, 5, 'preg_match'),
        ];
    }

    /**
     * @param array<array{file: string, line: int, ...}> $results
     *
     * @return list<array{string, int}>
     */
    private static function locations(array $results): array
    {
        return array_values(array_map(static fn (array $result): array => [$result['file'], $result['line']], $results));
    }
}
