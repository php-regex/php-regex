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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Tests\TestUtils\PackageSmokeChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The packages workflow checks every package as Packagist will serve it:
 * its manifest, its install alone in a bare project, the advisories
 * against what that install pulled, and one class of its own loaded and
 * run from the installed copy.
 */
final class PackagesWorkflowTest extends TestCase
{
    private const SMOKE_SCRIPT = __DIR__.'/../../Tools/package_smoke_check.php';
    private const DEPENDENCY_SCRIPT = __DIR__.'/../../Tools/package_dependency_check.php';

    #[Test]
    public function test_the_standalone_job_installs_every_package(): void
    {
        $this->assertSame(1, preg_match('~^\s+package: \[ ([^\]]+) \]$~m', self::job('standalone'), $matrix), 'The standalone job has a package matrix.');
        $listed = array_map(trim(...), explode(',', $matrix[1]));
        sort($listed);

        $this->assertSame(self::packages(), $listed);
    }

    /**
     * The root manifest and the seventeen package manifests are validated
     * strictly: a warning fails the job as an error does.
     */
    #[Test]
    public function test_the_manifests_job_validates_every_manifest_strictly(): void
    {
        $job = self::job('manifests');

        $this->assertStringContainsString('composer validate --strict --no-interaction', $job);
        $this->assertStringContainsString('for manifest in composer.json src/*/composer.json; do', $job);
        $this->assertStringNotContainsString('continue-on-error', $job);
        $this->assertStringNotContainsString('|| true', $job);
    }

    /**
     * The root requires no package for an audit to read: the advisories are
     * read against what each package pulls when installed alone, the
     * dependencies a consumer of that package gets.
     */
    #[Test]
    public function test_the_standalone_job_audits_what_it_installed(): void
    {
        $job = self::job('standalone');

        $this->assertStringContainsString('run: composer audit -d "$project" --no-interaction', $job);
        $this->assertLessThan(strpos($job, 'composer audit'), strpos($job, 'composer install -d "$project"'));
        $this->assertStringNotContainsString('continue-on-error', $job);
        $this->assertStringNotContainsString('|| true', $job);
    }

    /**
     * Each package answers with a class of its own namespace: a sibling's
     * class would load through the sibling's autoload and pass whatever
     * the package under test ships.
     */
    #[Test]
    public function test_every_package_has_a_smoke_check_of_its_own(): void
    {
        $checks = PackageSmokeChecks::all();
        $listed = array_keys($checks);
        sort($listed);

        $this->assertSame(self::packages(), $listed);
        foreach ($checks as $package => [$class]) {
            $this->assertStringStartsWith('PHPRegex\\'.$package.'\\', $class, $package);
        }
    }

    #[Test]
    #[DataProvider('providePackages')]
    public function test_a_smoke_check_answers_in_the_monorepo(string $package): void
    {
        [$class, $check] = PackageSmokeChecks::all()[$package];

        $this->assertTrue(class_exists($class), $class);
        $this->assertTrue($check(), $package);
    }

    #[Test]
    public function test_the_standalone_job_runs_the_package_smoke_check(): void
    {
        $job = self::job('standalone');

        $this->assertStringContainsString('run: php tests/Tools/package_smoke_check.php "$PACKAGE" "$project"', $job);
        $this->assertStringNotContainsString('RegexParser::create()', $job);
    }

    /**
     * After the smoke check, the manifest is checked against what the
     * installed copy uses: an undeclared import or extension fails the
     * cell, with no tolerance in the workflow.
     */
    #[Test]
    public function test_the_standalone_job_checks_the_manifest_against_what_the_package_uses(): void
    {
        $job = self::job('standalone');

        $this->assertStringContainsString('run: php tests/Tools/package_dependency_check.php "$PACKAGE" "$project"', $job);
        $this->assertLessThan(strpos($job, 'package_dependency_check.php'), strpos($job, 'package_smoke_check.php'));
        $this->assertStringContainsString('tools: dep-analyser', $job);
        $this->assertStringNotContainsString('continue-on-error', $job);
        $this->assertStringNotContainsString('|| true', $job);
    }

    #[Test]
    public function test_the_dependency_script_refuses_a_package_it_does_not_know(): void
    {
        [$code, $output] = self::runScript(self::DEPENDENCY_SCRIPT, 'Unknown', \dirname(__DIR__, 3));

        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage: php tests/Tools/package_dependency_check.php <package> <project-dir>', $output);
    }

    #[Test]
    public function test_the_dependency_script_refuses_a_directory_without_an_install(): void
    {
        [$code, $output] = self::runScript(self::DEPENDENCY_SCRIPT, 'Parser', sys_get_temp_dir().'/php-regex-no-such-project');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage: php tests/Tools/package_dependency_check.php <package> <project-dir>', $output);
    }

    #[Test]
    public function test_the_smoke_script_refuses_a_package_it_has_no_check_for(): void
    {
        [$code, $output] = self::runSmokeScript('Unknown', \dirname(__DIR__, 3));

        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage: php tests/Tools/package_smoke_check.php <package> <project-dir>', $output);
    }

    #[Test]
    public function test_the_smoke_script_refuses_a_directory_without_an_install(): void
    {
        [$code, $output] = self::runSmokeScript('Parser', sys_get_temp_dir().'/php-regex-no-such-project');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage: php tests/Tools/package_smoke_check.php <package> <project-dir>', $output);
    }

    /**
     * @return iterable<string, array{package: string}>
     */
    public static function providePackages(): iterable
    {
        foreach (self::packages() as $package) {
            yield $package => ['package' => $package];
        }
    }

    /**
     * @return array{int, string}
     */
    private static function runSmokeScript(string $package, string $project): array
    {
        return self::runScript(self::SMOKE_SCRIPT, $package, $project);
    }

    /**
     * @return array{int, string}
     */
    private static function runScript(string $script, string $package, string $project): array
    {
        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', $script, $package, $project],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output.$errors];
    }

    private static function job(string $name): string
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/packages.yml');
        self::assertSame(1, preg_match('~\n  '.$name.':\n(.*?)(?=\n  [\w-]+:\n|\z)~s', $workflow, $job), 'packages.yml has a '.$name.' job.');

        return $job[1];
    }

    /**
     * @return list<string>
     */
    private static function packages(): array
    {
        $packages = [];
        foreach (glob(\dirname(__DIR__, 3).'/src/*/composer.json') ?: [] as $manifest) {
            $packages[] = basename(\dirname($manifest));
        }
        sort($packages);

        self::assertCount(17, $packages);

        return $packages;
    }
}
