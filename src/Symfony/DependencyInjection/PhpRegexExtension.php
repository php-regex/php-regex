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

namespace PhpRegex\Symfony\DependencyInjection;

use PhpParser\ParserFactory;
use PhpRegex\Linter\Extraction\ExtractorInterface;
use PhpRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Parser\Cache\FilesystemCache;
use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Parser\Cache\PsrCacheAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Loads and manages configuration for the PhpRegex bundle.
 *
 * @internal
 */
final class PhpRegexExtension extends Extension
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
        $container->setParameter('php_regex.max_pattern_length', $config['max_pattern_length']);
        $container->setParameter('php_regex.max_lookbehind_length', $config['max_lookbehind_length']);
        $container->setParameter('php_regex.runtime_pcre_validation', $config['runtime_pcre_validation']);
        $container->setParameter('php_regex.php_version', $config['php_version']);
        $container->setParameter('php_regex.pcre_version', $config['pcre_version']);
        // Where regex:lint reads composer.json; never the working directory.
        $container->setParameter('php_regex.project_dir', $container->hasParameter('kernel.project_dir') ? '%kernel.project_dir%' : null);
        $container->setParameter('php_regex.cache', $config['cache']);
        $container->setParameter('php_regex.extractor_service', $config['extractor_service']);
        $container->setParameter('php_regex.redos.enabled', $config['redos']['enabled']);
        $container->setParameter('php_regex.redos.threshold', $config['redos']['threshold']);
        $container->setParameter('php_regex.redos.ignored_patterns', $ignoredPatterns);
        $container->setParameter('php_regex.analysis.warning_threshold', $config['analysis']['warning_threshold']);
        $container->setParameter('php_regex.automata.minimization_algorithm', $config['automata']['minimization_algorithm']);
        $container->setParameter('php_regex.automata.determinization_algorithm', $config['automata']['determinization_algorithm']);
        $container->setParameter('php_regex.optimizations', [
            'digits' => $config['optimizations']['digits'],
            'word' => $config['optimizations']['word'],
            'ranges' => $config['optimizations']['ranges'],
            'canonicalize_char_classes' => $config['optimizations']['canonicalize_char_classes'],
            'possessive' => $config['optimizations']['possessive'],
            'factorize' => $config['optimizations']['factorize'],
            'min_quantifier_count' => $config['optimizations']['min_quantifier_count'],
        ]);
        $container->setParameter('php_regex.paths', $config['paths']);
        $container->setParameter('php_regex.exclude', $config['exclude']);
        $container->setParameter('php_regex.editor_format', $editorFormat);

        $container->setDefinition('php_regex.cache', $this->buildCacheDefinition($config));

        // Configure extractor service or default implementation.
        $extractorService = $config['extractor_service'];
        if ($this->isNotNullOrEmpty($extractorService)) {
            if (\is_string($extractorService)) {
                $container->setAlias(ExtractorInterface::class, $extractorService);
                $container->setAlias('php_regex.extractor.instance', $extractorService);
            }
        } else {
            // Determine and register appropriate extractor.
            $extractorDefinition = $this->createExtractorDefinition();
            $container->setDefinition('php_regex.extractor.instance', $extractorDefinition);
            $container->setAlias(ExtractorInterface::class, 'php_regex.extractor.instance');
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
        return 'php_regex';
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

        // Fallback to framework.ide if php_regex.ide is not set
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
