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

namespace PHPRegex\Tests\Integration\Transpiler\Oracle;

/**
 * A target regex engine run as a child process: Node for JavaScript and the
 * HTML pattern attribute, Python for its re module.
 *
 * The cases go to the engine in batches of JSON, one process per batch. A
 * pattern can backtrack for ever in an engine that has no backtracking
 * limit, as JavaScript and Python have none: a batch that outlives its time,
 * or whose engine crashes, is split in two and each half run again, down
 * to the one pattern that hangs, which is reported as unanswered rather
 * than compared.
 *
 * @phpstan-type EngineCase array{pattern: string, flags: string, whole: bool, subjects: list<array{string, int}>}
 * @phpstan-type EngineResult array{error: ?string, results: list<?list<?array{int, int}>>, unassigned: list<bool>, timedOut?: bool}
 */
final class TargetEngine
{
    private const BATCH = 400;

    private const SECONDS = 30;

    /**
     * @param list<string> $command the engine and the script it runs, as for proc_open
     * @param list<string> $probe   a command that exits 0 when the engine can run the script
     */
    private function __construct(
        public readonly string $name,
        private readonly array $command,
        private readonly array $probe,
    ) {}

    /**
     * Node 20 or later, whose RegExp takes the v flag the HTML pattern
     * attribute is read under. TRANSPILER_ORACLE_NODE names another binary.
     */
    public static function node(): self
    {
        $binary = getenv('TRANSPILER_ORACLE_NODE') ?: 'node';

        return new self('node', [$binary, __DIR__.'/javascript.mjs'], [$binary, '-e', 'new RegExp("[\\\\p{L}--[a-z]]", "dv")']);
    }

    /**
     * Python 3.11 or later: before it, re loses a group's capture when it
     * backtracks into a lazy repetition ("^(b+|a){1,2}?bc" on "bbc" gives
     * group 1 the span (2, 1)), which no pattern can work around.
     * TRANSPILER_ORACLE_PYTHON names another binary.
     */
    public static function python(): self
    {
        $binary = getenv('TRANSPILER_ORACLE_PYTHON') ?: 'python3';

        return new self('python3', [$binary, '-I', __DIR__.'/python.py'], [$binary, '-I', '-c', 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)']);
    }

    /**
     * Whether the engine is installed, and recent enough for the script.
     */
    public function isAvailable(): bool
    {
        $process = @proc_open($this->probe, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($process);
    }

    /**
     * @param list<EngineCase> $cases
     *
     * @return list<EngineResult> one result per case, in order
     */
    public function run(array $cases): array
    {
        $results = [];
        foreach (array_chunk($cases, self::BATCH) as $batch) {
            array_push($results, ...$this->runSplitting($batch));
        }

        return $results;
    }

    /**
     * @param list<EngineCase> $cases
     *
     * @return list<EngineResult>
     */
    private function runSplitting(array $cases): array
    {
        $results = $this->runBatch($cases);
        if (null !== $results) {
            return $results;
        }

        if (1 === \count($cases)) {
            return [['error' => null, 'results' => [], 'unassigned' => [], 'timedOut' => true]];
        }

        $half = intdiv(\count($cases), 2);

        return [...$this->runSplitting(\array_slice($cases, 0, $half)), ...$this->runSplitting(\array_slice($cases, $half))];
    }

    /**
     * @param list<EngineCase> $cases
     *
     * @return list<EngineResult>|null null when the engine outlived its time or crashed
     */
    private function runBatch(array $cases): ?array
    {
        $input = json_encode(['cases' => $cases], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $process = proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException(\sprintf('%s did not start.', $this->name));
        }

        // The scripts read the whole batch before they write anything.
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $errors = '';
        $deadline = microtime(true) + self::SECONDS;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return null;
            }

            if (false === stream_select($read, $write, $except, 0, (int) min(200000, $left * 1000000))) {
                break;
            }

            $output .= (string) stream_get_contents($pipes[1]);
            $errors .= (string) stream_get_contents($pipes[2]);
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        // An engine that crashes on a pattern, as Node 20 does on some under
        // the v flag, answers nothing for the batch: it is split as one
        // that outlives its time is.
        if (0 !== $status) {
            return null;
        }

        $decoded = json_decode($output, true);
        if (!\is_array($decoded) || \count($decoded) !== \count($cases)) {
            throw new \RuntimeException(\sprintf('%s wrote no result for each case: %s', $this->name, $errors));
        }

        return array_map(self::result(...), array_values($decoded));
    }

    /**
     * One case's result as the script wrote it, its shape checked.
     *
     * @return EngineResult
     */
    private static function result(mixed $value): array
    {
        if (!\is_array($value) || !\array_key_exists('error', $value) || !\is_array($value['results'] ?? null) || !\is_array($value['unassigned'] ?? null)) {
            throw new \RuntimeException('The engine wrote a result of another shape: '.json_encode($value));
        }

        $results = [];
        foreach ($value['results'] as $spans) {
            $results[] = null === $spans ? null : array_map(self::span(...), array_values((array) $spans));
        }

        return [
            'error' => \is_string($value['error']) ? $value['error'] : null,
            'results' => $results,
            'unassigned' => array_map(static fn (mixed $flag): bool => true === $flag, array_values($value['unassigned'])),
        ];
    }

    /**
     * @return array{int, int}|null
     */
    private static function span(mixed $span): ?array
    {
        if (null === $span) {
            return null;
        }

        if (!\is_array($span) || !\is_int($span[0] ?? null) || !\is_int($span[1] ?? null)) {
            throw new \RuntimeException('The engine wrote a span of another shape: '.json_encode($span));
        }

        return [$span[0], $span[1]];
    }
}
