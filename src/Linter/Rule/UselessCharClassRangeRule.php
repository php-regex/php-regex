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
use PHPRegex\Linter\Rule\Support\CharClassSets;
use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RangeNode;

/**
 * Detects a range of one character, [a-a], which is a mistake: the author
 * meant a character or a wider range. A range of two consecutive characters,
 * [a-b], is correct as written and the list [ab] saves one character only,
 * so it is not reported: a generated Unicode table holds them by the thousand.
 *
 * @internal
 */
final class UselessCharClassRangeRule extends AbstractLintRule
{
    public const ID = 'regex.lint.range.useless';

    public function getRuleIds(): array
    {
        return [self::ID];
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

            if ($start !== $end) {
                continue;
            }

            $issues[] = new RuleViolation(
                self::ID,
                'Character range is unnecessary; list the characters explicitly.',
                $part->startPosition,
                \sprintf('Use %s directly instead of a range.', CodePoints::formatCodePointForHint($start)),
                LintSeverity::Warning,
                $part->getEndPosition(),
            );
        }

        return $issues;
    }
}
