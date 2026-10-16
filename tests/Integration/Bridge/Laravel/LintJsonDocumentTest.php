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
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\ResetsShellVerbosity;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint --format=json prints one document whatever happens: the report,
 * or the error envelope naming the stage that failed, each ending with one
 * newline, and --quiet silences neither.
 */
final class LintJsonDocumentTest extends LaravelTestCase
{
    use ResetsShellVerbosity;

    use TemporaryProject;

    #[Before]
    protected function resetLintFunctions(): void
    {
        LintFunctionOverrides::reset();
    }

    #[After]
    protected function restoreLintFunctions(): void
    {
        LintFunctionOverrides::reset();
    }

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

    /**
     * A file the PHP parser cannot read is read with the tokenizer and
     * counted, never reported.
     */
    #[Test]
    public function test_json_stats_count_a_parser_fallback(): void
    {
        $project = $this->makeProject(['src/Broken.php' => "<?php\npreg_match('/a+/', \$s);\nfunction (\n"]);

        $status = Artisan::call('regex:lint', $this->arguments($project.'/src'));

        $this->assertSame(0, $status);
        $document = $this->decodeDocument(Artisan::output());
        JsonContract::assertShape('lint', $document);
        $this->assertIsArray($document['stats'] ?? null);
        $this->assertSame(1, $document['stats']['parser_fallbacks'] ?? null);
        $this->assertSame(0, $document['stats']['errors'] ?? null);
        $this->assertSame([], $document['results'] ?? null);
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

    /**
     * PHP 7.2 and earlier ship PCRE1: the command refuses to judge them,
     * with the stage the CLI names the refusal with.
     */
    #[Test]
    public function test_a_target_on_pcre1_prints_the_envelope_with_the_target_stage(): void
    {
        config(['php-regex.php_version' => '5.6']);

        $status = Artisan::call('regex:lint', $this->arguments('src') + ['--quiet' => true]);

        $this->assertSame(2, $status);
        $this->assertSame(
            ['error' => 'config php-regex.php_version names PHP 5.6: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.', 'stage' => 'target'],
            $this->decodeDocument(Artisan::output()),
        );
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
        $analysis = $this->application()->make('php-regex.analysis');
        $this->assertInstanceOf(AnalysisService::class, $analysis);
        $this->application()->instance('php-regex.lint', new LintService($analysis, new PatternSourceCollection([$failing])));

        $status = Artisan::call('regex:lint', $this->arguments('src') + ['--quiet' => true]);

        $this->assertSame(1, $status);
        $this->assertSame(['error' => 'Failed to collect patterns: boom', 'stage' => 'collect'], $this->decodeDocument(Artisan::output()));
    }

    /**
     * A worker that ends without its results stops the analysis: the
     * envelope names the stage analyze. The pids handed back are ones no
     * system gives out (above the largest pid_max): no worker runs, and its
     * channel is closed from the start, as when a worker is killed.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_analysis_failure_prints_the_envelope(): void
    {
        $project = $this->makeProject(['src/Patterns.php' => "<?php\n\npreg_match('/^[a-z]+$/', \$subject);\npreg_match('/^[0-9]+$/', \$subject);\n"]);
        LintFunctionOverrides::queuePcntlForkResult(2_000_000_001);
        LintFunctionOverrides::queuePcntlForkResult(2_000_000_002);

        $async = pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function (): never {
            throw new \RuntimeException('The lint did not end within 30 seconds: it waits for a worker that is gone.');
        });
        pcntl_alarm(30);

        try {
            $status = Artisan::call('regex:lint', ['--jobs' => '2', '--quiet' => true] + $this->arguments($project.'/src'));
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals($async);
        }

        $document = $this->decodeDocument(Artisan::output());
        $this->assertSame(1, $status);
        $this->assertSame(['error', 'stage'], array_keys($document));
        $this->assertSame('analyze', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertStringContainsString('Parallel analysis failed: a worker exited unexpectedly while reading ', $document['error']);
        $this->assertStringContainsString('Patterns.php', $document['error']);
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
        self::configOf($app)->set('php-regex.runtime_pcre_validation', false);
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
