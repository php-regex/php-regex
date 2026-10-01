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

use PhpRegex\Linter\LintSeverity;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\UnicodePropNode;

/**
 * Detects Unicode properties used without the /u flag.
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
            \sprintf('Unicode property "\\p{%s}" requires /u flag.', trim($node->prop, '^{}')),
            $node->startPosition,
            'Add /u flag to enable Unicode property matching.',
            LintSeverity::Error,
        )];
    }
}
