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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * Detects a lookahead the pattern after it contradicts: "(?=a)b" asks for
 * an "a" where the pattern reads a "b", "(?!a)a" forbids the "a" it reads.
 *
 * The automata decide it. What follows the lookahead runs through the
 * enclosing groups and quantifiers to the end of the pattern, or of the
 * lookaround holding it; a quantifier around the lookahead may repeat its
 * item again, at most as often as it still can. The lookahead holds at a
 * point of some subject the continuation matches from, any character
 * before it, any text after: when no such subject exists, the pattern
 * never matches through the lookahead. Outside the regular subset (a
 * backreference, a lookbehind or "\K" in the continuation), past the work
 * cap, or where a subroutine call runs the lookahead from elsewhere, the
 * rule stays silent.
 *
 * A lookahead repeated by a quantifier is quantifier.assertion's, or
 * quantifier.emptyRepeat's; an empty negative lookahead "(?!)" fails on
 * purpose.
 *
 * @internal
 */
final class ImpossibleLookaroundRule extends AbstractLintRule
{
    private const ID = 'regex.lint.lookaround.impossible';

    /**
     * How much of the pattern after the lookahead, in bytes as written, is
     * read: a contradiction sits within the next few items, and the
     * automata's work grows with what they are given.
     */
    private const MAX_CONTINUATION_LENGTH = 24;

    /**
     * The longest lookahead, in bytes as written, the rule reads.
     */
    private const MAX_LOOKAHEAD_LENGTH = 32;

    /**
     * The DFAs the automata built for this rule's questions, kept for the
     * ones that read the same pattern again.
     */
    private readonly InMemoryDfaCache $dfas;

    private readonly QuestionBudget $questions;

