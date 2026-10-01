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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A8: the analysis is bounded in memory and time, not only in steps.
 *
 * Each case runs in a fresh PHP process: the class-set cache starts cold,
 * the peak memory is the analysis's own, and a run that does not end is
 * killed instead of holding the suite. The child measures the call itself;
 * the parent only enforces a kill deadline above the bound under test.
 *
 * Figures before the fix (PHP 8.4.26 / PCRE2 10.49, Xdebug off,
 * memory_limit -1): the 249-deep nesting ends budget_exceeded after a peak
 * of 329.8 MB in 0.4 s; the 400 classes end "safe (proven)" after 18.2 s;
 * the confirmed replay had not returned after 120 s.
 */
final class RedosResourceBoundsTest extends TestCase
{
    private const MEMORY_BOUND_BYTES = 64 * 1024 * 1024;

    #[Test]
    public function test_deep_nesting_ends_budget_exceeded_within_the_memory_bound(): void
    {
        $pattern = '/'.str_repeat('(?:', 249).'a'.str_repeat(')*', 249).'$/';
        // The engine compiles it: preg_match on '' returns 1.
        $this->assertSame(1, preg_match($pattern, ''));

        $result = self::runIsolated(<<<'PHP_WRAP'
            $analyzer = new \PHPRegex\Redos\RedosAnalyzer();
            memory_reset_peak_usage();
            $before = memory_get_peak_usage();
            $analysis = $analyzer->analyze($pattern);
            $result = ['proof' => $analysis->proof->value, 'memory' => memory_get_peak_usage() - $before];
            PHP_WRAP, $pattern, 30.0);

        $this->assertSame('budget_exceeded', $result['proof'] ?? null, json_encode($result, \JSON_THROW_ON_ERROR));
        $memory = $result['memory'] ?? null;
        $this->assertIsInt($memory);
        $this->assertLessThan(self::MEMORY_BOUND_BYTES, $memory, \sprintf('peak memory grew by %.1f MB', $memory / 1048576));
    }

    #[Test]
    public function test_cold_class_scans_are_charged_to_the_budget(): void
    {
        $pattern = '/';
        for ($i = 0; $i < 400; $i++) {
            $pattern .= \sprintf('[\w\x{%X}]', 0x4E00 + $i);
        }
        $pattern .= '/iu';
        // The engine compiles it: preg_match on '' returns 0.
        $this->assertSame(0, preg_match($pattern, ''));

        $result = self::runIsolated(<<<'PHP_WRAP'
            $analyzer = new \PHPRegex\Redos\RedosAnalyzer();
            $start = hrtime(true);
            $analysis = $analyzer->analyze($pattern);
            $result = ['proof' => $analysis->proof->value, 'seconds' => (hrtime(true) - $start) / 1e9];
            PHP_WRAP, $pattern, 10.0);

        $this->assertArrayHasKey('proof', $result, 'the analysis did not end within 10 s');
        $seconds = $result['seconds'] ?? null;
        $this->assertIsFloat($seconds);
        $this->assertLessThan(5.0, $seconds, \sprintf('%s after %.1f s', \is_string($result['proof']) ? $result['proof'] : '?', $seconds));
    }

    #[Test]
    public function test_confirmed_replay_has_a_total_work_cap(): void
    {
        $pattern = '/(?:(?!a)'.str_repeat('a', 200).'|'.str_repeat('(?:a(?:b|))', 200).')+$/';
        // The engine compiles it: preg_match on '' returns 0.
        $this->assertSame(0, preg_match($pattern, ''));

        $result = self::runIsolated(<<<'PHP_WRAP'
            $analyzer = new \PHPRegex\Redos\RedosAnalyzer();
            $start = hrtime(true);
            $analysis = $analyzer->analyze($pattern, \PHPRegex\Redos\RedosSeverity::Low, \PHPRegex\Redos\RedosMode::Confirmed);
            $result = ['replayed' => $analysis->replayed, 'seconds' => (hrtime(true) - $start) / 1e9];
            PHP_WRAP, $pattern, 15.0);

        $this->assertArrayHasKey('seconds', $result, 'the confirmed analysis did not return within 15 s');
        $seconds = $result['seconds'];
        $this->assertIsFloat($seconds);
        $this->assertLessThan(10.0, $seconds);
    }

