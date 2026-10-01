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
use PhpRegex\Linter\Rule\Support\CodePoints;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\RangeNode;

/**
 * Detects two-element (or single-element) ranges that are clearer as an
 * explicit character list.
 */
final class UselessCharClassRangeRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.range.useless'];
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

        $parts = CharClassSets::collectParts($node->expression);
        $unicodeMode = $context->pattern->unicodeMode;
        $issues = [];

        foreach ($parts as $part) {
            if (!$part instanceof RangeNode) {
                continue;
            }

            $start = CodePoints::fromNode($part->start, $unicodeMode);
            $end = CodePoints::fromNode($part->end, $unicodeMode);
            if (null === $start || null === $end) {
                continue;
            }

            $min = min($start, $end);
            $max = max($start, $end);

            if ($max - $min > 1) {
                continue;
            }

            $hint = $max === $min
                ? \sprintf('Use %s directly instead of a range.', CodePoints::formatCodePointForHint($min))
                : \sprintf(
                    'Use %s and %s explicitly instead of a range.',
                    CodePoints::formatCodePointForHint($min),
                    CodePoints::formatCodePointForHint($max),
                );

            $issues[] = new RuleViolation(
                'regex.lint.range.useless',
                'Character range is unnecessary; list the characters explicitly.',
                $part->startPosition,
                $hint,
            );
        }

        return $issues;
    }
}
