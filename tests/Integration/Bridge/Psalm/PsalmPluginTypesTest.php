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

use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * Psalm with the plugin, on PHP 8.4, reads the fixtures of Fixtures/Types:
 * each "@psalm-check-type-exact" holds, on a line the run reached. Where
 * preg_match() returned 1, $matches is the pattern's shape; where it did not,
 * []; everywhere else, and for every call the plugin cannot read, Psalm's
 * stub decides.
 */
final class PsalmPluginTypesTest extends TestCase
{
    use PcreGates;

    private const DIRECTORY = 'Types';

    /**
     * What the calls a fixture checks $matches after return and write, by
     * fixture: matches_written_twice.php reads two calls writing the same
     * $m, which holds what the right one wrote where it ran, the left one's
     * [] where it did not; assigned_condition.php reads a call that wrote []
     * where its result is false; loop.php a pattern the loop body replaces,
     * whose shape differs.
     */
    private const ENGINE_RUNS = [
        'matches_written_twice.php' => [
            ['function' => 'preg_match', 'pattern' => '/(x)/', 'subject' => 'x', 'returns' => 1, 'matches' => ['x', 'x']],
            ['function' => 'preg_match', 'pattern' => '/(a)(b)?/', 'subject' => 'ab', 'returns' => 1, 'matches' => ['ab', 'a', 'b']],
            ['function' => 'preg_match', 'pattern' => '/(x)/', 'subject' => 'y', 'returns' => 0, 'matches' => []],
        ],
        'assigned_condition.php' => [
            ['function' => 'preg_match', 'pattern' => '/(a)(b)?/', 'subject' => 'a', 'returns' => 1, 'matches' => ['a', 'a']],
            ['function' => 'preg_match', 'pattern' => '/(a)(b)?/', 'subject' => 'x', 'returns' => 0, 'matches' => []],
        ],
        'loop.php' => [
            ['function' => 'preg_match', 'pattern' => '/(a)(b)?/', 'subject' => 'ab', 'returns' => 1, 'matches' => ['ab', 'a', 'b']],
            ['function' => 'preg_match', 'pattern' => '/(x)(y)(z)/', 'subject' => 'xyz', 'returns' => 1, 'matches' => ['xyz', 'x', 'y', 'z']],
            ['function' => 'preg_match_all', 'pattern' => '/(x)(y)(z)/', 'subject' => 'xyz', 'returns' => 1, 'matches' => [['xyz'], ['x'], ['y'], ['z']]],
        ],
    ];

    /**
     * @param list<array{function: 'preg_match'|'preg_match_all', pattern: string, subject: string, returns: int, matches: list<string|list<string>>}> $runs what the engine returns and writes for the calls the check rests on
     */
    #[Test]
    #[DataProvider('provideTypeChecks')]
    public function test_plugin_types_matches_as_the_fixture_checks(string $file, int $from, int $to, string $variable, string $expected, array $runs): void
    {
        // The engine first: the type the fixture expects holds what it writes.
        foreach ($runs as ['function' => $function, 'pattern' => $pattern, 'subject' => $subject, 'returns' => $returns, 'matches' => $matches]) {
            $this->assertSame($returns, 'preg_match' === $function ? preg_match($pattern, $subject, $actual) : preg_match_all($pattern, $subject, $actual), \sprintf('%s(%s) on %s', $function, $pattern, json_encode($subject)));
            $this->assertSame($matches, $actual, \sprintf('%s(%s) on %s', $function, $pattern, json_encode($subject)));
        }

        // The patterns the check reads, from the function it stands in.
        $lines = file(PsalmRun::FIXTURES.'/'.$file) ?: [];
        $this->requiresPhpReadingCode(implode('', \array_slice($lines, max(0, $from - 6), $to - $from + 7)));

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
    public function test_plugin_types_fixtures_raise_no_other_issue(): void
    {
        foreach (PsalmRun::filesIn(self::DIRECTORY) as $file) {
            $this->requiresPhpReadingCode((string) file_get_contents(PsalmRun::FIXTURES.'/'.$file));
        }

        $others = array_values(array_filter(self::issues(), static fn (IssueData $issue): bool => !\in_array($issue->type, ['Trace', 'CheckType'], true)));

        $this->assertSame([], array_map(static fn (IssueData $issue): string => basename($issue->file_path).' '.PsalmRun::describe($issue), $others));
    }

    #[Test]
    public function test_plugin_types_engine_runs_belong_to_a_fixture(): void
    {
        $files = array_map(basename(...), PsalmRun::filesIn(self::DIRECTORY));

        // A run kept for a fixture that is gone would never run.
        $this->assertSame([], array_values(array_diff(array_keys(self::ENGINE_RUNS), $files)));
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string, runs: list<array{function: 'preg_match'|'preg_match_all', pattern: string, subject: string, returns: int, matches: list<string|list<string>>}>}>
     */
    public static function provideTypeChecks(): iterable
    {
        foreach (PsalmRun::typeChecks(PsalmRun::filesIn(self::DIRECTORY)) as $name => $check) {
            yield $name => $check + ['runs' => self::ENGINE_RUNS[basename($check['file'])] ?? []];
        }
    }

    /**
     * @return list<IssueData>
     */
    private static function issues(): array
    {
        return PsalmRun::issues(PsalmRun::filesIn(self::DIRECTORY));
    }
}
