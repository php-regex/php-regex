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

namespace PhpRegex\Linter\Rule;

use PhpRegex\Linter\Rule\Support\BackrefTarget;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\NodeInterface;

/**
 * Detects backreferences to non-existent capturing groups.
 */
final class UndefinedBackrefRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.backref.undefined'];
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

        $target = BackrefTarget::parse($node->ref);
        if (null === $target) {
            return [];
        }

        if ('number' === $target['type']) {
            $num = $target['value'];
            if (0 === $num) {
                return [];
            }

            // "\NN" of two digits or more that names no group is an octal
            // escape when it starts with an octal digit: "\11" is a tab.
            if ($num > $context->groups->maxCapturingGroup && 1 === preg_match('/^\\\\[0-7]\d/', $node->ref)) {
                return [];
            }

            if ($num > $context->groups->maxCapturingGroup) {
                return [new RuleViolation(
                    'regex.lint.backref.undefined',
                    "Backreference \\{$num} refers to a non-existent capturing group.",
                    $node->startPosition,
                )];
            }

            return [];
        }

        $name = $target['value'];
        if (!isset($context->groups->definedNamedGroups[$name])) {
            return [new RuleViolation(
                'regex.lint.backref.undefined',
                "Backreference \\k<{$name}> refers to a non-existent named group.",
                $node->startPosition,
            )];
        }

        return [];
    }
}
