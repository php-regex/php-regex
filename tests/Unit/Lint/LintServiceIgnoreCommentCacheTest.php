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
 * An issue is dropped when the line above its pattern holds
 * "// @regex-lint-ignore". The file is read once per run to find those
 * comments, however many issues it holds; a run reads it afresh.
 *
 * The occurrences carry no offset in the file, so the analysis never reads
 * the source for the invisible-character rule: every read counted in the
 * PHPRegex\Linter namespace is the ignore-comment check.
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
    public function test_analyze_reads_a_file_once_for_the_ignore_comments_of_all_its_issues(): void
    {
        $project = $this->makeProject(['lib/a.php' => self::SOURCE]);
        $file = $project.'/lib/a.php';
        LintFunctionOverrides::$countFileGetContents = true;

        $report = self::service()->analyze(self::occurrences($file), self::request($project));

        $this->assertSame([[$file, 4], [$file, 5]], self::locations($report->results));
        $this->assertSame([$file => 1], LintFunctionOverrides::$fileGetContentsCalls);
    }

    /**
     * Two files, each read once; the comment of one drops only its own
     * issue.
     */
    #[Test]
    public function test_analyze_reads_each_file_once_and_keeps_their_comments_apart(): void
    {
        $project = $this->makeProject([
            'lib/a.php' => self::SOURCE,
            'lib/b.php' => "<?php\npreg_match('/(d+)+/', \$s);\npreg_match('/(e+)+/', \$s);\n// @regex-lint-ignore\npreg_match('/(f+)+/', \$s);\n",
        ]);
        $a = $project.'/lib/a.php';
        $b = $project.'/lib/b.php';
        LintFunctionOverrides::$countFileGetContents = true;

        $report = self::service()->analyze([
            ...self::occurrences($a),
            new PatternOccurrence('/(d+)+/', $b, 2, 'preg_match'),
            new PatternOccurrence('/(e+)+/', $b, 3, 'preg_match'),
            new PatternOccurrence('/(f+)+/', $b, 5, 'preg_match'),
        ], self::request($project));

        $this->assertSame([[$a, 4], [$a, 5], [$b, 2], [$b, 3]], self::locations($report->results));
        $this->assertSame([$a => 1, $b => 1], LintFunctionOverrides::$fileGetContentsCalls);
    }

    /**
     * The comments are read per run: a comment removed between two runs no
     * longer drops the issue.
     */
    #[Test]
    public function test_analyze_reads_the_comments_afresh_on_each_run(): void
    {
        $project = $this->makeProject(['lib/a.php' => self::SOURCE]);
        $file = $project.'/lib/a.php';
        $service = self::service();
        LintFunctionOverrides::$countFileGetContents = true;

        $first = $service->analyze(self::occurrences($file), self::request($project));
        file_put_contents($file, str_replace('// @regex-lint-ignore', '// nothing to see', self::SOURCE));
        $second = $service->analyze(self::occurrences($file), self::request($project));

        $this->assertSame([[$file, 4], [$file, 5]], self::locations($first->results));
        $this->assertSame([[$file, 3], [$file, 4], [$file, 5]], self::locations($second->results));
        $this->assertSame([$file => 2], LintFunctionOverrides::$fileGetContentsCalls);
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
