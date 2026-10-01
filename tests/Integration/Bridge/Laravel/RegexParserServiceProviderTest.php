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

namespace PhpRegex\Tests\Integration\Bridge\Laravel;

use PhpRegex\Toolkit\Regex;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Cache\CacheInterface;
use PhpRegex\Parser\Cache\FilesystemCache;
use PhpRegex\Parser\PcreTarget;
use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\LintService;
use PhpRegex\Linter\Formatter\FormatterRegistry;
use PhpRegex\Linter\Source\PatternSourceCollection;
use PhpRegex\Laravel\Extractor\RoutePatternSource;
use PhpRegex\Laravel\Extractor\ValidationRulePatternSource;
use PhpRegex\Laravel\Command\LintCommand;
use PhpRegex\Laravel\Command\RoutesCommand;
use PhpRegex\Laravel\Command\ExplainCommand;
use PhpRegex\Laravel\Command\CompareCommand;
use PhpRegex\Laravel\Command\TranspileCommand;
use PhpRegex\Laravel\PhpRegexServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

/**
 * Integration tests for the Laravel bridge.
 */
final class RegexParserServiceProviderTest extends TestCase
{
    public function test_regex_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound(Regex::class));
        $this->assertTrue($this->app->bound('regex-parser'));

        /** @var \PhpRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);
        $this->assertInstanceOf(Regex::class, $regex);
    }

    public function test_regex_service_parses_patterns(): void
    {
        /** @var \PhpRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);

        $ast = $regex->parse('/^hello$/');
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public function test_facade_works(): void
    {
        $ast = \PhpRegex\Laravel\Facades\Regex::parse('/[a-z]+/');
        $this->assertInstanceOf(RegexNode::class, $ast);

        $validation = \PhpRegex\Laravel\Facades\Regex::validate('/^test$/');
        $this->assertTrue($validation->isValid);

        $validation = \PhpRegex\Laravel\Facades\Regex::validate('/^(unclosed/');
        $this->assertFalse($validation->isValid);
    }

    public function test_cache_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.cache'));

        /** @var \PhpRegex\Parser\Cache\CacheInterface $cache */
        $cache = $this->app->make('regex-parser.cache');
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    public function test_filesystem_cache_works(): void
    {
        /** @var string $cacheDir */
        $cacheDir = $this->app['config']->get('regex-parser.cache.directory');

        /** @var \PhpRegex\Toolkit\Regex $regex */
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

        /** @var \PhpRegex\Linter\AnalysisService $analysis */
        $analysis = $this->app->make('regex-parser.analysis');
        $this->assertInstanceOf(AnalysisService::class, $analysis);
    }

    public function test_lint_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.lint'));

        /** @var \PhpRegex\Linter\LintService $lint */
        $lint = $this->app->make('regex-parser.lint');
        $this->assertInstanceOf(LintService::class, $lint);
    }

    public function test_formatter_registry_is_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.formatter-registry'));

        /** @var \PhpRegex\Linter\Formatter\FormatterRegistry $registry */
        $registry = $this->app->make('regex-parser.formatter-registry');
        $this->assertInstanceOf(FormatterRegistry::class, $registry);
    }

    public function test_pattern_sources_are_registered(): void
    {
        $this->assertTrue($this->app->bound('regex-parser.pattern-sources'));

        /** @var \PhpRegex\Linter\Source\PatternSourceCollection $sources */
        $sources = $this->app->make('regex-parser.pattern-sources');
        $this->assertInstanceOf(PatternSourceCollection::class, $sources);
    }

    public function test_route_extractor_is_registered(): void
    {
        $this->assertTrue($this->app->bound(RoutePatternSource::class));

        /** @var \PhpRegex\Laravel\Extractor\RoutePatternSource $extractor */
        $extractor = $this->app->make(RoutePatternSource::class);
        $this->assertInstanceOf(RoutePatternSource::class, $extractor);
        $this->assertSame('routes', $extractor->getName());
        $this->assertTrue($extractor->isSupported());
    }

    public function test_validation_extractor_is_registered(): void
    {
        $this->assertTrue($this->app->bound(ValidationRulePatternSource::class));

        /** @var \PhpRegex\Laravel\Extractor\ValidationRulePatternSource $extractor */
        $extractor = $this->app->make(ValidationRulePatternSource::class);
        $this->assertInstanceOf(ValidationRulePatternSource::class, $extractor);
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
        $this->assertArrayHasKey('exclude', $config);
    }

    /**
     * A Laravel cache store is a PSR-16 cache: parsed trees go to it.
     */
    public function test_a_laravel_cache_store_holds_the_trees(): void
    {
        $this->app['config']->set('regex-parser.cache.store', 'array');
        $this->app->forgetInstance(Regex::class);
        $this->app->forgetInstance('regex-parser.cache');

        /** @var \PhpRegex\Parser\Cache\CacheInterface $cache */
        $cache = $this->app->make('regex-parser.cache');
        /** @var \PhpRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);
        $tree = $regex->parse('/a{2,3}/');

        $this->assertEquals($tree, $cache->load($cache->generateKey(Regex::cacheSeed('/a{2,3}/', $regex->target(), Regex::DEFAULT_MAX_RECURSION_DEPTH))));
    }

    public function test_config_values_are_applied(): void
    {
        $this->app['config']->set('regex-parser.max_pattern_length', 5000);

        // Re-register the service with new config
        $this->app->forgetInstance(Regex::class);
        $this->app->forgetInstance('regex-parser.cache');

        /** @var \PhpRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);

        // Create a pattern that's exactly 5000 characters (4998 chars + 2 delimiters)
        $longPattern = '/'.str_repeat('a', 4998).'/';

        // This should work (at limit)
        $ast = $regex->parse($longPattern);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public function test_service_provider_provides_list(): void
    {
        $provider = new PhpRegexServiceProvider($this->app);
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
            PhpRegexServiceProvider::class,
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
            'Regex' => \PhpRegex\Laravel\Facades\Regex::class,
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