    /**
     * B4: each lookaround sub-search's automaton is charged to the budget
     * and released once finished. Before the fix: the 120x pattern ends
     * "safe (proven)" after a peak growth of 136.6 MB, the 300x one
     * budget_exceeded after 129.8 MB (uncatchable at the default 128M
     * memory_limit).
     */
    #[Test]
    #[DataProvider('provideLookaroundChains')]
    public function test_lookaround_sub_searches_stay_within_the_memory_bound(string $pattern): void
    {
        // The engine compiles it: preg_match on '' returns 0.
        $this->assertSame(0, preg_match($pattern, ''));

        $result = self::runIsolated(<<<'PHP_WRAP'
            $analyzer = new \PHPRegex\Redos\RedosAnalyzer();
            memory_reset_peak_usage();
            $before = memory_get_peak_usage();
            $analysis = $analyzer->analyze($pattern);
            $result = ['proof' => $analysis->proof->value, 'memory' => memory_get_peak_usage() - $before];
            PHP_WRAP, $pattern, 30.0);

        $memory = $result['memory'] ?? null;
        $this->assertIsInt($memory, 'the analysis did not end within 30 s');
        $this->assertLessThan(self::MEMORY_BOUND_BYTES, $memory, \sprintf('peak memory grew by %.1f MB', $memory / 1048576));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLookaroundChains(): iterable
    {
        yield '120 lookaheads each before a letter' => ['pattern' => '/'.str_repeat('(?=\pL+\b)\pL', 120).'/u'];
        yield '300 lookaheads in a row' => ['pattern' => '/'.str_repeat('(?=\pL+\b)', 300).'/u'];
    }

    /**
     * B5: one total-work budget shared by every replay candidate and pump.
     * Before the fix the confirmed analysis took 17.4 s, 17.5 s, and more
     * than 120 s for the third row.
     */
    #[Test]
    #[DataProvider('provideCostlyReplays')]
    public function test_confirmed_replay_work_is_shared_by_every_candidate(string $pattern): void
    {
        // The engine compiles it: preg_match on '' returns 0.
        $this->assertSame(0, preg_match($pattern, ''));

        $result = self::runIsolated(<<<'PHP_WRAP'
            $analyzer = new \PHPRegex\Redos\RedosAnalyzer();
            $start = hrtime(true);
            $analysis = $analyzer->analyze($pattern, \PHPRegex\Redos\RedosSeverity::Low, \PHPRegex\Redos\RedosMode::Confirmed);
            $result = ['replayed' => $analysis->replayed, 'seconds' => (hrtime(true) - $start) / 1e9];
            PHP_WRAP, $pattern, 12.0);

        $this->assertArrayHasKey('seconds', $result, 'the confirmed analysis did not return within 12 s');
        $seconds = $result['seconds'];
        $this->assertIsFloat($seconds);
        $this->assertLessThan(8.0, $seconds);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCostlyReplays(): iterable
    {
        yield 'bounded ambiguous alternation of one character' => ['pattern' => '/(?:a|a){1,15}$/'];
        yield 'bounded overlapping classes before a literal' => ['pattern' => '/(?:\w|\d){1,15}!$/'];
        yield 'bounded ambiguous alternation of long literals' => ['pattern' => '/(?:'.str_repeat('a', 30).'|'.str_repeat('a', 30).'){1,15}$/'];
    }

    /**
     * Runs the snippet in a fresh PHP process with $pattern defined, and
     * returns the $result array it builds; an empty array when the process
     * was killed at the deadline or printed nothing usable.
     *
     * @return array<string, mixed>
     */
    private static function runIsolated(string $snippet, string $pattern, float $killAfterSeconds): array
    {
        $code = 'require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'
            .'$pattern = '.var_export($pattern, true).';'
            .$snippet
            .'echo json_encode($result, JSON_THROW_ON_ERROR);';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'memory_limit=-1', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = hrtime(true) + (int) ($killAfterSeconds * 1e9);
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            if (hrtime(true) > $deadline) {
                proc_terminate($process, 9);

                break;
            }
            usleep(20_000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($output, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
