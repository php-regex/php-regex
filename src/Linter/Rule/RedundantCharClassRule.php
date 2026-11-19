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
 * Detects duplicate characters and overlapping ranges inside a character class.
 *
 * @internal
 */
final class RedundantCharClassRule extends AbstractLintRule
{
    public const ID = 'regex.lint.charclass.redundant';

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
        $source = $context->pattern->source;

        $ranges = [];
        $literals = [];
        // The characters each member is a byte of, null for one written as
        // it is, and where the class writes each of those characters first.
        $rangeOrigins = [];
        $literalOrigins = [];
        $written = [];
        $redundant = false;
        $redundantNotes = [];
        $redundantOverflow = 0;

        foreach ($parts as $part) {
            if (!$part instanceof RangeNode) {
                $codePoint = CodePoints::fromNode($part, $unicodeMode);
                if (null !== $codePoint) {
                    $origin = self::origin($part, $unicodeMode, $source);
                    self::write($origin, $written);
                    if (isset($literals[$codePoint])) {
                        if (!self::fromSplitting($origin, $literalOrigins[$codePoint] ?? null, $written)) {
                            $redundant = true;
                            $this->recordRedundantNote(
                                $redundantNotes,
                                $redundantOverflow,
                                CodePoints::formatCodePointForHint($codePoint).' (duplicate)',
                            );
                        }
                    } else {
                        $covering = $this->findCoveringRange($codePoint, $ranges);
                        if (null !== $covering && !self::fromSplitting($origin, $rangeOrigins[$covering] ?? null, $written)) {
                            $redundant = true;
                            $this->recordRedundantNote(
                                $redundantNotes,
                                $redundantOverflow,
                                CodePoints::formatCodePointForHint($codePoint).' (covered by range '.$ranges[$covering]['label'].')',
                            );
                        }

                        $literalOrigins[$codePoint] = $origin;
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

                // A range from "a" to "ä" holds "\xA4", the last byte of "ä":
                // an end written as it is holds no byte of the other's.
                $startOrigin = self::origin($part->start, $unicodeMode, $source);
                $endOrigin = self::origin($part->end, $unicodeMode, $source);
                $origin = null === $startOrigin && null === $endOrigin ? null : ($startOrigin ?? []) + ($endOrigin ?? []);
                self::write($origin, $written);
                $rangeLabel = $this->formatRangeForHint($start, $end);
                foreach ($ranges as $key => $existingRange) {
                    // Two ranges sharing an ASCII character share one the
                    // author wrote in both, which no split makes: "[a-äb-ö]".
                    if ($start <= $existingRange['end'] && $end >= $existingRange['start']
                        && (max($start, $existingRange['start']) < 0x80 || !self::fromSplitting($origin, $rangeOrigins[$key] ?? null, $written))
                    ) {
                        $redundant = true;
                        $this->recordRedundantNote(
                            $redundantNotes,
                            $redundantOverflow,
                            $this->describeRangeOverlap($existingRange, ['start' => $start, 'end' => $end, 'label' => $rangeLabel]),
                        );

                        break;
                    }
                }

                foreach ($literals as $codePoint => $seen) {
                    if ($codePoint >= $start && $codePoint <= $end) {
                        if (!self::fromSplitting($origin, $literalOrigins[$codePoint] ?? null, $written)) {
                            $redundant = true;
                            $this->recordRedundantNote(
                                $redundantNotes,
                                $redundantOverflow,
                                CodePoints::formatCodePointForHint($codePoint).' (covered by range '.$rangeLabel.')',
                            );
                        }
                        unset($literals[$codePoint], $literalOrigins[$codePoint]);
                    }
                }

                $ranges[] = ['start' => $start, 'end' => $end, 'label' => $rangeLabel];
                $rangeOrigins[array_key_last($ranges)] = $origin;
            }
        }

        if ($redundant) {
            return [new RuleViolation(
                self::ID,
                'Redundant elements detected in character class.',
                $node->startPosition,
                $this->formatRedundantCharClassHint($redundantNotes, $redundantOverflow),
                end: $node->getEndPosition(),
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

    /**
     * A repeated range or one inside the other can go; two ranges that only
     * partly overlap are both needed, and the fix is to merge them.
     *
     * @param array{start: int, end: int, label: string} $earlier
     * @param array{start: int, end: int, label: string} $later
     */
    private function describeRangeOverlap(array $earlier, array $later): string
    {
        if ($earlier['start'] === $later['start'] && $earlier['end'] === $later['end']) {
            return 'range '.$later['label'].' (overlaps '.$earlier['label'].')';
        }

        if ($earlier['start'] <= $later['start'] && $later['end'] <= $earlier['end']) {
            return 'range '.$later['label'].' (covered by range '.$earlier['label'].')';
        }

        if ($later['start'] <= $earlier['start'] && $earlier['end'] <= $later['end']) {
            return 'range '.$earlier['label'].' (covered by range '.$later['label'].')';
        }

        return \sprintf(
            'ranges %s and %s overlap: merge them into %s',
            $earlier['label'],
            $later['label'],
            $this->formatRangeForHint(min($earlier['start'], $later['start']), max($earlier['end'], $later['end'])),
        );
    }

    private function formatRangeForHint(int $start, int $end): string
    {
        return CodePoints::formatCodePointForHint($start).'-'.CodePoints::formatCodePointForHint($end);
    }

    /**
     * @param array<int, array{start: int, end: int, label: string}> $ranges
     *
     * @return int|null the key of the first range holding the code point
     */
    private function findCoveringRange(int $ord, array $ranges): ?int
    {
        foreach ($ranges as $key => $range) {
            if ($ord >= $range['start'] && $ord <= $range['end']) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The characters the pattern writes whole that a member of the class is
     * a byte of, by where each starts: without UTF the tree holds each byte
     * of a multibyte character apart. Null for a member written as it is.
     *
     * @return array<int, string>|null
     */
    private static function origin(NodeInterface $node, bool $unicodeMode, string $source): ?array
    {
        if ($unicodeMode || !$node instanceof LiteralNode) {
            return null;
        }

        $character = SplitCharacter::holding($source, $node);

        return null === $character ? null : [$character[1] => $character[0]];
    }

    /**
     * Notes where the class writes each character of the member first.
     *
     * @param array<int, string>|null $origin
     * @param array<string, int>      $written
     */
    private static function write(?array $origin, array &$written): void
    {
        foreach ($origin ?? [] as $at => $character) {
            $written[$character] ??= $at;
        }
    }

    /**
     * Whether a member that holds a byte an earlier one holds is only a
     * byte of a character the pattern writes, as the earlier one is, the
     * class writing that character nowhere before: "[äö]" holds 0xC3
     * twice, which no one wrote twice, and "[ö¤ää]" writes "ä" twice,
     * though "ö" and "¤" hold each of its bytes first. The class is
     * reported for the missing u already, whose fix leaves no duplicate.
     *
     * @param array<int, string>|null $one     the member's characters, by where each starts
     * @param array<int, string>|null $other   the earlier member's
     * @param array<string, int>      $written where the class writes each character first
     */
    private static function fromSplitting(?array $one, ?array $other, array $written): bool
    {
        if (null === $one || null === $other) {
            return false;
        }

        foreach ($one as $at => $character) {
            if (($written[$character] ?? $at) !== $at) {
                return false;
            }
        }

        return true;
    }
}
