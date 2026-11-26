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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * php-regex.optimizations is nested as regex.json's checks.optimizations:
 * "enabled" switches the suggestions, "min_savings" is the saving below
 * which none is reported unless --min-savings says otherwise, and
 * "options" says what the optimizer may rewrite.
 */
final class LintCommandOptimizationsTest extends LaravelTestCase
{
    use TemporaryProject;

    // [0-9]+ becomes \d+: a saving of 3 characters.
    private const DIGITS_FILE = "<?php\n\npreg_match('/[0-9]+/', \$subject);\n";

    /**
     * @return iterable<string, array{optimizations: array<string, mixed>, input: array<string, string>, expected: int}>
     */
    public static function provideOptimizationSettings(): iterable
    {
        yield 'on by default' => ['optimizations' => [], 'input' => [], 'expected' => 1];
        yield 'switched off' => ['optimizations' => ['enabled' => false], 'input' => [], 'expected' => 0];
        yield 'switched off, a saving asked on the command line' => ['optimizations' => ['enabled' => false], 'input' => ['--min-savings' => '1'], 'expected' => 0];
        yield 'a saving the rewrite reaches' => ['optimizations' => ['min_savings' => 3], 'input' => [], 'expected' => 1];
        yield 'a saving the rewrite misses' => ['optimizations' => ['min_savings' => 4], 'input' => [], 'expected' => 0];
        yield 'the command line wins over the saving' => ['optimizations' => ['min_savings' => 4], 'input' => ['--min-savings' => '3'], 'expected' => 1];
        yield 'an option the rewrite needs, off' => ['optimizations' => ['options' => ['digits' => false]], 'input' => [], 'expected' => 0];
    }

    /**
     * @param array<string, mixed>  $optimizations
     * @param array<string, string> $input
     */
    #[Test]
    #[DataProvider('provideOptimizationSettings')]
    public function test_the_optimization_settings_reach_the_run(array $optimizations, array $input, int $expected): void
    {
        $this->configure($optimizations);

        $this->assertSame($expected, $this->optimizationCount($input));
    }

    #[Test]
    public function test_the_defaults_are_those_of_regex_json(): void
    {
        $this->assertTrue(config('php-regex.optimizations.enabled'));
        $this->assertSame(1, config('php-regex.optimizations.min_savings'));
        $this->assertTrue(config('php-regex.optimizations.options.digits'));
        $this->assertFalse(config()->has('php-regex.optimizations.digits'));
    }

    /**
     * A value the run cannot use stops it as any setting of
     * config/php-regex.php does: one line naming the key, exit code 2.
     *
     * @return iterable<string, array{optimizations: array<string, mixed>, message: string}>
     */
    public static function provideUnreadableSettings(): iterable
    {
        yield 'a switch that is a string' => ['optimizations' => ['enabled' => 'no'], 'message' => '"optimizations.enabled" must be true or false, not a string.'];
        yield 'a switch that is a number' => ['optimizations' => ['enabled' => 1], 'message' => '"optimizations.enabled" must be true or false, not a int.'];
        yield 'a saving that is a string' => ['optimizations' => ['min_savings' => '3'], 'message' => '"optimizations.min_savings" must be an integer of at least 1, not a string.'];
        yield 'a saving of zero' => ['optimizations' => ['min_savings' => 0], 'message' => '"optimizations.min_savings" must be an integer of at least 1, not 0.'];
        yield 'a negative saving' => ['optimizations' => ['min_savings' => -1], 'message' => '"optimizations.min_savings" must be an integer of at least 1, not -1.'];
        yield 'an option the optimizer does not know' => ['optimizations' => ['options' => ['canonicalizeCharClasses' => false]], 'message' => 'Unknown optimizer option "canonicalizeCharClasses"'];
    }

    /**
     * @param array<string, mixed> $optimizations
     */
    #[Test]
    #[DataProvider('provideUnreadableSettings')]
    public function test_a_setting_the_run_cannot_use_stops_it_with_exit_code_2(array $optimizations, string $message): void
    {
        $this->configure($optimizations);

        [$status, $payload] = $this->lint([]);

        $this->assertSame(2, $status);
        $this->assertSame('config', $payload['stage'] ?? null);
        $this->assertIsString($payload['error'] ?? null);
        $this->assertStringStartsWith('Invalid config/php-regex.php: '.$message, $payload['error']);
    }

    /**
     * A saving of one is the least the run takes, a switch off is read as
     * one: neither stops it.
     */
    #[Test]
    public function test_the_least_saving_and_a_switch_off_are_read(): void
    {
        $this->configure(['enabled' => false, 'min_savings' => 1]);

        [$status] = $this->lint([]);

        $this->assertSame(0, $status);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::configOf($app)->set('php-regex.cache.directory', null);
        self::configOf($app)->set('php-regex.cache.store', null);
    }

    /**
     * @param array<string, mixed> $optimizations
     */
    private function configure(array $optimizations): void
    {
        $config = self::configOf($this->application());
        foreach ($optimizations as $key => $value) {
            $config->set('php-regex.optimizations.'.$key, \is_array($value) ? $value + (array) $config->get('php-regex.optimizations.'.$key) : $value);
        }
    }

    /**
     * @param array<string, string> $input
     */
    private function optimizationCount(array $input): int
    {
        [, $payload] = $this->lint($input);
        $this->assertIsArray($payload['stats'] ?? null);
        $this->assertIsInt($payload['stats']['optimizations'] ?? null);

        return $payload['stats']['optimizations'];
    }

    /**
     * The exit code and the JSON document of a run over a project holding
     * one pattern to optimize.
     *
     * @param array<string, string> $input
     *
     * @return array{int, array<mixed>}
     */
    private function lint(array $input): array
    {
        $project = $this->makeProject(['src/Pattern.php' => self::DIGITS_FILE]);

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/src'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ] + $input);
        $output = Artisan::output();

        $payload = json_decode($output, true);
        $this->assertIsArray($payload, 'output is not JSON: '.$output);

        return [$status, $payload];
    }
}
