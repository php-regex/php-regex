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

use PHPRegex\Linter\Rule\Support\EscapeJoin;
use PHPRegex\Linter\Rule\Support\LanguageQuestions;
use PHPRegex\Linter\Rule\Support\NodePredicates;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\SequenceNode;

/**
 * Detects an unbounded quantifier whose item can match the empty string,
 * as "(a*)*" or "(?:a|b?)+": PCRE ends the loop on an empty iteration,
 * which matches nothing more, and when every empty way through the item
 * enters a capturing group that last, empty iteration sets the capture to
 * "": "(a*)*" on "aaa" captures "". An item that fails rather than match
 * the empty string, as "(?:a|(*FAIL))", is sound.
 *
 * One defect, one issue: where an empty alternative, a quantified
 * lookaround or a nested quantifier already reports the repeat, this rule
 * stays silent, as long as the configuration turns that rule on.
 *
 * @internal
 */
final class EmptyRepeatRule extends AbstractLintRule
{
    private const ID = 'regex.lint.quantifier.emptyRepeat';

    /**
     * @var list<LintRuleInterface> the rules that report the same repeat their own way
     */
    private readonly array $owners;

    public function __construct()
    {
        $this->owners = [new QuantifiedAssertionRule(), new NestedQuantifierRule(), new NestedDotStarRule()];
    }

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [QuantifierNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof QuantifierNode) {
            return []; // never taken: getNodeTypes() dispatches quantifiers only
        }

        $bounds = QuantifierBounds::parse($node->quantifier);
        if (null === $bounds || null !== $bounds->max || !self::matchesEmpty($node->node, false)) {
            return [];
        }

        if (self::holdsAnEmptyAlternative($node->node) && $context->isRuleEnabled('regex.lint.alternation.empty')) {
            return [];
        }

        foreach ($this->owners as $owner) {
            foreach ($owner->check($node, $context) as $violation) {
                if ($context->isRuleEnabled($violation->id)) {
                    return [];
                }
            }
        }

        if (!self::matchesEmpty($node->node, true)) {
            return [new RuleViolation(
                self::ID,
                \sprintf('Quantifier "%s" repeats a group that can match the empty string: the last, empty iteration is redundant, and changes the capture to "".', $node->quantifier),
                $node->getStartPosition(),
            )];
        }

        $rewrite = self::rewrite($node, $context);

        return [new RuleViolation(
            self::ID,
            \sprintf('Quantifier "%s" repeats an item that can match the empty string: an empty iteration matches nothing more.', $node->quantifier),
            $node->getStartPosition(),
            null === $rewrite ? null : \sprintf('Write "%s" instead.', $rewrite),
        )];
    }

    /**
     * Whether the node can match the empty string; with $outsideCaptures,
     * without entering a capturing group. A node that always fails, as
     * "(*FAIL)" or "(?!)", matches nothing at all; DEFINE matches the
     * empty string without running the groups it holds.
     */
    private static function matchesEmpty(NodeInterface $node, bool $outsideCaptures): bool
    {
        if (NodePredicates::alwaysFails($node)) {
            return false;
        }

        if ($node instanceof GroupNode) {
            // A lookaround reads nothing, and a capture inside it keeps what
            // the lookaround read: "(?:x|(?=(a)))*" on "xa" captures "a".
            if (!NodePredicates::isTransparentGroup($node->type)) {
                return true;
            }

            if ($outsideCaptures && self::isCapturing($node)) {
                return false;
            }

            return self::matchesEmpty($node->child, $outsideCaptures);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!self::matchesEmpty($child, $outsideCaptures)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (self::matchesEmpty($alternative, $outsideCaptures)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof QuantifierNode) {
            return 0 === QuantifierMath::parseRange($node->quantifier)[0] || self::matchesEmpty($node->node, $outsideCaptures);
        }

        if ($node instanceof ConditionalNode) {
            return self::matchesEmpty($node->yes, $outsideCaptures) || self::matchesEmpty($node->no, $outsideCaptures);
        }

        return $node instanceof DefineNode || NodePredicates::canBeEmpty($node);
    }

    /**
     * The repeat rewritten, when the item is a non-capturing group around
     * one greedy repeat from zero of an item that reads a character, and
     * both repeats are greedy: "(?:a*)+", "(?:a?)*" and "(?:a{0,2})+" are
     * each "a*", where "(?:a{0})+" reads nothing. Null when no rewrite of
     * that shape applies.
     */
    private static function rewrite(QuantifierNode $node, LintContext $context): ?string
    {
        $group = $node->node;
        if (QuantifierType::Greedy !== $node->type || !$group instanceof GroupNode || GroupType::NonCapturing !== $group->type) {
            return null;
        }

        $inner = $group->child;
        if (!$inner instanceof QuantifierNode) {
            return null;
        }

        // "(?:a{0})+" reads nothing at all: only a maximum of one or more is "a*".
        [$min, $max] = QuantifierMath::parseRange($inner->quantifier);
        if (QuantifierType::Greedy !== $inner->type
            || 0 !== $min
            || (null !== $max && $max < 1)
            || self::matchesEmpty($inner->node, false)
            || self::captures($inner->node)
        ) {
            return null;
        }

        $operand = LanguageQuestions::text($inner->node, $context);
        // A quoted character's text is the character alone: "\Q*\E" reads "*".
        if ('' === $operand || self::isQuoted($context->pattern->source, $inner->node->getStartPosition())) {
            return null;
        }

        $rewrite = $operand.'*';

        return EscapeJoin::separates($node, $rewrite, $context) ? null : $rewrite;
    }

    /**
     * Whether an alternative of the item is written empty, as in "(|a)+":
     * alternation.empty reports it.
     */
    private static function holdsAnEmptyAlternative(NodeInterface $node): bool
    {
        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (NodePredicates::isSyntacticallyEmptyAlternative($alternative) || self::holdsAnEmptyAlternative($alternative)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof GroupNode && NodePredicates::isTransparentGroup($node->type)) {
            return self::holdsAnEmptyAlternative($node->child);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (self::holdsAnEmptyAlternative($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the offset falls inside a \Q...\E span of the source.
     */
    private static function isQuoted(string $source, int $offset): bool
    {
        $quoted = false;
        for ($i = 0; $i < $offset; $i++) {
            if ($quoted) {
                if ('\\' === $source[$i] && 'E' === ($source[$i + 1] ?? '')) {
                    $quoted = false;
                    $i++;
                }
            } elseif ('\\' === $source[$i]) {
                $quoted = 'Q' === ($source[$i + 1] ?? '');
                $i++;
            }
        }

        return $quoted;
    }

    private static function captures(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && self::isCapturing($node)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::captures($child)) {
                return true;
            }
        }

        return false;
    }

    private static function isCapturing(GroupNode $group): bool
    {
        return \in_array($group->type, [GroupType::Capturing, GroupType::Named], true);
    }
}
