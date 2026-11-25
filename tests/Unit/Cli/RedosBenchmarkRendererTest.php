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
use PHPRegex\Cli\Command\RedosBenchmarkRenderer;
use PHPRegex\Cli\Output;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The console table of `regex redos`: one row per pattern measured, its
 * result in a column wide enough for "no_match", and "-" in the error cell
 * of a run without an error.
 *
 * @phpstan-import-type BenchResult from RedosBenchmark
 */
final class RedosBenchmarkRendererTest extends TestCase
{
    #[Test]
    public function test_the_table_names_each_row_and_marks_a_run_without_an_error(): void
    {
        $table = $this->render(false, self::row('pattern', 'error', 'Backtrack limit exhausted', \PREG_BACKTRACK_LIMIT_ERROR), self::row('safe', 'no_match'));
        $lines = explode("\n", $table);

        $this->assertSame('case     | result   |    wall_ms |     avg_ms |     cpu_ms |     mem_kb |    peak_kb | err', $lines[0]);
        $this->assertSame(str_repeat('-', 94), $lines[1]);
        $this->assertSame('pattern  | error    |       2.00 |       2.00 |        n/a |       +0.0 |       +0.0 | Backtrack limit exhausted', $lines[2]);
        $this->assertSame('safe     | no_match |       2.00 |       2.00 |        n/a |       +0.0 |       +0.0 | -', $lines[3]);
        $this->assertStringContainsString("Speedup       : 1.00x (pattern avg / safe avg)\n", $table);
        $this->assertStringContainsString("Delta avg     : 0.00 ms (pattern - safe)\n", $table);
    }

    #[Test]
    public function test_the_table_colours_each_label_and_result_by_what_it_says(): void
    {
        $table = $this->render(true, self::row('pattern', 'no_match'), self::row('safe', 'match'));

        $this->assertStringContainsString(Output::RED.'pattern '.Output::RESET, $table);
        $this->assertStringContainsString(Output::GREEN.'safe    '.Output::RESET, $table);
        $this->assertStringContainsString(Output::YELLOW.'no_match'.Output::RESET, $table);
        $this->assertStringContainsString(Output::GREEN.'match   '.Output::RESET, $table);
        // No error: the cell is "-", not coloured as one.
        $this->assertStringEndsNotWith(Output::RESET, explode("\n", $table)[2]);
        $this->assertStringEndsWith(' | -', explode("\n", $table)[2]);
    }

    /**
     * @param BenchResult $pattern
     * @param BenchResult $safe
     */
    private function render(bool $ansi, array $pattern, array $safe): string
    {
        $renderer = new RedosBenchmarkRenderer(OutputFactory::create($ansi));

        ob_start();

        try {
            $renderer->renderResults($pattern, $safe);
        } finally {
            $table = (string) ob_get_clean();
        }

        return $table;
    }

    /**
     * @return BenchResult
     */
    private static function row(string $label, string $result, ?string $error = null, ?int $errorCode = null): array
    {
        return ['label' => $label, 'result' => $result, 'wall_ms' => 2.0, 'avg_ms' => 2.0, 'cpu_ms' => null, 'mem_bytes' => 0, 'peak_bytes' => 0, 'error' => $error, 'error_code' => $errorCode, 'iterations' => 1];
    }
}
