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

use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects a useless 's' flag: the pattern contains no dots.
 *
 * Stateful: tracks dots during traversal and emits in finish().
 *
 * @internal
 */
final class UselessSFlagRule extends AbstractLintRule
{
    private bool $hasDots = false;

    public function getRuleIds(): array
    {
        return ['regex.lint.flag.useless.s'];
    }

    public function getNodeTypes(): array
    {
        return [DotNode::class];
    }

    public function begin(LintContext $context): void
    {
        $this->hasDots = false;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        $this->hasDots = true;

        return [];
    }

    public function finish(LintContext $context): array
    {
        if ($context->pattern->hasFlag('s') && !$this->hasDots) {
            return [new RuleViolation(
                'regex.lint.flag.useless.s',
                "Flag 's' is useless: the pattern contains no dots.",
            )];
        }

        return [];
    }
}
