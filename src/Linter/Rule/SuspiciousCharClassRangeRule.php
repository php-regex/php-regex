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

use PHPRegex\Linter\Rule\Support\CharClassSets;
use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RangeNode;

/**
 * Detects ASCII letter ranges like A-z that unintentionally include
 * non-letter characters.
 */
final class SuspiciousCharClassRangeRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.charclass.suspiciousRange'];
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
        foreach ($parts as $part) {
            if (!$part instanceof RangeNode) {
                continue;
            }

            if (!$part->start instanceof LiteralNode || !$part->end instanceof LiteralNode) {
                continue;
            }

            if (1 !== \strlen($part->start->value) || 1 !== \strlen($part->end->value)) {
                continue;
            }

            $startOrd = \ord($part->start->value);
            $endOrd = \ord($part->end->value);
            if ($startOrd > 127 || $endOrd > 127) {
                continue;
            }

            $minOrd = min($startOrd, $endOrd);
            $maxOrd = max($startOrd, $endOrd);

            if (CodePoints::isAsciiLetter($startOrd) && CodePoints::isAsciiLetter($endOrd) && $minOrd <= 90 && $maxOrd >= 97) {
                $rangeLabel = $part->start->value.'-'.$part->end->value;
                $minChar = \chr($minOrd);
                $maxChar = \chr($maxOrd);

                return [new RuleViolation(
                    'regex.lint.charclass.suspiciousRange',
                    \sprintf(
                        'Suspicious ASCII range "%s" includes non-letters between "%s" and "%s" in ASCII order.',
                        $rangeLabel,
                        $minChar,
                        $maxChar,
                    ),
                    $part->startPosition,
                    'Use separate ranges like [A-Z] and [a-z] (or combine as [A-Za-z]).',
                )];
            }
        }

        return [];
    }
}
