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
 * Detects a useless 'D' flag: D reads only a $ anchor read without m, and
 * the pattern has none ("\Z" and a "$" in a class are not read by D).
 *
 * Stateful: tracks $ anchors during traversal and emits in finish().
 *
 * @internal
 */
final class UselessDFlagRule extends AbstractLintRule
{
    private bool $readsD = false;

    private bool $hasDollar = false;

    public function getRuleIds(): array
    {
        return ['regex.lint.flag.useless.D'];
    }

    public function getNodeTypes(): array
    {
        return [AnchorNode::class];
    }

    public function begin(LintContext $context): void
    {
        $this->readsD = false;
        $this->hasDollar = false;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if ($node instanceof AnchorNode && '$' === $node->value) {
            $this->hasDollar = true;
            $this->readsD = $this->readsD || !str_contains($context->activeFlags(), 'm');
        }

        return [];
    }

    public function finish(LintContext $context): array
    {
        if (!$context->pattern->hasFlag('D') || $this->readsD) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.flag.useless.D',
            $this->hasDollar
                ? "Flag 'D' is useless: every $ anchor is read under m, which overrides D."
                : "Flag 'D' is useless: the pattern contains no $ anchor.",
        )];
    }
}
