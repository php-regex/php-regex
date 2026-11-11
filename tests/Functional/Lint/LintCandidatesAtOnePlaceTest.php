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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An argument composed at the call of a part that takes several values
 * holds one pattern for each, all at the place of the argument. Each is a
 * result of its own, its pattern over its own issues and its own
 * optimizations, as the same pattern written as a literal is reported;
 * the results of one place come in the byte order of their patterns,
 * whatever the number of workers.
 *
 * The engine, PHP 8.4.27 and PCRE2 10.49 (php -r, preg_match() and its
 * warning):
 *
 *   /^(a)+$/     matches "aa" and keeps "a", the last capture
 *   /^(a+$/      missing closing parenthesis at offset 5
 *   /^[aa]b$/    matches "ab" and not "b", as /^[a]b$/; /^[aa]c$/ the same with "c"
 *   /^(?:a)x$/   matches "ax" and not "x", as /^ax$/; /^(?:a)y$/ the same with "y"
 *   /^a$/        matches "a"
 *   /^a{1}$/     matches "a" and not "aa", as /^a$/
 */
final class LintCandidatesAtOnePlaceTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    /**
     * @return iterable<string, array{argument: string, patterns: list<string>, refused: array<string, string>}>
     */
    public static function provideArguments(): iterable
    {
        // The first compiles and keeps the last capture; the second does not compile.
        yield 'an issue of each pattern' => [
            'argument' => "'/^(a' . (\$flag ? ')+' : '+') . '$/'",
            'patterns' => ['/^(a)+$/', '/^(a+$/'],
            'refused' => ['/^(a+$/' => 'preg_match(): Compilation failed: missing closing parenthesis at offset 5'],
        ];
        // The class written twice stands in both: each pattern says it.
        yield 'an issue both patterns raise' => [
            'argument' => "'/^[aa]' . (\$flag ? 'b' : 'c') . '$/'",
            'patterns' => ['/^[aa]b$/', '/^[aa]c$/'],
            'refused' => [],
        ];
        yield 'an optimization of each pattern' => [
            'argument' => "'/^(?:a)' . (\$flag ? 'x' : 'y') . '$/'",
            'patterns' => ['/^(?:a)x$/', '/^(?:a)y$/'],
            'refused' => [],
        ];
        yield 'an issue of one pattern alone' => [
            'argument' => "'/^a' . (\$flag ? '' : '{1}') . '$/'",
            'patterns' => ['/^a$/', '/^a{1}$/'],
            'refused' => [],
        ];
    }

    /**
     * @param list<string>          $patterns the patterns of the argument, in byte order
     * @param array<string, string> $refused  what the engine says of each pattern it does not compile
     */
    #[Test]
    #[DataProvider('provideArguments')]
    public function test_each_pattern_of_an_argument_is_reported_as_its_literal_is(string $argument, array $patterns, array $refused): void
    {
        // The engine compiles each pattern of the argument, or refuses it as the row says.
        foreach ($patterns as $pattern) {
            $this->assertSame($refused[$pattern] ?? null, EngineProbe::warning($pattern), $pattern);
        }

        // What each pattern is told when a literal holds it, one call a line.
        $literals = "<?php\n\n";
        foreach ($patterns as $pattern) {
            $literals .= 'preg_match('.var_export($pattern, true).", \$s);\n";
        }
        $this->enterProject(['app/a.php' => $literals]);
        [, $stdout] = $this->runRegex(['lint', 'app', '--format=json', '--jobs=1']);
        $expected = [];
        foreach (self::results(JsonContract::decodeDocument($stdout)) as $result) {
            $expected[] = [$result['pattern'], $result['issues'], $result['optimizations']];
        }
        $told = array_column($expected, 0);
        $this->assertNotSame([], $told, $stdout);

        $composed = "<?php\n\nfunction check(bool \$flag, string \$s): void\n{\n    preg_match(".$argument.", \$s);\n}\n";
        $runs = ['one worker' => fn (): array => $this->runRegex(['lint', 'app', '--format=json', '--jobs=1'])];
        if (ForkedWorkerPool::canFork()) {
            $runs['two workers, through the binary'] = fn (): array => $this->runRegexProcess(['lint', 'app', '--format=json', '--jobs=2']);
        }

        foreach ($runs as $name => $run) {
            $this->enterProject(['app/a.php' => $composed]);
            [, $stdout, $stderr] = $run();
            $results = self::results(JsonContract::decodeDocument($stdout));

            $this->assertSame($expected, array_map(static fn (array $result): array => [$result['pattern'], $result['issues'], $result['optimizations']], $results), $name."\n".$stdout.$stderr);
            foreach ($results as $result) {
                // All where the argument starts.
                $this->assertSame(['app/a.php', 5, 16], $result['place'], $name);

                // Each rewrite is of the pattern it stands under, and the engine matches both alike.
                foreach ($result['optimizations'] as [$original, $optimized]) {
                    $this->assertSame($result['pattern'], $original, $name);
                    $this->assertIsString($original);
                    $this->assertIsString($optimized);
                    foreach (['ax', 'ay', 'a', 'x', 'aax', ''] as $subject) {
                        $this->assertSame(preg_match($original, $subject), preg_match($optimized, $subject), $name.': '.$optimized.' on "'.$subject.'"');
                    }
                }
            }

            // In the byte order of the patterns, the order they are given in here.
            $sorted = $patterns;
            sort($sorted, \SORT_STRING);
            $this->assertSame($sorted, $patterns);
            $this->assertSame($told, array_column($results, 'pattern'), $name);
        }
    }

    /**
     * What the rows say of their patterns, read by the engine: the group
     * repeated keeps its last capture, the class written twice and the
     * group that changes nothing match as the pattern without them, and
     * the quantifier of one as the atom alone.
     */
    #[Test]
    public function test_the_engine_reads_the_patterns_as_the_rows_say(): void
    {
        $this->assertSame(1, preg_match('/^(a)+$/', 'aa', $matches));
        $this->assertSame(['aa', 'a'], $matches);

        foreach (['ab', 'b', 'aab', 'ac', 'c', ''] as $subject) {
            $this->assertSame(preg_match('/^[a]b$/', $subject), preg_match('/^[aa]b$/', $subject), $subject);
            $this->assertSame(preg_match('/^[a]c$/', $subject), preg_match('/^[aa]c$/', $subject), $subject);
        }
        $this->assertSame(1, preg_match('/^[aa]b$/', 'ab'));
        $this->assertSame(1, preg_match('/^[aa]c$/', 'ac'));

        foreach (['ax', 'x', 'aax', 'ay', 'y', ''] as $subject) {
            $this->assertSame(preg_match('/^ax$/', $subject), preg_match('/^(?:a)x$/', $subject), $subject);
            $this->assertSame(preg_match('/^ay$/', $subject), preg_match('/^(?:a)y$/', $subject), $subject);
        }
        $this->assertSame(1, preg_match('/^(?:a)x$/', 'ax'));
        $this->assertSame(1, preg_match('/^(?:a)y$/', 'ay'));

        foreach (['a', 'aa', ''] as $subject) {
            $this->assertSame(preg_match('/^a$/', $subject), preg_match('/^a{1}$/', $subject), $subject);
        }
        $this->assertSame(1, preg_match('/^a{1}$/', 'a'));
    }

    /**
     * The console shows each pattern over what is told of it.
     */
    #[Test]
    public function test_the_console_shows_each_pattern_over_its_own_issues(): void
    {
        $this->enterProject(['app/a.php' => "<?php\n\nfunction check(bool \$flag, string \$s): void\n{\n    preg_match('/^(a' . (\$flag ? ')+' : '+') . '$/', \$s);\n}\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'app', '--no-optimize', '--jobs=1']);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $first = strpos($stdout, '/^(a)+$/');
        $info = strpos($stdout, 'only the last iteration');
        $second = strpos($stdout, '/^(a+$/');
        $error = strpos($stdout, 'Expected ) at end of input');
        $this->assertIsInt($first, $stdout);
        $this->assertIsInt($info, $stdout);
        $this->assertIsInt($second, $stdout);
        $this->assertIsInt($error, $stdout);
        $this->assertLessThan($info, $first, $stdout);
        $this->assertLessThan($second, $info, $stdout);
        $this->assertLessThan($error, $second, $stdout);
    }

    /**
     * A baseline written from such a run holds each pattern under its own
     * hash, and hides the issues of both.
     */
    #[Test]
    public function test_a_baseline_holds_each_pattern_of_an_argument(): void
    {
        $this->enterProject(['app/a.php' => "<?php\n\nfunction check(bool \$flag, string \$s): void\n{\n    preg_match('/^(a' . (\$flag ? ')+' : '+') . '$/', \$s);\n}\n"]);

        [, $stdout, $stderr] = $this->runRegex(['lint', 'app', '--no-optimize', '--jobs=1', '--generate-baseline=baseline.json']);
        $this->assertFileExists('baseline.json', $stdout.$stderr);
        $baseline = JsonContract::asArray(json_decode((string) file_get_contents('baseline.json'), true, 512, \JSON_THROW_ON_ERROR));
        $entries = [];
        foreach (JsonContract::asArray($baseline['issues'] ?? null) as $entry) {
            $entry = JsonContract::asArray($entry);
            $entries[] = [$entry['pattern'] ?? null, $entry['issue_id'] ?? null];
        }
        sort($entries);
        $this->assertSame([['/^(a)+$/', 'regex.lint.group.quantifiedCapture'], ['/^(a+$/', 'regex.group.unclosed']], $entries);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'app', '--no-optimize', '--jobs=1', '--baseline=baseline.json', '--format=json']);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame([], self::results(JsonContract::decodeDocument($stdout)), $stdout);
    }

    /**
     * The results of a report: the place, the pattern, the level and the
     * identifier of each issue, and the pattern each optimization rewrites
     * with what it makes of it.
     *
     * @param array<mixed> $document
     *
     * @return list<array{place: array{mixed, mixed, mixed}, pattern: mixed, issues: list<array{mixed, mixed}>, optimizations: list<array{mixed, mixed}>}>
     */
    private static function results(array $document): array
    {
        $results = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            $result = JsonContract::asArray($result);
            $issues = [];
            foreach (JsonContract::asArray($result['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                $issues[] = [$issue['severity'] ?? null, $issue['issue_id'] ?? null];
            }
            $optimizations = [];
            foreach (JsonContract::asArray($result['optimizations'] ?? null) as $optimization) {
                $rewrite = JsonContract::asArray(JsonContract::asArray($optimization)['optimization'] ?? null);
                $optimizations[] = [$rewrite['original'] ?? null, $rewrite['optimized'] ?? null];
            }
            $results[] = [
                'place' => [$result['file'] ?? null, $result['line'] ?? null, $result['column'] ?? null],
                'pattern' => $result['pattern'] ?? null,
                'issues' => $issues,
                'optimizations' => $optimizations,
            ];
        }

        return $results;
    }
}
