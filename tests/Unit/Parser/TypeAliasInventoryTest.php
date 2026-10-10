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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Automata\Match\MatchExplorer;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Transform\LookaroundProduct;
use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\CompareCommand;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\RedosCommand;
use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\LanguageServer\Handler\CompletionHandler;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Parser\Analysis\CaptureFacts;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Analysis\MetricsCollector;
use PHPRegex\Parser\Cache\RemovableCacheInterface;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\PHPStan\ReplacementReferences;
use PHPRegex\Psalm\Internal\PregCallAnalyzer;
use PHPRegex\Redos\Internal\Backtrack\AmbiguityFinder;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Symfony\Analyzer\SecurityAnalyzer;
use PHPRegex\Symfony\Routing\RouteConflictReport;
use PHPRegex\Symfony\Security\SecurityAccessControlReport;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityFirewallReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The importable type aliases — @phpstan-type declarations sitting on a
 * class docblock — are public surface for all of 2.x: a consumer writes
 * `@phpstan-import-type CacheStats from RemovableCacheInterface`, and an
 * alias has no deprecation path, so renaming or deleting one is a silent
 * break for everyone importing it. This test makes the freeze executable:
 * the inventory below is the complete list of class => alias pairs that
 * may be declared anywhere under src/, and any addition, removal or rename
 * reddens it until the inventory is updated on purpose.
 *
 * Two deliberate absences: LintService and the three lint formatters stop
 * declaring LintIssue, LintResult, LintStats, OptimizationEntry and
 * FlattenedProblem locally — they import the names from LintReport instead
 * (a docblock cannot declare and import the same name), so once the shapes
 * are single-sourced they export nothing.
 *
 * Class docblocks are read from the files rather than through reflection:
 * the inventory spans the static-analysis bridges, whose classes the test
 * runtime cannot autoload.
 */
final class TypeAliasInventoryTest extends TestCase
{
    /**
     * Fully qualified class => the alias names its class docblock
     * declares, alphabetically. Everything a consumer can import is here.
     */
    private const INVENTORY = [
        // Public homes: parser, engine and report classes a consumer builds on.
        Dfa::class => ['AlphabetRange'],
        DfaState::class => ['RangeTransition'],
        TestCaseGenerator::class => ['TestCases'],
        LengthRangeCalculator::class => ['LengthRange'],
        MetricsCollector::class => ['Metrics'],
        RemovableCacheInterface::class => ['CacheStats'],
        NodeFinder::class => ['NodeFilter'],
        ParserOptions::class => ['OptionsArray'],
        RedosSearchCost::class => ['SearchCostArray'],
        RedosWitness::class => ['WitnessArray'],

        // Internal homes: shapes shared inside one subsystem.
        RegexOccurrence::class => ['Position', 'Range'],
        MatchExplorer::class => ['Side', 'Thread'],
        LookaroundProduct::class => ['ProductState', 'PromisePair'],
        AnalyzeCommand::class => ['AnalyzeArguments'],
        CompareCommand::class => ['CompareArguments'],
        HelpCommand::class => ['CommandHelp'],
        RedosCommand::class => ['BenchResult'],
        CompletionHandler::class => ['CompletionContext', 'CompletionItem'],
        ProjectTarget::class => ['TargetDescription'],
        GroupIndex::class => ['CapturingGroupInfo'],
        CaptureFacts::class => ['Facts'],
        ReplacementReferences::class => ['GroupReference'],
        PregCallAnalyzer::class => ['CachedType', 'PatternVerdict'],
        AmbiguityFinder::class => ['Verdict', 'Witness'],
        SecurityAnalyzer::class => ['SkippedFile'],

        // Exported before the named-shape change and frozen since.
        RailroadSvgRenderer::class => ['SvgBox', 'SvgLayout', 'SvgMarker', 'SvgNode', 'SvgPath', 'SvgPoint', 'SvgText'],
        LintReport::class => ['FlattenedProblem', 'LintIssue', 'LintResult', 'LintStats', 'OptimizationEntry'],
        RouteConflictReport::class => ['RouteConflict', 'RouteDescriptor', 'RouteSkip'],
        SecurityAccessControlReport::class => ['AccessConflict', 'AccessRule', 'AccessSkip'],
        SecurityConfigExtractor::class => ['AccessControlRule', 'FirewallRule'],
        SecurityFirewallReport::class => ['FirewallFinding', 'FirewallSkip'],
    ];

