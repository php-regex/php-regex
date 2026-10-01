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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Laravel\Extractor\RoutePatternSource;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Laravel\Command\LintCommand;
use PHPRegex\Laravel\Command\RoutesCommand;
use PHPRegex\Laravel\Command\ExplainCommand;
use PHPRegex\Laravel\Command\CompareCommand;
use PHPRegex\Laravel\Command\TranspileCommand;
use PHPRegex\Laravel\PHPRegexServiceProvider;
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
        $this->assertTrue($this->app->bound('php-regex'));

        /** @var \PHPRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);
        $this->assertInstanceOf(Regex::class, $regex);
    }

    public function test_regex_service_parses_patterns(): void
    {
        /** @var \PHPRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);

        $ast = $regex->parse('/^hello$/');
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public function test_facade_works(): void
    {
        $ast = \PHPRegex\Laravel\Facades\Regex::parse('/[a-z]+/');
        $this->assertInstanceOf(RegexNode::class, $ast);

        $validation = \PHPRegex\Laravel\Facades\Regex::validate('/^test$/');
        $this->assertTrue($validation->isValid);

        $validation = \PHPRegex\Laravel\Facades\Regex::validate('/^(unclosed/');
        $this->assertFalse($validation->isValid);
    }

    public function test_cache_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('php-regex.cache'));

        /** @var \PHPRegex\Parser\Cache\CacheInterface $cache */
        $cache = $this->app->make('php-regex.cache');
        $this->assertInstanceOf(CacheInterface::class, $cache);
    }

    public function test_filesystem_cache_works(): void
    {
        /** @var string $cacheDir */
        $cacheDir = $this->app['config']->get('php-regex.cache.directory');

        /** @var \PHPRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);

        // Parse a pattern to populate the cache
        $regex->parse('/abc/');

        $cache = new FilesystemCache($cacheDir);
        $cacheFile = $cache->generateKey(RegexParser::cacheSeed('/abc/', PcreTarget::runtime(), 1024));

        $this->assertFileExists($cacheFile);

        // Clean up
        $cache->clear();
    }

    public function test_analysis_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('php-regex.analysis'));

        /** @var \PHPRegex\Linter\AnalysisService $analysis */
        $analysis = $this->app->make('php-regex.analysis');
        $this->assertInstanceOf(AnalysisService::class, $analysis);
    }

    public function test_lint_service_is_registered(): void
    {
        $this->assertTrue($this->app->bound('php-regex.lint'));

        /** @var \PHPRegex\Linter\LintService $lint */
        $lint = $this->app->make('php-regex.lint');
        $this->assertInstanceOf(LintService::class, $lint);
    }

    public function test_formatter_registry_is_registered(): void
    {
        $this->assertTrue($this->app->bound('php-regex.formatter-registry'));

        /** @var \PHPRegex\Linter\Formatter\FormatterRegistry $registry */
        $registry = $this->app->make('php-regex.formatter-registry');
        $this->assertInstanceOf(FormatterRegistry::class, $registry);
    }

    public function test_pattern_sources_are_registered(): void
    {
        $this->assertTrue($this->app->bound('php-regex.pattern-sources'));

        /** @var \PHPRegex\Linter\Source\PatternSourceCollection $sources */
        $sources = $this->app->make('php-regex.pattern-sources');
        $this->assertInstanceOf(PatternSourceCollection::class, $sources);
    }

    public function test_route_extractor_is_registered(): void
    {
        $this->assertTrue($this->app->bound(RoutePatternSource::class));

        /** @var \PHPRegex\Laravel\Extractor\RoutePatternSource $extractor */
        $extractor = $this->app->make(RoutePatternSource::class);
        $this->assertInstanceOf(RoutePatternSource::class, $extractor);
        $this->assertSame('routes', $extractor->getName());
        $this->assertTrue($extractor->isSupported());
    }

    public function test_validation_extractor_is_registered(): void
    {
        $this->assertTrue($this->app->bound(ValidationRulePatternSource::class));

        /** @var \PHPRegex\Laravel\Extractor\ValidationRulePatternSource $extractor */
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
        $config = $this->app['config']->get('php-regex');

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
        $this->app['config']->set('php-regex.cache.store', 'array');
        $this->app->forgetInstance(Regex::class);
        $this->app->forgetInstance('php-regex.cache');

        /** @var \PHPRegex\Parser\Cache\CacheInterface $cache */
        $cache = $this->app->make('php-regex.cache');
        /** @var \PHPRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);
        $tree = $regex->parse('/a{2,3}/');

        $this->assertEquals($tree, $cache->load($cache->generateKey(RegexParser::cacheSeed('/a{2,3}/', $regex->target(), Regex::DEFAULT_MAX_RECURSION_DEPTH))));
    }

    public function test_config_values_are_applied(): void
    {
        $this->app['config']->set('php-regex.max_pattern_length', 5000);

        // Re-register the service with new config
        $this->app->forgetInstance(Regex::class);
        $this->app->forgetInstance('php-regex.cache');

        /** @var \PHPRegex\Toolkit\Regex $regex */
        $regex = $this->app->make(Regex::class);

        // Create a pattern that's exactly 5000 characters (4998 chars + 2 delimiters)
        $longPattern = '/'.str_repeat('a', 4998).'/';

        // This should work (at limit)
        $ast = $regex->parse($longPattern);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public function test_service_provider_provides_list(): void
    {
        $provider = new PHPRegexServiceProvider($this->app);
        $provides = $provider->provides();

        $this->assertContains(Regex::class, $provides);
        $this->assertContains('php-regex', $provides);
        $this->assertContains('php-regex.cache', $provides);
        $this->assertContains('php-regex.analysis', $provides);
        $this->assertContains('php-regex.lint', $provides);
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
            PHPRegexServiceProvider::class,
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
            'Regex' => \PHPRegex\Laravel\Facades\Regex::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $cacheDir = sys_get_temp_dir().'/php_regex_laravel_'.uniqid();

        $app['config']->set('php-regex.cache.directory', $cacheDir);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }
}
