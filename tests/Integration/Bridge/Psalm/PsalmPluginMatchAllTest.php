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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * Psalm with the plugin, on PHP 8.4, reads the fixtures of Fixtures/MatchAll:
 * after preg_match_all(), $matches is the pattern's matchAllShape(), in
 * either order, whatever the call returned. A pattern the plugin cannot read
 * is left to Psalm's stub.
 *
 * The plugin types preg_match_all() only while it keeps every behaviour of
 * the stub: PsalmPluginParityTest is the condition these rows stand on.
 */
final class PsalmPluginMatchAllTest extends TestCase
{
    private const DIRECTORY = 'MatchAll';

    #[Test]
    #[DataProvider('provideTypeChecks')]
    public function test_plugin_types_match_all_as_the_fixture_checks(string $file, int $from, int $to, string $variable, string $expected): void
    {
        $issues = array_values(array_filter(
            PsalmRun::inFile(self::issues(), $file),
            static fn (IssueData $issue): bool => $issue->line_from >= $from && $issue->line_from <= $to + 1,
        ));
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, $variable.': ')));
        $failures = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' !== $issue->type));

        $this->assertNotSame([], $traces, \sprintf('%s:%d: Psalm never reached the check of %s.', $file, $from, $variable));
        $this->assertSame([], array_map(PsalmRun::describe(...), $failures), \sprintf('%s:%d expects %s = %s; Psalm traced %s.', $file, $from, $variable, $expected, $traces[0]->message));
    }

    #[Test]
    public function test_plugin_match_all_fixtures_raise_no_other_issue(): void
    {
        $others = array_values(array_filter(self::issues(), static fn (IssueData $issue): bool => !\in_array($issue->type, ['Trace', 'CheckType'], true)));

        $this->assertSame([], array_map(static fn (IssueData $issue): string => basename($issue->file_path).' '.PsalmRun::describe($issue), $others));
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function provideTypeChecks(): iterable
    {
        return PsalmRun::typeChecks(PsalmRun::filesIn(self::DIRECTORY));
    }

    /**
     * @return list<IssueData>
     */
    private static function issues(): array
    {
        return PsalmRun::issues(PsalmRun::filesIn(self::DIRECTORY));
    }
}
