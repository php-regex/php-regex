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

namespace PHPRegex\Tests\Unit\Support\PhpUnitCompat;

use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\Support\PhpUnitCompat\PolyfilledMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

/**
 * The layer that gives every PHPUnit the suite runs on the methods of the
 * newest one: each feature trait is the implementation the running PHPUnit
 * calls for, the method it provides behaves as PHPUnit's own, and the
 * newest PHPUnit still has it.
 */
final class PolyfilledMethodsTest extends TestCase
{
    /**
     * @return iterable<string, array{trait: trait-string, method: non-empty-string, since: non-empty-string}>
     */
    public static function provideFeatures(): iterable
    {
        foreach (PolyfilledMethods::FEATURES as $trait => $feature) {
            yield $feature['method'] => ['trait' => $trait, 'method' => $feature['method'], 'since' => $feature['since']];
        }
    }

    public function test_the_aggregate_uses_every_feature_trait_and_nothing_else(): void
    {
        $used = (new \ReflectionClass(PhpUnitForwardCompat::class))->getTraitNames();
        $listed = array_keys(PolyfilledMethods::FEATURES);
        sort($used);
        sort($listed);

        $this->assertSame($listed, $used);
    }

    /**
     * @param trait-string     $trait
     * @param non-empty-string $method
     * @param non-empty-string $since
     */
    #[DataProvider('provideFeatures')]
    public function test_the_running_phpunit_has_the_method_exactly_from_the_listed_release(string $trait, string $method, string $since): void
    {
        $native = version_compare(Version::id(), $since, '>=');

        $this->assertSame(
            $native,
            (new \ReflectionClass(TestCase::class))->hasMethod($method),
            \sprintf('PHPUnit %s %s %s(), listed as added in %s: a PHPUnit that removed or renamed it needs the feature rewritten.', Version::id(), $native ? 'lacks' : 'has', $method, $since),
        );
    }

    /**
     * @param trait-string     $trait
     * @param non-empty-string $method
     * @param non-empty-string $since
     */
    #[DataProvider('provideFeatures')]
    public function test_the_trait_is_the_implementation_the_running_phpunit_calls_for(string $trait, string $method, string $since): void
    {
        $native = PolyfilledMethods::isNative($trait);
        $implementation = new \ReflectionClass($trait);

        $this->assertTrue($implementation->isTrait());
        $this->assertSame(PolyfilledMethods::implementation($trait, $native), $implementation->getName());
        $this->assertSame(realpath(PolyfilledMethods::file($trait, $native)), $implementation->getFileName());
        $this->assertSame(
            $native ? [] : [$method],
            array_map(static fn (\ReflectionMethod $declared): string => $declared->getName(), $implementation->getMethods()),
        );
        $this->assertFileExists(PolyfilledMethods::file($trait, !$native), 'Both implementations exist.');
    }

    /**
     * @param trait-string     $trait
     * @param non-empty-string $method
     * @param non-empty-string $since
     */
    #[DataProvider('provideFeatures')]
    public function test_the_newest_phpunit_the_suite_runs_on_has_the_method(string $trait, string $method, string $since): void
    {
        $newest = self::newestSupportedMajor();

        $this->assertLessThanOrEqual($newest, (int) $since, \sprintf('%s() arrives in PHPUnit %s, after the newest one the suite runs on, %d.', $method, $since, $newest));

        if ($newest === (int) Version::id()) {
            $this->assertTrue((new \ReflectionClass(TestCase::class))->hasMethod($method), \sprintf('PHPUnit %s has no %s().', Version::id(), $method));
        }
    }

