<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use RegexParser\Bridge\Laravel\Command\CompareCommand;
use RegexParser\Bridge\Laravel\Command\ExplainCommand;
use RegexParser\Bridge\Laravel\Command\LintCommand;
use RegexParser\Bridge\Laravel\Command\RoutesCommand;
use RegexParser\Bridge\Laravel\Command\TranspileCommand;
use RegexParser\Bridge\Laravel\Extractor\LaravelRouteExtractor;
use RegexParser\Bridge\Laravel\Extractor\ValidationRuleExtractor;
use RegexParser\Bridge\Laravel\Facades\Regex as RegexFacade;
use RegexParser\Bridge\Laravel\RegexParserServiceProvider;
use RegexParser\Cache\CacheInterface;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Lint\Formatter\FormatterRegistry;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexLintService;
use RegexParser\Lint\RegexPatternSourceCollection;
use RegexParser\Node\RegexNode;
use RegexParser\Regex;
use RegexParser\PcreTarget;

/**
 * Integration tests for the Laravel bridge.
 */
final class RegexParserServiceProviderTest extends TestCase
{
    public function test_regex_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound(Regex::class));
        $this->assertTrue($this->app->bound('regex-parser'));

        /** @var Regex $regex */
        $regex = $this->app->make(Regex::class);
        $this->assertInstanceOf(Regex::class, $regex);
    }

    public function test_regex_service_parses_patterns(): void
    {
        /** @var Regex $regex */
        $regex = $this->app->make(Regex::class);

        $ast = $regex->parse('/^hello$/');
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public function test_facade_works(): void
    {
        $ast = RegexFacade::parse('/[a-z]+/');
        $this->assertInstanceOf(RegexNode::class, $ast);

        $validation = RegexFacade::validate('/^test$/');
        $this->assertTrue($validation->isValid);

        $validation = RegexFacade::validate('/^(unclosed/');
        $this->assertFalse($validation->isValid);
    }

    public function test_cache_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.cache'));

        /** @var CacheInterface $cache */
        $cache = $this->app->make('regex-parser.cache');
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    public function test_filesystem_cache_works(): void
    {
        /** @var string $cacheDir */
        $cacheDir = $this->app['config']->get('regex-parser.cache.directory');

        /** @var Regex $regex */
        $regex = $this->app->make(Regex::class);

        // Parse a pattern to populate the cache
        $regex->parse('/abc/');

        $cache = new FilesystemCache($cacheDir);
        $cacheFile = $cache->generateKey(Regex::cacheSeed('/abc/', PcreTarget::runtime(), 1024));

        $this->assertFileExists($cacheFile);

        // Clean up
        $cache->clear();
    }

    public function test_analysis_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.analysis'));

        /** @var RegexAnalysisService $analysis */
        $analysis = $this->app->make('regex-parser.analysis');
        $this->assertInstanceOf(RegexAnalysisService::class, $analysis);
    }

    public function test_lint_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.lint'));

        /** @var RegexLintService $lint */
        $lint = $this->app->make('regex-parser.lint');
        $this->assertInstanceOf(RegexLintService::class, $lint);
    }

    public function test_formatter_registry_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.formatter-registry'));

        /** @var FormatterRegistry $registry */
        $registry = $this->app->make('regex-parser.formatter-registry');
        $this->assertInstanceOf(FormatterRegistry::class, $registry);
    }

    public function test_pattern_sources_are_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.pattern-sources'));

        /** @var RegexPatternSourceCollection $sources */
        $sources = $this->app->make('regex-parser.pattern-sources');
        $this->assertInstanceOf(RegexPatternSourceCollection::class, $sources);
    }

    public function test_route_extractor_is_registered(): void
    {
        $this->assertTrue($this->app->bound(LaravelRouteExtractor::class));

        /** @var LaravelRouteExtractor $extractor */
        $extractor = $this->app->make(LaravelRouteExtractor::class);
        $this->assertInstanceOf(LaravelRouteExtractor::class, $extractor);
        $this->assertSame('routes', $extractor->getName());
        $this->assertTrue($extractor->isSupported());
    }

    public function test_validation_extractor_is_registered(): void
    {
        $this->assertTrue($this->app->bound(ValidationRuleExtractor::class));

        /** @var ValidationRuleExtractor $extractor */
        $extractor = $this->app->make(ValidationRuleExtractor::class);
        $this->assertInstanceOf(ValidationRuleExtractor::class, $extractor);
        $this->assertSame('validators', $extractor->getName());
        $this->assertTrue($extractor->isSupported());
    }

    public function test_artisan_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('regex:lint', $commands);
        $this->assertArrayHasKey('regex:routes', $commands);
        $this->assertArrayHasKey('regex:explain', $commands);
        $this->assertArrayHasKey('regex:compare', $commands);
        $this->assertArrayHasKey('regex:transpile', $commands);

        $this->assertInstanceOf(LintCommand::class, $commands['regex:lint']);
        $this->assertInstanceOf(RoutesCommand::class, $commands['regex:routes']);
        $this->assertInstanceOf(ExplainCommand::class, $commands['regex:explain']);
        $this->assertInstanceOf(CompareCommand::class, $commands['regex:compare']);
        $this->assertInstanceOf(TranspileCommand::class, $commands['regex:transpile']);
    }

    public function test_config_is_published(): void
    {
        $config = $this->app['config']->get('regex-parser');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('max_pattern_length', $config);
        $this->assertArrayHasKey('max_lookbehind_length', $config);
        $this->assertArrayHasKey('cache', $config);
        $this->assertArrayHasKey('redos', $config);
        $this->assertArrayHasKey('analysis', $config);
        $this->assertArrayHasKey('automata', $config);
        $this->assertArrayHasKey('optimizations', $config);
        $this->assertArrayHasKey('paths', $config);
        $this->assertArrayHasKey('exclude_paths', $config);
    }

    public function test_config_values_are_applied(): void
    {
        $this->app['config']->set('regex-parser.max_pattern_length', 5000);

        // Re-register the service with new config
        $this->app->forgetInstance(Regex::class);
        $this->app->forgetInstance('regex-parser.cache');

        /** @var Regex $regex */
        $regex = $this->app->make(Regex::class);

        // Create a pattern that's exactly 5000 characters (4998 chars + 2 delimiters)
        $longPattern = '/'.str_repeat('a', 4998).'/';

        // This should work (at limit)
        $ast = $regex->parse($longPattern);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public function test_service_provider_provides_list(): void
    {
        $provider = new RegexParserServiceProvider($this->app);
        $provides = $provider->provides();

        $this->assertContains(Regex::class, $provides);
        $this->assertContains('regex-parser', $provides);
        $this->assertContains('regex-parser.cache', $provides);
        $this->assertContains('regex-parser.analysis', $provides);
        $this->assertContains('regex-parser.lint', $provides);
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            RegexParserServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Regex' => RegexFacade::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $cacheDir = sys_get_temp_dir().'/regex_parser_laravel_'.uniqid();

        $app['config']->set('regex-parser.cache.directory', $cacheDir);
        $app['config']->set('regex-parser.cache.store', null);
        $app['config']->set('regex-parser.runtime_pcre_validation', false);
    }
}
