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
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern the library stops on at one of its own limits is reported as not
 * analyzed: a warning, never an error that calls a pattern PCRE compiles
 * invalid.
 */
final class LintLimitOutcomeTest extends TestCase
{
    #[Test]
    public function test_a_pattern_past_a_limit_is_a_warning_not_an_invalid_pattern(): void
    {
        $this->assertSame(1, preg_match('/((((a))))/', 'a'), 'control: PCRE compiles the pattern');

        $progress = new class implements LintProgress {
            /**
             * @var array<string, int> the patterns counted done, per step
             */
            public array $done = [];

            private string $step = '';

            public function step(string $step, ?int $total): void
            {
                $this->step = $step;
            }

            public function advance(int $count = 1): void
            {
                $this->done[$this->step] = ($this->done[$this->step] ?? 0) + $count;
            }

            public function tick(): void {}

            public function workers(int $count): void {}

            public function stalled(string $file, int $seconds): void {}

            public function finish(): void {}
        };

        $service = new LintService(new AnalysisService(RegexParser::create(['max_recursion_depth' => 3])), new PatternSourceCollection([]));
        $report = $service->analyze(
            [new PatternOccurrence('/((((a))))/', 'test.php', 1, 'preg_match')],
            new LintRequest(['.'], [], 0),
            $progress,
        );

        $this->assertSame(1, $progress->done[LintProgress::STEP_ANALYSIS] ?? 0, 'The pattern not analyzed still counts as done.');
        $this->assertSame(0, $report->stats['errors']);
        $this->assertSame(1, $report->stats['warnings']);

        $problems = $report->results[0]['problems'] ?? [];
        $this->assertCount(1, $problems);
        $this->assertSame(DiagnosticType::Limit, $problems[0]->type);
        $this->assertSame(LintSeverity::Warning, $problems[0]->severity);
        $this->assertStringStartsWith('Not analyzed: Recursion limit of 3 exceeded', $problems[0]->message);
        $this->assertSame('regex.nesting.too_deep', $problems[0]->code);
        $this->assertStringContainsString('"max_recursion_depth"', (string) $problems[0]->suggestion);
    }

    #[Test]
    public function test_an_invalid_pattern_stays_an_error(): void
    {
        $service = new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));
        $report = $service->analyze(
            [new PatternOccurrence('/(a/', 'test.php', 1, 'preg_match')],
            new LintRequest(['.'], [], 0),
        );

        $this->assertSame(1, $report->stats['errors']);
        $problems = $report->results[0]['problems'] ?? [];
        $this->assertSame(DiagnosticType::Syntax, $problems[0]->type);
        $this->assertSame(LintSeverity::Error, $problems[0]->severity);
    }
}
