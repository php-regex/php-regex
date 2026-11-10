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
 * A job pays for what it uses and nothing else: no coverage driver where
 * nothing is measured, and no second run for a download GitHub did not
 * serve the first time.
 */
final class RunnerTimeTest extends TestCase
{
    /**
     * The Ubuntu runners load Xdebug into the PHP setup-php installs unless
     * the step names another driver or none, and the suite runs under it
     * whether it measures coverage or not.
     */
    #[Test]
    public function test_a_job_loads_a_coverage_driver_only_when_it_asks_for_one(): void
    {
        $action = (string) file_get_contents(self::root().'/.github/actions/setup-php-project/action.yml');

        $this->assertMatchesRegularExpression('~^  coverage:\n(?:    .*\n)*?    default: none$~m', $action);
        $this->assertStringContainsString('coverage: ${{ inputs.coverage }}', $action);

        $asking = [];
        foreach (self::workflows() as $name => $source) {
            preg_match_all('~^          coverage: (\S+)$~m', $source, $drivers);
            foreach (array_diff($drivers[1], ['none']) as $driver) {
                $asking[] = $name.': '.$driver;
            }
        }

        // The coverage run of the suite, and Infection run end to end in a
        // bare project, which measures the coverage of that project itself.
        $this->assertSame(['coverage.yml: pcov', 'packages.yml: pcov'], $asking, 'The jobs that measure coverage are the ones that load a driver.');
    }

    /**
     * A release asset GitHub answers with a 504 for is asked for again: one
     * failed download otherwise fails its job, and the job its run.
     */
    #[Test]
    public function test_a_download_is_asked_for_again_before_it_fails_a_job(): void
    {
        $downloads = [];
        foreach (self::workflows() as $name => $source) {
            preg_match_all('~^.*\bcurl\b.* -o .*$~m', $source, $lines);
            foreach ($lines[0] as $line) {
                $downloads[$name][] = trim($line);
            }
        }

        $this->assertSame(['engines.yml', 'packages.yml', 'split.yml', 'workflows.yml'], array_keys($downloads));
        foreach ($downloads as $name => $lines) {
            foreach ($lines as $line) {
                $this->assertStringContainsString('curl -fsSL --retry 5 -o ', $line, $name);
            }
        }
    }

    /**
     * @return array<string, string> the source of each workflow, by file name
     */
    private static function workflows(): array
    {
        $workflows = [];
        foreach (glob(self::root().'/.github/workflows/*.yml') ?: [] as $path) {
            $workflows[basename($path)] = (string) file_get_contents($path);
        }
        ksort($workflows);

        return $workflows;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
