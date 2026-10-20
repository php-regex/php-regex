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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The coverage workflow measures the suite once, then mutates on that
 * coverage within what a standard runner holds: the report reduced to the
 * files mutated, a bounded number of processes, a time limit on each run,
 * and the parser dealt to shards.
 */
final class CoverageWorkflowTest extends TestCase
{
    #[Test]
    public function test_every_mutation_run_reads_a_reduced_coverage(): void
    {
        $workflow = self::workflow();
        $runs = self::mutationRuns($workflow);

        $this->assertCount(2, $runs);
        foreach ($runs as $offset => $run) {
            $reduced = strrpos(substr($workflow, 0, $offset), 'php tests/Tools/reduce_mutation_coverage.php var/coverage/coverage-xml');
            $this->assertNotFalse($reduced, $run);
            $this->assertStringContainsString('--coverage=var/coverage --skip-initial-tests', $run);
        }
    }

    /**
     * The mutation processes run alongside the tool on a four-core runner:
     * never one per core, and never past a time limit of their own step.
     */
    #[Test]
    public function test_every_mutation_run_is_bounded_in_processes_and_time(): void
    {
        $workflow = self::workflow();

        $this->assertStringNotContainsString('--threads=max', $workflow);
        foreach (self::mutationRuns($workflow) as $offset => $run) {
            $this->assertSame(1, preg_match('~--threads=(\d+)~', $run, $threads), $run);
            $this->assertLessThanOrEqual(3, (int) $threads[1]);

            $step = substr($workflow, (int) strrpos(substr($workflow, 0, $offset), '- name: '));
            $this->assertMatchesRegularExpression('~^- name: [^\n]+\n(?:\s+(?:if|env|  [A-Z_]+): [^\n]+\n)*\s+timeout-minutes: \d+\n~', $step);
        }
    }

    #[Test]
    public function test_the_paths_are_given_as_arguments_not_through_the_deprecated_filter(): void
    {
        $this->assertStringNotContainsString('--filter', self::workflow());
    }

    /**
     * A manual run has no commit before it: on 2.x it mutates the parser, on
     * another branch the lines that branch changes from 2.x.
     */
    #[Test]
    public function test_a_manual_run_mutates_against_a_base_it_has(): void
    {
        $workflow = self::workflow();

        $this->assertStringContainsString(
            'workflow_dispatch) if [ "$REF" = refs/heads/2.x ]; then base=\'\'; else base=origin/2.x; fi ;;',
            $workflow,
        );
        $this->assertStringNotContainsString("github.event_name != 'schedule'", $workflow);
    }

    #[Test]
    public function test_the_parser_shards_cover_every_share(): void
    {
        $workflow = self::workflow();

        $this->assertSame(1, preg_match('~^        shard: \[ ([\d, ]+) \]$~m', $workflow, $matrix));
        $shards = array_map(intval(...), explode(', ', $matrix[1]));
        $count = \count($shards);

        $this->assertSame(range(1, $count), $shards);
        $this->assertStringContainsString(\sprintf('--shard="$SHARD/%d"', $count), $workflow);
        $this->assertStringContainsString(\sprintf('name: Parser Mutations (${{ matrix.shard }}/%d)', $count), $workflow);
    }

    #[Test]
    public function test_the_logs_of_a_failed_run_are_kept(): void
    {
        $workflow = self::workflow();

        $this->assertSame(2, preg_match_all("~- name: Keep the Mutation Logs\n        if: failure\\(\\)\n        uses: actions/upload-artifact@v\\d+\n~", $workflow));
        $this->assertStringNotContainsString('continue-on-error', $workflow);
    }

    /**
     * @return array<int, string> each call of the mutation tool, by offset
     */
    private static function mutationRuns(string $workflow): array
    {
        preg_match_all('~tools/infection/vendor/bin/infection(?:[^\n]*\n {10,}[^\n]*)*~', $workflow, $matches, \PREG_OFFSET_CAPTURE);

        $runs = [];
        foreach ($matches[0] as [$run, $offset]) {
            $runs[$offset] = $run;
        }

        return $runs;
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/coverage.yml');
    }
}
