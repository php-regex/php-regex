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

namespace RegexParser\Bridge\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PhpParser\ParserFactory;
use RegexParser\Bridge\Laravel\Command\CompareCommand;
use RegexParser\Bridge\Laravel\Command\ExplainCommand;
use RegexParser\Bridge\Laravel\Command\LintCommand;
use RegexParser\Bridge\Laravel\Command\RoutesCommand;
use RegexParser\Bridge\Laravel\Command\TranspileCommand;
use RegexParser\Bridge\Laravel\Extractor\LaravelRouteExtractor;
use RegexParser\Bridge\Laravel\Extractor\ValidationRuleExtractor;
use RegexParser\Cache\CacheInterface;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Cache\NullCache;
use RegexParser\Cache\PsrSimpleCacheAdapter;
use RegexParser\Lint\Extraction\ExtractorInterface;
use RegexParser\Lint\Formatter\FormatterRegistry;
use RegexParser\Lint\PhpRegexPatternSource;
use RegexParser\Lint\Extraction\PhpParserExtractionStrategy;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexLintService;
use RegexParser\Lint\RegexPatternExtractor;
use RegexParser\Lint\RegexPatternSourceCollection;
use RegexParser\Lint\Extraction\TokenBasedExtractionStrategy;
use RegexParser\Regex;

/**
 * Laravel Service Provider for the RegexParser library.
 */
final class RegexParserServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'regex-parser');

        $this->registerCache();
        $this->registerExtractor();
        $this->registerRegex();
        $this->registerAnalysisServices();
        $this->registerPatternSources();
        $this->registerLintService();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => config_path('regex-parser.php'),
            ], 'regex-parser-config');

            $this->commands([
                LintCommand::class,
                RoutesCommand::class,
                ExplainCommand::class,
                CompareCommand::class,
                TranspileCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<class-string>
     */
    public function provides(): array
    {
        return [
            Regex::class,
            'regex-parser',
            'regex-parser.cache',
            'regex-parser.extractor',
            'regex-parser.analysis',
            'regex-parser.lint',
            'regex-parser.formatter-registry',
            'regex-parser.pattern-sources',
            LaravelRouteExtractor::class,
            ValidationRuleExtractor::class,
        ];
    }

    private function configPath(): string
    {
        return __DIR__.'/config/regex-parser.php';
    }

    private function registerCache(): void
    {
        $this->app->singleton('regex-parser.cache', static function (Application $app): CacheInterface {
            /** @var array{cache: array{store: string|null, directory: string|null, prefix: string}} $config */
            $config = $app['config']['regex-parser'];
            $cacheConfig = $config['cache'];

            // Use Laravel cache store if specified
            if (null !== $cacheConfig['store'] && '' !== $cacheConfig['store']) {
                /** @var \Illuminate\Contracts\Cache\Repository $store */
                $store = $app['cache']->store($cacheConfig['store']);

                return new PsrSimpleCacheAdapter($store, $cacheConfig['prefix']);
            }

            // Use filesystem cache if directory is specified
            if (null !== $cacheConfig['directory'] && '' !== $cacheConfig['directory']) {
                $directory = str_replace(
                    ['{storage_path}', '{base_path}'],
                    [storage_path(), base_path()],
                    $cacheConfig['directory'],
                );

                return new FilesystemCache($directory);
            }

            return new NullCache();
        });
    }

    private function registerExtractor(): void
    {
        $this->app->singleton('regex-parser.extractor.strategy', static function (): ExtractorInterface {
            // Prefer PhpParser-based extraction when available
            if (class_exists(ParserFactory::class)) {
                return new PhpParserExtractionStrategy();
            }

            // Fallback to token-based extractor
            return new TokenBasedExtractionStrategy();
        });

        $this->app->singleton('regex-parser.extractor', static function (Application $app): RegexPatternExtractor {
            /** @var ExtractorInterface $strategy */
            $strategy = $app->make('regex-parser.extractor.strategy');

            return new RegexPatternExtractor($strategy);
        });

        $this->app->alias('regex-parser.extractor.strategy', ExtractorInterface::class);
    }

    private function registerRegex(): void
    {
        $this->app->singleton(Regex::class, static function (Application $app): Regex {
            /** @var array{max_pattern_length: int, max_lookbehind_length: int, runtime_pcre_validation: bool, redos: array{ignored_patterns: array<string>}} $config */
            $config = $app['config']['regex-parser'];
            /** @var CacheInterface $cache */
            $cache = $app->make('regex-parser.cache');

            return Regex::create([
                'max_pattern_length' => $config['max_pattern_length'],
                'max_lookbehind_length' => $config['max_lookbehind_length'],
                'cache' => $cache,
                'redos_ignored_patterns' => $config['redos']['ignored_patterns'],
                'runtime_pcre_validation' => $config['runtime_pcre_validation'],
            ]);
        });

        $this->app->alias(Regex::class, 'regex-parser');
    }

    private function registerAnalysisServices(): void
    {
        $this->app->singleton('regex-parser.analysis', static function (Application $app): RegexAnalysisService {
            /** @var array{redos: array{enabled: bool, threshold: string, ignored_patterns: array<string>}, analysis: array{warning_threshold: int, ignore_patterns: array<string>}} $config */
            $config = $app['config']['regex-parser'];
            /** @var Regex $regex */
            $regex = $app->make(Regex::class);
            /** @var RegexPatternExtractor $extractor */
            $extractor = $app->make('regex-parser.extractor');

            $ignoredPatterns = array_values(array_unique([
                ...$config['analysis']['ignore_patterns'],
                ...$config['redos']['ignored_patterns'],
            ]));

            return new RegexAnalysisService(
                $regex,
                $extractor,
                $config['analysis']['warning_threshold'],
                $config['redos']['threshold'],
                $ignoredPatterns,
                $config['redos']['ignored_patterns'],
                $config['redos']['enabled'],
            );
        });

        $this->app->alias('regex-parser.analysis', RegexAnalysisService::class);

        $this->app->singleton('regex-parser.formatter-registry', static fn (): FormatterRegistry => new FormatterRegistry());
        $this->app->alias('regex-parser.formatter-registry', FormatterRegistry::class);
    }

    private function registerPatternSources(): void
    {
        $this->app->singleton(LaravelRouteExtractor::class, static function (Application $app): LaravelRouteExtractor {
            /** @var \Illuminate\Routing\Router $router */
            $router = $app->make('router');

            return new LaravelRouteExtractor($router);
        });

        $this->app->singleton(ValidationRuleExtractor::class, static fn (): ValidationRuleExtractor => new ValidationRuleExtractor());

        $this->app->singleton('regex-parser.pattern-sources', static function (Application $app): RegexPatternSourceCollection {
            /** @var RegexPatternExtractor $extractor */
            $extractor = $app->make('regex-parser.extractor');

            return new RegexPatternSourceCollection([
                new PhpRegexPatternSource($extractor),
                $app->make(LaravelRouteExtractor::class),
                $app->make(ValidationRuleExtractor::class),
            ]);
        });

        $this->app->alias('regex-parser.pattern-sources', RegexPatternSourceCollection::class);
    }

    private function registerLintService(): void
    {
        $this->app->singleton('regex-parser.lint', static function (Application $app): RegexLintService {
            /** @var RegexAnalysisService $analysis */
            $analysis = $app->make('regex-parser.analysis');
            /** @var RegexPatternSourceCollection $sources */
            $sources = $app->make('regex-parser.pattern-sources');

            return new RegexLintService($analysis, $sources);
        });

        $this->app->alias('regex-parser.lint', RegexLintService::class);
    }
}