    /**
     * Rector reads the tests with no PHPUnit installed beside it, its own
     * TestCase standing in only once a class asks for it: the traits load
     * there all the same, as the implementations built on the older API.
     */
    public function test_every_feature_trait_loads_where_no_phpunit_is_installed(): void
    {
        $root = \dirname(__DIR__, 4);
        $script = \sprintf(
            'require %s; require %s; echo class_exists(%s, false) ? "PHPUnit loaded" : "", "\n"; foreach (%s as $trait) { echo $trait, " ", (new ReflectionClass($trait))->getName(), "\n"; }',
            var_export($root.'/vendor/autoload.php', true),
            var_export($root.'/tests/Support/PhpUnitCompat/bootstrap.php', true),
            var_export(TestCase::class, true),
            var_export(array_keys(PolyfilledMethods::FEATURES), true),
        );

        exec(escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $output, $code);

        $expected = [''];
        foreach (array_keys(PolyfilledMethods::FEATURES) as $trait) {
            $expected[] = $trait.' '.PolyfilledMethods::implementation($trait, false);
        }
        $this->assertSame([0, $expected], [$code, $output]);
    }

    /**
     * Each passing and each failing expectation of the feature's probe, run
     * by the PHPUnit running this test, ends as its name says.
     *
     * @param trait-string     $trait
     * @param non-empty-string $method
     * @param non-empty-string $since
     */
    #[DataProvider('provideFeatures')]
    public function test_each_expectation_of_the_probe_passes_or_fails_as_its_name_says(string $trait, string $method, string $since): void
    {
        $name = substr($trait, (int) strrpos($trait, '\\') + 1);
        $probe = \dirname(__DIR__, 3).'/Fixtures/PhpUnitCompat/'.$name.'Probe.php';
        $this->assertFileExists($probe, 'Every feature has a probe.');

        $outcomes = $this->runProbe($probe);

        $passing = array_filter($outcomes, static fn (string $test): bool => str_starts_with($test, 'test_passes_'), \ARRAY_FILTER_USE_KEY);
        $failing = array_filter($outcomes, static fn (string $test): bool => str_starts_with($test, 'test_fails_'), \ARRAY_FILTER_USE_KEY);

        $this->assertNotSame([], $passing, 'The probe has a passing expectation.');
        $this->assertNotSame([], $failing, 'The probe has a failing expectation.');
        $this->assertSame(\count($outcomes), \count($passing) + \count($failing), 'Every probe test says how it ends.');
        $this->assertSame(array_fill_keys(array_keys($passing), 'passed'), $passing);
        $this->assertSame(array_fill_keys(array_keys($failing), 'failed'), $failing);
    }

    /**
     * The probe runs under the PHPUnit the test itself runs under, with no
     * configuration, and reports through its JUnit log.
     *
     * @return array<string, 'passed'|'failed'|'errored'|'skipped'>
     */
    private function runProbe(string $probe): array
    {
        // vendor/phpunit/phpunit/src/Framework/TestCase.php
        $phpunit = \dirname((string) (new \ReflectionClass(TestCase::class))->getFileName(), 5).'/bin/phpunit';
        $log = sys_get_temp_dir().'/phpunit-compat-'.bin2hex(random_bytes(6)).'.xml';

        try {
            $command = [
                \PHP_BINARY,
                $phpunit,
                '--no-configuration',
                '--bootstrap',
                \dirname(__DIR__, 4).'/vendor/autoload.php',
                '--do-not-cache-result',
                '--no-progress',
                '--log-junit',
                $log,
                $probe,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $junit = new \DOMDocument();
            $this->assertTrue(@$junit->load($log), "The probe ran.\n".$output);

            $outcomes = [];
            foreach ($junit->getElementsByTagName('testcase') as $case) {
                $outcome = 'passed';
                foreach ($case->childNodes as $child) {
                    $outcome = match ($child->nodeName) {
                        'failure' => 'failed',
                        'error' => 'errored',
                        'skipped' => 'skipped',
                        default => $outcome,
                    };
                }
                $outcomes[$case->getAttribute('name')] = $outcome;
            }
            $this->assertNotSame([], $outcomes, "The probe ran tests.\n".$output);

            return $outcomes;
        } finally {
            @unlink($log);
        }
    }

    private static function newestSupportedMajor(): int
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 4).'/tools/phpunit/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['require-dev'] ?? null);
        $constraint = $manifest['require-dev']['phpunit/phpunit'] ?? null;
        self::assertIsString($constraint);
        preg_match_all('/\^(\d+)\./', $constraint, $majors);
        $majors = array_map(intval(...), $majors[1]);
        self::assertNotSame([], $majors, $constraint);

        return max($majors);
    }
}
