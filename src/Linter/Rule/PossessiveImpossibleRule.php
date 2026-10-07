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
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects a possessive repeat, or a greedy one in an atomic group, that
 * takes every character the atom after it could read: "a*+a" never
 * matches, the repeat having taken every "a" and giving none back. Decided
 * on atoms of one character, the next atom's set inside the repeat's under
 * the flags in force at each; an unbounded repeat only (a bounded one
 * stops at its bound: "^a{0,3}+a$" matches "aaaa"), and never before an
 * atom that may match nothing.
 *
 * @internal
 */
final class PossessiveImpossibleRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.possessiveImpossible';

    /**
     * Groups that hold the repeat without changing what it matches.
     */
    private const WRAPPERS = [GroupType::NonCapturing, GroupType::InlineFlags, GroupType::Capturing, GroupType::Named, GroupType::Atomic];

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
        return [SequenceNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof SequenceNode) {
            return []; // never taken: getNodeTypes() dispatches sequences only
        }

        $children = array_values($node->children);
        $flagsAtChild = array_values($context->flagsAtEachChild($node));
        $issues = [];
        $spelled = null;
        foreach ($children as $index => $child) {
            $repeat = self::committedRepeat($child, $flagsAtChild[$index], $context);
            $nextIndex = self::nextItem($children, $index);
            if (null === $repeat || null === $nextIndex) {
                continue;
            }

            $unicode = $context->pattern->unicodeMode;
            $taken = NodePredicates::unwrapTransparentNode($repeat->node);
            $next = self::readAtom($children[$nextIndex]);
            if (null === $next || !LanguageQuestions::isOneCharacter($taken, $unicode) || !LanguageQuestions::isOneCharacter($next, $unicode)) {
                continue;
            }

            // An option the questions cannot carry is in the pattern.
            $spelled ??= LanguageQuestions::spelledFlags($context, $node) ?? false;
            if (false === $spelled) {
                continue;
            }

            $takenPattern = LanguageQuestions::pattern($context, LanguageQuestions::text($taken, $context), $context->flagsAt($taken, $child, $flagsAtChild[$index]), $spelled);
            $nextPattern = LanguageQuestions::pattern($context, LanguageQuestions::text($next, $context), $context->flagsAt($next, $children[$nextIndex], $flagsAtChild[$nextIndex]), $spelled);
            if (!$this->questions->allows($context) || true !== LanguageQuestions::isSubset($nextPattern, $takenPattern, $this->dfas)) {
                continue;
            }

            // In a negative lookaround the dead path makes the lookaround
            // hold: "(?!a*+a)b" matches "b".
            $outcome = self::inNegativeLookaround($context)
                ? 'the negative lookaround always holds'
                : 'the pattern can never match through here';

            $issues[] = new RuleViolation(
                self::ID,
                \sprintf('"%s" never gives back a character, and takes every one "%s" could read: %s.', LanguageQuestions::text($child, $context), LanguageQuestions::text($children[$nextIndex], $context), $outcome),
                $child->getStartPosition(),
                'Make the repeat greedy, or exclude from it what must follow.',
            );
        }

        return $issues;
    }

    /**
     * The unbounded repeat the item ends with that never gives back what
     * it took: possessive, or greedy inside an atomic group ("(?>a*)"),
     * the U flag swapping greedy and lazy.
     */
    private static function committedRepeat(NodeInterface $item, string $flags, LintContext $context): ?QuantifierNode
    {
        $atomic = false;
        $node = $item;
        while ($node instanceof GroupNode && \in_array($node->type, self::WRAPPERS, true) && !NodePredicates::isStandaloneInlineFlagsGroup($node)) {
            $atomic = $atomic || GroupType::Atomic === $node->type;
            $node = $node->child;
            if ($node instanceof SequenceNode) {
                // A comment beside the repeat changes nothing it matches.
                $items = array_values(array_filter($node->children, static fn (NodeInterface $child): bool => !$child instanceof CommentNode));
                $node = 1 === \count($items) ? $items[0] : $node;
            }
        }

        if (!$node instanceof QuantifierNode) {
            return null;
        }

        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null === $bounds || null !== $bounds->max) {
            return null;
        }

        if (QuantifierType::Possessive === $node->type) {
            return $node;
        }

        $ungreedy = str_contains($context->flagsAt($node, $item, $flags), 'U');
        $greedy = (QuantifierType::Greedy === $node->type) !== $ungreedy;

        return $atomic && $greedy ? $node : null;
    }

    /**
     * Whether the nearest lookaround around the node is a negative one.
     */
    private static function inNegativeLookaround(LintContext $context): bool
    {
        foreach (array_reverse($context->parents()) as $parent) {
            if ($parent instanceof GroupNode && \in_array($parent->type, [GroupType::LookaheadPositive, GroupType::LookbehindPositive], true)) {
                return false;
            }
            if ($parent instanceof GroupNode && \in_array($parent->type, [GroupType::LookaheadNegative, GroupType::LookbehindNegative], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The index of the item after the one at $index, past comments.
     *
     * @param list<NodeInterface> $children
     */
    private static function nextItem(array $children, int $index): ?int
    {
        for ($next = $index + 1, $count = \count($children); $next < $count; $next++) {
            if (!$children[$next] instanceof CommentNode) {
                return $next;
            }
        }

        return null;
    }

    /**
     * The atom the item must read a character with: the item itself, or
     * the atom it repeats at least once; null when it may match nothing.
     */
    private static function readAtom(NodeInterface $item): ?NodeInterface
    {
        if (!$item instanceof QuantifierNode) {
            return $item;
        }

        $bounds = QuantifierBounds::parse($item->quantifier);

        return null === $bounds || $bounds->min < 1 ? null : NodePredicates::unwrapTransparentNode($item->node);
    }
}
