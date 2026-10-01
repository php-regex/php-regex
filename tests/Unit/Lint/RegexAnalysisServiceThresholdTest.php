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
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The analysis service reads its ReDoS threshold with the one threshold
 * parser: a value that names no threshold is refused when the service is
 * built, where 1.x silently judged it as "high".
 */
final class RegexAnalysisServiceThresholdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        yield 'a word that is no severity' => ['severe'];
        yield 'safe is a verdict' => ['safe'];
        yield 'unknown is a verdict' => ['unknown'];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_the_service_refuses_a_threshold_that_names_no_severity(string $threshold): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage('"'.$threshold.'"');

        new AnalysisService(RegexParser::create(), redosThreshold: $threshold);
    }

    #[Test]
    public function test_the_service_reads_a_threshold_in_any_case(): void
    {
        // "(a+)+$" is critical: reported from CRITICAL up, whatever the case.
        $upper = $this->redosIssues(new AnalysisService(RegexParser::create(), redosThreshold: 'CRITICAL', redosEnabled: true));
        $lower = $this->redosIssues(new AnalysisService(RegexParser::create(), redosThreshold: RedosSeverity::Critical->value, redosEnabled: true));

        $this->assertCount(1, $upper);
        $this->assertCount(\count($lower), $upper);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function redosIssues(AnalysisService $service): array
    {
        $issues = $service->lint([new PatternOccurrence('/(a+)+$/', 'file.php', 1, 'php:preg_match()')]);

        return array_values(array_filter($issues, static fn (array $issue): bool => 'regex.lint.redos' === ($issue['issueId'] ?? null)));
    }
}
