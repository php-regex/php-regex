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
 * The suite runs where the library runs: the Linux matrix of PHP versions
 * and dependency floors, Windows on every PHP with the stable
 * dependencies, and macOS on one. Every cell gates; every step runs under
 * the bash each runner carries.
 */
final class TestsWorkflowTest extends TestCase
{
    #[Test]
    public function test_the_linux_matrix_keeps_every_php_and_both_dependency_floors(): void
    {
        $job = self::job('tests');

        $this->assertMatchesRegularExpression('~^        os: \[ ubuntu-latest \]$~m', $job);
        $this->assertMatchesRegularExpression("~^        php: \\[ '8\\.6', '8\\.5', '8\\.4', '8\\.3', '8\\.2', '8\\.1' \\]$~m", $job);
        $this->assertMatchesRegularExpression('~^        dependency: \[ prefer-lowest, prefer-stable \]$~m', $job);
        $this->assertStringContainsString('runs-on: ${{ matrix.os }}', $job);
        $this->assertStringContainsString('name: Tests (PHP ${{ matrix.php }}, ${{ matrix.dependency }}, ${{ matrix.os }})', $job);
    }

    #[Test]
    public function test_windows_runs_every_php_on_the_stable_dependencies_and_macos_one(): void
    {
        $job = self::job('tests');
        $this->assertSame(1, preg_match('~^        include:\n((?:          .*\n)+)~m', $job, $include), 'The matrix has include rows.');

        $rows = [];
        preg_match_all('~^          - \{ os: ([\w-]+), php: \'([\d.]+)\', dependency: ([\w-]+) \}$~m', $include[1], $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $os, $php, $dependency]) {
            $rows[] = [$os, $php, $dependency];
        }

        $this->assertSame([
            ['windows-latest', '8.6', 'prefer-stable'],
            ['windows-latest', '8.5', 'prefer-stable'],
            ['windows-latest', '8.4', 'prefer-stable'],
            ['windows-latest', '8.3', 'prefer-stable'],
            ['windows-latest', '8.2', 'prefer-stable'],
            ['windows-latest', '8.1', 'prefer-stable'],
            ['macos-latest', '8.4', 'prefer-stable'],
        ], $rows);
        $this->assertSame(7, substr_count($include[1], "\n"), 'Nothing but these rows.');
    }

    #[Test]
    public function test_every_cell_gates_and_every_step_runs_under_bash(): void
    {
        $job = self::job('tests');

        $this->assertStringNotContainsString('continue-on-error', $job);
        $this->assertStringNotContainsString('|| true', str_replace("(grep -v '^No syntax errors detected' || true)", '', $job));

        preg_match_all('~^      - name: (.+)\n((?:        .*\n)*)~m', $job, $steps, \PREG_SET_ORDER);
        $this->assertNotSame([], $steps);
        foreach ($steps as [, $name, $body]) {
            if (!str_contains($body, 'run:')) {
                continue;
            }
            $this->assertStringContainsString('shell: bash', $body, $name.' runs under the bash of the runner, on Windows as elsewhere.');
        }
    }

    /**
     * The body of the job with this key.
     */
    private static function job(string $key): string
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/tests.yml');
        self::assertSame(1, preg_match('~\n  '.$key.':\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $workflow, $job), 'tests.yml has a '.$key.' job.');

        return $job[1];
    }
}
