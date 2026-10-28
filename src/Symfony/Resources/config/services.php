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
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
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
 * decides are set by PHPRegexSymfonyBundle::loadExtension().
 *
 * Every service is private and named php_regex.*: the classes behind them
 * are internal, and an application autowires none of them. It autowires
 * the Regex service, as PHPRegex\Toolkit\Regex; the validator of the
 * ValidRegex constraint is found by its class name. The commands are lazy,
 * listed by their #[AsCommand] name.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->private();

    $services->set('php_regex.regex', Regex::class)
        ->factory([Regex::class, 'create']);

    // The one way to reach the service from outside the container's
    // wiring, kept for the code that fetches it by its class.
    $services->alias(Regex::class, 'php_regex.regex')
        ->public();

    // php_regex.extractor.instance is the extraction strategy, or the
    // service extractor_service names; both set by loadExtension().
    $services->set('php_regex.extractor', PatternExtractor::class)
        ->args([
            '$extractor' => service('php_regex.extractor.instance')->nullOnInvalid(),
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

    $services->set('php_regex.pattern_source.php', PhpFilePatternSource::class)
        ->args([
            '$extractor' => service('php_regex.extractor'),
        ])
        ->tag('php_regex.pattern_source');

    $services->set('php_regex.pattern_source.routes', RoutePatternSource::class)
        ->args([
            '$patternNormalizer' => service('php_regex.routing.requirement_normalizer'),
            '$router' => service('router')->nullOnInvalid(),
        ])
        ->tag('php_regex.pattern_source');

    // The mapping files are set by ValidatorMappingPass.
    $services->set('php_regex.pattern_source.validators', ValidatorPatternSource::class)
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
        ->tag('console.command');

    $services->set('php_regex.command.compare', CompareCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command');

    $services->set('php_regex.routing.requirement_normalizer', RouteRequirementNormalizer::class);

    $services->set('php_regex.routing.conflict_analyzer', RouteConflictAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'));

    $services->set('php_regex.routing.conflict_suggestion_builder', RouteConflictSuggestionBuilder::class);

    $services->set('php_regex.command.routes', RoutesCommand::class)
        ->arg('$analyzer', service('php_regex.routing.conflict_analyzer'))
        ->arg('$suggestionBuilder', service('php_regex.routing.conflict_suggestion_builder'))
        ->arg('$router', service('router')->nullOnInvalid())
        ->tag('console.command');

    $services->set('php_regex.security.pattern_normalizer', SecurityPatternNormalizer::class);

    $services->set('php_regex.security.config_extractor', SecurityConfigExtractor::class);

    $services->set('php_regex.security.config_locator', SecurityConfigLocator::class);

    $services->set('php_regex.security.access_suggestion_builder', SecurityAccessSuggestionBuilder::class);

    $services->set('php_regex.security.access_control_analyzer', SecurityAccessControlAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'))
        ->arg('$patternNormalizer', service('php_regex.security.pattern_normalizer'));

    $services->set('php_regex.security.firewall_analyzer', SecurityFirewallAnalyzer::class)
        ->arg('$regex', service('php_regex.regex'))
        ->arg('$patternNormalizer', service('php_regex.security.pattern_normalizer'));

    $services->set('php_regex.command.security', SecurityCommand::class)
        ->arg('$extractor', service('php_regex.security.config_extractor'))
        ->arg('$accessAnalyzer', service('php_regex.security.access_control_analyzer'))
        ->arg('$firewallAnalyzer', service('php_regex.security.firewall_analyzer'))
        ->arg('$configLocator', service('php_regex.security.config_locator'))
        ->arg('$suggestionBuilder', service('php_regex.security.access_suggestion_builder'))
        ->arg('$kernel', service('kernel')->nullOnInvalid())
        ->tag('console.command');

    $services->set('php_regex.analyzer.console_formatter', ConsoleReportFormatter::class);

    $services->set('php_regex.analyzer.json_formatter', JsonReportFormatter::class);

    $services->set('php_regex.analyzer.routes', RoutesAnalyzer::class)
        ->arg('$analyzer', service('php_regex.routing.conflict_analyzer'))
        ->arg('$suggestionBuilder', service('php_regex.routing.conflict_suggestion_builder'))
        ->arg('$router', service('router')->nullOnInvalid());

    $services->set('php_regex.analyzer.security', SecurityAnalyzer::class)
        ->arg('$extractor', service('php_regex.security.config_extractor'))
        ->arg('$locator', service('php_regex.security.config_locator'))
        ->arg('$accessAnalyzer', service('php_regex.security.access_control_analyzer'))
        ->arg('$firewallAnalyzer', service('php_regex.security.firewall_analyzer'))
        ->arg('$suggestionBuilder', service('php_regex.security.access_suggestion_builder'));

    // The two analyzers regex:analyze runs, in this order.
    $services->set('php_regex.command.analyze', AnalyzeCommand::class)
        ->arg('$analyzers', [service('php_regex.analyzer.routes'), service('php_regex.analyzer.security')])
        ->arg('$consoleFormatter', service('php_regex.analyzer.console_formatter'))
        ->arg('$jsonFormatter', service('php_regex.analyzer.json_formatter'))
        ->arg('$kernel', service('kernel')->nullOnInvalid())
        ->tag('console.command');

    // The ValidRegex constraint judges with the service above; the
    // validator finds it under its class name.
    if (class_exists(ConstraintValidator::class)) {
        $services->set(ValidRegexValidator::class)
            ->arg('$regex', service('php_regex.regex'))
            ->tag('validator.constraint_validator');
    }

    $services->set('php_regex.command.explain', ExplainCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command');

    $services->set('php_regex.command.transpile', TranspileCommand::class)
        ->arg('$regex', service('php_regex.regex'))
        ->tag('console.command');
};
