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
use PHPRegex\Automata\Transform\AutoPossession;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\NodeWalker;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\Delimiters;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\TraversalAction;

/**
 * Rewrites a pattern into a shorter or faster one that matches the same
 * strings, read with the parser it is given; the automata can confirm the
 * two are equivalent before the rewrite is offered, and a rewrite the
 * parser refuses where it accepts the pattern is never offered.
 */
final class Optimizer
{
    private readonly RegexParser $parser;

    private readonly RewriteCheck $check;

    /**
     * @param RegexParser|null $parser Reads every pattern and judges every rewrite; null builds RegexParser::create()
     */
    public function __construct(?RegexParser $parser = null)
    {
        $this->parser = $parser ?? RegexParser::create();
        $this->check = new RewriteCheck(new LanguageSolver($this->parser));
    }

    /**
     * Optimize a regular expression for better performance.
     *
     * @param string                $regex   The regular expression to optimize
     * @param OptimizerOptions|null $options What may be rewritten; null reads the defaults. A config
     *                                       array becomes one through OptimizerOptions::fromArray()
     *
     * @throws ParserException when the pattern does not parse: directly, on a delimiter or
     *                         modifier the reader refuses, or as one of its leaves
     *                         (SyntaxErrorException, RecursionLimitException or
     *                         ResourceLimitException) while the tree is built
     * @throws RegexException  when the rewrite returns a tree that is not a pattern: a
     *                         safety net, and the parent of the two families above
     *
     * @return OptimizationResult Optimization results with changes applied
     */
    public function optimize(string $regex, ?OptimizerOptions $options = null): OptimizationResult
    {
        $options ??= new OptimizerOptions();
        $ast = $this->parser->parse($regex);
        $optimizer = self::rewriter($options, $options->possessive);
        $result = $this->rewrite($regex, $ast, $optimizer, $options, $options->possessive);

        // The check proves a possessive quantifier less often than the
        // other rewrites, none in a pattern that holds "\b" or a
        // reference: a rewrite it refuses with one is made again without
        // the possessive step, and the others ship on their own,
        // "/\d+y\b/s" losing its "s".
        if (null === $result && \in_array(Rewriter::POSSESSIVE, $optimizer->changes(), true)) {
            $result = $this->rewrite($regex, $ast, self::rewriter($options, false), $options, false);
        }

        return $result ?? new OptimizationResult($regex, $regex, []);
    }

    private static function rewriter(OptimizerOptions $options, bool $possessive): Rewriter
    {
        return new Rewriter(
            optimizeDigits: $options->digits,
            optimizeWord: $options->word,
            ranges: $options->ranges,
            canonicalizeCharClasses: $options->canonicalizeCharClasses,
            possessive: $possessive,
            factorize: $options->factorize,
            minQuantifierCount: $options->minQuantifierCount,
        );
    }

