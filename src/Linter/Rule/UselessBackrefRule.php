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

use PHPRegex\Linter\Rule\Support\BackrefTarget;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;

/**
 * Detects backreferences that can never capture useful text: references to
 * unclosed groups, groups in a different alternative, or always-empty groups.
 *
 * @phpstan-import-type CapturingGroupInfo from GroupIndex
 *
 * @internal
 */
final class UselessBackrefRule extends AbstractLintRule
{
    public const ID = 'regex.lint.backref.useless';

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [BackrefNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof BackrefNode) {
            return [];
        }

        if ($context->groups->containsBranchReset) {
            return [];
        }

        // A subroutine call can run the backreference again after a group
        // of another alternative, or one closed later, has captured.
        if ($context->isReenteredBySubroutine($node)) {
            return [];
        }

        $target = BackrefTarget::parse($node->ref);
        if (null === $target) {
            return [];
        }

        $groupInfo = null;
        if ('number' === $target['type']) {
            $groupInfo = $context->groups->capturingGroups[$target['value']] ?? null;
        } else {
            $groups = $context->groups->capturingGroupsByName[$target['value']] ?? [];
            if (1 === \count($groups)) {
                $groupInfo = $groups[0];
            }
        }

        if (null === $groupInfo) {
            return [];
        }

        // In a loop, the reference reads what an earlier iteration captured.
        if (self::isFilledByAnEarlierIteration($node, $groupInfo, $context)) {
            return $groupInfo['alwaysEmpty'] ? $this->alwaysEmpty($node) : [];
        }

        $backrefPos = $node->getStartPosition();
        if ($backrefPos < $groupInfo['end']) {
            return [new RuleViolation(
                self::ID,
                'Backreference refers to a group that has not been closed yet.',
                $node->startPosition,
                'Move the backreference after the capturing group or rewrite the pattern.',
                end: $node->getEndPosition(),
            )];
        }

        if ($this->alternationSignaturesConflict($context->currentAlternationSignature(), $groupInfo['alternation'])) {
            return [new RuleViolation(
                self::ID,
                'Backreference refers to a group in a different alternative.',
                $node->startPosition,
                'Place the backreference in the same alternative as its capturing group.',
                end: $node->getEndPosition(),
            )];
        }

        return $groupInfo['alwaysEmpty'] ? $this->alwaysEmpty($node) : [];
    }

    /**
     * @return list<RuleViolation>
     */
    private function alwaysEmpty(BackrefNode $node): array
    {
        return [new RuleViolation(
            self::ID,
            'Backreference refers to a capturing group that always matches an empty string.',
            $node->startPosition,
            'Remove the backreference or replace the capturing group with literal text.',
            end: $node->getEndPosition(),
        )];
    }

    /**
     * Whether a loop around both the reference and its group runs the
     * reference after an earlier iteration has set the group: an iteration
     * may take a way that skips the reference, through another branch
     * holding no reference or past a count that may be zero, as in the
     * PCRE2 manual's "(a|b\1)+" or in "(?:x(a)|\1)+". In "(a\1)+" every
     * way passes the reference first, and no iteration ever sets the group.
     *
     * @param CapturingGroupInfo $group
     */
    private static function isFilledByAnEarlierIteration(BackrefNode $node, array $group, LintContext $context): bool
    {
        $skippable = false;
        $child = $node;
        foreach (array_reverse($context->parents()) as $parent) {
            if ($parent instanceof QuantifierNode) {
                [$min, $max] = QuantifierMath::parseRange($parent);
                if ($skippable
                    && (null === $max || $max > 1)
                    && $parent->getStartPosition() <= $group['start']
                    && $group['end'] <= $parent->getEndPosition()
                ) {
                    return true;
                }

                $skippable = $skippable || 0 === $min;
            } elseif ($parent instanceof AlternationNode) {
                foreach ($parent->alternatives as $alternative) {
                    $skippable = $skippable || ($alternative !== $child && !self::holdsABackref($alternative));
                }
            }

            $child = $parent;
        }

        return false;
    }

    private static function holdsABackref(NodeInterface $node): bool
    {
        if ($node instanceof BackrefNode) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsABackref($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     */
    private function alternationSignaturesConflict(array $a, array $b): bool
    {
        foreach ($a as $id => $index) {
            if (isset($b[$id]) && $b[$id] !== $index) {
                return true;
            }
        }

        return false;
    }
}
