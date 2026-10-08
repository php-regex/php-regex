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

use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects a capturing group that captures "" wherever it takes part: in
 * "/a+(a*)/" the greedy "a+" takes every "a", and $1 is always empty.
 *
 * The proof: the pattern with the group's body removed writes the same
 * $matches on every subject. That pattern keeps exactly the paths through
 * an empty group, in the same order, so the first path that matches is the
 * same in both exactly when it went through an empty group. Never in a
 * pattern that may match the empty string: after an empty match,
 * preg_match_all() tries again for a non-empty one, where another path may
 * fill the group.
 *
 * @internal
 */
final class AlwaysEmptyCaptureRule extends AbstractLintRule
{
    private const ID = 'regex.lint.group.alwaysEmptyCapture';

    private readonly QuestionBudget $questions;

    public function __construct()
    {
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
        if (!$node instanceof GroupNode || !\in_array($node->type, [GroupType::Capturing, GroupType::Named], true)) {
            return [];
        }

        // A body that reads nothing is a marker, "()", not a capture gone
        // wrong.
        $body = LanguageQuestions::text($node->child, $context);
        if ('' === $body || !NodePredicates::isConsuming($node->child)) {
            return [];
        }

        // In a branch reset another alternative fills the same slot, which
        // is all the proof reads.
        $root = $context->parents()[0] ?? $node;
        $inBranchReset = array_filter($context->parents(), static fn (NodeInterface $parent): bool => $parent instanceof GroupNode && GroupType::BranchReset === $parent->type);
        if ([] !== $inBranchReset) {
            return [];
        }

        if (NodePredicates::canBeEmpty($root) || self::anotherRuleExplains($node, $context) || !$this->questions->allows($context)) {
            return [];
        }

        if (true !== LanguageQuestions::matchTheSameFromAnyOffset($context, $node->child->getStartPosition(), $node->child->getEndPosition(), '')) {
            return [];
        }

        return [new RuleViolation(
            self::ID,
            \sprintf('Capturing group "%s" never captures any text: it is empty or unset wherever the pattern matches.', LanguageQuestions::text($node, $context)),
            $node->getStartPosition(),
            'Remove the group, or check what precedes it: a greedy repeat may take everything the group could read.',
        )];
    }

    /**
     * Whether quantifier.zero ("{0}" around the group) or quantifier.lazyEnd
     * (a lazy quantifier in or around the group that nothing able to fail
     * follows) already says why the group stays empty.
     */
    private static function anotherRuleExplains(GroupNode $group, LintContext $context): bool
    {
        $ungreedy = str_contains($context->activeFlags(), 'U');
        $quantifiers = array_filter($context->parents(), static fn (NodeInterface $parent): bool => $parent instanceof QuantifierNode);
        foreach ($quantifiers as $quantifier) {
            if (0 === QuantifierBounds::parse($quantifier->quantifier)?->max) {
                return true;
            }
        }

        if (!self::nothingFollows($group, $context->parents())) {
            return false;
        }

        foreach ([...$quantifiers, ...self::quantifiersIn($group->child)] as $quantifier) {
            if ((QuantifierType::Lazy === $quantifier->type) !== $ungreedy) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether only comments follow the node up to the end of the pattern,
     * through the sequences, alternatives, groups and quantifiers that hold
     * it.
     *
     * @param list<NodeInterface> $parents the node's ancestors, outermost first
     */
    private static function nothingFollows(NodeInterface $node, array $parents): bool
    {
        $child = $node;
        foreach (array_reverse($parents) as $parent) {
            if ($parent instanceof SequenceNode) {
                $after = \array_slice($parent->children, (int) array_search($child, $parent->children, true) + 1);
                foreach ($after as $next) {
                    if (!$next instanceof CommentNode) {
                        return false;
                    }
                }
            } elseif ($parent instanceof GroupNode && !NodePredicates::isTransparentGroup($parent->type)) {
                return false;
            } elseif (!$parent instanceof AlternationNode && !$parent instanceof QuantifierNode && !$parent instanceof GroupNode) {
                return false;
            }

            $child = $parent;
        }

        return true;
    }

    /**
     * @return list<QuantifierNode>
     */
    private static function quantifiersIn(NodeInterface $node): array
    {
        $found = $node instanceof QuantifierNode ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            array_push($found, ...self::quantifiersIn($child));
        }

        return $found;
    }
}
