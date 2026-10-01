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
use PhpRegex\Parser\Analysis\ByteCharSet;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\NodeInterface;

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
            if (!$entry['set'] instanceof ByteCharSet || $entry['set']->isUnknown() || $entry['set']->isEmpty()) {
                continue;
            }

            $otherUnion = ByteCharSet::empty();
            $otherBasicUnion = ByteCharSet::empty();
            foreach ($entries as $j => $other) {
                if ($j === $index) {
                    continue;
                }

                if (!$other['set'] instanceof ByteCharSet || $other['set']->isUnknown() || $other['set']->isEmpty()) {
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

                return [new RuleViolation(
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
