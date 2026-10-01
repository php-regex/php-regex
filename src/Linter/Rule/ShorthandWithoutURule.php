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
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\NodeInterface;

/**
 * Detects \w, \d, \s shorthands that match only ASCII without the /u flag.
 *
 * Disabled by default; enable via the 'unicode.shorthandWithoutU' rule ID.
 *
 * @internal
 */
final class ShorthandWithoutURule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.unicode.shorthandWithoutU'];
    }

    public function getNodeTypes(): array
    {
        return [CharTypeNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof CharTypeNode) {
            return [];
        }

        if (!\in_array($node->value, ['w', 'd', 's', 'W', 'D', 'S'], true) || $context->pattern->unicodeMode) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.unicode.shorthandWithoutU',
            \sprintf('Shorthand "\\%s" matches only ASCII without /u flag.', $node->value),
            $node->startPosition,
            'Add /u flag for Unicode support, or use \\p{L} for letters.',
            LintSeverity::Style,
        )];
    }
}
