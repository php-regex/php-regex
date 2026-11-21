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
 * Psalm under taint analysis, on PHP 8.4, reads the fixtures of
 * Fixtures/Taint. From 7.11 on, Psalm itself reads a preg_match() call whose
 * pattern is anchored at both ends as removing taints from its subject. With
 * the plugin, the call's types hold beside that reading, and every taint
 * Psalm reports, or does not, is the same as without the plugin.
 */
final class PsalmPluginTaintTest extends TestCase
{
    private const DIRECTORY = 'Taint';

    #[Test]
    #[DataProvider('provideTypeChecks')]
    public function test_plugin_taint_run_types_as_the_fixture_checks(string $file, int $from, int $to, string $variable, string $expected): void
    {
        // Psalm 6 keeps only the taints during a taint run: there the types
        // are read in a run of their own.
        $issues = array_values(array_filter(
            PsalmRun::inFile(PsalmRun::reportsTypesInTaintRun() ? self::issues([]) : PsalmRun::issues(PsalmRun::filesIn(self::DIRECTORY)), $file),
            static fn (IssueData $issue): bool => $issue->line_from >= $from && $issue->line_from <= $to + 1,
        ));
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, $variable.': ')));
        $checks = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'CheckType' === $issue->type));

        $this->assertNotSame([], $traces, \sprintf('%s:%d: Psalm never reached the check of %s.', $file, $from, $variable));
        $this->assertSame([], array_map(PsalmRun::describe(...), $checks), \sprintf('%s:%d expects %s = %s; Psalm traced %s.', $file, $from, $variable, $expected, $traces[0]->message));
    }

    #[Test]
    public function test_plugin_taint_reports_what_psalm_reports_without_the_plugin(): void
    {
        $with = self::flows(self::issues([]));
        $without = self::flows(self::issues(null));

        // A subject the pattern does not hold to letters and digits is tainted on every release.
        $this->assertContains('TaintedHtml '.self::lastEchoLine(), $with);
        $this->assertSame($without, $with);
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function provideTypeChecks(): iterable
    {
        return PsalmRun::typeChecks(PsalmRun::filesIn(self::DIRECTORY));
    }

    /**
     * @param array<string, string>|null $pluginOptions null to run Psalm without the plugin
     *
     * @return list<IssueData>
     */
    private static function issues(?array $pluginOptions): array
    {
        return PsalmRun::issues(PsalmRun::filesIn(self::DIRECTORY), pluginOptions: $pluginOptions, taint: true);
    }

    /**
     * Each issue but a type check, as "Type line", sorted.
     *
     * @param list<IssueData> $issues
     *
     * @return list<string>
     */
    private static function flows(array $issues): array
    {
        $flows = [];
        foreach ($issues as $issue) {
            if (!\in_array($issue->type, ['Trace', 'CheckType'], true)) {
                $flows[] = $issue->type.' '.$issue->line_from;
            }
        }
        sort($flows);

        return $flows;
    }

    private static function lastEchoLine(): int
    {
        $lines = file(PsalmRun::FIXTURES.'/'.self::DIRECTORY.'/anchored_subject.php') ?: [];
        $echoes = array_keys(array_filter($lines, static fn (string $line): bool => 'echo $x;' === trim($line)));
        self::assertNotSame([], $echoes);

        return (int) end($echoes) + 1;
    }
}
