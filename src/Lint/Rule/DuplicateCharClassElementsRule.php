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

use RegexParser\Analysis\CharSet;
use RegexParser\Lint\Rule\Support\CharClassSets;
use RegexParser\LintIssue;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\NodeInterface;

/**
 * Detects character-class elements whose matches are fully covered by the
 * other elements of the same class.
 */
final class DuplicateCharClassElementsRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.charclass.duplicateChars'];
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
        if (\count($parts) < 2) {
            return [];
        }

        $unicodeMode = $context->pattern->unicodeMode;

        $entries = [];
        foreach ($parts as $part) {
            $set = CharClassSets::partCharSet($part, $unicodeMode);
            $entries[] = [
                'node' => $part,
                'set' => $set,
                'basic' => CharClassSets::isBasicPart($part),
            ];
        }

        foreach ($entries as $index => $entry) {
            if (!$entry['set'] instanceof CharSet || $entry['set']->isUnknown() || $entry['set']->isEmpty()) {
                continue;
            }

            $otherUnion = CharSet::empty();
            $otherBasicUnion = CharSet::empty();
            foreach ($entries as $j => $other) {
                if ($j === $index) {
                    continue;
                }

                if (!$other['set'] instanceof CharSet || $other['set']->isUnknown() || $other['set']->isEmpty()) {
                    continue;
                }

                $otherUnion = $otherUnion->union($other['set']);
                if ($other['basic']) {
                    $otherBasicUnion = $otherBasicUnion->union($other['set']);
                }
            }

            if ($otherUnion->isEmpty()) {
                continue;
            }

            if (CharClassSets::isSubset($entry['set'], $otherUnion)) {
                if ($entry['basic'] && CharClassSets::isSubset($entry['set'], $otherBasicUnion)) {
                    continue;
                }

                return [new LintIssue(
                    'regex.lint.charclass.duplicateChars',
                    'Character class contains duplicate elements that do not add new matches.',
                    $entry['node']->getStartPosition(),
                    'Remove the redundant character class element.',
                )];
            }
        }

        return [];
    }
}
