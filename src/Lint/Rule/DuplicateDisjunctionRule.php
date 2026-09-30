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

namespace RegexParser\Lint\Rule;

use RegexParser\Internal\DisplayEscaper;
use RegexParser\Lint\Rule\Support\NodePredicates;
use RegexParser\LintIssue;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\SequenceNode;
use RegexParser\NodeVisitor\CompilerNodeVisitor;

/**
 * Detects duplicate alternation branches.
 */
final class DuplicateDisjunctionRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.alternation.duplicateDisjunction'];
    }

    public function getNodeTypes(): array
    {
        return [AlternationNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof AlternationNode) {
            return [];
        }

        $seen = [];

        foreach ($node->alternatives as $alt) {
            if (NodePredicates::isSyntacticallyEmptyAlternative($alt)) {
                continue;
            }

            if ($this->alternativeHasCapturingGroupOrBackref($alt)) {
                continue;
            }

            $compiler = new CompilerNodeVisitor();
            $signature = $alt->accept($compiler);
            if (isset($seen[$signature])) {
                $display = DisplayEscaper::escape($signature);

                return [new LintIssue(
                    'regex.lint.alternation.duplicateDisjunction',
                    \sprintf('Duplicate alternation branch "%s".', $display),
                    $alt->getStartPosition(),
                    'Remove the redundant alternative.',
                )];
            }

            $seen[$signature] = true;
        }

        return [];
    }

    private function alternativeHasCapturingGroupOrBackref(NodeInterface $node): bool
    {
        if ($node instanceof BackrefNode) {
            return true;
        }

        if ($node instanceof GroupNode) {
            if (GroupType::T_GROUP_BRANCH_RESET === $node->type
                || GroupType::T_GROUP_CAPTURING === $node->type
                || GroupType::T_GROUP_NAMED === $node->type
            ) {
                return true;
            }

            return $this->alternativeHasCapturingGroupOrBackref($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                if ($this->alternativeHasCapturingGroupOrBackref($alt)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($this->alternativeHasCapturingGroupOrBackref($child)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof QuantifierNode) {
            return $this->alternativeHasCapturingGroupOrBackref($node->node);
        }

        if ($node instanceof ConditionalNode) {
            return $this->alternativeHasCapturingGroupOrBackref($node->condition)
                || $this->alternativeHasCapturingGroupOrBackref($node->yes)
                || $this->alternativeHasCapturingGroupOrBackref($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->alternativeHasCapturingGroupOrBackref($node->content);
        }

        if ($node instanceof CharClassNode) {
            return $this->alternativeHasCapturingGroupOrBackref($node->expression);
        }

        if ($node instanceof RangeNode) {
            return $this->alternativeHasCapturingGroupOrBackref($node->start)
                || $this->alternativeHasCapturingGroupOrBackref($node->end);
        }

        return false;
    }
}
