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
use PHPRegex\Symfony\Command\ExplainCommand;
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
use PHPRegex\Symfony\Validator\Constraints\ValidRegexValidator;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Validator\ConstraintValidator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the PHPRegex bundle. The arguments its configuration
 * decides are set by PHPRegexBundle::loadExtension().
 */
/* @internal */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private();

    $services->set('php_regex.regex', Regex::class)
        ->factory([Regex::class, 'create'])
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
        ->arg('$extractor', service('php_regex.extractor')->nullOnInvalid());

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

    // The mapping files are set by ValidatorMappingPass.
    $services->set(ValidatorPatternSource::class)
        ->arg('$mappingFiles', [])
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
        ->tag('console.command')
        ->public();

    $services->set('php_regex.command.compare', CompareCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command')
        ->public();

    $services->set(RouteConflictAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'));

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
        ->arg('$patternNormalizer', service(SecurityPatternNormalizer::class));

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
        ->tag('console.command')
        ->public();

    // The ValidRegex constraint judges with the service above; the
    // validator finds it under its class name.
    if (class_exists(ConstraintValidator::class)) {
        $services->set(ValidRegexValidator::class)
            ->arg('$regex', service('php_regex.regex'))
            ->tag('validator.constraint_validator');
    }

    $services->set('php_regex.command.explain', ExplainCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command')
        ->public();

    $services->set('php_regex.command.transpile', TranspileCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command')
        ->public();
};
