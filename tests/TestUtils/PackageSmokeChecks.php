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

namespace PHPRegex\Tests\TestUtils;

use Illuminate\Support\ServiceProvider;
use Infection\Mutator\Mutator;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Infection\Mutator\RegexAnchor;
use PHPRegex\LanguageServer\Server;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Mcp\Application as McpApplication;
use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPRegex\PHPUnit\Constraint\CompilesPattern;
use PHPRegex\Psalm\Plugin;
use PHPRegex\Rector\EscapeNulByteRector;
use PHPRegex\Rector\Set\RegexSetList;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\PHPRegexSymfonyBundle;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Transpiler;
use PHPStan\Rules\Rule;
use Psalm\Plugin\PluginEntryPointInterface;
use Rector\Contract\Rector\RectorInterface;

/**
 * One check per package, for the install of that package alone: a class of
 * its own namespace, which the caller makes sure was loaded from the
 * installed copy of the package, and the least call that runs it. The
 * libraries answer a pattern; the bridges, whose framework a bare project
 * does not boot, are checked to plug into the interface or base class of
 * their framework, the Rector bridge with the set file it ships.
 *
 * Loaded by path, not through Composer: tests/Tools/package_smoke_check.php
 * runs it with the bare project's autoloader only.
 */
final class PackageSmokeChecks
{
    /**
     * @return array<string, array{class-string, \Closure(): bool}>
     */
    public static function all(): array
    {
        return [
            'Automata' => [LanguageSolver::class, static fn (): bool => (new LanguageSolver())->equivalent('/a+/', '/aa*/')->isEquivalent],
            'Cli' => [ApplicationFactory::class, static fn (): bool => [] !== ApplicationFactory::create(new Output(false, true))->registeredCommands()],
            'Explain' => [TextExplainer::class, static fn (): bool => str_contains((string) RegexParser::create()->parse('/a+/')->accept(new TextExplainer()), 'one or more times')],
            'Generator' => [SampleGenerator::class, static fn (): bool => 1 === preg_match('/^a+$/', (new SampleGenerator())->generate('/a+/'))],
            // A mutator Infection would load by its class name, asked to
            // mutate a call: the anchor goes, the mutant says what kills it.
            'Infection' => [RegexAnchor::class, static function (): bool {
                $call = new FuncCall(new Name('preg_match'), [new Arg(new String_('/^a/')), new Arg(new Variable('s'))]);
                $mutants = (new RegexAnchor())->canMutate($call) ? [...(new RegexAnchor())->mutate($call)] : [];

                return self::extends(RegexAnchor::class, Mutator::class) && 1 === \count($mutants);
            }],
            // Read until the end of an empty input, the loop an editor's
            // session runs in.
            'LanguageServer' => [Server::class, static function (): bool {
                $input = fopen('php://memory', 'r');

                return false !== $input && 0 === (new Server(null, $input))->run();
            }],
            'Laravel' => [PHPRegexServiceProvider::class, static fn (): bool => self::extends(PHPRegexServiceProvider::class, ServiceProvider::class)],
            'Mcp' => [McpApplication::class, static fn (): bool => 'Valid.' === ToolCatalog::v1()->get('regex_validate')->tool->execute(['pattern' => '/a+/'])->text],
            'Linter' => [PatternLinter::class, static function (): bool {
                $linter = new PatternLinter();
                RegexParser::create()->parse('/[aa]/')->accept($linter);

                return [] !== $linter->getIssues();
            }],
            'Optimizer' => [Optimizer::class, static fn (): bool => '/[ab]/' === (new Optimizer(RegexParser::create()))->optimize('/a|b/')->optimized],
            'Parser' => [RegexParser::class, static fn (): bool => RegexParser::create()->validate('/a+/')->isValid],
            'PHPStan' => [RegexPatternRule::class, static fn (): bool => self::extends(RegexPatternRule::class, Rule::class)],
            'PHPUnit' => [CompilesPattern::class, static fn (): bool => true === (new CompilesPattern())->evaluate('/a+/', '', true) && false === (new CompilesPattern())->evaluate('/(/', '', true)],
            'Psalm' => [Plugin::class, static fn (): bool => self::extends(Plugin::class, PluginEntryPointInterface::class)],
            'Rector' => [EscapeNulByteRector::class, static fn (): bool => self::extends(EscapeNulByteRector::class, RectorInterface::class) && is_file(RegexSetList::PCRE_UPGRADE) && is_file(RegexSetList::UPGRADE_2_0)],
            'Redos' => [RedosAnalyzer::class, static fn (): bool => RedosSeverity::Critical === (new RedosAnalyzer())->analyze('/(a+)+$/')->severity],
            'Symfony' => [PHPRegexSymfonyBundle::class, static fn (): bool => 'php_regex' === (new PHPRegexSymfonyBundle())->getContainerExtension()?->getAlias()],
            'Toolkit' => [Regex::class, static fn (): bool => Regex::create()->validate('/a+/')->isValid],
            'Transpiler' => [Transpiler::class, static fn (): bool => '/a+/i' === (new Transpiler(RegexParser::create()))->transpile('/a+/i', 'javascript')->literal],
        ];
    }

    /**
     * Whether the bridge class plugs into its framework, read at run time:
     * the framework a bare project installed is the one that answers.
     *
     * @param class-string $class
     * @param class-string $parent
     */
    private static function extends(string $class, string $parent): bool
    {
        return (new \ReflectionClass($class))->isSubclassOf($parent);
    }
}
