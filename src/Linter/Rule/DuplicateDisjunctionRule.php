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

use PhpRegex\Linter\Rule\Support\NodePredicates;
use PhpRegex\Parser\Internal\DisplayEscaper;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Printer\PatternPrinter;

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

            $compiler = new PatternPrinter();
            $signature = $alt->accept($compiler);
            if (isset($seen[$signature])) {
                $display = DisplayEscaper::escape($signature);

                return [new RuleViolation(
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
            if (GroupType::BranchReset === $node->type
                || GroupType::Capturing === $node->type
                || GroupType::Named === $node->type
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
