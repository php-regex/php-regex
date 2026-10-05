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
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects backreferences that can never capture useful text: references to
 * unclosed groups, groups in a different alternative, or always-empty groups.
 *
 * @internal
 */
final class UselessBackrefRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.backref.useless'];
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

        $backrefPos = $node->getStartPosition();
        if ($backrefPos < $groupInfo['end']) {
            return [new RuleViolation(
                'regex.lint.backref.useless',
                'Backreference refers to a group that has not been closed yet.',
                $node->startPosition,
                'Move the backreference after the capturing group or rewrite the pattern.',
            )];
        }

        if ($this->alternationSignaturesConflict($context->currentAlternationSignature(), $groupInfo['alternation'])) {
            return [new RuleViolation(
                'regex.lint.backref.useless',
                'Backreference refers to a group in a different alternative.',
                $node->startPosition,
                'Place the backreference in the same alternative as its capturing group.',
            )];
        }

        if ($groupInfo['alwaysEmpty']) {
            return [new RuleViolation(
                'regex.lint.backref.useless',
                'Backreference refers to a capturing group that always matches an empty string.',
                $node->startPosition,
                'Remove the backreference or replace the capturing group with literal text.',
            )];
        }

        return [];
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
