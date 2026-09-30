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

namespace RegexParser\Bridge\Symfony\DependencyInjection;

use PhpParser\ParserFactory;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Cache\NullCache;
use RegexParser\Cache\PsrCacheAdapter;
use RegexParser\Lint\Extraction\ExtractorInterface;
use RegexParser\Lint\Extraction\PhpParserExtractionStrategy;
use RegexParser\Lint\Extraction\TokenBasedExtractionStrategy;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Loads and manages configuration for the RegexParser bundle.
 *
 * @internal
 */
final class RegexParserExtension extends Extension
{
    /**
     * @param array<array<string, mixed>> $configs   an array of configuration values from the application's config files
     * @param ContainerBuilder            $container the DI container builder instance
     *
     * @throws \Exception if the service definition files cannot be loaded
     */
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        /**
         * @var array{
         *     max_pattern_length: int,
         *     max_lookbehind_length: int,
         *     runtime_pcre_validation: bool,
         *     php_version: string|int|null,
         *     pcre_version: string|null,
         *     cache: array{
         *         pool: string|null,
         *         directory: string|null,
         *         prefix: string,
         *     },
         *     extractor_service: string|null,
         *     redos: array{
         *         enabled: bool,
         *         threshold: string,
         *         ignored_patterns: array<int, string>,
         *     },
         *     analysis: array{
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
         *     ide: string|null,
         * } $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        $ignoredPatterns = array_values(array_unique($config['redos']['ignored_patterns']));
        $editorFormat = $this->resolveEditorFormat($config, $container);

        // Set parameters
        $container->setParameter('regex_parser.max_pattern_length', $config['max_pattern_length']);
        $container->setParameter('regex_parser.max_lookbehind_length', $config['max_lookbehind_length']);
        $container->setParameter('regex_parser.runtime_pcre_validation', $config['runtime_pcre_validation']);
        $container->setParameter('regex_parser.php_version', $config['php_version']);
        $container->setParameter('regex_parser.pcre_version', $config['pcre_version']);
        // Where regex:lint reads composer.json; never the working directory.
        $container->setParameter('regex_parser.project_dir', $container->hasParameter('kernel.project_dir') ? '%kernel.project_dir%' : null);
        $container->setParameter('regex_parser.cache', $config['cache']);
        $container->setParameter('regex_parser.extractor_service', $config['extractor_service']);
        $container->setParameter('regex_parser.redos.enabled', $config['redos']['enabled']);
        $container->setParameter('regex_parser.redos.threshold', $config['redos']['threshold']);
        $container->setParameter('regex_parser.redos.ignored_patterns', $ignoredPatterns);
        $container->setParameter('regex_parser.analysis.warning_threshold', $config['analysis']['warning_threshold']);
        $container->setParameter('regex_parser.automata.minimization_algorithm', $config['automata']['minimization_algorithm']);
        $container->setParameter('regex_parser.automata.determinization_algorithm', $config['automata']['determinization_algorithm']);
        $container->setParameter('regex_parser.optimizations', [
            'digits' => $config['optimizations']['digits'],
            'word' => $config['optimizations']['word'],
            'ranges' => $config['optimizations']['ranges'],
            'canonicalize_char_classes' => $config['optimizations']['canonicalize_char_classes'],
            'possessive' => $config['optimizations']['possessive'],
            'factorize' => $config['optimizations']['factorize'],
            'min_quantifier_count' => $config['optimizations']['min_quantifier_count'],
        ]);
        $container->setParameter('regex_parser.paths', $config['paths']);
        $container->setParameter('regex_parser.exclude', $config['exclude']);
        $container->setParameter('regex_parser.editor_format', $editorFormat);

        $container->setDefinition('regex_parser.cache', $this->buildCacheDefinition($config));

        // Configure extractor service or default implementation.
        $extractorService = $config['extractor_service'];
        if ($this->isNotNullOrEmpty($extractorService)) {
            if (\is_string($extractorService)) {
                $container->setAlias(ExtractorInterface::class, $extractorService);
                $container->setAlias('regex_parser.extractor.instance', $extractorService);
            }
        } else {
            // Determine and register appropriate extractor.
            $extractorDefinition = $this->createExtractorDefinition();
            $container->setDefinition('regex_parser.extractor.instance', $extractorDefinition);
            $container->setAlias(ExtractorInterface::class, 'regex_parser.extractor.instance');
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');
    }

    /**
     * @return string the configuration alias
     */
    #[\Override]
    public function getAlias(): string
    {
        return 'regex_parser';
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
     * Create the appropriate extractor definition based on availability.
     */
    private function createExtractorDefinition(): Definition
    {
        // Prefer PhpParser-based extraction when available.
        if ($this->isPhpParserAvailable()) {
            return new Definition(PhpParserExtractionStrategy::class);
        }

        // Fallback to token-based extractor
        return new Definition(TokenBasedExtractionStrategy::class);
    }

    /**
     * Resolve the editor format from config and container parameters.
     *
     * @param array{
     *     ide: string|null,
     *     ...
     * } $config
     */
    private function resolveEditorFormat(array $config, ContainerBuilder $container): ?string
    {
        $editorFormat = $config['ide'];

        // Fallback to framework.ide if regex_parser.ide is not set
        if (!$this->isNotNullOrEmpty($editorFormat) && $container->hasParameter('framework.ide')) {
            $frameworkIde = $container->getParameter('framework.ide');
            if (\is_string($frameworkIde) && '' !== $frameworkIde) {
                $editorFormat = $frameworkIde;
            }
        }

        return \is_string($editorFormat) && '' !== $editorFormat ? $editorFormat : null;
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
}