    /**
     * @param list<string> $expected the frozen alias names, sorted
     */
    #[Test]
    #[DataProvider('provideInventoryClasses')]
    public function test_frozen_class_declares_exactly_its_inventory_of_aliases(string $class, array $expected): void
    {
        $path = self::classFile($class);
        $this->assertFileExists($path, sprintf('%s is named by the frozen alias inventory but its file is gone; a class move must carry the inventory row with it.', $class));

        $names = array_keys(self::declaredAliasOffsets(self::classDocBlock((string) file_get_contents($path))));
        sort($names);

        $this->assertSame(
            [],
            array_values(array_diff($expected, $names)),
            sprintf('%s must declare the alias(es) listed above in its class docblock: they are frozen importable surface, and an alias that is not declared cannot be imported.', $class),
        );

        $this->assertSame(
            [],
            array_values(array_diff($names, $expected)),
            sprintf('%s declares alias(es) its inventory row does not list. The freeze is exact — extend the inventory in the same change that adds an alias.', $class),
        );
    }

    #[Test]
    #[DataProvider('provideDeclaredAliasTags')]
    public function test_alias_tag_line_carries_only_the_type(string $class, string $alias): void
    {
        $docblock = self::classDocBlock((string) file_get_contents(self::classFile($class)));
        $offsets = self::declaredAliasOffsets($docblock);

        $this->assertArrayHasKey($alias, $offsets, sprintf('%s stopped declaring %s between the scan and this row.', $class, $alias));
        $this->assertFalse(
            self::tagLineCarriesProse($docblock, $offsets[$alias]),
            sprintf('%s::%s carries text after the type expression on its @phpstan-type line. PHPStan reads that line as a name plus one type expression and nothing else, so a description glued after the shape is a parse error — put the description on its own prose line.', $class, $alias),
        );
    }

    #[Test]
    #[DataProvider('provideTagLineExamples')]
    public function test_tag_line_prose_detection_tells_prose_from_type_continuation(string $docblock, bool $carriesProse): void
    {
        $offset = self::declaredAliasOffsets($docblock)['Example'] ?? -1;
        $this->assertNotSame(-1, $offset, 'the example must declare one alias named Example');

        $this->assertSame(
            $carriesProse,
            self::tagLineCarriesProse($docblock, $offset),
            $carriesProse
                ? 'a description after the type expression must be detected as prose'
                : 'a legitimate type expression must not be reported as prose',
        );
    }

    #[Test]
    public function test_no_alias_is_declared_outside_the_inventory(): void
    {
        $strays = [];
        foreach (self::allDeclaredAliases() as $class => $aliases) {
            foreach ($aliases as $alias) {
                if (!\in_array($alias, self::INVENTORY[$class] ?? [], true)) {
                    $strays[] = $class.'::'.$alias;
                }
            }
        }

        $this->assertSame(
            [],
            $strays,
            "Aliases declared outside the inventory:\n".implode("\n", $strays)
                ."\nThe inventory is the complete list src/ may declare. LintService and the three lint formatters"
                .' import their alias names from LintReport instead of declaring them, so their local declarations'
                .' must be gone; any other stray is an export nobody froze.',
        );
    }

