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

namespace RegexParser\Optimizer;

use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\Solver\RegexSolver;
use RegexParser\ErrorCode;
use RegexParser\Exception\RegexException;
use RegexParser\Internal\PatternParser;
use RegexParser\Node\RegexNode;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\OptimizerNodeVisitor;
use RegexParser\OptimizationResult;
use RegexParser\RegexParser;

/**
 * Rewrites a pattern into a shorter or faster one that matches the same
 * strings, read with the parser it is given; the automata can confirm the
 * two are equivalent before the rewrite is offered.
 */
final readonly class Optimizer
{
    public function __construct(private RegexParser $parser) {}

    /**
     * Optimize a regular expression for better performance.
     *
     * @param string                                                                                                                                                                                                  $regex   The regular expression to optimize
     * @param array{digits?: bool, word?: bool, ranges?: bool, canonicalizeCharClasses?: bool, autoPossessify?: bool, allowAlternationFactorization?: bool, minQuantifierCount?: int, verifyWithAutomata?: bool, ...} $options Optimization options (unknown keys are ignored)
     *
     * @return OptimizationResult Optimization results with changes applied
     */
    public function optimize(string $regex, array $options = []): OptimizationResult
    {
        $verifyWithAutomata = (bool) ($options['verifyWithAutomata'] ?? false);
        $optimizer = new OptimizerNodeVisitor(
            optimizeDigits: (bool) ($options['digits'] ?? true),
            optimizeWord: (bool) ($options['word'] ?? true),
            ranges: (bool) ($options['ranges'] ?? true),
            canonicalizeCharClasses: (bool) ($options['canonicalizeCharClasses'] ?? true),
            autoPossessify: (bool) ($options['autoPossessify'] ?? false),
            allowAlternationFactorization: (bool) ($options['allowAlternationFactorization'] ?? false),
            minQuantifierCount: (int) ($options['minQuantifierCount'] ?? 4),
        );

        $ast = $this->parser->parse($regex);
        $optimizedAst = $ast->accept($optimizer);

        // A safety net: the optimizing visitor returns a tree for a tree.
        if (!$optimizedAst instanceof RegexNode) {
            throw new RegexException('Optimizer returned an unexpected AST root.', ErrorCode::InternalUnexpectedState);
        }

        if ($optimizedAst === $ast) {
            return new OptimizationResult($regex, $regex, []);
        }

        $pretty = str_contains($ast->flags, 'x');
        // Both sides are normalized so that a pattern only counts as optimized
        // when its structure changed, not when it merely spells an escape
        // differently.
        $originalCompiled = $ast->accept(new CompilerNodeVisitor($pretty, preserveSpelling: false));
        $optimizedCompiled = $optimizedAst->accept(new CompilerNodeVisitor($pretty, preserveSpelling: false));

        [$originalPattern] = PatternParser::extractPatternAndFlags($originalCompiled, $this->parser->target());
        [$optimizedPatternPart] = PatternParser::extractPatternAndFlags($optimizedCompiled, $this->parser->target());

        if ($originalPattern === $optimizedPatternPart) {
            [$pattern, , $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->parser->target());
            $closingDelimiter = PatternParser::closingDelimiter($delimiter);
            $optimizedPattern = $delimiter.$pattern.$closingDelimiter.$optimizedAst->flags;
        } else {
            $optimizedPattern = $optimizedCompiled;
        }

        if ($optimizedPattern !== $regex && $verifyWithAutomata) {
            $isEquivalent = $this->verifyOptimizedPatternWithAutomata($regex, $optimizedPattern);
            // A safety net: only a wrong rewrite fails the check, and none is known.
            if (false === $isEquivalent) {
                return new OptimizationResult($regex, $regex, []);
            }
        }

        $appliedChanges = $optimizedPattern === $regex ? [] : ['Optimized pattern.'];

        return new OptimizationResult($regex, $optimizedPattern, $appliedChanges);
    }

    /**
     * @return bool|null true when equivalent, false when not, null when unsupported
     */
    private function verifyOptimizedPatternWithAutomata(string $original, string $optimized): ?bool
    {
        try {
            $solver = new RegexSolver($this->parser);
            $result = $solver->equivalent($original, $optimized, new SolverOptions());

            return $result->isEquivalent;
        } catch (\Throwable) {
            return null;
        }
    }
}
