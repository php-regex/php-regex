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

use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects a useless 'm' flag: the pattern contains no ^ or $ anchors.
 *
 * Stateful: tracks anchors during traversal and emits in finish().
 *
 * @internal
 */
final class UselessMFlagRule extends AbstractLintRule
{
    private bool $hasAnchors = false;

    public function getRuleIds(): array
    {
        return ['regex.lint.flag.useless.m'];
    }

    public function getNodeTypes(): array
    {
        return [AnchorNode::class];
    }

    public function begin(LintContext $context): void
    {
        $this->hasAnchors = false;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if ($node instanceof AnchorNode && ('^' === $node->value || '$' === $node->value)) {
            $this->hasAnchors = true;
        }

        return [];
    }

    public function finish(LintContext $context): array
    {
        if ($context->pattern->hasFlag('m') && !$this->hasAnchors) {
            return [new RuleViolation(
                'regex.lint.flag.useless.m',
                "Flag 'm' is useless: the pattern contains no ^ or $ anchor.",
            )];
        }

        return [];
    }
}
