<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule;

use PhpRegex\Linter\Rule\Support\CharClassSets;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;

/**
 * Detects quantifier metacharacters (+, *, ?) used as literals inside
 * character classes, which is a common source of confusion.
 */
final class LiteralMetacharInCharClassRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.charclass.literalMetachar'];
    }

    public function getNodeTypes(): array
    {
        return [CharClassNode::class];
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if (!$node instanceof CharClassNode) {
            return [];
        }

        // Skip negated classes — [^\s+] (exclude whitespace and plus) is
        // virtually always intentional.
        if ($node->isNegated) {
            return [];
        }

        $parts = CharClassSets::collectParts($node->expression);
        // Only flag when the class also contains \w, \d, or \s (shorthand
        // types that the author likely intended to quantify).
        $hasShorthand = false;
        foreach ($parts as $part) {
            if ($part instanceof CharTypeNode && \in_array($part->value, ['w', 'd', 's', 'W', 'D', 'S'], true)) {
                $hasShorthand = true;

                break;
            }
        }

        if (!$hasShorthand) {
            return [];
        }

        // Count distinct non-metachar elements.  When the class contains 3+
        // elements beyond the metachar itself (e.g. [\w+.-], [a-z\d/+]),
        // the literal +/*/?  is almost certainly intentional — the developer
        // is building a character set, not trying to quantify a shorthand.
        $metachars = [];
        $otherCount = 0;

        foreach ($parts as $part) {
            if ($part instanceof LiteralNode && 1 === \strlen($part->value) && \in_array($part->value, ['+', '*', '?'], true)) {
                $metachars[] = $part;
            } else {
                $otherCount++;
            }
        }

        if ([] === $metachars || $otherCount > 2) {
            return [];
        }

        $meta = $metachars[0];

        return [new RuleViolation(
            'regex.lint.charclass.literalMetachar',
            \sprintf(
                '"%s" is a literal character inside a character class, not a quantifier.',
                $meta->value,
            ),
            $meta->startPosition,
            \sprintf(
                'Inside [...], "%s" matches a literal "%s". '
                .'If you meant to quantify a shorthand like \\w, use \\w%s outside the class.',
                $meta->value,
                $meta->value,
                $meta->value,
            ),
        )];
    }
}
