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
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\RecordingLintProgress;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The analysis of an occurrence a guard or a marker narrows, under
 * composer.json's "^8.2": judged by the analysis of the versions kept, for
 * the lint, the ReDoS report and the optimizations alike; not judged when
 * none is kept; judged as it is when every one is.
 */
final class AnalysisServiceGuardTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_a_guard_keeping_every_version_judges_the_pattern_as_it_is(): void
    {
        $analysis = $this->analysis();

        $kept = $analysis->lint([self::occurrence('/(?a)\d+/', 'php>=8.0')]);

        $this->assertSame(['regex.group.syntax'], array_map(static fn (LintIssue $issue): ?string => $issue->validation?->errorCode?->value, $kept));
        $this->assertNull($kept[0]->target);
    }

    #[Test]
    public function test_the_redos_report_judges_the_versions_kept(): void
    {
        $analysis = $this->analysis(redos: true);

        $this->assertCount(1, $analysis->analyzeRedos([self::occurrence('/(a+)+$/', 'php>=8.4')], RedosSeverity::Low));
        $this->assertSame([], $analysis->analyzeRedos([self::occurrence('/(a+)+$/', 'php>=9.0')], RedosSeverity::Low));
        $this->assertSame([], $analysis->analyzeRedos([PatternOccurrence::scanned('a.php', [])], RedosSeverity::Low));
        $this->assertCount(1, $analysis->analyzeRedos([PatternOccurrence::scanned('a.php', []), new PatternOccurrence('/(a+)+$/', 'a.php', 3, 'preg_match')], RedosSeverity::Low));
    }

    /**
     * What the lint read of a narrowed pattern is kept apart from what it
     * read of the same pattern judged for the whole range: the
     * optimizations take each.
     */
    #[Test]
    public function test_the_optimizations_take_what_the_lint_read_under_the_guard(): void
    {
        $analysis = $this->analysis();
        $patterns = [self::occurrence('/[0-9]+x/', 'php>=8.4'), new PatternOccurrence('/[0-9]+x/', 'a.php', 3, 'preg_match'), self::occurrence('/[0-9]+y/', 'php>=9.0')];

        [, $analyzed] = $analysis->lintForSuggestions($patterns);
        $suggestions = $analysis->suggestOptimizations($patterns, 1, analyzed: $analyzed);

        $this->assertCount(2, $analyzed->byPattern);
        $this->assertSame([2, 3], array_column($suggestions, 'line'));
    }

    /**
     * Two patterns a guard narrows alike each get the rewrite of their own
     * tree; a pattern refused on the floor and accepted on the versions the
     * guard keeps gets one under the guard only, where the engine running
     * the analysis compiles it to check the rewrite.
     */
    #[Test]
    public function test_each_narrowed_pattern_takes_its_own_reading(): void
    {
        $analysis = $this->analysis();
        $patterns = [
            self::occurrence('/[0-9]+x/', 'php>=8.4'),
            self::occurrence('/[0-9]+y/', 'php>=8.4'),
            self::occurrence('/(?a)[0-9]+z/', 'php>=8.4'),
            new PatternOccurrence('/(?a)[0-9]+z/', 'a.php', 5, 'preg_match'),
        ];

        [, $analyzed] = $analysis->lintForSuggestions($patterns);
        $suggestions = $analysis->suggestOptimizations($patterns, 1, analyzed: $analyzed);

        $expected = false === @preg_match('/(?a)[0-9]+z/', '') ? ['/\d+x/', '/\d+y/'] : ['/\d+x/', '/\d+y/', '/(?a)\d+z/'];
        $this->assertSame($expected, array_map(static fn (array $entry): string => $entry['optimization']->optimized, $suggestions));
        $this->assertSame([], $analysis->suggestOptimizations([PatternOccurrence::unread('a.php', 'Not read.'), PatternOccurrence::parserFallback('a.php', 'Broken.')], 1));
    }

    /**
     * A file read holds no pattern: an analysis given it passes it over,
     * and counts it as done.
     */
    #[Test]
    public function test_a_file_read_is_passed_over(): void
    {
        $progress = new RecordingLintProgress();
        $progress->step(LintProgress::STEP_ANALYSIS, 1);

        $this->assertSame([], $this->analysis()->lint([PatternOccurrence::scanned('a.php', [])], $progress));
        $this->assertSame([], $this->analysis()->suggestOptimizations([PatternOccurrence::scanned('a.php', [])], 1));
        $this->assertSame(1, $progress->advancedIn(LintProgress::STEP_ANALYSIS));
    }

    private function analysis(bool $redos = false): AnalysisService
    {
        $target = ProjectTarget::resolve(null, null, [], $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']), []);
        $parser = RegexParser::create($target->regexOptions() + ['runtime_pcre_validation' => false]);

        return new AnalysisService($parser, redosMode: RedosMode::Theoretical, redosEnabled: $redos, range: $target->rangeParsers($parser), judged: $target->judgedRange($parser));
    }

    private static function occurrence(string $pattern, string $constraint): PatternOccurrence
    {
        $line = ['php>=8.4' => 2, 'php>=9.0' => 4][$constraint] ?? 1;

        return new PatternOccurrence($pattern, 'a.php', $line, 'preg_match', guard: TargetConstraint::fromMarker($constraint));
    }
}
