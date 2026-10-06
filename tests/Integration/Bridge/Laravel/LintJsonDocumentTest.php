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
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint --format=json prints one document whatever happens: the report,
 * or the error envelope naming the stage that failed, each ending with one
 * newline, and --quiet silences neither.
 */
final class LintJsonDocumentTest extends TestCase
{
    use TemporaryProject;

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideProjects')]
    public function test_json_report_is_printed_under_quiet(array $files, int $resultCount): void
    {
        $project = $this->makeProject($files);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src') + ['--quiet' => true]);

        $this->assertSame(0, $status);
        $document = $this->decodeDocument(Artisan::output());
        $this->assertArrayHasKey('stats', $document);
        $this->assertIsArray($document['results'] ?? null);
        $this->assertCount($resultCount, $document['results']);
    }

    /**
     * @return iterable<string, array{files: array<string, string>, resultCount: int}>
     */
    public static function provideProjects(): iterable
    {
        yield 'no pattern found' => ['files' => ['src/Empty.php' => "<?php\n"], 'resultCount' => 0];
        yield 'a pattern found' => ['files' => ['src/Pattern.php' => "<?php\n\npreg_match('/<info>(a)+/', \$subject);\n"], 'resultCount' => 1];
    }

    #[Test]
    public function test_json_report_keeps_console_tags_in_a_pattern(): void
    {
        $project = $this->makeProject(['src/Pattern.php' => "<?php\n\npreg_match('/<info>(a)+/', \$subject);\n"]);

        Artisan::call('regex:lint', $this->arguments($project.'/src'));

        $results = $this->decodeDocument(Artisan::output())['results'] ?? null;
        $this->assertIsArray($results);
        $this->assertIsArray($results[0] ?? null);
        $this->assertSame('/<info>(a)+/', $results[0]['pattern'] ?? null);
    }

    #[Test]
    #[DataProvider('provideFormatSpellings')]
    public function test_format_is_case_insensitive(string $format): void
    {
        $project = $this->makeProject(['src/Pattern.php' => "<?php\n\npreg_match('/(a)+/', \$subject);\n"]);

        $status = Artisan::call('regex:lint', ['--format' => $format] + $this->arguments($project.'/src'));

        $this->assertSame(0, $status);
        $document = $this->decodeDocument(Artisan::output());
        JsonContract::assertShape('lint', $document);
        $this->assertIsArray($document['results'] ?? null);
        $this->assertCount(1, $document['results']);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideFormatSpellings(): iterable
    {
        yield 'upper case' => ['format' => 'JSON'];
        yield 'mixed case' => ['format' => 'Json'];
    }

    #[Test]
    public function test_invalid_config_prints_the_envelope(): void
    {
        config(['php-regex.php_version' => ['8.2']]);

        $status = Artisan::call('regex:lint', $this->arguments('src') + ['--quiet' => true]);

        $this->assertSame(2, $status);
        $document = $this->decodeDocument(Artisan::output());
        $this->assertSame('config', $document['stage'] ?? null);
        $this->assertIsString($document['error'] ?? null);
        $this->assertStringStartsWith('Invalid config/php-regex.php: ', $document['error']);
    }

    #[Test]
    public function test_collection_failure_prints_the_envelope(): void
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

        $status = Artisan::call('regex:lint', $this->arguments('src') + ['--quiet' => true]);

        $this->assertSame(1, $status);
        $this->assertSame(['error' => 'Failed to collect patterns: boom', 'stage' => 'collect'], $this->decodeDocument(Artisan::output()));
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
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ];
    }

    /**
     * One JSON object, ending with exactly one newline.
     *
     * @return array<mixed>
     */
    private function decodeDocument(string $output): array
    {
        $this->assertStringEndsWith("}\n", $output);

        return JsonContract::decodeDocument($output);
    }
}
