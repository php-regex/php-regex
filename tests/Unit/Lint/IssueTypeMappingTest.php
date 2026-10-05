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
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lint pass gives each rule violation the issue type of its severity:
 * Critical and Error fail the run, Warning warns, Style, Perf and Info
 * inform. No built-in rule is Critical or Perf today, so those two rows
 * are read from the mapping itself, as a rule added later would meet it.
 */
final class IssueTypeMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{severity: LintSeverity, type: string}>
     */
    public static function provideSeverities(): iterable
    {
        yield 'critical' => ['severity' => LintSeverity::Critical, 'type' => 'error'];
        yield 'error' => ['severity' => LintSeverity::Error, 'type' => 'error'];
        yield 'warning' => ['severity' => LintSeverity::Warning, 'type' => 'warning'];
        yield 'style' => ['severity' => LintSeverity::Style, 'type' => 'info'];
        yield 'perf' => ['severity' => LintSeverity::Perf, 'type' => 'info'];
        yield 'info' => ['severity' => LintSeverity::Info, 'type' => 'info'];
    }

    #[Test]
    #[DataProvider('provideSeverities')]
    public function test_each_severity_has_its_issue_type(LintSeverity $severity, string $type): void
    {
        $issueType = new \ReflectionMethod(AnalysisService::class, 'issueType');

        $this->assertSame($type, $issueType->invoke(new AnalysisService(RegexParser::create(['cache' => null])), $severity));
    }
}
