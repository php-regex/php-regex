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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The benchmark workflow keeps two jobs apart: the smoke job runs every
 * subject once and gates; the counted job counts the Tally benchmarks in a
 * pinned image and gates.
 */
#[Group('documentation')]
final class BenchmarksWorkflowTest extends TestCase
{
    #[Test]
    public function test_the_smoke_job_runs_every_subject_once_and_stops_on_error(): void
    {
        $job = self::job('benchmarks');

        $this->assertStringContainsString('name: Benchmarks (one run of each)', $job);
        $this->assertStringContainsString('run: tools/phpbench/vendor/bin/phpbench run --iterations=1 --revs=1 --progress=none --stop-on-error', $job);
        $this->assertStringNotContainsString('continue-on-error', $job, 'The smoke job gates.');
    }

    #[Test]
    public function test_the_workflow_runs_the_smoke_job_and_the_counted_job_alone(): void
    {
        $this->assertSame(1, preg_match('~^jobs:\n(.*)\z~ms', self::workflow(), $jobs));
        preg_match_all('~^  ([\w-]+):$~m', $jobs[1], $keys);

        $this->assertSame(['benchmarks', 'counted'], $keys[1], 'The counts measure a change: no timed table is drawn beside them.');
    }

    #[Test]
    public function test_the_workflow_only_reads_the_repository(): void
    {
        $workflow = self::workflow();

        $this->assertSame(1, substr_count($workflow, 'permissions:'));
        $this->assertStringContainsString("permissions:\n  contents: read\n", $workflow);
        $this->assertStringNotContainsString(': write', $workflow);
    }

    #[Test]
    public function test_the_counted_job_gates_in_the_image_the_task_counts_in(): void
    {
        $job = self::job('counted');
        $this->assertStringContainsString('name: Benchmarks (counted)', $job);
        $this->assertStringNotContainsString('continue-on-error', $job, 'The counts repeat from run to run: the job gates.');
        // The architecture of the Apple silicon hosts `task tally` runs on.
        $this->assertMatchesRegularExpression('~^    runs-on: ubuntu-24\.04-arm$~m', $job);

        $this->assertSame(1, preg_match('~^      TALLY_IMAGE: (\S+)$~m', $job, $image), 'The job names its image once.');
        // A digest: a tag the official images move would change every count.
        $this->assertMatchesRegularExpression('~^php:\d+\.\d+\.\d+-cli-[a-z]+@sha256:[0-9a-f]{64}$~', $image[1]);
        $this->assertStringContainsString('      TALLY_IMAGE: '.$image[1]."\n", self::taskfile(), 'A local run counts in the image the job counts in.');
    }

    #[Test]
    public function test_the_counted_job_and_the_task_run_tally_alike(): void
    {
        $count = self::stepBody(self::job('counted'), 'Count the benchmarks');
        $this->assertStringContainsString('--volume "$GITHUB_WORKSPACE:/app" --workdir /app', $count);
        $this->assertStringContainsString('"$TALLY_IMAGE" php tools/tally/vendor/bin/tally run --output=tmp/tally', $count);
        $this->assertStringContainsString('--volume "{{.ROOT_DIR}}:/app" --workdir /app {{.TALLY_IMAGE}} php tools/tally/vendor/bin/tally run --output=tmp/tally', self::taskfile());

        // The summary, the annotations and the accept command of the report
        // come from these variables: the annotations name the files from the
        // workspace the container sees.
        $this->assertStringContainsString('--volume "$GITHUB_STEP_SUMMARY:$GITHUB_STEP_SUMMARY"', $count);
        foreach (['GITHUB_ACTIONS', 'GITHUB_STEP_SUMMARY', 'GITHUB_RUN_ID', 'GITHUB_REPOSITORY'] as $name) {
            $this->assertMatchesRegularExpression('~--env '.$name.'(?:\s|$)~', $count, $name);
        }
        $this->assertStringContainsString('--env GITHUB_WORKSPACE=/app', $count);
    }

    #[Test]
    public function test_the_counts_are_kept_where_the_accept_command_downloads_them(): void
    {
        $keep = self::stepBody(self::job('counted'), 'Keep the counts');
        $this->assertStringContainsString('if: always()', $keep, 'A failed count is the one whose numbers are accepted.');
        $this->assertStringContainsString('uses: '.self::uploadAction(), $keep);
        // The command the report prints downloads the artifacts named
        // tally-* into tmp/tally, which git leaves alone.
        $this->assertStringContainsString('name: tally-results', $keep);
        $this->assertStringContainsString('path: tmp/tally', $keep);
        $this->assertStringContainsString("\n/tmp/tally/\n", (string) file_get_contents(\dirname(__DIR__, 3).'/.gitignore'));
        $this->assertStringContainsString('tools/tally/vendor/bin/tally accept tmp/tally', self::taskfile());
    }

    /**
     * The lines of the step with this name, up to the next step.
     */
    private static function stepBody(string $job, string $name): string
    {
        if (1 === preg_match('~^( *)- name: '.preg_quote($name, '~').'\n((?:(?!\1- ).*\n)*)~m', $job, $match)) {
            return $match[2];
        }
        self::fail('No step named "'.$name.'".');
    }

    /**
     * The body of the job with this key.
     */
    private static function job(string $key): string
    {
        if (1 === preg_match('~^  '.preg_quote($key, '~').':\n((?:(?:    .*)?\n)*)~m', self::workflow(), $match)) {
            return $match[1];
        }
        self::fail('No job "'.$key.'".');
    }

    /**
     * The upload action the packages workflow pins for the phar.
     */
    private static function uploadAction(): string
    {
        $uses = [];
        preg_match_all('~uses: (actions/upload-artifact@\S+)~', (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/packages.yml'), $uses);
        self::assertNotSame([], $uses[1], 'The packages workflow uploads the phar with a pinned action.');

        return $uses[1][0];
    }

    private static function taskfile(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/taskfile.dist.yaml');
    }

    private static function workflow(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/benchmarks.yml');
    }
}
