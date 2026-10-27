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

use PhpParser\ParserFactory;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "@regex-target" narrows the versions a pattern is judged for, in a
 * project whose composer.json requires "^8.2": the floor 8.2 (PCRE2
 * 10.40), then 8.3 (10.42), 8.4 (10.44), 8.4.25, 8.5 and 8.5.10 (10.44).
 *
 * "(?a)" needs PCRE2 10.43: 8.2 and 8.3 refuse it. "\K" in a lookbehind:
 * 8.5 refuses it. "a{,3}" is text before PCRE2 10.43 and a quantifier
 * from it.
 */
final class TargetMarkerLintTest extends TestCase
{
    use PcreRule;
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    /**
     * @return iterable<string, array{strategy: class-string<ExtractorInterface>}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokenizer' => ['strategy' => TokenBasedExtractionStrategy::class];
        yield 'php-parser' => ['strategy' => PhpParserExtractionStrategy::class];
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_marker_judges_the_versions_it_names(string $strategy): void
    {
        $report = $this->lint($strategy, <<<'PHP'
            <?php
            // @regex-target-next-line php>=8.4
            preg_match('/(?a)\d+/', $s);
            preg_match('/(?a)\d+/', $s);
            preg_match('/(?a)\d+/', $s); // @regex-target pcre>=10.43
            preg_match('/(?<=a\Kb)c/', $s); // @regex-target php<8.5
            preg_match('/(?<=a\Kb)c/', $s);
            preg_match('/a{,3}/', $s); // @regex-target php>=8.4
            preg_match('/a{,3}/', $s);
            PHP);

        $this->assertSame([
            [4, 'regex.group.syntax', null],
            [7, 'regex.keep.in_lookaround', ['php' => '8.5', 'pcre' => '10.44']],
            [9, 'regex.lint.compat.meaningChanges', ['php' => '8.4', 'pcre' => self::judgedPcre(80400)]],
        ], self::issues($report));
    }

    /**
     * An issue judged under a narrowed range names the versions judged: the
     * lowest the marker keeps for an issue of the floor, the refusing one
     * for an issue of the range, and the constraint as "guard".
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_issue_under_a_marker_names_the_versions_judged(string $strategy): void
    {
        $report = $this->lint($strategy, <<<'PHP'
            <?php
            preg_match('/[11]/', $s); // @regex-target php>=8.4
            preg_match('/(?<=a\Kb)c/', $s); // @regex-target php>=8.4 pcre>=10.43
            PHP);

        $this->assertSame([
            [2, 'regex.lint.charclass.redundant', ['php' => '8.4', 'pcre' => self::judgedPcre(80400), 'guard' => '>=8.4']],
            [3, 'regex.keep.in_lookaround', ['php' => '8.5', 'pcre' => '10.44', 'guard' => '>=8.4', 'guard_pcre' => '>=10.43']],
        ], self::issues($report, true));
    }

    /**
     * A doc comment before a declaration covers it whole; markers that
     * cover one line all apply.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_marker_on_a_declaration_covers_it_and_markers_intersect(string $strategy): void
    {
        $report = $this->lint($strategy, <<<'PHP'
            <?php

            final class Matcher
            {
                /**
                 * @regex-target php>=8.4
                 */
                public function run(string $s): bool
                {
                    preg_match('/(?<=a\Kb)c/', $s); // @regex-target php<8.5

                    return 1 === preg_match('/(?a)\d+/', $s);
                }
            }
            preg_match('/(?a)\d+/', $s);
            PHP);

        $this->assertSame([[15, 'regex.group.syntax', null]], self::issues($report));
    }

    /**
     * A marker that keeps no version of the range is an error on its line,
     * and the patterns it covers are not judged; a malformed one is an
     * error too, and its patterns are judged for the whole range.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_empty_or_malformed_marker_is_an_error(string $strategy): void
    {
        $report = $this->lint($strategy, <<<'PHP'
            <?php
            preg_match('/(a/', $s); // @regex-target php>=9.0
            preg_match('/(?a)\d+/', $s); // @regex-target php>=eight
            preg_match('/x/', $s); // @regex-target php>=8.4
            preg_match('/x/', $s); // @regex-target php<=eight
            PHP);

        $this->assertSame([
            [2, 'regex.target.empty', null],
            [3, 'regex.group.syntax', null],
            [3, 'regex.target.invalid', null],
            [5, 'regex.target.invalid', null],
        ], self::issues($report));
        $markers = array_values(array_filter(array_merge([], ...array_column($report->results, 'issues')), static fn (LintIssue $issue): bool => str_starts_with($issue->issueId ?? '', 'regex.target.')));
        $this->assertSame([[2, 28], [3, 33], [5, 27]], array_map(static fn (LintIssue $issue): array => [$issue->line, $issue->column], $markers));
        $this->assertSame('Widen the marker to versions of the range, or remove the code it covers if it never runs there.', $markers[0]->hint);
        $this->assertSame('Write the versions the code runs on as a Composer constraint after "php", "pcre", or both.', $markers[1]->hint);
        $messages = array_column(array_merge([], ...array_column($report->results, 'issues')), 'message');
        $this->assertContains('The @regex-target marker "php>=9.0" keeps no version the project is judged for (PHP >=8.2 <9.0): the patterns it covers are not judged.', $messages);
        $this->assertContains('The @regex-target marker holds "php>=eight", which names no PHP or PCRE2 version constraint: write "@regex-target php>=8.4", "@regex-target pcre>=10.43" or "@regex-target php>=8.4 pcre>=10.43".', $messages);
        $this->assertSame(4, $report->stats['errors']);
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    private function lint(string $strategy, string $code): LintReport
    {
        if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
            $strategy = TokenBasedExtractionStrategy::class;
        }

        $project = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}', 'src/a.php' => $code]);
        $target = ProjectTarget::resolve(null, null, [], $project, []);
        $parser = RegexParser::create($target->regexOptions() + ['runtime_pcre_validation' => false]);
        $service = new LintService(
            new AnalysisService($parser, range: $target->rangeParsers($parser), judged: $target->judgedRange($parser)),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new $strategy()))]),
        );
        $request = new LintRequest([$project.'/src'], [], 1, checkOptimizations: false, analysisWorkers: 1);

        return $service->analyze($service->collectPatterns($request), $request);
    }

    /**
     * The line, identifier and target of each issue, sorted; the target
     * without its constraint unless asked.
     *
     * @return list<array{int, string|null, array<string, string>|null}>
     */
    private static function issues(LintReport $report, bool $withGuard = false): array
    {
        $issues = [];
        foreach ($report->results as $result) {
            foreach ($result['issues'] as $issue) {
                $target = $issue->target;
                if (null !== $target && !$withGuard) {
                    unset($target['guard'], $target['guard_pcre']);
                }
                $issues[] = [$issue->line, $issue->id(), $target];
            }
        }
        sort($issues);

        return $issues;
    }
}
