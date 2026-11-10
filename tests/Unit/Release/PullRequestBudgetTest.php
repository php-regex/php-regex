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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The organization runs sixty jobs at once, five of them on macOS, and every
 * open pull request draws on them. The matrices of one pull request hold a
 * third of the runners at most, and one macOS runner: the latest PCRE2,
 * which only macOS reads, is read once, and the other macOS cells are left
 * to the weekly run and to a run by hand.
 */
final class PullRequestBudgetTest extends TestCase
{
    private const RUNNERS = 60;

    /**
     * The workflows of the third stage, and how many cells each of their
     * matrices runs at once.
     */
    private const AT_ONCE = [
        'tests.yml' => ['tests' => 8, 'psalm' => 1],
        'engines.yml' => ['engines-bundled' => 3, 'engine-claims' => 2, 'engines-latest' => 2],
        'php74-twin.yml' => ['twin' => 3],
    ];

    /**
     * The matrices too small for a ceiling, and their cells.
     */
    private const SMALL = [
        'php74-twin.yml' => ['twin-below-the-original' => "        php: ['7.4', '8.0']"],
    ];

    /**
     * @return iterable<string, array{workflow: string}>
     */
    public static function provideMatrixWorkflows(): iterable
    {
        foreach (array_keys(self::AT_ONCE) as $workflow) {
            yield $workflow => ['workflow' => $workflow];
        }
    }

    #[Test]
    #[DataProvider('provideMatrixWorkflows')]
    public function test_every_matrix_of_the_third_stage_names_how_many_cells_run_at_once(string $workflow): void
    {
        $matrices = [];
        foreach (self::jobs($workflow) as $job => $body) {
            if (1 !== preg_match('~^    strategy:\n((?:      .*\n)+?)      matrix:\n~m', $body, $strategy)) {
                $this->assertStringNotContainsString('matrix:', $body, $workflow.': '.$job);

                continue;
            }
            if (isset(self::SMALL[$workflow][$job])) {
                $this->assertStringContainsString("      matrix:\n".self::SMALL[$workflow][$job]."\n", $body, $workflow.': '.$job);
                $this->assertStringNotContainsString('max-parallel', $body, $workflow.': '.$job);

                continue;
            }
            $this->assertSame(1, preg_match('~^      max-parallel: (\d+)$~m', $strategy[1], $ceiling), $workflow.': the matrix of '.$job.' names how many of its cells run at once.');
            $matrices[$job] = (int) $ceiling[1];
        }

        $this->assertSame(self::AT_ONCE[$workflow], $matrices);
    }

    /**
     * The jobs that are no matrix add a runner each, most of them for a
     * minute or two.
     */
    #[Test]
    public function test_the_matrices_of_one_pull_request_hold_a_third_of_the_runners_at_most(): void
    {
        $atOnce = self::AT_ONCE;
        // A pull request runs one macOS cell.
        $atOnce['engines.yml']['engines-latest'] = min($atOnce['engines.yml']['engines-latest'], \count(self::latestEngineCells(true)));

        $held = array_sum(array_map(array_sum(...), $atOnce));
        foreach (self::SMALL as $matrices) {
            foreach ($matrices as $cells) {
                $held += substr_count($cells, ',') + 1;
            }
        }

        $this->assertLessThanOrEqual(intdiv(self::RUNNERS, 3), $held);
    }

    /**
     * Every Linux cell and every Windows cell reads a PCRE2 older than
     * Homebrew's, and each of the five macOS runners is waited for by every
     * open pull request: the cell that stays is the reference's PHP, so
     * that what it finds is the engine's or the host's.
     */
    #[Test]
    public function test_a_pull_request_reads_the_latest_engine_on_the_php_of_its_reference(): void
    {
        $this->assertSame(['8.4'], self::latestEngineCells(true));
        $this->assertStringContainsString('name: Reference (PHP 8.4, ', self::jobs('ci.yml')['reference']);
    }

    #[Test]
    public function test_the_weekly_run_and_a_run_by_hand_read_the_latest_engine_on_every_php(): void
    {
        $this->assertSame(['8.6', '8.5', '8.4', '8.3', '8.2'], self::latestEngineCells(false));

        $engines = self::workflow('engines.yml');
        $this->assertMatchesRegularExpression('~^  workflow_call:\n    inputs:\n      pull-request:\n(?:        .*\n)*?        type: boolean\n(?:        .*\n)*?        default: false$~m', $engines);
        $this->assertMatchesRegularExpression('~^  schedule:$~m', $engines);
        $this->assertMatchesRegularExpression('~^  workflow_dispatch:$~m', $engines);
        // A run of ci.yml by hand is no pull request: it reads every cell.
        $this->assertMatchesRegularExpression("~^    with:\n(?:      #.*\n)*      pull-request: \\$\\{\\{ github\\.event_name == 'pull_request' \\}\\}$~m", self::jobs('ci.yml')['engines']);
    }

    /**
     * The PHP versions of the macOS cells, as the matrix leaves them once
     * its exclusions are read for a pull request or for another run.
     *
     * @return list<string>
     */
    private static function latestEngineCells(bool $pullRequest): array
    {
        $job = self::jobs('engines.yml')['engines-latest'];
        self::assertMatchesRegularExpression('~^    runs-on: macos-latest$~m', $job);
        self::assertSame(1, preg_match('~^        php: \[ (.+) \]$~m', $job, $list));
        $cells = array_map(static fn (string $php): string => trim($php, "'"), explode(', ', $list[1]));

        self::assertSame(1, preg_match('~^        exclude:\n((?:          .*\n)+)~m', $job, $exclude), 'The matrix leaves cells out.');
        preg_match_all("~^          - php: \\$\\{\\{ inputs\\.pull-request && '([\\d.]+)' \\|\\| 'no PHP' \\}\\}$~m", $exclude[1], $rows);
        self::assertCount(substr_count($exclude[1], "\n"), $rows[1], 'Every exclusion is read here.');

        return array_values(array_diff($cells, $pullRequest ? $rows[1] : []));
    }

    /**
     * @return array<string, string> the body of each job of the workflow, by job id
     */
    private static function jobs(string $name): array
    {
        $workflow = self::workflow($name);
        $jobs = substr($workflow, (int) strpos($workflow, "\njobs:\n"));
        preg_match_all('~^  ([a-z][a-z0-9-]*):\n((?:(?:    .*)?\n)*)~m', $jobs, $matches, \PREG_SET_ORDER);

        $bodies = [];
        foreach ($matches as $match) {
            $bodies[$match[1]] = $match[2];
        }

        return $bodies;
    }

    private static function workflow(string $name): string
    {
        $path = \dirname(__DIR__, 3).'/.github/workflows/'.$name;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