    public function __construct()
    {
        $this->dfas = new InMemoryDfaCache();
        $this->questions = new QuestionBudget();
    }

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [GroupNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof GroupNode
            || !\in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative], true)
            || '*' === $node->flags
            || (GroupType::LookaheadNegative === $node->type && NodePredicates::canBeEmpty($node->child))
            || self::isRepeated($context)
            || $context->isReenteredBySubroutine($node)
        ) {
            return [];
        }

        $pattern = $this->continuationPattern($node, $context);
        if (null === $pattern || !$this->questions->allows($context) || true !== LanguageQuestions::matchesNothing($pattern, $this->dfas)) {
            return [];
        }

        $written = LanguageQuestions::text($node, $context);

        return [new RuleViolation(
            self::ID,
            GroupType::LookaheadPositive === $node->type
                ? \sprintf('Lookahead "%s" asks for what the pattern after it can never read: the pattern can never match through here.', $written)
                : \sprintf('Negative lookahead "%s" forbids whatever the pattern after it reads: the pattern can never match through here.', $written),
            $node->getStartPosition(),
            'Fix the lookahead or what follows it, or remove the branch.',
        )];
    }

    /**
     * Whether a quantifier repeats the lookahead, through the groups and
     * one-item sequences around it.
     */
    private static function isRepeated(LintContext $context): bool
    {
        foreach (array_reverse($context->parents()) as $parent) {
            if ($parent instanceof QuantifierNode) {
                return true;
            }

            if (($parent instanceof GroupNode && NodePredicates::isTransparentGroup($parent->type))
                || ($parent instanceof SequenceNode && 1 === \count($parent->children))
            ) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * The pattern that matches a subject when the lookahead holds where the
     * continuation matches from: "(?s:.)?" for the character before it when
     * a word boundary reads it, then the lookahead and the continuation,
     * each part under the flags in force there, then "(?s:.)*" for the rest
     * of the subject. Null when the continuation leaves what the rule can
     * read.
     */
    private function continuationPattern(GroupNode $lookahead, LintContext $context): ?string
    {
        $parents = $context->parents();
        if ([] === $parents) {
            // The lookahead is the whole pattern: nothing follows it.
            return null;
        }

        if ($lookahead->getEndPosition() - $lookahead->getStartPosition() > self::MAX_LOOKAHEAD_LENGTH) {
            return null;
        }

        $spelled = LanguageQuestions::spelledFlags($context, $lookahead);
        if (null === $spelled) {
            return null;
        }

        $root = $parents[0];
        $patternFlags = $context->pattern->flags;
        $text = LanguageQuestions::scoped(LanguageQuestions::text($lookahead, $context), $context->flagsAt($lookahead, $root, $patternFlags), $spelled);
        $continuation = [];
        $child = $lookahead;

        $budget = self::MAX_CONTINUATION_LENGTH;
        foreach (array_reverse($parents) as $parent) {
            if ($parent instanceof SequenceNode) {
                $children = array_values($parent->children);
                $index = array_search($child, $children, true);
                if (!\is_int($index)) {
                    return null; // never taken: each parent on the stack holds the node walked before it
                }

                // Past the budget the continuation is cut short, and what
                // it can match only grows: the answer stays sound.
                $after = [];
                foreach (\array_slice($children, $index + 1) as $next) {
                    $budget -= $next->getEndPosition() - $next->getStartPosition();
                    if ($budget < 0) {
                        break;
                    }
                    $after[] = $next;
                }

                $afterText = '';
                if ([] !== $after) {
                    $continuation = [...$continuation, ...$after];
                    $end = $after[\count($after) - 1]->getEndPosition();
                    $afterText = self::textWithoutLookaheads($child->getEndPosition(), $end, $after, $context);
                }

                $text = LanguageQuestions::scoped($text.$afterText, $context->flagsAt($child, $root, $patternFlags), $spelled);
            } elseif ($parent instanceof QuantifierNode) {
                $bounds = QuantifierBounds::parse($parent->quantifier);
                if (null === $bounds) {
                    return null; // never taken: the parser builds no quantifier QuantifierBounds cannot read
                }

                if (1 !== $bounds->max) {
                    $budget -= $parent->node->getEndPosition() - $parent->node->getStartPosition();
                    if ($budget < 0) {
                        break;
                    }

                    $continuation[] = $parent->node;
                    $repeat = null === $bounds->max ? '*' : '{0,'.($bounds->max - 1).'}';
                    $body = self::textWithoutLookaheads($parent->node->getStartPosition(), $parent->node->getEndPosition(), [$parent->node], $context);
                    $text = LanguageQuestions::scoped($text.'(?:'.$body.')'.$repeat, $context->flagsAt($parent, $root, $patternFlags), $spelled);
                }
            } elseif ($parent instanceof GroupNode) {
                if (\in_array($parent->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative], true)) {
                    // The continuation ends with the lookahead holding it.
                    break;
                }

                if (!NodePredicates::isTransparentGroup($parent->type)) {
                    return null;
                }
            } elseif (!$parent instanceof AlternationNode) {
                return null;
            }

            if ($budget < 0) {
                break;
            }

            $child = $parent;
        }

        if ([] === $continuation) {
            // Nothing follows the lookahead: nothing contradicts it.
            return null;
        }

        foreach ($continuation as $node) {
            if (self::leavesTheRegularSubset($node)) {
                return null;
            }
        }

        // In UTF mode a word boundary, "\w" or a property spans the whole of
        // Unicode, which the automata take long to build: no question.
        if ($context->pattern->unicodeMode && 1 === LibraryPcre::match('/\\\\[bBwWpPX]|\[:/', $text)) {
            return null;
        }

        // Only a word boundary reads the character before: "^" and "\A" at
        // the subject's start hold, so without that character the
        // continuation matches more, never less.
        $before = 1 === LibraryPcre::match('/\\\\[bB]/', $text) ? '(?s:.)?' : '';

        return LanguageQuestions::pattern($context, $before.$text.'(?s:.)*', '', $spelled);
    }

    /**
     * The source between the offsets, the lookaheads among the nodes left
     * out: a lookahead only ever narrows what follows it, so without it the
     * continuation matches more, and the answer stays sound; the automata
     * then build one lookaround product, not several.
     *
     * @param list<NodeInterface> $nodes
     */
    private static function textWithoutLookaheads(int $start, int $end, array $nodes, LintContext $context): string
    {
        $holes = [];
        foreach ($nodes as $node) {
            self::collectLookaheads($node, $holes);
        }
        usort($holes, static fn (GroupNode $left, GroupNode $right): int => $left->getStartPosition() <=> $right->getStartPosition());

        $source = $context->pattern->source;
        $text = '';
        $at = $start;
        foreach ($holes as $hole) {
            $text .= substr($source, $at, max(0, $hole->getStartPosition() - $at));
            $at = $hole->getEndPosition();
        }

        return $text.substr($source, $at, max(0, $end - $at));
    }

    /**
     * @param list<GroupNode> $holes
     */
    private static function collectLookaheads(NodeInterface $node, array &$holes): void
    {
        if ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative], true)) {
            $holes[] = $node;

            return;
        }

        foreach ($node->getChildren() as $child) {
            self::collectLookaheads($child, $holes);
        }
    }

    /**
     * A backreference, a lookbehind, "\K", a subroutine call or a
     * conditional: the rule does not read past one.
     */
    private static function leavesTheRegularSubset(NodeInterface $node): bool
    {
        if ($node instanceof BackrefNode
            || $node instanceof KeepNode
            || $node instanceof SubroutineNode
            || $node instanceof ConditionalNode
            || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true))
        ) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::leavesTheRegularSubset($child)) {
                return true;
            }
        }

        return false;
    }
}
