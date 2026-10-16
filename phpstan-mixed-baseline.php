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

/*
 * The mixed types src/ declares, by file and symbol: the reason each stays
 * mixed, and how many the symbol may declare. The static analysis reports
 * one more as much as one less, so the counts only go down.
 */
return [
    'src/Automata/LanguageSolver.php' => [
        'PHPRegex\Automata\LanguageSolver::shape()' => ['reflection-sink', 2],
    ],
    'src/Cli/Command/RedosCommand.php' => [
        'PHPRegex\Cli\Command\RedosCommand::usageToMs()' => ['host-return', 2],
    ],
    'src/Explain/Internal/RailroadLayoutVisitor.php' => [
        'PHPRegex\Explain\Internal\RailroadLayoutVisitor' => ['template-identity', 1],
        'PHPRegex\Explain\Internal\RailroadLayoutVisitor::assertLayout()' => ['validator-input', 1],
    ],
    'src/LanguageServer/Protocol/Message.php' => [
        'PHPRegex\LanguageServer\Protocol\Message::__construct()' => ['protocol-slot', 1],
        'PHPRegex\LanguageServer\Protocol\Message::fromObject()' => ['json-decode', 1],
    ],
    'src/LanguageServer/Protocol/Response.php' => [
        'PHPRegex\LanguageServer\Protocol\Response::notification()' => ['protocol-slot', 1],
        'PHPRegex\LanguageServer\Protocol\Response::send()' => ['reflection-sink', 1],
        'PHPRegex\LanguageServer\Protocol\Response::success()' => ['protocol-slot', 1],
    ],
    'src/LanguageServer/Server.php' => [
        'PHPRegex\LanguageServer\Server::readVersion()' => ['protocol-slot', 1],
        'PHPRegex\LanguageServer\Server::redos()' => ['protocol-slot', 1],
        'PHPRegex\LanguageServer\Server::resolveTarget()' => ['protocol-slot', 1],
        'PHPRegex\LanguageServer\Server::rootDirectory()' => ['protocol-slot', 1],
    ],
    'src/Laravel/Command/LintCommand.php' => [
        'PHPRegex\Laravel\Command\LintCommand::normalizeOptimizations()' => ['validator-input', 1],
    ],
    'src/Laravel/PHPRegexServiceProvider.php' => [
        'PHPRegex\Laravel\PHPRegexServiceProvider::mergeConfigWithNestedDefaults()' => ['open-options-bag', 1],
        'PHPRegex\Laravel\PHPRegexServiceProvider::registerAnalysisServices()' => ['host-return', 1],
        'PHPRegex\Laravel\PHPRegexServiceProvider::withDefaults()' => ['open-options-bag', 3],
    ],
    'src/Laravel/Rules/ValidRegex.php' => [
        'PHPRegex\Laravel\Rules\ValidRegex::validate()' => ['host-contract', 1],
    ],
    'src/Linter/Config/LintArgumentParser.php' => [
        'PHPRegex\Linter\Config\LintArgumentParser::parse()' => ['validator-input', 1],
    ],
    'src/Linter/Config/LintArguments.php' => [
        'PHPRegex\Linter\Config\LintArguments::fromDefaults()' => ['validator-input', 1],
        'PHPRegex\Linter\Config\LintArguments::normalizeStringList()' => ['validator-input', 1],
    ],
    'src/Linter/Config/LintConfigLoader.php' => [
        'PHPRegex\Linter\Config\LintConfigLoader::plain()' => ['json-decode', 2],
        'PHPRegex\Linter\Config\LintConfigLoader::toArray()' => ['json-decode', 1],
    ],
    'src/Linter/Config/LintConfigSchema.php' => [
        'PHPRegex\Linter\Config\LintConfigSchema::checksComplexity()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::checksLint()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::checksOptimizations()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::checksRedos()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::definition()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::enumProperty()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::extraction()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::ide()' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigSchema::optimizationOptions()' => ['reflection-sink', 1],
    ],
    'src/Linter/Config/LintConfigValidator.php' => [
        'PHPRegex\Linter\Config\LintConfigValidator::$schema' => ['reflection-sink', 1],
        'PHPRegex\Linter\Config\LintConfigValidator::accepts()' => ['json-decode', 2],
        'PHPRegex\Linter\Config\LintConfigValidator::checkNode()' => ['json-decode', 2],
        'PHPRegex\Linter\Config\LintConfigValidator::checkObject()' => ['json-decode', 1],
        'PHPRegex\Linter\Config\LintConfigValidator::describe()' => ['json-decode', 2],
        'PHPRegex\Linter\Config\LintConfigValidator::hasType()' => ['json-decode', 2],
        'PHPRegex\Linter\Config\LintConfigValidator::map()' => ['json-decode', 2],
        'PHPRegex\Linter\Config\LintConfigValidator::resolve()' => ['json-decode', 2],
    ],
    'src/Linter/Config/ProjectTarget.php' => [
        'PHPRegex\Linter\Config\ProjectTarget::at()' => ['json-decode', 2],
    ],
    'src/Linter/Extraction/InteropPresets.php' => [
        'PHPRegex\Linter\Extraction\InteropPresets::notAStringWarning()' => ['validator-input', 1],
    ],
    'src/Linter/Extraction/PendingPattern.php' => [
        'PHPRegex\Linter\Extraction\PendingPattern::__construct()' => ['template-identity', 1],
    ],
    'src/Linter/Internal/ForkedWorkerPool.php' => [
        'PHPRegex\Linter\Internal\ForkedWorkerPool::payload()' => ['json-decode', 1],
    ],
    'src/Optimizer/OptimizerOptions.php' => [
        'PHPRegex\Optimizer\OptimizerOptions::fromArray()' => ['validator-input', 1],
        'PHPRegex\Optimizer\OptimizerOptions::fromCamelCaseArray()' => ['validator-input', 1],
        'PHPRegex\Optimizer\OptimizerOptions::read()' => ['validator-input', 1],
    ],
    'src/PHPStan/PatternChecker.php' => [
        'PHPRegex\PHPStan\PatternChecker::complexityThreshold()' => ['validator-input', 1],
        'PHPRegex\PHPStan\PatternChecker::redosThreshold()' => ['validator-input', 1],
    ],
    'src/Parser/Cache/PsrCacheAdapter.php' => [
        'PHPRegex\Parser\Cache\PsrCacheAdapter::__construct()' => ['callback-opaque', 1],
    ],
    'src/Parser/Cache/PsrSimpleCacheAdapter.php' => [
        'PHPRegex\Parser\Cache\PsrSimpleCacheAdapter::__construct()' => ['callback-opaque', 1],
    ],
    'src/Parser/Internal/JsonDocument.php' => [
        'PHPRegex\Parser\Internal\JsonDocument::encode()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Internal\JsonDocument::error()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Internal\JsonDocument::escapeArray()' => ['reflection-sink', 2],
        'PHPRegex\Parser\Internal\JsonDocument::escapeValue()' => ['reflection-sink', 2],
    ],
    'src/Parser/Internal/LibraryPcre.php' => [
        'PHPRegex\Parser\Internal\LibraryPcre::match()' => ['preg-mirror', 1],
        'PHPRegex\Parser\Internal\LibraryPcre::matchAll()' => ['preg-mirror', 2],
        'PHPRegex\Parser\Internal\LibraryPcre::quantity()' => ['template-identity', 1],
        'PHPRegex\Parser\Internal\LibraryPcre::quietly()' => ['template-identity', 1],
        'PHPRegex\Parser\Internal\LibraryPcre::replace()' => ['preg-mirror', 1],
        'PHPRegex\Parser\Internal\LibraryPcre::replaceCallback()' => ['preg-mirror', 1],
        'PHPRegex\Parser\Internal\LibraryPcre::run()' => ['template-identity', 1],
    ],
    'src/Parser/ParserOptions.php' => [
        'PHPRegex\Parser\ParserOptions::createCache()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::fromArray()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getIgnoredPatterns()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getLookbehindLength()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getPatternLength()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getPhpVersionId()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getRecursionDepth()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getRuntimePcreValidation()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::getTarget()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::validateOptionKeys()' => ['validator-input', 1],
        'PHPRegex\Parser\ParserOptions::validatePatternStrings()' => ['validator-input', 1],
    ],
    'src/Parser/Printer/Internal/StructuralFingerprint.php' => [
        'PHPRegex\Parser\Printer\Internal\StructuralFingerprint::canonical()' => ['reflection-sink', 3],
        'PHPRegex\Parser\Printer\Internal\StructuralFingerprint::canonicalSequence()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\Internal\StructuralFingerprint::isEmptyLiteral()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\Internal\StructuralFingerprint::isLiteral()' => ['reflection-sink', 1],
    ],
    'src/Parser/Printer/JsonDumper.php' => [
        'PHPRegex\Parser\Printer\JsonDumper' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::all()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitAlternation()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitAnchor()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitAssertion()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitBackref()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitCallout()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitCharClass()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitCharLiteral()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitCharType()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitClassSetOperation()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitComment()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitConditional()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitControlChar()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitDefine()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitDot()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitExtendedCharClass()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitGroup()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitKeep()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitLimitMatch()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitLiteral()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitPcreVerb()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitPosixClass()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitQuantifier()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitRange()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitRegex()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitScriptRun()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitSequence()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitSubroutine()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitUnicodeProp()' => ['reflection-sink', 1],
        'PHPRegex\Parser\Printer\JsonDumper::visitVersionCondition()' => ['reflection-sink', 1],
    ],
    'src/Symfony/Command/AnalyzeCommand.php' => [
        'PHPRegex\Symfony\Command\AnalyzeCommand::normalizeLowercaseList()' => ['host-return', 1],
        'PHPRegex\Symfony\Command\AnalyzeCommand::normalizeStringList()' => ['host-return', 1],
    ],
    'src/Symfony/Command/CompareCommand.php' => [
        'PHPRegex\Symfony\Command\CompareCommand::resolveDeterminizationAlgorithm()' => ['host-return', 1],
        'PHPRegex\Symfony\Command\CompareCommand::resolveMinimizationAlgorithm()' => ['host-return', 1],
    ],
    'src/Symfony/Command/LintCommand.php' => [
        'PHPRegex\Symfony\Command\LintCommand::normalizeStringList()' => ['host-return', 1],
    ],
    'src/Symfony/Command/SecurityCommand.php' => [
        'PHPRegex\Symfony\Command\SecurityCommand::normalizeStringList()' => ['host-return', 1],
    ],
    'src/Symfony/DependencyInjection/Configuration.php' => [
        'PHPRegex\Symfony\DependencyInjection\Configuration::getConfigTreeBuilder()' => ['host-contract', 3],
        'PHPRegex\Symfony\DependencyInjection\Configuration::redosThreshold()' => ['validator-input', 1],
        'PHPRegex\Symfony\DependencyInjection\Configuration::targetVersion()' => ['validator-input', 1],
    ],
    'src/Symfony/DependencyInjection/PHPRegexExtension.php' => [
        'PHPRegex\Symfony\DependencyInjection\PHPRegexExtension::createExtractorDefinition()' => ['host-return', 3],
        'PHPRegex\Symfony\DependencyInjection\PHPRegexExtension::extractionWarnings()' => ['host-return', 2],
        'PHPRegex\Symfony\DependencyInjection\PHPRegexExtension::load()' => ['host-return', 3],
    ],
    'src/Symfony/Extractor/ValidatorPatternSource.php' => [
        'PHPRegex\Symfony\Extractor\ValidatorPatternSource::getMappedClasses()' => ['host-return', 1],
    ],
    'src/Symfony/Security/SecurityAccessControlAnalyzer.php' => [
        'PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer::normalizeList()' => ['host-return', 1],
    ],
    'src/Symfony/Validator/Constraints/ValidRegex.php' => [
        'PHPRegex\Symfony\Validator\Constraints\ValidRegex::__construct()' => ['host-contract', 1],
    ],
    'src/Symfony/Validator/Constraints/ValidRegexValidator.php' => [
        'PHPRegex\Symfony\Validator\Constraints\ValidRegexValidator::validate()' => ['host-contract', 1],
    ],
];
