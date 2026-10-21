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

namespace PHPRegex\Symfony;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Cache\PsrCacheAdapter;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\DependencyInjection\EditorLinkPass;
use PHPRegex\Symfony\DependencyInjection\ValidatorMappingPass;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The PHPRegex bundle: its configuration under php_regex, the Regex
 * service and the regex:* commands.
 */
final class PHPRegexBundle extends AbstractBundle
{
    protected string $extensionAlias = 'php_regex';

    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        if (!$root instanceof ArrayNodeDefinition) {
            return;
        }

        // The target settings, each read as Regex::create() reads it.
        $target = static fn (string $key): \Closure => static fn (mixed $value): string|int|null => self::targetVersion($key, $value);

        $root
            ->children()
                ->integerNode('max_pattern_length')
                    ->defaultValue(Regex::DEFAULT_MAX_PATTERN_LENGTH)
                    ->info('The maximum allowed length for a regex pattern string to parse.')
                ->end()
                ->integerNode('max_lookbehind_length')
                    ->defaultValue(Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH)
                    ->min(0)
                    ->info('The maximum length of a variable-length lookbehind; a fixed-length one is only limited by PCRE\'s 65535.')
                ->end()
                ->booleanNode('runtime_pcre_validation')
                    ->defaultFalse()
                    ->info('Whether the php_regex.regex service also compiles every pattern with the running PHP (preg_match compile check). regex:lint never does: it judges for php_version / pcre_version.')
                ->end()
                ->scalarNode('php_version')
                    ->defaultNull()
                    ->info('The PHP version regex:lint judges patterns for ("8.2", "8.2.4" or 80200). Unset: the lowest PHP composer.json allows, else the running PHP. The php_regex.regex service always judges for the running PHP.')
                    ->validate()
                        ->always($target('php_version'))
                    ->end()
                ->end()
                ->scalarNode('pcre_version')
                    ->defaultNull()
                    ->info('The PCRE2 release regex:lint judges patterns for ("10.42"). Unset: the release php_version bundles.')
                    ->validate()
                        ->always($target('pcre_version'))
                    ->end()
                ->end()
                ->scalarNode('locale')
                    ->defaultNull()
                    ->info('The LC_CTYPE locale regex:lint judges patterns under ("tr_TR.UTF-8"): its character tables decide the size of some classes. Unset: the locale of the PHP running the command. The php_regex.regex service always judges under the running PHP\'s locale.')
                    ->validate()
                        ->always($target('locale'))
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->info('Cache configuration for storing parsed regex patterns.')
                    ->children()
                        ->scalarNode('pool')
                            ->defaultNull()
                            ->info('Symfony cache pool service id (PSR-6). Takes precedence over "directory" when set.')
                        ->end()
                        ->scalarNode('directory')
                            ->defaultValue('%kernel.cache_dir%/php_regex')
                            ->info('Directory path for cached AST files. Set to null to disable caching.')
                        ->end()
                        ->scalarNode('prefix')
                            ->defaultValue('regex_')
                            ->info('Cache key prefix for PSR-6 cache pools.')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('extractor_service')
                    ->defaultNull()
                    ->info('Custom regex pattern extractor service ID. If not provided, PhpParser-based extraction will be tried first, then token-based extraction.')
                ->end()
                ->arrayNode('extraction')
                    ->addDefaultsIfNotSet()
                    ->info('Which calls regex:lint reads patterns from, beyond the native preg_* functions, RegexIterator and filter_var(): the shape of regex.json\'s "extraction", which the bundle does not read. Unused when extractor_service is set.')
                    ->children()
                        ->arrayNode('interop')
                            ->beforeNormalization()->ifString()->then(static fn (string $name): array => [$name])->end()
                            ->scalarPrototype()->end()
                            ->defaultValue(InteropPresets::DEFAULT_PRESETS)
                            ->treatNullLike(InteropPresets::DEFAULT_PRESETS)
                            ->performNoDeepMerging()
                            // A name that is no preset, left out, would leave a
                            // list that reads fewer calls than meant.
                            ->validate()
                                ->always(static function (array $names): array {
                                    foreach ($names as $name) {
                                        if (\is_string($name) && !InteropPresets::exists($name)) {
                                            throw new InvalidRegexOptionException(InteropPresets::unknownError($name));
                                        }
                                    }

                                    return $names;
                                })
                            ->end()
                            ->info('Libraries whose calls carry a regex pattern, by package slug ('.implode(', ', InteropPresets::names()).'). Every preset is on by default, and ~ says the same; a list reads exactly the presets it names, [] the native calls only, and a later config file\'s list replaces it. An unknown name is a configuration error, which names the preset it most likely means.')
                        ->end()
                        ->arrayNode('functions')
                            ->beforeNormalization()->ifString()->then(static fn (string $name): array => [$name])->end()
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->performNoDeepMerging()
                            ->info('Project helpers carrying a pattern, as "function" or "Some\\Class::method". Append "#<index>" when the pattern is not the first argument, and "#<index>:keys" when that argument is an array whose keys hold the patterns. A call may pass the pattern by name as pattern:, patterns: or regex:; append "$<name>" to read it under the helper\'s own parameter name instead, as in "App\\grep#1$expression". A later config file\'s list replaces it.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('redos')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable ReDoS vulnerability analysis. Disabled by default for performance; enable explicitly when needed.')
                        ->end()
                        ->scalarNode('threshold')
                            ->defaultValue('high')
                            ->info('Minimum ReDoS severity to report (low|medium|high|critical, in any case).')
                            ->validate()
                                ->always(static fn (mixed $value): string => self::redosThreshold($value))
                            ->end()
                        ->end()
                        ->arrayNode('ignored_patterns')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Patterns, fragments or full regexes to skip in the risk analysis (e.g. Symfony requirement constants).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('analysis')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('complexity')
                            ->defaultFalse()
                            ->info('Whether regex:lint reports the patterns whose complexity score reaches warning_threshold.')
                        ->end()
                        ->integerNode('warning_threshold')
                            ->defaultValue(50)
                            ->min(0)
                            ->info('Complexity score from which regex:lint reports a pattern, once "complexity" is on.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('automata')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('minimization_algorithm')
                            ->defaultValue('hopcroft')
                            ->info('DFA minimization strategy for automata comparisons (hopcroft|moore).')
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn (string $value): string => strtolower($value))
                            ->end()
                            ->validate()
                                ->ifNotInArray(['hopcroft', 'moore'])
                                ->thenInvalid('Invalid "php_regex.automata.minimization_algorithm" value "%s". Allowed: hopcroft, moore.')
                            ->end()
                        ->end()
                        ->scalarNode('determinization_algorithm')
                            ->defaultValue('subset-indexed')
                            ->info('NFA determinization strategy for automata comparisons (subset|subset-indexed).')
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn (string $value): string => strtolower($value))
                            ->end()
                            ->validate()
                                ->ifNotInArray(['subset', 'subset-indexed'])
                                ->thenInvalid('Invalid "php_regex.automata.determinization_algorithm" value "%s". Allowed: subset, subset-indexed.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('optimizations')
                    ->addDefaultsIfNotSet()
                    ->info('Default optimization options for regex:lint.')
                    ->children()
                        ->booleanNode('digits')
                            ->defaultTrue()
                            ->info('Optimize digit character classes (e.g., [0-9] -> \\d).')
                        ->end()
                        ->booleanNode('word')
                            ->defaultTrue()
                            ->info('Write [A-Za-z0-9_] as \\w where they match alike: never today, as \\w follows the locale without u and Unicode with it.')
                        ->end()
                        ->booleanNode('ranges')
                            ->defaultTrue()
                            ->info('Allow range merging inside character classes.')
                        ->end()
                        ->booleanNode('canonicalize_char_classes')
                            ->defaultTrue()
                            ->info('Normalize character class order and deduplicate elements.')
                        ->end()
                        ->booleanNode('possessive')
                            ->defaultFalse()
                            ->info('Enable auto-possessive quantifier optimizations.')
                        ->end()
                        ->booleanNode('factorize')
                            ->defaultFalse()
                            ->info('Enable alternation factorization optimizations.')
                        ->end()
                        ->integerNode('min_quantifier_count')
                            ->defaultValue(4)
                            ->min(2)
                            ->info('Minimum repeated quantifier count before collapsing (e.g., aaaa -> a{4}).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['src'])
                    ->info('Directories to scan for regex patterns. Defaults to src/ for Symfony applications.')
                ->end()
                ->arrayNode('exclude')
                    ->scalarPrototype()->end()
                    ->defaultValue(['vendor'])
                    ->info('Directories regex:lint does not scan. Defaults to vendor/.')
                ->end()
                ->booleanNode('report_unmatched_ignores')
                    ->defaultTrue()
                    ->info('Whether regex:lint reports as regex.ignore.unmatched each @regex-ignore marker, or issue identifier it names, that silences nothing.')
                ->end()
                ->scalarNode('ide')
                    ->defaultNull()
                    ->info('IDE shorthand (vscode, phpstorm, etc.) or custom URL template for clickable links (e.g., phpstorm://open?file=%%file%%&line=%%line%%&column=%%column%%). Unset: the editor FrameworkBundle links to, framework.ide, which defaults to the SYMFONY_IDE environment variable.')
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     max_pattern_length: int,
     *     max_lookbehind_length: int,
     *     runtime_pcre_validation: bool,
     *     php_version: string|int|null,
     *     pcre_version: string|null,
     *     locale: string|null,
     *     cache: array{
     *         pool: string|null,
     *         directory: string|null,
     *         prefix: string,
     *     },
     *     extractor_service: string|null,
     *     extraction: array{
     *         interop: array<int, mixed>,
     *         functions: array<int, mixed>,
     *     },
     *     redos: array{
     *         enabled: bool,
     *         threshold: string,
     *         ignored_patterns: array<int, string>,
     *     },
     *     analysis: array{
     *         complexity: bool,
     *         warning_threshold: int,
     *     },
     *     automata: array{
     *         minimization_algorithm: string,
     *         determinization_algorithm: string,
     *     },
     *     optimizations: array{
     *         digits: bool,
     *         word: bool,
     *         ranges: bool,
     *         canonicalize_char_classes: bool,
     *         possessive: bool,
     *         factorize: bool,
     *         min_quantifier_count: int,
     *     },
     *     paths: array<int, string>,
     *     exclude: array<int, string>,
     *     report_unmatched_ignores: bool,
     *     ide: string|null,
     * } $config the configuration, processed
     */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('Resources/config/services.php');

        // The configuration goes into the services that read it, never into
        // container parameters the application could read or override.
        $ignoredPatterns = array_values(array_unique($config['redos']['ignored_patterns']));
        $minimization = $config['automata']['minimization_algorithm'];
        $determinization = $config['automata']['determinization_algorithm'];
        $redosThreshold = $config['redos']['threshold'];
        // What patterns are read with, as Regex::create() takes it; the lint
        // judges for the project's target with these settings, but never with
        // the runtime validation, which only the running PHP can do.
        $readOptions = [
            'max_pattern_length' => $config['max_pattern_length'],
            'max_lookbehind_length' => $config['max_lookbehind_length'],
            'cache' => new Reference('php_regex.cache'),
            'redos_ignored_patterns' => $ignoredPatterns,
        ];

        $builder->setDefinition('php_regex.cache', $this->buildCacheDefinition($config));

        $builder->getDefinition('php_regex.regex')
            ->setArgument('$options', $readOptions + ['runtime_pcre_validation' => $config['runtime_pcre_validation']]);

        $builder->getDefinition('php_regex.service.regex_analysis')
            ->setArgument('$warningThreshold', $config['analysis']['warning_threshold'])
            ->setArgument('$redosThreshold', $redosThreshold)
            ->setArgument('$redosIgnoredPatterns', $ignoredPatterns)
            ->setArgument('$redosEnabled', $config['redos']['enabled']);

        // A custom extractor service reads its own calls, and
        // php_regex.extraction is unused.
        $extractorService = $config['extractor_service'];
        $warnings = [];
        if (null !== $extractorService && '' !== $extractorService) {
            $builder->setAlias('php_regex.extractor.instance', $extractorService);
        } else {
            $builder->setDefinition('php_regex.extractor.instance', $this->createExtractorDefinition($config['extraction']));
            $warnings = $this->extractionWarnings($config['extraction']);
        }

        $builder->getDefinition('php_regex.command.lint')
            ->setArgument('$defaultPaths', $config['paths'])
            ->setArgument('$defaultExcludePaths', $config['exclude'])
            ->setArgument('$defaultOptimizations', [
                'digits' => $config['optimizations']['digits'],
                'word' => $config['optimizations']['word'],
                'ranges' => $config['optimizations']['ranges'],
                'canonicalize_char_classes' => $config['optimizations']['canonicalize_char_classes'],
                'possessive' => $config['optimizations']['possessive'],
                'factorize' => $config['optimizations']['factorize'],
                'min_quantifier_count' => $config['optimizations']['min_quantifier_count'],
            ])
            // Without an ide of its own, EditorLinkPass links to the editor
            // FrameworkBundle links to.
            ->setArgument('$editorUrl', $this->isNotNullOrEmpty($config['ide']) ? $config['ide'] : null)
            ->setArgument('$regexOptions', $readOptions)
            ->setArgument('$phpVersion', $config['php_version'])
            ->setArgument('$pcreVersion', $config['pcre_version'])
            ->setArgument('$locale', $config['locale'])
            // Where regex:lint reads composer.json; never the working directory.
            ->setArgument('$projectDir', $builder->hasParameter('kernel.project_dir') ? '%kernel.project_dir%' : null)
            ->setArgument('$checkRedos', $config['redos']['enabled'])
            ->setArgument('$checkComplexity', $config['analysis']['complexity'])
            ->setArgument('$warnings', $warnings)
            ->setArgument('$reportUnmatchedIgnores', $config['report_unmatched_ignores']);

        $builder->getDefinition('php_regex.command.compare')
            ->setArgument('$defaultMinimizer', $minimization)
            ->setArgument('$defaultDeterminizer', $determinization);

        foreach (['php_regex.routing.conflict_analyzer', 'php_regex.security.access_control_analyzer'] as $analyzer) {
            $builder->getDefinition($analyzer)
                ->setArgument('$minimizationAlgorithm', $minimization)
                ->setArgument('$determinizationAlgorithm', $determinization);
        }

        foreach (['php_regex.command.security', 'php_regex.command.analyze'] as $command) {
            $builder->getDefinition($command)->setArgument('$defaultRedosThreshold', $redosThreshold);
        }
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        // After the passes of other bundles, which may give the validator
        // mapping files of their own.
        $container->addCompilerPass(new ValidatorMappingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1024);
        $container->addCompilerPass(new EditorLinkPass());
    }

    /**
     * The bundle's root directory, where Resources/ lies: the class is not
     * under a src/ directory of its own.
     */
    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    /**
     * @param array{
     *     cache: array{
     *         pool: string|null,
     *         directory: string|null,
     *         prefix: string,
     *     },
     *     ...
     * } $config
     */
    private function buildCacheDefinition(array $config): Definition
    {
        $cacheConfig = $config['cache'];

        if ($this->isNotNullOrEmpty($cacheConfig['pool'])) {
            return (new Definition(PsrCacheAdapter::class))
                ->setArguments([
                    new Reference((string) $cacheConfig['pool']),
                    (string) $cacheConfig['prefix'],
                ]);
        }

        if ($this->isNotNullOrEmpty($cacheConfig['directory'])) {
            return (new Definition(FilesystemCache::class))
                ->setArguments([(string) $cacheConfig['directory']]);
        }

        return new Definition(NullCache::class);
    }

    /**
     * Create the appropriate extractor definition based on availability,
     * reading the calls php_regex.extraction names: the presets it knows
     * and the project's own functions.
     *
     * @param array{interop: array<int, mixed>, functions: array<int, mixed>} $extraction
     */
    private function createExtractorDefinition(array $extraction): Definition
    {
        $registry = (new Definition(PatternFunctionRegistry::class))
            ->setFactory([PatternFunctionRegistry::class, 'create'])
            ->setArguments([
                array_values(array_filter($extraction['interop'], static fn (mixed $name): bool => \is_string($name) && InteropPresets::exists($name))),
                array_values(array_filter($extraction['functions'], \is_string(...))),
            ]);

        // Prefer PhpParser-based extraction when available.
        if ($this->isPhpParserAvailable()) {
            return new Definition(PhpParserExtractionStrategy::class, [[], $registry]);
        }

        // Fallback to token-based extractor
        return new Definition(TokenBasedExtractionStrategy::class, [[], $registry]);
    }

    /**
     * One warning per entry of either list of php_regex.extraction that is
     * no string, and per spec of "functions" the lint cannot read: regex:lint
     * prints them and reads the rest. A name that is no preset never gets
     * here: the configuration refuses it. A "%" is doubled, the container
     * reading parameters for placeholders.
     *
     * @param array{interop: array<int, mixed>, functions: array<int, mixed>} $extraction
     *
     * @return list<string>
     */
    private function extractionWarnings(array $extraction): array
    {
        $warnings = [];
        foreach ($extraction['interop'] as $name) {
            if (!\is_string($name)) {
                $warnings[] = InteropPresets::notAStringWarning('interop', $name);
            }
        }

        foreach ($extraction['functions'] as $spec) {
            if (!\is_string($spec)) {
                $warnings[] = InteropPresets::notAStringWarning('functions', $spec);
            } elseif (!PatternFunctionRegistry::isSpec($spec)) {
                $warnings[] = str_replace('%', '%%', PatternFunctionRegistry::malformedSpecWarning($spec));
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Check if PhpParser classes are available.
     */
    private function isPhpParserAvailable(): bool
    {
        return class_exists(ParserFactory::class);
    }

    /**
     * Check if a value is not null and not an empty string.
     */
    private function isNotNullOrEmpty(?string $value): bool
    {
        return null !== $value && '' !== $value;
    }

    /**
     * The threshold, lower-cased, read with the one threshold parser.
     *
     * @throws InvalidRegexOptionException when it names no threshold
     */
    private static function redosThreshold(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new InvalidRegexOptionException(\sprintf('The ReDoS threshold must be low, medium, high or critical, not a %s.', get_debug_type($value)));
        }

        return RedosSeverity::fromConfig($value)->value;
    }

    /**
     * The version or the locale as given, once Regex::create() could read
     * it.
     *
     * @throws InvalidRegexOptionException when it names no version, or a locale the machine cannot set
     */
    private static function targetVersion(string $key, mixed $version): string|int|null
    {
        if (null === $version) {
            return null;
        }

        if (!\is_string($version) && !\is_int($version)) {
            // YAML reads an unquoted 8.2 as a float, and 8.10 as 8.1.
            throw new InvalidRegexOptionException(\sprintf('"%s" must be a quoted version like "8.2", not a %s.', $key, get_debug_type($version)));
        }

        ParserOptions::fromArray([$key => $version]);

        return $version;
    }
}
