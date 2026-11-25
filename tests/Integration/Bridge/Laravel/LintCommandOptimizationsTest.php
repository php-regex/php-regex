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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
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
    use PhpUnitForwardCompat;
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
     * @return iterable<string, array{saving: mixed, shown: string}>
     */
    public static function provideUnreadableSavings(): iterable
    {
        yield 'zero' => ['saving' => 0, 'shown' => '0'];
        yield 'a string' => ['saving' => '3', 'shown' => 'a string'];
    }

    #[Test]
    #[DataProvider('provideUnreadableSavings')]
    public function test_a_saving_below_one_is_refused(mixed $saving, string $shown): void
    {
        $this->configure(['min_savings' => $saving]);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"optimizations.min_savings" must be an integer of at least 1, not '.$shown.'.');

        $this->optimizationCount([]);
    }

    #[Test]
    public function test_a_switch_that_is_no_boolean_is_refused(): void
    {
        $this->configure(['enabled' => 'no']);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"optimizations.enabled" must be true or false, not a string.');

        $this->optimizationCount([]);
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
        $project = $this->makeProject(['src/Pattern.php' => self::DIGITS_FILE]);

        Artisan::call('regex:lint', [
            'paths' => [$project.'/src'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ] + $input);
        $output = Artisan::output();

        $payload = json_decode($output, true);
        $this->assertIsArray($payload, 'output is not JSON: '.$output);
        $this->assertIsArray($payload['stats'] ?? null);
        $this->assertIsInt($payload['stats']['optimizations'] ?? null);

        return $payload['stats']['optimizations'];
    }
}
