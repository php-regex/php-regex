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

namespace PHPRegex\Symfony\DependencyInjection;

use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the configuration schema for the PHPRegex bundle.
 *
 * @internal
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * @return TreeBuilder<'array'> the tree builder instance
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('php_regex');

        $treeBuilder->getRootNode()
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
                        ->always(static fn (mixed $version): string|int|null => self::targetVersion('php_version', $version))
                    ->end()
                ->end()
                ->scalarNode('pcre_version')
                    ->defaultNull()
                    ->info('The PCRE2 release regex:lint judges patterns for ("10.42"). Unset: the release php_version bundles.')
                    ->validate()
                        ->always(static fn (mixed $release): string|int|null => self::targetVersion('pcre_version', $release))
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
                            ->info('Libraries whose calls carry a regex pattern, by package slug ('.implode(', ', InteropPresets::names()).'). Every preset is on by default, and ~ says the same; a list reads exactly the presets it names, [] the native calls only, and a later config file\'s list replaces it. An unknown name is ignored, with a warning when regex:lint runs.')
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
                            ->info('Optimize word character classes (e.g., [A-Za-z0-9_] -> \\w).')
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
                ->scalarNode('ide')
                    ->defaultValue('%env(default::SYMFONY_IDE)%')
                    ->info('IDE shorthand (vscode, phpstorm, etc.) or custom URL template for clickable links (e.g., phpstorm://open?file=%%file%%&line=%%line%%&column=%%column%%). Falls back to framework.ide.')
                ->end()
            ->end();

        return $treeBuilder;
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
     * The version as given, once Regex::create() could read it.
     *
     * @throws InvalidRegexOptionException when it names no version
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
