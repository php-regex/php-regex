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

namespace PHPRegex\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use PhpParser\ParserFactory;
use PHPRegex\Laravel\Command\CompareCommand;
use PHPRegex\Laravel\Command\ExplainCommand;
use PHPRegex\Laravel\Command\LintCommand;
use PHPRegex\Laravel\Command\RoutesCommand;
use PHPRegex\Laravel\Command\TranspileCommand;
use PHPRegex\Laravel\Extractor\RoutePatternSource;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Cache\PsrSimpleCacheAdapter;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Toolkit\Regex;

/**
 * Laravel Service Provider for the PHPRegex library.
 *
 * @phpstan-import-type OptionsArray from ParserOptions
 */
final class PHPRegexServiceProvider extends ServiceProvider
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
     * @return list<string>
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
     * @return OptionsArray
     */
    public static function regexOptions(Application $app): array
    {
        /** @var array{max_pattern_length: int, max_lookbehind_length: int, redos: array{ignored_patterns: array<string>}} $config */
        $config = self::config($app)->get('php-regex');
        /** @var CacheInterface $cache */
        $cache = $app->make('php-regex.cache');

        return [
            'max_pattern_length' => $config['max_pattern_length'],
            'max_lookbehind_length' => $config['max_lookbehind_length'],
            'cache' => $cache,
            'redos_ignored_patterns' => $config['redos']['ignored_patterns'],
        ];
    }

    /**
     * The calls regex:lint reads, from php-regex.extraction: the presets
     * named (every one when "interop" is null or missing), the project's
     * functions, and one warning per entry that is no string or spec the
     * lint cannot read, left out. Read when the extractor is built, so a key
     * the application sets after the provider registers, or leaves out of a
     * published file, takes the default all the same.
     *
     * @internal
     *
     * @throws InvalidRegexOptionException on a name that is no preset: left out, it would leave a list that
     *                                     reads fewer calls than meant
     *
     * @return array{interop: list<string>, functions: list<string>, warnings: list<string>}
     */
    public static function extraction(Application $app): array
    {
        $extraction = self::config($app)->get('php-regex.extraction');
        $extraction = \is_array($extraction) ? $extraction : [];
        $interop = $extraction['interop'] ?? null;
        $functions = $extraction['functions'] ?? [];

        $presets = [];
        $warnings = [];
        foreach (null === $interop ? InteropPresets::DEFAULT_PRESETS : (array) $interop as $name) {
            if (!\is_string($name)) {
                $warnings[] = InteropPresets::notAStringWarning('interop', $name);

                continue;
            }

            if (!InteropPresets::exists($name)) {
                throw new InvalidRegexOptionException(InteropPresets::unknownError($name, 'php-regex.extraction.interop'));
            }

            $presets[] = $name;
        }

        $specs = [];
        foreach ((array) $functions as $spec) {
            if (!\is_string($spec)) {
                $warnings[] = InteropPresets::notAStringWarning('functions', $spec);
            } elseif (PatternFunctionRegistry::isSpec($spec)) {
                $specs[] = $spec;
            } else {
                $warnings[] = PatternFunctionRegistry::malformedSpecWarning($spec);
            }
        }

        return [
            'interop' => array_values(array_unique($presets)),
            'functions' => $specs,
            'warnings' => array_values(array_unique($warnings)),
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
            $config = self::config($this->app);
            /** @var array<string, mixed> $defaults */
            $defaults = require $this->configPath();
            $current = $config->get('php-regex', []);

            $config->set('php-regex', self::withDefaults($defaults, \is_array($current) ? $current : []));
        }
    }

    private function registerCache(): void
    {
        $this->app->singleton('php-regex.cache', static function (Application $app): CacheInterface {
            /** @var array{cache: array{store: string|null, directory: string|null, prefix: string}} $config */
            $config = self::config($app)->get('php-regex');
            $cacheConfig = $config['cache'];

            // Use Laravel cache store if specified
            if (null !== $cacheConfig['store'] && '' !== $cacheConfig['store']) {
                /** @var CacheFactory $cache */
                $cache = $app->make('cache');
                $store = $cache->store($cacheConfig['store']);

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
        $this->app->singleton('php-regex.extractor.strategy', static function (Application $app): ExtractorInterface {
            $extraction = self::extraction($app);
            $registry = PatternFunctionRegistry::create($extraction['interop'], $extraction['functions']);

            // Prefer PhpParser-based extraction when available
            if (class_exists(ParserFactory::class)) {
                return new PhpParserExtractionStrategy([], $registry);
            }

            // Without nikic/php-parser, the token-based extractor reads the
            // same calls.
            return new TokenBasedExtractionStrategy([], $registry);
        });

        $this->app->singleton('php-regex.extractor', static function (Application $app): PatternExtractor {
            /** @var \PHPRegex\Linter\Extraction\ExtractorInterface $strategy */
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
            'runtime_pcre_validation' => true === self::config($app)->get('php-regex.runtime_pcre_validation'),
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
            $config = self::config($app)->get('php-regex');
            /** @var \PHPRegex\Toolkit\Regex $regex */
            $regex = $app->make(Regex::class);
            /** @var \PHPRegex\Linter\PatternExtractor $extractor */
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
            /** @var \PHPRegex\Linter\PatternExtractor $extractor */
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
            /** @var \PHPRegex\Linter\AnalysisService $analysis */
            $analysis = $app->make('php-regex.analysis');
            /** @var \PHPRegex\Linter\Source\PatternSourceCollection $sources */
            $sources = $app->make('php-regex.pattern-sources');

            return new LintService($analysis, $sources);
        });

        $this->app->alias('php-regex.lint', LintService::class);
    }

    /**
     * The application's config, which the container holds untyped.
     */
    private static function config(Application $app): Repository
    {
        /** @var Repository $config */
        $config = $app->make('config');

        return $config;
    }
}
