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

namespace PHPRegex\Cli\Command;

/**
 * Runs a pattern on a subject with preg_match(), as production runs it,
 * and measures the time, the CPU and the memory it takes.
 *
 * @internal
 *
 * @phpstan-type BenchResult array{label: string, result: string, wall_ms: float, avg_ms: float, cpu_ms: ?float, mem_bytes: int, peak_bytes: int, err_msg: string, err_code: int, iterations: int}
 */
final class RedosBenchmark
{
    /**
     * Run the pattern $warmup times unmeasured, then $iterations times, or
     * until the first run that fails.
     *
     * @return BenchResult
     */
    public function run(string $label, string $pattern, string $subject, int $warmup, int $iterations): array
    {
        // A pattern PHP refuses warns on every call: its error is read from
        // preg_last_error() below.
        set_error_handler(static fn (): bool => true);

        try {
            return $this->measure($label, $pattern, $subject, $warmup, $iterations);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether PHP refuses to compile the pattern: the call fails with a
     * warning, which a failure while matching never raises.
     */
    public function refuses(string $pattern): bool
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            $result = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        return false === $result && $warned;
    }

    /**
     * How the safe pattern compares with the vulnerable one: whether they
     * gave the same result, and how much faster the safe one ran.
     *
     * @param BenchResult $vuln
     * @param BenchResult $safe
     *
     * @return array{result_parity: string, speedup: ?float, delta_ms: ?float}
     */
    public static function summary(array $vuln, array $safe): array
    {
        $speedup = null;
        $delta = null;
        if ($safe['avg_ms'] > 0.0) {
            $speedup = $vuln['avg_ms'] / $safe['avg_ms'];
            $delta = $vuln['avg_ms'] - $safe['avg_ms'];
        }

        return [
            'result_parity' => $vuln['result'] === $safe['result'] ? 'same' : 'different',
            'speedup' => $speedup,
            'delta_ms' => $delta,
        ];
    }

    /**
     * The milliseconds a getrusage() result records under one of its times,
     * "ru_utime" or "ru_stime".
     *
     * @param array<mixed, mixed> $usage
     */
    public static function milliseconds(array $usage, string $prefix): float
    {
        $secKey = $prefix.'.tv_sec';
        $usecKey = $prefix.'.tv_usec';

        $sec = isset($usage[$secKey]) && is_numeric($usage[$secKey]) ? (float) $usage[$secKey] : 0.0;
        $usec = isset($usage[$usecKey]) && is_numeric($usage[$usecKey]) ? (float) $usage[$usecKey] : 0.0;

        return $sec * 1000 + $usec / 1000;
    }

    /**
     * @return BenchResult
     */
    private function measure(string $label, string $pattern, string $subject, int $warmup, int $iterations): array
    {
        for ($i = 0; $i < $warmup; $i++) {
            preg_match($pattern, $subject);
        }

        $usageStart = \function_exists('getrusage') ? (array) getrusage() : null;
        $memStart = memory_get_usage(true);
        $peakStart = memory_get_peak_usage(true);

        $t0 = hrtime(true);
        $result = false;
        $errCode = \PREG_NO_ERROR;
        $iterationsRun = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $iterationsRun++;
            $result = preg_match($pattern, $subject);
            $errCode = preg_last_error();

            if (false === $result && \PREG_NO_ERROR !== $errCode) {
                break;
            }
        }

        $wallMs = (hrtime(true) - $t0) / 1e6;
        $usageEnd = \function_exists('getrusage') ? (array) getrusage() : null;
        $memEnd = memory_get_usage(true);
        $peakEnd = memory_get_peak_usage(true);

        $cpuMs = null;
        if (\is_array($usageStart) && \is_array($usageEnd)) {
            $cpuMs = self::milliseconds($usageEnd, 'ru_utime') - self::milliseconds($usageStart, 'ru_utime');
            $cpuMs += self::milliseconds($usageEnd, 'ru_stime') - self::milliseconds($usageStart, 'ru_stime');
        }

        $errMsg = '-';
        if (\PREG_NO_ERROR !== $errCode) {
            $errMsg = \function_exists('preg_last_error_msg') ? preg_last_error_msg() : (string) $errCode;
        }

        return [
            'label' => $label,
            'result' => self::resultName($result),
            'wall_ms' => $wallMs,
            'avg_ms' => $wallMs / max(1, $iterationsRun),
            'cpu_ms' => $cpuMs,
            'mem_bytes' => $memEnd - $memStart,
            'peak_bytes' => $peakEnd - $peakStart,
            'err_msg' => $errMsg,
            'err_code' => $errCode,
            'iterations' => $iterationsRun,
        ];
    }

    private static function resultName(int|false $result): string
    {
        if (1 === $result) {
            return 'match';
        }

        if (0 === $result) {
            return 'no';
        }

        return 'error';
    }
}
