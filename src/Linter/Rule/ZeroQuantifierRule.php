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

use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * Detects {0} quantifiers, which always repeat zero times.
 *
 * @internal
 */
final class ZeroQuantifierRule extends AbstractLintRule
{
    public const ID = 'regex.lint.quantifier.zero';

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
            return [];
        }

        if (!str_starts_with($node->quantifier, '{')) {
            return [];
        }

        [$min, $max] = QuantifierMath::parseRange($node);
        if (0 !== $min || 0 !== $max || self::removalChangesTheMatch($node->node)) {
            return [];
        }

        return [new RuleViolation(
            self::ID,
            'Quantifier always repeats zero times; it can be removed.',
            $node->startPosition,
            'Remove the quantified element or replace it with an empty pattern.',
            end: $node->getEndPosition(),
        )];
    }

    /**
     * A repeat of zero copies still numbers the groups it holds, which a
     * call may reach, and an assertion it holds still tells PCRE2 where a
     * match may start: "(?:x|(?=a)){0}\w" never matches "\tb", where "\w"
     * does. Removing it then changes the match, as the optimizer guards it.
     */
    private static function removalChangesTheMatch(NodeInterface $node): bool
    {
        if ($node instanceof AnchorNode || $node instanceof AssertionNode
            || $node instanceof BackrefNode || $node instanceof SubroutineNode || $node instanceof ConditionalNode
            || ($node instanceof GroupNode && \in_array($node->type, [
                GroupType::Capturing, GroupType::Named, GroupType::BranchReset,
                GroupType::LookaheadPositive, GroupType::LookaheadNegative,
                GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring,
            ], true))
        ) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::removalChangesTheMatch($child)) {
                return true;
            }
        }

        return false;
    }
}
