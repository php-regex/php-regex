<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use PhpParser\ParserFactory;
use PhpRegex\Laravel\Command\CompareCommand;
use PhpRegex\Laravel\Command\ExplainCommand;
use PhpRegex\Laravel\Command\LintCommand;
use PhpRegex\Laravel\Command\RoutesCommand;
use PhpRegex\Laravel\Command\TranspileCommand;
use PhpRegex\Laravel\Extractor\RoutePatternSource;
use PhpRegex\Laravel\Extractor\ValidationRulePatternSource;
use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\Extraction\ExtractorInterface;
use PhpRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Linter\Formatter\FormatterRegistry;
use PhpRegex\Linter\LintService;
use PhpRegex\Linter\PatternExtractor;
use PhpRegex\Linter\Source\PatternSourceCollection;
use PhpRegex\Linter\Source\PhpFilePatternSource;
use PhpRegex\Parser\Cache\CacheInterface;
use PhpRegex\Parser\Cache\FilesystemCache;
use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Toolkit\Regex;

/**
 * Laravel Service Provider for the PhpRegex library.
 */
final class PhpRegexServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigWithNestedDefaults();

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
                $this->configPath() => config_path('php-regex.php'),
            ], 'php-regex-config');

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
            'php-regex',
            'php-regex.cache',
            'php-regex.extractor',
            'php-regex.analysis',
            'php-regex.lint',
            'php-regex.formatter-registry',
            'php-regex.pattern-sources',
            RoutePatternSource::class,
            ValidationRulePatternSource::class,
        ];
    }

    /**
     * The config file values under the package defaults, section by
     * section: a config published by an older release lacks the keys added
     * since, which Laravel's mergeConfigFrom() only fills at the top level.
     * A list (paths, exclude, ignored_patterns) is replaced wholesale.
     *
     * @internal
     *
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    public static function withDefaults(array $defaults, array $config): array
    {
        foreach ($defaults as $key => $default) {
            if (!\array_key_exists($key, $config)) {
                $config[$key] = $default;

                continue;
            }

            if (\is_array($default) && !array_is_list($default) && \is_array($config[$key])) {
                $config[$key] = self::withDefaults($default, $config[$key]);
            }
        }

        return $config;
    }

    /**
     * What Regex::create() takes from config/php-regex.php, the target
     * and the runtime validation left out: the settings patterns are read
     * with, by the Regex service and by regex:lint alike.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public static function regexOptions(Application $app): array
    {
        /** @var array{max_pattern_length: int, max_lookbehind_length: int, redos: array{ignored_patterns: array<string>}} $config */
        $config = $app['config']['php-regex'];

        return [
            'max_pattern_length' => $config['max_pattern_length'],
            'max_lookbehind_length' => $config['max_lookbehind_length'],
            'cache' => $app->make('php-regex.cache'),
            'redos_ignored_patterns' => $config['redos']['ignored_patterns'],
        ];
    }

    private function configPath(): string
    {
        return __DIR__.'/config/php-regex.php';
    }

    private function mergeConfigWithNestedDefaults(): void
    {
        // A cached configuration was merged when it was cached.
        if (!($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            /** @var array<string, mixed> $defaults */
            $defaults = require $this->configPath();
            $current = $config->get('php-regex', []);

            $config->set('php-regex', self::withDefaults($defaults, \is_array($current) ? $current : []));

            // 1.x published config/regex-parser.php: Laravel still loads it,
            // nothing reads it any more.
            if ($config->has('regex-parser')) {
                @trigger_error('config/regex-parser.php is no longer read since 2.0: move its settings to config/php-regex.php (php artisan vendor:publish --tag=php-regex-config).', \E_USER_DEPRECATED);
            }
        }
    }

    private function registerCache(): void
    {
        $this->app->singleton('php-regex.cache', static function (Application $app): CacheInterface {
            /** @var array{cache: array{store: string|null, directory: string|null, prefix: string}} $config */
            $config = $app['config']['php-regex'];
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
        $this->app->singleton('php-regex.extractor.strategy', static function (): ExtractorInterface {
            // Prefer PhpParser-based extraction when available
            if (class_exists(ParserFactory::class)) {
                return new PhpParserExtractionStrategy();
            }

            // Fallback to token-based extractor
            return new TokenBasedExtractionStrategy();
        });

        $this->app->singleton('php-regex.extractor', static function (Application $app): PatternExtractor {
            /** @var \PhpRegex\Linter\Extraction\ExtractorInterface $strategy */
            $strategy = $app->make('php-regex.extractor.strategy');

            return new PatternExtractor($strategy);
        });

        $this->app->alias('php-regex.extractor.strategy', ExtractorInterface::class);
    }

    private function registerRegex(): void
    {
        // The application's own service: it judges for the running PHP,
        // whatever php_version / pcre_version say (they drive regex:lint).
        $this->app->singleton(Regex::class, static fn (Application $app): Regex => Regex::create(self::regexOptions($app) + [
            'runtime_pcre_validation' => true === $app['config']['php-regex.runtime_pcre_validation'],
        ]));

        $this->app->alias(Regex::class, 'php-regex');
    }

    private function registerAnalysisServices(): void
    {
        // Resolved when first used, never while the application boots: an
        // unknown ReDoS threshold stops the command that analyses, not every
        // artisan command.
        $this->app->singleton('php-regex.analysis', static function (Application $app): AnalysisService {
            /** @var array{redos: array{enabled: bool, threshold: mixed, ignored_patterns: array<string>}, analysis: array{warning_threshold: int}} $config */
            $config = $app['config']['php-regex'];
            /** @var \PhpRegex\Toolkit\Regex $regex */
            $regex = $app->make(Regex::class);
            /** @var \PhpRegex\Linter\PatternExtractor $extractor */
            $extractor = $app->make('php-regex.extractor');
            $threshold = $config['redos']['threshold'];

            return new AnalysisService(
                $regex->parser(),
                $extractor,
                warningThreshold: $config['analysis']['warning_threshold'],
                redosThreshold: \is_string($threshold) ? $threshold : throw new InvalidRegexOptionException(\sprintf('The ReDoS threshold must be low, medium, high or critical, not a %s.', get_debug_type($threshold))),
                redosIgnoredPatterns: $config['redos']['ignored_patterns'],
                redosEnabled: $config['redos']['enabled'],
            );
        });

        $this->app->alias('php-regex.analysis', AnalysisService::class);

        $this->app->singleton('php-regex.formatter-registry', static fn (): FormatterRegistry => new FormatterRegistry());
        $this->app->alias('php-regex.formatter-registry', FormatterRegistry::class);
    }

    private function registerPatternSources(): void
    {
        $this->app->singleton(RoutePatternSource::class, static function (Application $app): RoutePatternSource {
            /** @var \Illuminate\Routing\Router $router */
            $router = $app->make('router');

            return new RoutePatternSource($router);
        });

        $this->app->singleton(ValidationRulePatternSource::class, static fn (): ValidationRulePatternSource => new ValidationRulePatternSource());

        $this->app->singleton('php-regex.pattern-sources', static function (Application $app): PatternSourceCollection {
            /** @var \PhpRegex\Linter\PatternExtractor $extractor */
            $extractor = $app->make('php-regex.extractor');

            return new PatternSourceCollection([
                new PhpFilePatternSource($extractor),
                $app->make(RoutePatternSource::class),
                $app->make(ValidationRulePatternSource::class),
            ]);
        });

        $this->app->alias('php-regex.pattern-sources', PatternSourceCollection::class);
    }

    private function registerLintService(): void
    {
        $this->app->singleton('php-regex.lint', static function (Application $app): LintService {
            /** @var \PhpRegex\Linter\AnalysisService $analysis */
            $analysis = $app->make('php-regex.analysis');
            /** @var \PhpRegex\Linter\Source\PatternSourceCollection $sources */
            $sources = $app->make('php-regex.pattern-sources');

            return new LintService($analysis, $sources);
        });

        $this->app->alias('php-regex.lint', LintService::class);
    }
}
