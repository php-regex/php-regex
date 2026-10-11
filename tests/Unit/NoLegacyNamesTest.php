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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\Token\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 carries no name from before a class moved: an old name is not found,
 * and nothing is loaded on every request to alias it.
 */
final class NoLegacyNamesTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOldNames')]
    public function test_an_old_name_is_not_found(string $name): void
    {
        $this->assertFalse(class_exists($name) || interface_exists($name) || enum_exists($name), $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOldNames(): iterable
    {
        yield 'RegexParser\Automata\CharSet' => ['RegexParser\\Automata\\CharSet'];
        yield 'RegexParser\Automata\DfaBuilder' => ['RegexParser\\Automata\\DfaBuilder'];
        yield 'RegexParser\Automata\NfaBuilder' => ['RegexParser\\Automata\\NfaBuilder'];
        yield 'RegexParser\Automata\DfaMinimizer' => ['RegexParser\\Automata\\DfaMinimizer'];
        yield 'RegexParser\Automata\HopcroftWorklist' => ['RegexParser\\Automata\\HopcroftWorklist'];
        yield 'RegexParser\Automata\MinimizationAlgorithm' => ['RegexParser\\Automata\\MinimizationAlgorithm'];
        yield 'RegexParser\Automata\MinimizationAlgorithmFactory' => ['RegexParser\\Automata\\MinimizationAlgorithmFactory'];
        yield 'RegexParser\Automata\MinimizationAlgorithmInterface' => ['RegexParser\\Automata\\MinimizationAlgorithmInterface'];
        yield 'RegexParser\Automata\MoorePartitionRefinement' => ['RegexParser\\Automata\\MoorePartitionRefinement'];
        yield 'RegexParser\Automata\Dfa' => ['RegexParser\\Automata\\Dfa'];
        yield 'RegexParser\Automata\DfaState' => ['RegexParser\\Automata\\DfaState'];
        yield 'RegexParser\Automata\Nfa' => ['RegexParser\\Automata\\Nfa'];
        yield 'RegexParser\Automata\NfaFragment' => ['RegexParser\\Automata\\NfaFragment'];
        yield 'RegexParser\Automata\NfaState' => ['RegexParser\\Automata\\NfaState'];
        yield 'RegexParser\Automata\NfaTransition' => ['RegexParser\\Automata\\NfaTransition'];
        yield 'RegexParser\Automata\MatchMode' => ['RegexParser\\Automata\\MatchMode'];
        yield 'RegexParser\Automata\SolverOptions' => ['RegexParser\\Automata\\SolverOptions'];
        yield 'RegexParser\Automata\EquivalenceResult' => ['RegexParser\\Automata\\EquivalenceResult'];
        yield 'RegexParser\Automata\IntersectionResult' => ['RegexParser\\Automata\\IntersectionResult'];
        yield 'RegexParser\Automata\RegexSolver' => ['RegexParser\\Automata\\RegexSolver'];
        yield 'RegexParser\Automata\RegexSolverInterface' => ['RegexParser\\Automata\\RegexSolverInterface'];
        yield 'RegexParser\Automata\SubsetResult' => ['RegexParser\\Automata\\SubsetResult'];
        yield 'RegexParser\Automata\AstToNfaTransformer' => ['RegexParser\\Automata\\AstToNfaTransformer'];
        yield 'RegexParser\Automata\AstToNfaTransformerInterface' => ['RegexParser\\Automata\\AstToNfaTransformerInterface'];
        yield 'RegexParser\Automata\RegularSubsetValidator' => ['RegexParser\\Automata\\RegularSubsetValidator'];
        yield 'RegexParser\Automata\Api\RegexLanguageSolver' => ['RegexParser\\Automata\\Api\\RegexLanguageSolver'];
        yield 'RegexParser\Automata\Solver\RegexSolver' => ['RegexParser\\Automata\\Solver\\RegexSolver'];
        yield 'RegexParser\Automata\Solver\RegexSolverInterface' => ['RegexParser\\Automata\\Solver\\RegexSolverInterface'];
        yield 'RegexParser\Automata\Solver\RegexSolverCompilerInterface' => ['RegexParser\\Automata\\Solver\\RegexSolverCompilerInterface'];
        yield 'RegexParser\Lint\Command\LintCommand' => ['RegexParser\\Lint\\Command\\LintCommand'];
        yield 'RegexParser\Lint\Command\LintOutputRenderer' => ['RegexParser\\Lint\\Command\\LintOutputRenderer'];
        yield 'RegexParser\Lint\ExtractorInterface' => ['RegexParser\\Lint\\ExtractorInterface'];
        yield 'RegexParser\Lint\TokenBasedExtractionStrategy' => ['RegexParser\\Lint\\TokenBasedExtractionStrategy'];
        yield 'RegexParser\Lint\PhpStanExtractionStrategy' => ['RegexParser\\Lint\\PhpStanExtractionStrategy'];
        yield 'RegexParser\Node\ClassOperationNode' => ['RegexParser\\Node\\ClassOperationNode'];
        yield 'RegexParser\Node\ClassOperationType' => ['RegexParser\\Node\\ClassOperationType'];
    }

    #[Test]
    #[DataProvider('provideOldTokenTypes')]
    public function test_an_old_token_type_is_not_found(string $case): void
    {
        $this->assertFalse(\defined(TokenType::class.'::'.$case), $case);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOldTokenTypes(): iterable
    {
        yield 'T_CLASS_INTERSECTION' => ['T_CLASS_INTERSECTION'];
        yield 'T_CLASS_SUBTRACTION' => ['T_CLASS_SUBTRACTION'];
    }

    #[Test]
    public function test_a_visitor_has_no_class_operation_method(): void
    {
        $this->assertFalse(method_exists(NodeVisitorInterface::class, 'visitClassOperation'));
    }

    /**
     * The PHP sources carry no 1.x knowledge: the old Laravel config key is
     * read nowhere, the old environment variable name nowhere either. The
     * upgrade guide and UpgradeMap keep theirs — they are migration tools,
     * not runtime code.
     */
    #[Test]
    #[DataProvider('provideLegacyStrings')]
    public function test_no_php_source_carries_a_1_x_string(string $needle, string $what): void
    {
        $found = [];
        foreach (self::phpSources() as $file) {
            if (str_contains((string) file_get_contents($file), $needle)) {
                $found[] = $file;
            }
        }

        $this->assertSame([], $found, \sprintf('%s still named in: %s.', $what, implode(', ', $found)));
    }

    /**
     * @return iterable<string, array{needle: string, what: string}>
     */
    public static function provideLegacyStrings(): iterable
    {
        yield 'the 1.x Laravel config key' => ['needle' => "'regex-parser'", 'what' => 'The 1.x config key'];
        yield 'the 1.x environment prefix' => ['needle' => 'REGEX_PARSER_', 'what' => 'The 1.x environment variable name'];
        yield 'the 1.x baseline reader' => ['needle' => 'fromList', 'what' => 'The 1.x baseline reader'];
        yield 'the 1.x baseline key' => ['needle' => 'legacyKey', 'what' => 'The 1.x baseline key'];
        yield 'the stale Laravel key table' => ['needle' => 'STALE_KEYS', 'what' => 'The stale-key table'];
        yield 'the removed-key tables' => ['needle' => 'REMOVED_KEYS', 'what' => 'The removed-key table'];
    }

    #[Test]
    public function test_the_optimizer_options_carry_no_rename_table(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Optimizer/OptimizerOptions.php');

        $this->assertStringNotContainsString('RENAMED', $source, 'OptimizerOptions must not keep a 1.x rename table: a 1.x key is an unknown key.');
    }

    #[Test]
    public function test_composer_loads_no_file_on_every_request(): void
    {
        /** @var array{autoload: array<string, mixed>} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('files', $composer['autoload']);
    }

    /**
     * Every PHP file under src/, the packages' own files included.
     *
     * @return \Generator<string, string>
     */
    private static function phpSources(): \Generator
    {
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                yield $file->getPathname() => $file->getPathname();
            }
        }
    }
}
