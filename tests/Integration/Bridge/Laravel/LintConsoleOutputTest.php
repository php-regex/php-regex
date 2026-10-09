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
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint on the console: the report is written once, through the
 * console styles, and a run that stops prints its error line, never a
 * JSON document, with the exit code of the stage that failed.
 */
final class LintConsoleOutputTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_console_report_is_written_once(): void
    {
        $project = $this->makeProject(['src/Pattern.php' => "<?php\n\npreg_match('/(a/', \$subject);\n"]);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src'));

        $this->assertSame(1, $status);
        $output = Artisan::output();
        $this->assertSame(1, substr_count($output, 'Expected ) at end of input'), $output);
        $this->assertSame(1, substr_count($output, '1 invalid patterns, 0 warnings, 0 optimizations.'), $output);
    }

    /**
     * A file read with the tokenizer because the PHP parser could not is no
     * pattern of its own.
     */
    #[Test]
    public function test_console_pattern_count_leaves_parser_fallbacks_out(): void
    {
        $project = $this->makeProject(['src/Broken.php' => "<?php\npreg_match('/a+/', \$s);\nfunction (\n"]);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src'));

        $this->assertSame(0, $status);
        $this->assertStringContainsString('found 1 patterns.', Artisan::output());
    }

    /**
     * @param \Closure(self): void $breakRun
     */
    #[Test]
    #[DataProvider('provideStoppedRuns')]
    public function test_console_failure_prints_the_error_line(\Closure $breakRun, int $exitCode, string $error): void
    {
        $breakRun($this);

        $status = Artisan::call('regex:lint', $this->arguments('src'));

        $this->assertSame($exitCode, $status);
        $output = Artisan::output();
        $this->assertSame(1, substr_count($output, $error), $output);
        $this->assertNull(json_decode($output, true), $output);
    }

    /**
     * @return iterable<string, array{breakRun: \Closure(self): void, exitCode: int, error: string}>
     */
    public static function provideStoppedRuns(): iterable
    {
        yield 'a configuration the lint cannot use' => [
            'breakRun' => static function (self $test): void {
                config(['php-regex.php_version' => ['8.2']]);
            },
            'exitCode' => 2,
            'error' => 'Invalid config/php-regex.php: ',
        ];
        yield 'a collection that fails' => [
            'breakRun' => static function (self $test): void {
                $test->bindFailingCollection();
            },
            'exitCode' => 1,
            'error' => 'Failed to collect patterns: boom',
        ];
    }

    /**
     * A lint whose only source throws while collecting.
     */
    public function bindFailingCollection(): void
    {
        $failing = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $analysis = $this->app?->make('php-regex.analysis');
        $this->assertInstanceOf(AnalysisService::class, $analysis);
        $this->app?->instance('php-regex.lint', new LintService($analysis, new PatternSourceCollection([$failing])));
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
        $app['config']->set('php-regex.cache.directory', null);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(string $path): array
    {
        return [
            'paths' => [$path],
            '--format' => 'console',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ];
    }
}
