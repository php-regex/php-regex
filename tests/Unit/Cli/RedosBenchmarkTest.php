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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\RedosBenchmark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedosBenchmarkTest extends TestCase
{
    #[Test]
    public function test_a_run_counts_the_iterations_and_averages_the_wall_time_over_them(): void
    {
        $result = (new RedosBenchmark())->run('vuln', '/a/', 'a', 2, 3);

        $this->assertSame('vuln', $result['label']);
        $this->assertSame('match', $result['result']);
        $this->assertSame(3, $result['iterations']);
        $this->assertSame($result['wall_ms'] / 3, $result['avg_ms']);
        $this->assertSame('-', $result['err_msg']);
        $this->assertSame(\PREG_NO_ERROR, $result['err_code']);
        // A few microseconds: a unit off by a thousand is not.
        $this->assertGreaterThanOrEqual(0.0, $result['wall_ms']);
        $this->assertLessThan(1000.0, $result['wall_ms']);
        $this->assertNotNull($result['cpu_ms']);
        $this->assertLessThan(1000.0, $result['cpu_ms']);
        $this->assertLessThan(1 << 20, abs($result['mem_bytes']));
        $this->assertLessThan(1 << 20, abs($result['peak_bytes']));
    }

    #[Test]
    public function test_a_subject_the_pattern_does_not_match_is_no(): void
    {
        $this->assertSame('no', (new RedosBenchmark())->run('safe', '/b/', 'a', 0, 1)['result']);
    }

    #[Test]
    public function test_a_run_stops_at_the_first_error_and_names_it(): void
    {
        $backtrack = ini_set('pcre.backtrack_limit', '100');
        $jit = ini_set('pcre.jit', '0');

        try {
            $result = (new RedosBenchmark())->run('vuln', '/(a+)+$/', str_repeat('a', 30).'!', 0, 5);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrack);
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertSame('error', $result['result']);
        $this->assertSame(1, $result['iterations']);
        $this->assertSame($result['wall_ms'], $result['avg_ms']);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $result['err_code']);
        $this->assertSame('Backtrack limit exhausted', $result['err_msg']);
    }

    #[Test]
    public function test_a_pattern_php_refuses_is_an_error_reported_without_a_warning(): void
    {
        error_clear_last();
        $result = (new RedosBenchmark())->run('vuln', '/a(/', 'a', 1, 3);

        $this->assertSame('error', $result['result']);
        $this->assertSame(1, $result['iterations']);
        $this->assertSame(\PREG_INTERNAL_ERROR, $result['err_code']);
        $this->assertSame('Internal error', $result['err_msg']);
        $this->assertNull(error_get_last());
    }

    #[Test]
    public function test_a_pattern_php_refuses_is_told_from_one_it_compiles(): void
    {
        error_clear_last();
        $benchmark = new RedosBenchmark();

        $this->assertTrue($benchmark->refuses('/a(/'));
        $this->assertFalse($benchmark->refuses('/a/'));
        $this->assertFalse($benchmark->refuses('/(a+)+$/'));
        $this->assertNull(error_get_last());
    }

    /**
     * @param array<string, int|float|string> $usage
     */
    #[Test]
    #[DataProvider('provideUsages')]
    public function test_the_milliseconds_of_a_resource_usage_add_its_seconds_and_microseconds(array $usage, string $prefix, float $expected): void
    {
        $this->assertSame($expected, RedosBenchmark::milliseconds($usage, $prefix));
    }

    /**
     * @return iterable<string, array{usage: array<string, int|float|string>, prefix: string, expected: float}>
     */
    public static function provideUsages(): iterable
    {
        yield 'user time' => ['usage' => ['ru_utime.tv_sec' => 2, 'ru_utime.tv_usec' => 500000, 'ru_stime.tv_sec' => 7], 'prefix' => 'ru_utime', 'expected' => 2500.0];
        yield 'system time' => ['usage' => ['ru_utime.tv_sec' => 2, 'ru_stime.tv_sec' => 1, 'ru_stime.tv_usec' => 250], 'prefix' => 'ru_stime', 'expected' => 1000.25];
        yield 'none recorded' => ['usage' => [], 'prefix' => 'ru_utime', 'expected' => 0.0];
        yield 'not a number' => ['usage' => ['ru_utime.tv_sec' => 'x', 'ru_utime.tv_usec' => 'y'], 'prefix' => 'ru_utime', 'expected' => 0.0];
    }

    /**
     * @param array{result_parity: string, speedup: ?float, delta_ms: ?float} $expected
     */
    #[Test]
    #[DataProvider('provideSummaries')]
    public function test_the_summary_compares_the_safe_run_with_the_vulnerable_one(float $vulnAvg, string $vulnResult, float $safeAvg, string $safeResult, array $expected): void
    {
        $this->assertSame($expected, RedosBenchmark::summary(self::benchResult($vulnAvg, $vulnResult), self::benchResult($safeAvg, $safeResult)));
    }

    /**
     * @return iterable<string, array{vulnAvg: float, vulnResult: string, safeAvg: float, safeResult: string, expected: array{result_parity: string, speedup: ?float, delta_ms: ?float}}>
     */
    public static function provideSummaries(): iterable
    {
        yield 'faster, same result' => ['vulnAvg' => 6.0, 'vulnResult' => 'no', 'safeAvg' => 2.0, 'safeResult' => 'no', 'expected' => ['result_parity' => 'same', 'speedup' => 3.0, 'delta_ms' => 4.0]];
        yield 'slower, another result' => ['vulnAvg' => 1.0, 'vulnResult' => 'error', 'safeAvg' => 4.0, 'safeResult' => 'no', 'expected' => ['result_parity' => 'different', 'speedup' => 0.25, 'delta_ms' => -3.0]];
        yield 'a safe run of no time' => ['vulnAvg' => 1.0, 'vulnResult' => 'match', 'safeAvg' => 0.0, 'safeResult' => 'match', 'expected' => ['result_parity' => 'same', 'speedup' => null, 'delta_ms' => null]];
    }

    /**
     * @return array{label: string, result: string, wall_ms: float, avg_ms: float, cpu_ms: ?float, mem_bytes: int, peak_bytes: int, err_msg: string, err_code: int, iterations: int}
     */
    private static function benchResult(float $avg, string $result): array
    {
        return ['label' => 'x', 'result' => $result, 'wall_ms' => $avg, 'avg_ms' => $avg, 'cpu_ms' => null, 'mem_bytes' => 0, 'peak_bytes' => 0, 'err_msg' => '-', 'err_code' => 0, 'iterations' => 1];
    }
}
