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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\Extraction\ExtractorInterface;
use PhpRegex\Linter\Formatter\FormatterRegistry;
use PhpRegex\Linter\LintService;
use PhpRegex\Linter\PatternExtractor;
use PhpRegex\Linter\Source\PatternSourceCollection;
use PhpRegex\Linter\Source\PhpFilePatternSource;
use PhpRegex\Parser\RegexParser;
use PhpRegex\Symfony\Analyzer\AnalyzerRegistry;
use PhpRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PhpRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PhpRegex\Symfony\Analyzer\RoutesAnalyzer;
use PhpRegex\Symfony\Analyzer\SecurityAnalyzer;
use PhpRegex\Symfony\Command\AnalyzeCommand;
use PhpRegex\Symfony\Command\CompareCommand;
use PhpRegex\Symfony\Command\LintCommand;
use PhpRegex\Symfony\Command\RoutesCommand;
use PhpRegex\Symfony\Command\SecurityCommand;
use PhpRegex\Symfony\Command\TranspileCommand;
use PhpRegex\Symfony\Extractor\RoutePatternSource;
use PhpRegex\Symfony\Extractor\ValidatorPatternSource;
use PhpRegex\Symfony\Routing\RouteConflictAnalyzer;
use PhpRegex\Symfony\Routing\RouteConflictSuggestionBuilder;
use PhpRegex\Symfony\Routing\RouteControllerFileResolver;
use PhpRegex\Symfony\Routing\RouteRequirementNormalizer;
use PhpRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PhpRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PhpRegex\Symfony\Security\SecurityConfigExtractor;
use PhpRegex\Symfony\Security\SecurityConfigLocator;
use PhpRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PhpRegex\Symfony\Security\SecurityPatternNormalizer;
use PhpRegex\Toolkit\Regex;

/*
 * Base services for the RegexParser library.
 *
 * These services are always loaded when the bundle is enabled.
 */