    /**
     * What the tag-line rule must catch and what it must leave alone: the
     * prose forms are the ones PHPStan itself rejects (a description glued
     * after the shape), the clean forms are every spelling a frozen alias
     * legitimately uses — shapes, nullable unions, callables, shapes spread
     * over several lines.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function provideTagLineExamples(): iterable
    {
        yield 'description after a shape' => [
            "/**\n * @phpstan-type Example array{0: int, 1: int} a code point range, inclusive on both ends\n */\nfinal class X {}",
            true,
        ];

        yield 'description with a colon after a shape' => [
            "/**\n * @phpstan-type Example array{0: int, 1: array<int, int>, 2: int|null} a running thread: state, registers, label\n */\nfinal class X {}",
            true,
        ];

        yield 'description after a plain type' => [
            "/**\n * @phpstan-type Example int the number of states\n */\nfinal class X {}",
            true,
        ];

        yield 'shape alone' => [
            "/**\n * @phpstan-type Example array{0: int, 1: int}\n */\nfinal class X {}",
            false,
        ];

        yield 'shape nullable union without spaces' => [
            "/**\n * @phpstan-type Example array{int, list<int>, list<int>}|null\n */\nfinal class X {}",
            false,
        ];

        yield 'shape nullable union with spaces' => [
            "/**\n * @phpstan-type Example array{a: int} | null\n */\nfinal class X {}",
            false,
        ];

        yield 'callable with a return type' => [
            "/**\n * @phpstan-type Example \\Closure(NodeInterface): bool\n */\nfinal class X {}",
            false,
        ];

        yield 'shape spread over lines' => [
            "/**\n * @phpstan-type Example array{\n *     width: int,\n *     height: int,\n * }\n */\nfinal class X {}",
            false,
        ];

        yield 'nested generics inside a shape' => [
            "/**\n * @phpstan-type Example array{options: list<array{string, string}>, notes: list<string>}\n */\nfinal class X {}",
            false,
        ];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideInventoryClasses(): iterable
    {
        foreach (self::INVENTORY as $class => $aliases) {
            yield $class => [$class, $aliases];
        }
    }

    /**
     * Every @phpstan-type actually declared at class level under src/ right
     * now, so the tag-line rule runs against real docblocks as they stand.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideDeclaredAliasTags(): iterable
    {
        foreach (self::allDeclaredAliases() as $class => $aliases) {
            foreach ($aliases as $alias) {
                yield $class.'::'.$alias => [$class, $alias];
            }
        }
    }

    /**
     * @return array<string, list<string>> class => declared alias names
     */
    private static function allDeclaredAliases(): array
    {
        $root = self::src();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $declared = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $aliases = array_keys(self::declaredAliasOffsets(self::classDocBlock((string) file_get_contents($file->getPathname()))));
            if ([] === $aliases) {
                continue;
            }

            $class = 'PHPRegex\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
            $declared[$class] = $aliases;
        }

        ksort($declared);

        return $declared;
    }

    /**
     * The alias declarations of one docblock, as name => offset of the type
     * expression that follows the name.
     *
     * @return array<string, int>
     */
    private static function declaredAliasOffsets(string $docblock): array
    {
        $offsets = [];
        if (0 !== preg_match_all('/@phpstan-type[ \t]+(\w+)[ \t]*/', $docblock, $matches, \PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $name) {
                $offsets[$name[0]] = $matches[0][$index][1] + \strlen($matches[0][$index][0]);
            }
        }

        return $offsets;
    }

    /**
     * Whether anything follows the type expression on its own @phpstan-type
     * line. The expression may span lines while a bracket stays open; once
     * the brackets close, only a union/intersection separator or a
     * callable's return colon may continue it — any other word at depth
     * zero, after the expression has started, is a description and a
     * phpDoc parse error under PHPStan.
     */
    private static function tagLineCarriesProse(string $docblock, int $typeStart): bool
    {
        $length = \strlen($docblock);
        $depth = 0;
        $last = '';
        $afterSpace = false;

        for ($i = $typeStart; $i < $length; $i++) {
            $char = $docblock[$i];

            if ('{' === $char || '(' === $char || '[' === $char || '<' === $char) {
                $depth++;
                $last = $char;
                $afterSpace = false;

                continue;
            }

            if ('}' === $char || ')' === $char || ']' === $char || '>' === $char) {
                $depth = max(0, $depth - 1);
                $last = $char;
                $afterSpace = false;

                continue;
            }

            if (0 === $depth) {
                if ("\n" === $char || "\r" === $char) {
                    return false; // the expression ended with its line: nothing is glued after it
                }

                if ('*' === $char && '/' === ($docblock[$i + 1] ?? '')) {
                    return false; // the docblock ends right after the expression
                }

                if (' ' === $char || "\t" === $char) {
                    $afterSpace = true;

                    continue;
                }

                if ($afterSpace && !\in_array($char, ['|', '&', ':'], true) && !\in_array($last, ['|', '&', ':'], true)) {
                    return true; // a new word where the type grammar allows none: a description
                }

                $last = $char;
                $afterSpace = false;

                continue;
            }

            if ("\n" === $char) {
                // A shape spread over lines: skip the next line's decoration.
                $next = $i + 1;
                while ($next < $length && (' ' === $docblock[$next] || "\t" === $docblock[$next])) {
                    $next++;
                }
                if ($next < $length && '*' === $docblock[$next]) {
                    $next++;
                    if ($next < $length && ' ' === $docblock[$next]) {
                        $next++;
                    }
                }
                $i = $next - 1;
            }
        }

        return false;
    }

    /**
     * The docblock right above the class declaration, or ''.
     */
    private static function classDocBlock(string $code): string
    {
        if (1 !== preg_match('~(/\*\*(?:(?!\*/).)*\*/)\s*(?:#\[(?:[^\[\]]|\[[^\[\]]*\])*\]\s*)*(?:final |abstract |readonly )*(?:class|interface|trait|enum) ~s', $code, $match)) {
            return '';
        }

        return $match[1];
    }

    private static function classFile(string $class): string
    {
        return self::src().'/'.str_replace('\\', '/', substr($class, \strlen('PHPRegex\\'))).'.php';
    }

    private static function src(): string
    {
        return \dirname(__DIR__, 3).'/src';
    }
}
