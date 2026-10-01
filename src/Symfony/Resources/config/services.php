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

namespace PHPRegex\Symfony\Resources\config;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Analyzer\AnalyzerRegistry;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\RoutesAnalyzer;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Command\AnalyzeCommand;
use PHPRegex\Symfony\Command\CompareCommand;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Symfony\Command\RoutesCommand;
use PHPRegex\Symfony\Command\SecurityCommand;
use PHPRegex\Symfony\Command\TranspileCommand;
use PHPRegex\Symfony\Extractor\RoutePatternSource;
use PHPRegex\Symfony\Extractor\ValidatorPatternSource;
use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Routing\RouteConflictSuggestionBuilder;
use PHPRegex\Symfony\Routing\RouteControllerFileResolver;
use PHPRegex\Symfony\Routing\RouteRequirementNormalizer;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Symfony\Security\SecurityPatternNormalizer;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Base services for the PHPRegex library.
 *
 * These services are always loaded when the bundle is enabled.
 */
/* @internal */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private();

    $services->set('php_regex.regex', Regex::class)
        ->factory([Regex::class, 'create'])
        ->arg('$options', [
            'max_pattern_length' => param('php_regex.max_pattern_length'),
            'max_lookbehind_length' => param('php_regex.max_lookbehind_length'),
            'cache' => service('php_regex.cache'),
            'redos_ignored_patterns' => param('php_regex.redos.ignored_patterns'),
            'runtime_pcre_validation' => param('php_regex.runtime_pcre_validation'),
        ])
        ->public();

    // Aliases for autowiring
    $services->alias(Regex::class, 'php_regex.regex')
        ->public();

    // Configure extractor with the determined implementation
    $services->set('php_regex.extractor', PatternExtractor::class)
        ->args([
            '$extractor' => service(ExtractorInterface::class)->nullOnInvalid(),
        ]);

    $services->set('php_regex.parser', RegexParser::class)
        ->factory([service('php_regex.regex'), 'parser']);

    $services->set('php_regex.service.regex_analysis', AnalysisService::class)
        ->arg('$regex', service('php_regex.parser'))
        ->arg('$extractor', service('php_regex.extractor')->nullOnInvalid())
        ->arg('$warningThreshold', param('php_regex.analysis.warning_threshold'))
        ->arg('$redosThreshold', param('php_regex.redos.threshold'))
        ->arg('$redosIgnoredPatterns', param('php_regex.redos.ignored_patterns'))
        ->arg('$redosEnabled', param('php_regex.redos.enabled'));

    $services->set('php_regex.pattern_sources', PatternSourceCollection::class)
        ->args([
            '$sources' => tagged_iterator('php_regex.pattern_source'),
        ]);

    $services->set(PhpFilePatternSource::class)
        ->args([
            '$extractor' => service('php_regex.extractor'),
        ])
        ->tag('php_regex.pattern_source');

    $services->set(RoutePatternSource::class)
        ->args([
            '$patternNormalizer' => service(RouteRequirementNormalizer::class),
            '$router' => service('router')->nullOnInvalid(),
        ])
        ->tag('php_regex.pattern_source');

    $services->set(ValidatorPatternSource::class)
        ->args([
            '$validator' => service('validator')->nullOnInvalid(),
            '$validatorLoader' => service('validator.mapping.loader')->nullOnInvalid(),
        ])
        ->tag('php_regex.pattern_source');

    $services->set('php_regex.service.regex_lint', LintService::class)
        ->args([
            '$analysis' => service('php_regex.service.regex_analysis'),
            '$sources' => service('php_regex.pattern_sources'),
        ]);

    $services->set('php_regex.formatter_registry', FormatterRegistry::class);

    $services->set('php_regex.command.lint', LintCommand::class)
        ->arg('$lint', service('php_regex.service.regex_lint'))
        ->arg('$analysis', service('php_regex.service.regex_analysis'))
        ->arg('$formatterRegistry', service('php_regex.formatter_registry'))
        ->arg('$defaultPaths', param('php_regex.paths'))
        ->arg('$defaultExcludePaths', param('php_regex.exclude'))
        ->arg('$defaultOptimizations', param('php_regex.optimizations'))
        ->arg('$editorUrl', param('php_regex.editor_format'))
        // The lint judges for the project's target, with the service's
        // settings but never its runtime validation, which only the running
        // PHP can do.
        ->arg('$regexOptions', [
            'max_pattern_length' => param('php_regex.max_pattern_length'),
            'max_lookbehind_length' => param('php_regex.max_lookbehind_length'),
            'cache' => service('php_regex.cache'),
            'redos_ignored_patterns' => param('php_regex.redos.ignored_patterns'),
        ])
        ->arg('$phpVersion', param('php_regex.php_version'))
        ->arg('$pcreVersion', param('php_regex.pcre_version'))
        ->arg('$projectDir', param('php_regex.project_dir'))
        ->tag('console.command')
        ->public();

    $services->set('php_regex.command.compare', CompareCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->arg('$defaultMinimizer', param('php_regex.automata.minimization_algorithm'))
        ->arg('$defaultDeterminizer', param('php_regex.automata.determinization_algorithm'))
        ->tag('console.command')
        ->public();

    $services->set(RouteConflictAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'))
        ->arg('$minimizationAlgorithm', param('php_regex.automata.minimization_algorithm'))
        ->arg('$determinizationAlgorithm', param('php_regex.automata.determinization_algorithm'));

    $services->set(RouteConflictSuggestionBuilder::class);

    $services->set('php_regex.command.routes', RoutesCommand::class)
        ->arg('$analyzer', service(RouteConflictAnalyzer::class))
        ->arg('$suggestionBuilder', service(RouteConflictSuggestionBuilder::class))
        ->arg('$router', service('router')->nullOnInvalid())
        ->tag('console.command')
        ->public();

    $services->set(RouteRequirementNormalizer::class);

    $services->set(RouteControllerFileResolver::class);

    $services->set(SecurityPatternNormalizer::class);

    $services->set(SecurityConfigExtractor::class);

    $services->set(SecurityConfigLocator::class);

    $services->set(SecurityAccessSuggestionBuilder::class);

    $services->set(SecurityAccessControlAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'))
        ->arg('$patternNormalizer', service(SecurityPatternNormalizer::class))
        ->arg('$minimizationAlgorithm', param('php_regex.automata.minimization_algorithm'))
        ->arg('$determinizationAlgorithm', param('php_regex.automata.determinization_algorithm'));

    $services->set(SecurityFirewallAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'))
        ->arg('$patternNormalizer', service(SecurityPatternNormalizer::class));

    $services->set('php_regex.command.security', SecurityCommand::class)
        ->arg('$extractor', service(SecurityConfigExtractor::class))
        ->arg('$accessAnalyzer', service(SecurityAccessControlAnalyzer::class))
        ->arg('$firewallAnalyzer', service(SecurityFirewallAnalyzer::class))
        ->arg('$configLocator', service(SecurityConfigLocator::class))
        ->arg('$suggestionBuilder', service(SecurityAccessSuggestionBuilder::class))
        ->arg('$kernel', service('kernel')->nullOnInvalid())
        ->arg('$defaultRedosThreshold', param('php_regex.redos.threshold'))
        ->tag('console.command')
        ->public();

    $services->set(ConsoleReportFormatter::class);

    $services->set(JsonReportFormatter::class);

    $services->set(RoutesAnalyzer::class)
        ->arg('$analyzer', service(RouteConflictAnalyzer::class))
        ->arg('$suggestionBuilder', service(RouteConflictSuggestionBuilder::class))
        ->arg('$router', service('router')->nullOnInvalid())
        ->tag('php_regex.bridge_analyzer');

    $services->set(SecurityAnalyzer::class)
        ->arg('$extractor', service(SecurityConfigExtractor::class))
        ->arg('$locator', service(SecurityConfigLocator::class))
        ->arg('$accessAnalyzer', service(SecurityAccessControlAnalyzer::class))
        ->arg('$firewallAnalyzer', service(SecurityFirewallAnalyzer::class))
        ->arg('$suggestionBuilder', service(SecurityAccessSuggestionBuilder::class))
        ->tag('php_regex.bridge_analyzer');

    $services->set(AnalyzerRegistry::class)
        ->arg('$analyzers', tagged_iterator('php_regex.bridge_analyzer'));

    $services->set('php_regex.command.analyze', AnalyzeCommand::class)
        ->arg('$registry', service(AnalyzerRegistry::class))
        ->arg('$consoleFormatter', service(ConsoleReportFormatter::class))
        ->arg('$jsonFormatter', service(JsonReportFormatter::class))
        ->arg('$kernel', service('kernel')->nullOnInvalid())
        ->arg('$defaultRedosThreshold', param('php_regex.redos.threshold'))
        ->tag('console.command')
        ->public();

    $services->set('php_regex.command.transpile', TranspileCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command')
        ->public();
};