/* @internal */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private();

    $services->set('regex_parser.regex', Regex::class)
        ->factory([Regex::class, 'create'])
        ->arg('$options', [
            'max_pattern_length' => param('regex_parser.max_pattern_length'),
            'max_lookbehind_length' => param('regex_parser.max_lookbehind_length'),
            'cache' => service('regex_parser.cache'),
            'redos_ignored_patterns' => param('regex_parser.redos.ignored_patterns'),
            'runtime_pcre_validation' => param('regex_parser.runtime_pcre_validation'),
        ])
        ->public();

    // Aliases for autowiring
    $services->alias(Regex::class, 'regex_parser.regex')
        ->public();

    // Configure extractor with the determined implementation
    $services->set('regex_parser.extractor', PatternExtractor::class)
        ->args([
            '$extractor' => service(ExtractorInterface::class)->nullOnInvalid(),
        ]);

    $services->set('regex_parser.parser', RegexParser::class)
        ->factory([service('regex_parser.regex'), 'parser']);

    $services->set('regex_parser.service.regex_analysis', AnalysisService::class)
        ->arg('$regex', service('regex_parser.parser'))
        ->arg('$extractor', service('regex_parser.extractor')->nullOnInvalid())
        ->arg('$warningThreshold', param('regex_parser.analysis.warning_threshold'))
        ->arg('$redosThreshold', param('regex_parser.redos.threshold'))
        ->arg('$redosIgnoredPatterns', param('regex_parser.redos.ignored_patterns'))
        ->arg('$redosEnabled', param('regex_parser.redos.enabled'));

    $services->set('regex_parser.pattern_sources', PatternSourceCollection::class)
        ->args([
            '$sources' => tagged_iterator('regex_parser.pattern_source'),
        ]);

    $services->set(PhpFilePatternSource::class)
        ->args([
            '$extractor' => service('regex_parser.extractor'),
        ])
        ->tag('regex_parser.pattern_source');

    $services->set(RoutePatternSource::class)
        ->args([
            '$patternNormalizer' => service(RouteRequirementNormalizer::class),
            '$router' => service('router')->nullOnInvalid(),
        ])
        ->tag('regex_parser.pattern_source');

    $services->set(ValidatorPatternSource::class)
        ->args([
            '$validator' => service('validator')->nullOnInvalid(),
            '$validatorLoader' => service('validator.mapping.loader')->nullOnInvalid(),
        ])
        ->tag('regex_parser.pattern_source');

    $services->set('regex_parser.service.regex_lint', LintService::class)
        ->args([
            '$analysis' => service('regex_parser.service.regex_analysis'),
            '$sources' => service('regex_parser.pattern_sources'),
        ]);

    $services->set('regex_parser.formatter_registry', FormatterRegistry::class);

    $services->set('regex_parser.command.lint', LintCommand::class)
        ->arg('$lint', service('regex_parser.service.regex_lint'))
        ->arg('$analysis', service('regex_parser.service.regex_analysis'))
        ->arg('$formatterRegistry', service('regex_parser.formatter_registry'))
        ->arg('$defaultPaths', param('regex_parser.paths'))
        ->arg('$defaultExcludePaths', param('regex_parser.exclude'))
        ->arg('$defaultOptimizations', param('regex_parser.optimizations'))
        ->arg('$editorUrl', param('regex_parser.editor_format'))
        // The lint judges for the project's target, with the service's
        // settings but never its runtime validation, which only the running
        // PHP can do.
        ->arg('$regexOptions', [
            'max_pattern_length' => param('regex_parser.max_pattern_length'),
            'max_lookbehind_length' => param('regex_parser.max_lookbehind_length'),
            'cache' => service('regex_parser.cache'),
            'redos_ignored_patterns' => param('regex_parser.redos.ignored_patterns'),
        ])
        ->arg('$phpVersion', param('regex_parser.php_version'))
        ->arg('$pcreVersion', param('regex_parser.pcre_version'))
        ->arg('$projectDir', param('regex_parser.project_dir'))
        ->tag('console.command')
        ->public();

    $services->set('regex_parser.command.compare', CompareCommand::class)
        ->arg('$regex', service('regex_parser.regex'))
        ->arg('$defaultMinimizer', param('regex_parser.automata.minimization_algorithm'))
        ->arg('$defaultDeterminizer', param('regex_parser.automata.determinization_algorithm'))
        ->tag('console.command')
        ->public();

    $services->set(RouteConflictAnalyzer::class)
        ->arg('$regex', service('regex_parser.regex'))
        ->arg('$minimizationAlgorithm', param('regex_parser.automata.minimization_algorithm'))
        ->arg('$determinizationAlgorithm', param('regex_parser.automata.determinization_algorithm'));

    $services->set(RouteConflictSuggestionBuilder::class);

    $services->set('regex_parser.command.routes', RoutesCommand::class)
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
        ->arg('$regex', service('regex_parser.regex'))
        ->arg('$patternNormalizer', service(SecurityPatternNormalizer::class))
        ->arg('$minimizationAlgorithm', param('regex_parser.automata.minimization_algorithm'))
        ->arg('$determinizationAlgorithm', param('regex_parser.automata.determinization_algorithm'));

    $services->set(SecurityFirewallAnalyzer::class)
        ->arg('$regex', service('regex_parser.regex'))
        ->arg('$patternNormalizer', service(SecurityPatternNormalizer::class));

    $services->set('regex_parser.command.security', SecurityCommand::class)
        ->arg('$extractor', service(SecurityConfigExtractor::class))
        ->arg('$accessAnalyzer', service(SecurityAccessControlAnalyzer::class))
        ->arg('$firewallAnalyzer', service(SecurityFirewallAnalyzer::class))
        ->arg('$configLocator', service(SecurityConfigLocator::class))
        ->arg('$suggestionBuilder', service(SecurityAccessSuggestionBuilder::class))
        ->arg('$kernel', service('kernel')->nullOnInvalid())
        ->arg('$defaultRedosThreshold', param('regex_parser.redos.threshold'))
        ->tag('console.command')
        ->public();

    $services->set(ConsoleReportFormatter::class);

    $services->set(JsonReportFormatter::class);

    $services->set(RoutesAnalyzer::class)
        ->arg('$analyzer', service(RouteConflictAnalyzer::class))
        ->arg('$suggestionBuilder', service(RouteConflictSuggestionBuilder::class))
        ->arg('$router', service('router')->nullOnInvalid())
        ->tag('regex_parser.bridge_analyzer');

    $services->set(SecurityAnalyzer::class)
        ->arg('$extractor', service(SecurityConfigExtractor::class))
        ->arg('$locator', service(SecurityConfigLocator::class))
        ->arg('$accessAnalyzer', service(SecurityAccessControlAnalyzer::class))
        ->arg('$firewallAnalyzer', service(SecurityFirewallAnalyzer::class))
        ->arg('$suggestionBuilder', service(SecurityAccessSuggestionBuilder::class))
        ->tag('regex_parser.bridge_analyzer');

    $services->set(AnalyzerRegistry::class)
        ->arg('$analyzers', tagged_iterator('regex_parser.bridge_analyzer'));

    $services->set('regex_parser.command.analyze', AnalyzeCommand::class)
        ->arg('$registry', service(AnalyzerRegistry::class))
        ->arg('$consoleFormatter', service(ConsoleReportFormatter::class))
        ->arg('$jsonFormatter', service(JsonReportFormatter::class))
        ->arg('$kernel', service('kernel')->nullOnInvalid())
        ->arg('$defaultRedosThreshold', param('regex_parser.redos.threshold'))
        ->tag('console.command')
        ->public();

    $services->set('regex_parser.command.transpile', TranspileCommand::class)
        ->arg('$regex', service('regex_parser.regex'))
        ->tag('console.command')
        ->public();
};