    /**
     * The rewrite of the pattern the rewriter makes, or null when PCRE or
     * the check of the result refuses it.
     *
     * @param bool $possessive whether the rewriter may make a quantifier possessive
     *
     * @throws RegexException when the rewrite returns a tree that is not a pattern
     */
    private function rewrite(string $regex, RegexNode $ast, Rewriter $optimizer, OptimizerOptions $options, bool $possessive): ?OptimizationResult
    {
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
        $originalCompiled = $ast->accept($pretty ? PatternPrinter::pretty() : PatternPrinter::normalized());
        $optimizedCompiled = $optimizedAst->accept($pretty ? PatternPrinter::pretty() : PatternPrinter::normalized());

        [$originalPattern] = PatternParser::extractPatternAndFlags($originalCompiled, $this->parser->target());
        [$optimizedPatternPart] = PatternParser::extractPatternAndFlags($optimizedCompiled, $this->parser->target());

        if ($originalPattern === $optimizedPatternPart) {
            [$pattern, , $delimiter] = PatternParser::extractPatternAndFlags($regex, $this->parser->target());
            $closingDelimiter = Delimiters::closing($delimiter);
            $optimizedPattern = $delimiter.$pattern.$closingDelimiter.$optimizedAst->flags;
        } else {
            $optimizedPattern = $optimizedCompiled;
        }

        // Under x the rewrite is written into the pattern as laid out, only
        // its changed parts printed again.
        if ($pretty) {
            $optimizedPattern = $this->keepLayout($regex, $ast, $optimizedAst) ?? $optimizedPattern;
        }

        // A rewrite PCRE refuses where it compiles the original is no
        // rewrite, whatever the options say: the automata read what a
        // pattern matches, never whether PCRE compiles it, and prove
        // "(?<=abc|abde)" factorized for a release that refuses a
        // lookbehind branch of two lengths.
        // Nor is one of a pattern PCRE refuses: it has no matches to keep.
        if (!$this->parser->validate($regex)->isValid) {
            return new OptimizationResult($regex, $regex, []);
        }

        if (!$this->parser->validate($optimizedPattern)->isValid) {
            return null;
        }

        if ($optimizedPattern !== $regex && ($this->mayIntroduceAtomicity($regex, $optimizedPattern, $possessive) || (!$options->verifyWithAutomata && $this->movesAutoPossession($ast, $optimizedPattern)))) {
            // A rewrite that adds a possessive quantifier or an atomic group
            // ships only when the solver verifies it: the solver refuses the
            // possessive forms it cannot prove safe, and an unverified one can
            // change the language PCRE matches. This gate runs on its own, not
            // only under verifyWithAutomata, because the rewrite rules that
            // possessify are on by default. So does one that changes the
            // brackets PCRE2 reads to make a repeat possessive, which the
            // next branch checks when the options ask for every check.
            if (!$this->verifyOptimizedPatternWithAutomata($regex, $optimizedPattern)) {
                return null;
            }
        } elseif ($optimizedPattern !== $regex && $options->verifyWithAutomata) {
            // Only a proven rewrite ships: one the automata disprove, or
            // cannot prove, leaves the pattern as written.
            if (!$this->verifyOptimizedPatternWithAutomata($regex, $optimizedPattern)) {
                return null;
            }
        }

        if ($optimizedPattern === $regex) {
            return new OptimizationResult($regex, $regex, []);
        }

        // A member of a class or a literal the rewrite built again is
        // written without the backslash it does not need: "[^\)]" becomes
        // "[^)]" with nothing else to name.
        $appliedChanges = $optimizer->changes();
        if ([] === $appliedChanges) {
            $appliedChanges = [Rewriter::RESPELLED];
        }

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
     * Whether the rewrite may have added an atomic group or a possessive
     * quantifier, read from both ASTs: one more than the original holds,
     * or, when the rewrite may possessify, any at all, as a rewrite that
     * drops "a{0}+" and makes "b+" possessive holds as many as before.
     */
    private function mayIntroduceAtomicity(string $original, string $optimized, bool $possessive): bool
    {
        $markers = $this->atomicityMarkers($optimized);

        return $markers > $this->atomicityMarkers($original) || ($possessive && $markers > 0);
    }

    /**
     * Whether the rewrite may change which repeats PCRE2 makes possessive:
     * PCRE2 makes a character repeated before a possessive or atomic group
     * that may match nothing possessive, as if the group could not be
     * skipped, and the brackets decide which. "/a+(?:b)?+a/" finds nothing
     * in "aa", where "/a+b?+a/" finds it. Without "u", it makes a repeat of
     * the byte 0xFF possessive before "\w", and one of "\w" before that
     * byte, as if 0xFF were no word character: where the tables may hold it
     * in "\w", a rewrite of a pattern that holds such a repeat, or that
     * makes one, is checked.
     */
    private function movesAutoPossession(RegexNode $original, string $optimized): bool
    {
        try {
            $rewritten = $this->parser->parse($optimized);
        } catch (RegexException) {
            return true;
        }

        $target = $this->parser->target();
        if (null !== AutoPossession::repeatMadePossessiveBesideLastByte($original, $target) || null !== AutoPossession::repeatMadePossessiveBesideLastByte($rewritten, $target)) {
            return true;
        }

        return (null !== AutoPossession::repeatBeforeSkippableGroup($original->pattern) || null !== AutoPossession::repeatBeforeSkippableGroup($rewritten->pattern))
            && AutoPossession::shape($original->pattern, false) !== AutoPossession::shape($rewritten->pattern, false);
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
     * @return bool true when proven, false when disproven or unproven, a
     *              rewrite the parser refuses included: its error would
     *              name a pattern the caller never wrote
     */
    private function verifyOptimizedPatternWithAutomata(string $original, string $optimized): bool
    {
        try {
            return $this->check->verify($original, $optimized);
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
