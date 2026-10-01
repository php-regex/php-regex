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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Rule\Support\QuantifierMath;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;

/**
 * Detects quantified capturing groups, where only the last iteration's
 * capture is retained.
 *
 * @internal
 */
final class QuantifiedCapturingGroupRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.group.quantifiedCapture'];
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

        $inner = $node->node;
        if (!$inner instanceof GroupNode) {
            return [];
        }

        $isCapturing = \in_array($inner->type, [
            GroupType::Capturing,
            GroupType::Named,
        ], true);

        if (!$isCapturing) {
            return [];
        }

        // Only flag repeating quantifiers (not ?, {1}, {0,1})
        if (!QuantifierMath::isRepeatable($node->quantifier)) {
            return [];
        }

        $isNamed = null !== $inner->name;
        $label = $isNamed
            ? \sprintf('named group "(?<%s>...)"', $inner->name)
            : 'capturing group "(...)"';

        return [new RuleViolation(
            'regex.lint.group.quantifiedCapture',
            \sprintf(
                'Quantified %s with "%s": only the last iteration\'s capture is retained.',
                $label,
                $node->quantifier,
            ),
            $node->startPosition,
            'Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.',
            $isNamed ? LintSeverity::Warning : LintSeverity::Info,
        )];
    }
}
