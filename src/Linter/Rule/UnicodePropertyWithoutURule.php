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
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Detects Unicode properties used without the /u flag.
 *
 * @internal
 */
final class UnicodePropertyWithoutURule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.unicode.propertyWithoutU'];
    }

    public function getNodeTypes(): array
    {
        return [UnicodePropNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof UnicodePropNode) {
            return [];
        }

        if ($context->pattern->unicodeMode) {
            return [];
        }

        return [new RuleViolation(
            'regex.lint.unicode.propertyWithoutU',
            \sprintf('Without the /u flag, Unicode property "\\p{%s}" only covers the first 256 code points.', trim($node->prop, '^{}')),
            $node->startPosition,
            'Add the /u flag to match beyond the first 256 code points.',
            LintSeverity::Error,
        )];
    }
}
