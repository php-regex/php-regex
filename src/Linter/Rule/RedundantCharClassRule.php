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
 * Detects duplicate characters and overlapping ranges inside a character class.
 */
final class RedundantCharClassRule extends AbstractLintRule
{
    public function getRuleIds(): array
    {
        return ['regex.lint.charclass.redundant'];
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

        $ranges = [];
        $literals = [];
        $redundant = false;
        $redundantNotes = [];
        $redundantOverflow = 0;

        foreach ($parts as $part) {
            if (!$part instanceof RangeNode) {
                $codePoint = CodePoints::fromNode($part, $unicodeMode);
                if (null !== $codePoint) {
                    if (isset($literals[$codePoint])) {
                        $redundant = true;
                        $this->recordRedundantNote(
                            $redundantNotes,
                            $redundantOverflow,
                            CodePoints::formatCodePointForHint($codePoint).' (duplicate)',
                        );
                    } else {
                        $coveringRange = $this->findCoveringRange($codePoint, $ranges);
                        if (null !== $coveringRange) {
                            $redundant = true;
                            $this->recordRedundantNote(
                                $redundantNotes,
                                $redundantOverflow,
                                CodePoints::formatCodePointForHint($codePoint).' (covered by range '.$coveringRange['label'].')',
                            );
                        }
                    }
                    $literals[$codePoint] = true;

                    continue;
                }
            }

            if ($part instanceof RangeNode) {
                $start = CodePoints::fromNode($part->start, $unicodeMode);
                $end = CodePoints::fromNode($part->end, $unicodeMode);
                if (null === $start || null === $end) {
                    continue;
                }

                if ($start > $end) {
                    continue;
                }

                $rangeLabel = $this->formatRangeForHint($start, $end);
                foreach ($ranges as $existingRange) {
                    if ($start <= $existingRange['end'] && $end >= $existingRange['start']) {
                        $redundant = true;
                        $this->recordRedundantNote(
                            $redundantNotes,
                            $redundantOverflow,
                            'range '.$rangeLabel.' (overlaps '.$existingRange['label'].')',
                        );

                        break;
                    }
                }

                foreach ($literals as $codePoint => $seen) {
                    if ($codePoint >= $start && $codePoint <= $end) {
                        $redundant = true;
                        $this->recordRedundantNote(
                            $redundantNotes,
                            $redundantOverflow,
                            CodePoints::formatCodePointForHint($codePoint).' (covered by range '.$rangeLabel.')',
                        );
                        unset($literals[$codePoint]);
                    }
                }

                $ranges[] = ['start' => $start, 'end' => $end, 'label' => $rangeLabel];
            }
        }

        if ($redundant) {
            return [new RuleViolation(
                'regex.lint.charclass.redundant',
                'Redundant elements detected in character class.',
                $node->startPosition,
                $this->formatRedundantCharClassHint($redundantNotes, $redundantOverflow),
            )];
        }

        return [];
    }

    /**
     * @param array<string, true> $notes
     */
    private function recordRedundantNote(array &$notes, int &$overflow, string $note, int $limit = 4): void
    {
        if (isset($notes[$note])) {
            return;
        }

        if (\count($notes) >= $limit) {
            $overflow++;

            return;
        }

        $notes[$note] = true;
    }

    /**
     * @param array<string, true> $notes
     */
    private function formatRedundantCharClassHint(array $notes, int $overflow): string
    {
        if ([] === $notes) {
            return 'Remove duplicate characters or overlapping ranges from the character class.';
        }

        $hint = 'Redundant elements: '.implode(', ', array_keys($notes));
        if ($overflow > 0) {
            $hint .= \sprintf(' (and %d more).', $overflow);
        }

        return $hint;
    }

    private function formatRangeForHint(int $start, int $end): string
    {
        return CodePoints::formatCodePointForHint($start).'-'.CodePoints::formatCodePointForHint($end);
    }

    /**
     * @param array<int, array{start: int, end: int, label: string}> $ranges
     *
     * @return array{start: int, end: int, label: string}|null
     */
    private function findCoveringRange(int $ord, array $ranges): ?array
    {
        foreach ($ranges as $range) {
            if ($ord >= $range['start'] && $ord <= $range['end']) {
                return $range;
            }
        }

        return null;
    }
}
