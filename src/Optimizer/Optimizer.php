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

namespace PHPRegex\Optimizer;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\NodeWalker;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\TraversalAction;

/**
 * Rewrites a pattern into a shorter or faster one that matches the same
 * strings, read with the parser it is given; the automata can confirm the
 * two are equivalent before the rewrite is offered.
 *
 * @phpstan-import-type OptimizerOptionsArray from OptimizerOptions
 */
final class Optimizer
{
    private readonly RewriteCheck $check;

    public function __construct(private readonly RegexParser $parser)
    {
        $this->check = new RewriteCheck(new LanguageSolver($parser));
    }

    /**
     * Optimize a regular expression for better performance.
     *
     * @param string                                 $regex   The regular expression to optimize
     * @param OptimizerOptions|OptimizerOptionsArray $options What may be rewritten, as a value or as
     *                                                        the array OptimizerOptions::fromArray() reads
     *
     * @throws InvalidRegexOptionException on an option it does not know or a value of the wrong type
     * @throws LexerException              when the pattern does not lex
     * @throws ParserException             when the pattern does not parse: directly, on a delimiter or
     *                                     modifier the reader refuses, or as one of its leaves
     *                                     (SyntaxErrorException, RecursionLimitException or
     *                                     ResourceLimitException) while the tree is built
     * @throws RegexException              when the rewrite returns a tree that is not a pattern: a
     *                                     safety net, and the parent of the two families above
     *
     * @return OptimizationResult Optimization results with changes applied
     */
    public function optimize(string $regex, OptimizerOptions|array $options = []): OptimizationResult
    {
        $options = \is_array($options) ? OptimizerOptions::fromArray($options) : $options;
        $verifyWithAutomata = $options->verifyWithAutomata;
        $optimizer = new Rewriter(
            optimizeDigits: $options->digits,
            optimizeWord: $options->word,
            ranges: $options->ranges,
            canonicalizeCharClasses: $options->canonicalizeCharClasses,
            possessive: $options->possessive,
            factorize: $options->factorize,
            minQuantifierCount: $options->minQuantifierCount,
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
        $originalCompiled = $ast->accept(new PatternPrinter($pretty, preserveSpelling: false));
        $optimizedCompiled = $optimizedAst->accept(new PatternPrinter($pretty, preserveSpelling: false));

        [$originalPattern] = PatternParser::extractPatternAndFlags($originalCompiled, $this->parser->target());
        [$optimizedPatternPart] = PatternParser::extractPatternAndFlags($optimizedCompiled, $this->parser->target());

        if ($originalPattern === $optimizedPatternPart) {
            [$pattern, , $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->parser->target());
            $closingDelimiter = PatternParser::closingDelimiter($delimiter);
            $optimizedPattern = $delimiter.$pattern.$closingDelimiter.$optimizedAst->flags;
        } else {
            $optimizedPattern = $optimizedCompiled;
        }

        // Under x the rewrite is written into the pattern as laid out, only
        // its changed parts printed again.
        if ($pretty) {
            $optimizedPattern = $this->keepLayout($regex, $ast, $optimizedAst) ?? $optimizedPattern;
        }

        if ($optimizedPattern !== $regex && $this->introducesAtomicity($regex, $optimizedPattern)) {
            // A rewrite that adds a possessive quantifier or an atomic group
            // ships only when the solver verifies it: the solver refuses the
            // possessive forms it cannot prove safe, and an unverified one can
            // change the language PCRE matches. This gate runs on its own, not
            // only under verifyWithAutomata, because the rewrite rules that
            // possessify are on by default.
            if (!$this->verifyOptimizedPatternWithAutomata($regex, $optimizedPattern)) {
                return new OptimizationResult($regex, $regex, []);
            }
        } elseif ($optimizedPattern !== $regex && $verifyWithAutomata) {
            // Only a proven rewrite ships: one the automata disprove, or
            // cannot prove, leaves the pattern as written.
            if (!$this->verifyOptimizedPatternWithAutomata($regex, $optimizedPattern)) {
                return new OptimizationResult($regex, $regex, []);
            }
        }

        $appliedChanges = $optimizedPattern === $regex ? [] : ['Optimized pattern.'];

        return new OptimizationResult($regex, $optimizedPattern, $appliedChanges);
    }

    /**
     * The rewrite written into the text of $regex, with the whitespace PHP
     * skips before its delimiter and after its modifiers, or null when the
     * tree has no text to write into.
     */
    private function keepLayout(string $regex, RegexNode $ast, RegexNode $optimizedAst): ?string
    {
        $splicer = new LayoutSplicer($this->parser);
        $written = $splicer->splice($ast, $ast);
        $spliced = $splicer->splice($ast, $optimizedAst);
        // The pattern starts at its delimiter, past whitespace only: the
        // first place it is found is the one.
        if (null === $written || null === $spliced || false === $at = strpos($regex, $written)) {
            return null;
        }

        return substr($regex, 0, $at).$spliced.substr($regex, $at + \strlen($written));
    }

    /**
     * Whether the rewrite added an atomic group or a possessive quantifier
     * the original did not have, read from both ASTs.
     */
    private function introducesAtomicity(string $original, string $optimized): bool
    {
        return $this->atomicityMarkers($optimized) > $this->atomicityMarkers($original);
    }

    private function atomicityMarkers(string $pattern): int
    {
        try {
            $ast = $this->parser->parse($pattern);
        } catch (RegexException) {
            return \PHP_INT_MAX;
        }

        $markers = 0;
        NodeWalker::walk(
            $ast,
            /**
             * @param list<NodeInterface> $ancestors
             */
            static function (NodeInterface $node, array $ancestors) use (&$markers): ?TraversalAction {
                if ($node instanceof GroupNode && GroupType::Atomic === $node->type) {
                    $markers++;
                }
                if ($node instanceof QuantifierNode && QuantifierType::Possessive === $node->type) {
                    $markers++;
                }

                return null;
            },
        );

        return $markers;
    }

    /**
     * @return bool true when proven, false when disproven or unproven
     */
    private function verifyOptimizedPatternWithAutomata(string $original, string $optimized): bool
    {
        return $this->check->verify($original, $optimized);
    }
}
