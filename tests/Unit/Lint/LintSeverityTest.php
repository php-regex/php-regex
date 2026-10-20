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

use PHPRegex\Linter\LintSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one mapping from the six severities to the three levels every report
 * shows: Critical and Error are errors, and fail the run; Warning warns;
 * Style, Perf and Info inform.
 */
final class LintSeverityTest extends TestCase
{
    /**
     * @return iterable<string, array{severity: LintSeverity, level: string, failsRun: bool}>
     */
    public static function provideSeverities(): iterable
    {
        yield 'critical' => ['severity' => LintSeverity::Critical, 'level' => 'error', 'failsRun' => true];
        yield 'error' => ['severity' => LintSeverity::Error, 'level' => 'error', 'failsRun' => true];
        yield 'warning' => ['severity' => LintSeverity::Warning, 'level' => 'warning', 'failsRun' => false];
        yield 'style' => ['severity' => LintSeverity::Style, 'level' => 'info', 'failsRun' => false];
        yield 'perf' => ['severity' => LintSeverity::Perf, 'level' => 'info', 'failsRun' => false];
        yield 'info' => ['severity' => LintSeverity::Info, 'level' => 'info', 'failsRun' => false];
    }

    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_level_folds_each_severity_into_one_of_three(LintSeverity $severity, string $level, bool $failsRun): void
    {
        $this->assertSame($level, $severity->level());
    }

    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_fails_run_holds_for_the_error_levels_only(LintSeverity $severity, string $level, bool $failsRun): void
    {
        $this->assertSame($failsRun, $severity->failsRun());
    }

    #[Test]
    public function test_every_severity_is_listed(): void
    {
        $listed = [];
        foreach (self::provideSeverities() as $row) {
            $listed[] = $row['severity'];
        }

        $this->assertSame(LintSeverity::cases(), $listed);
    }
}
