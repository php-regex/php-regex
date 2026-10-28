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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RangeNode;

/**
 * The character classes of a pattern for Rewriter: a class written as the
 * shorthand it stands for, "[0-9]" as "\d"; the members of a class sorted,
 * merged and written as ranges; the classes of an alternation merged into
 * one, and an alternation of single characters written as a class.
 *
 * It shares the rewriter's RewriteState, and reads the parts of a class
 * the rewriter has already rewritten.
 *
 * @internal
 */
final class ClassRewrites
{
    private const CHAR_CLASS_META = [']' => true, '\\' => true, '^' => true, '-' => true];

    /**
     * @param string|null $locale the LC_CTYPE locale the pattern runs under,
     *                            when it is known: "\w" stands for
     *                            "[a-zA-Z0-9_]" under "C" and "POSIX" only
     */
    public function __construct(
        private readonly RewriteState $state,
        private readonly bool $optimizeDigits = true,
        private readonly bool $optimizeWord = true,
        private readonly bool $ranges = true,
        private readonly bool $canonicalizeCharClasses = true,
        private readonly ?string $locale = null,
    ) {}

    /**
     * The shorthand the class stands for, "\d" or "\w" and their negations,
     * or null.
     *
     * "\d" is the ten ASCII digits under every locale, as C's isdigit()
     * is; with Unicode properties it matches every decimal digit, as
     * U+0663. "\w" stands for "[a-zA-Z0-9_]" only under a locale known
     * to be C or POSIX: with Unicode properties it matches every
     * letter, and without them PHP builds its table from LC_CTYPE once
     * a program calls setlocale(), where a Latin-1 locale makes it take
     * "\xE9".
     *
     * @param array<NodeInterface> $parts the members of the class, as written
     */
    public function shorthand(CharClassNode $node, array $parts): ?CharTypeNode
    {
        if ($this->optimizeDigits && !$this->state->unicodeProperties && 1 === \count($parts)) {
            $part = $parts[0];
            if ($part instanceof RangeNode && $part->start instanceof LiteralNode && $part->end instanceof LiteralNode) {
                if ('0' === $part->start->value && '9' === $part->end->value) {
                    $this->state->changed(Rewriter::DIGITS);

                    return new CharTypeNode($node->isNegated ? 'D' : 'd', $node->startPosition, $node->endPosition);
                }
            }
        }

        if ($this->optimizeWord && !$this->state->unicodeProperties && \in_array($this->locale, ['C', 'POSIX'], true) && $this->isFullWordClass($parts)) {
            $this->state->changed(Rewriter::WORD);

            return new CharTypeNode($node->isNegated ? 'W' : 'w', $node->startPosition, $node->endPosition);
        }

        return null;
    }

    /**
     * The members of a class sorted and merged, when the option is on, and
     * whether that rebuilt them.
     *
     * @param array<NodeInterface> $parts
     *
     * @return array{0: array<NodeInterface>, 1: bool}
     */
    public function canonicalized(array $parts): array
    {
        if (!$this->canonicalizeCharClasses) {
            return [$parts, false];
        }

        [$normalizedParts, $normalizedChanged] = $this->normalizeCharClassParts($parts);
        // Ranges are taken apart and built again: only members that
        // read otherwise were rewritten.
        if ($normalizedChanged && $this->partsToString($normalizedParts) !== $this->partsToString($parts)) {
            $this->state->changed(Rewriter::CLASS_MEMBERS);
        }

        return [$normalizedParts, $normalizedChanged];
    }

    /**
     * @param array<NodeInterface> $alternatives
     *
     * @phpstan-assert-if-true array<LiteralNode> $alternatives
     */
    public function canAlternationBeCharClass(array $alternatives): bool
    {
        if (empty($alternatives)) {
            return false;
        }

        foreach ($alternatives as $alt) {
            if (!$alt instanceof LiteralNode) {
                return false;
            }
            if (1 !== \strlen($alt->value)) {  // Also excludes empty strings
                return false;
            }
            if (isset(self::CHAR_CLASS_META[$alt->value])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Merges adjacent character class nodes in an alternation.
     * For example: [a-z]|[0-9] becomes [a-z0-9]
     *
     * @param array<NodeInterface> $alternatives
     *
     * @return array<NodeInterface>
     */
    public function mergeAdjacentCharClasses(array $alternatives): array
    {
        if (\count($alternatives) < 2) {
            return $alternatives;
        }

        $merged = [];
        $i = 0;

        while ($i < \count($alternatives)) {
            $current = $alternatives[$i];

            // Check if current is a character class or a char type that can be converted
            $isCharClass = $current instanceof CharClassNode && !$current->isNegated;
            $isCharType = $current instanceof CharTypeNode && $this->canConvertCharTypeToCharClass($current);

            if (!$isCharClass && !$isCharType) {
                $merged[] = $current;
                $i++;

                continue;
            }

            // Look ahead to find adjacent character classes or char types
            $nodesToMerge = [$current];
            $j = $i + 1;

            while ($j < \count($alternatives)) {
                $next = $alternatives[$j];
                $isNextCharClass = $next instanceof CharClassNode && !$next->isNegated;
                $isNextCharType = $next instanceof CharTypeNode && $this->canConvertCharTypeToCharClass($next);

                if ($isNextCharClass || $isNextCharType) {
                    $nodesToMerge[] = $next;
                    $j++;
                } else {
                    break;
                }
            }

            // If we found multiple adjacent character classes/char types, merge them
            if (\count($nodesToMerge) > 1) {
                $merged[] = $this->mergeCharClassesAndCharTypes($nodesToMerge);
                $i = $j;
            } else {
                $merged[] = $current;
                $i++;
            }
        }

        return $merged;
    }

    /**
     * Whether the parts are exactly "a-z", "A-Z", "0-9" and "_", in any
     * order.
     *
     * @param array<NodeInterface> $parts
     */
    private function isFullWordClass(array $parts): bool
    {
        // The bytes the members cover, whatever their spelling: a range
        // inside another or a letter written twice changes nothing.
        $covered = [];
        foreach ($parts as $part) {
            if ($part instanceof RangeNode && $part->start instanceof LiteralNode && $part->end instanceof LiteralNode
                && 1 === \strlen($part->start->value) && 1 === \strlen($part->end->value)) {
                for ($byte = \ord($part->start->value); $byte <= \ord($part->end->value); $byte++) {
                    $covered[$byte] = true;
                }
            } elseif ($part instanceof LiteralNode && 1 === \strlen($part->value)) {
                $covered[\ord($part->value)] = true;
            } else {
                return false;
            }
        }

        ksort($covered);

        return array_keys($covered) === [...range(0x30, 0x39), ...range(0x41, 0x5A), 0x5F, ...range(0x61, 0x7A)];
    }

    /**
     * Classify a character by its ASCII category for range merging.
     *
     * @param int $ord the ASCII ordinal of the character
     *
     * @return int category: 0=other, 1=digits, 2=uppercase, 3=lowercase
     */
    private function getCharCategory(int $ord): int
    {
        if ($ord >= 48 && $ord <= 57) { // 0-9
            return 1;
        }
        if ($ord >= 65 && $ord <= 90) { // A-Z
            return 2;
        }
        if ($ord >= 97 && $ord <= 122) { // a-z
            return 3;
        }

        return 0; // other
    }

    /**
     * @param array<NodeInterface> $parts
     *
     * @return array{0: array<NodeInterface>, 1: bool}
     */
    private function normalizeCharClassParts(array $parts): array
    {
        /** @var array<int, array{start: int, end: int, fromRange: bool}> $scalarChars */
        $scalarChars = [];
        $otherParts = [];
        $changed = false;

        foreach ($parts as $part) {
            // Only an ASCII byte is the same character in both modes: a lone
            // byte above 0x7F (byte mode) is no code point, and stays as written.
            if ($part instanceof LiteralNode && 1 === \strlen($part->value) && \ord($part->value) < 0x80) {
                $ord = \ord($part->value);
                if (isset($scalarChars[$ord])) {
                    $scalarChars[$ord]['start'] = min($scalarChars[$ord]['start'], $part->startPosition);
                    $scalarChars[$ord]['end'] = max($scalarChars[$ord]['end'], $part->endPosition);
                    $changed = true;
                } else {
                    $scalarChars[$ord] = ['start' => $part->startPosition, 'end' => $part->endPosition, 'fromRange' => false];
                }

                continue;
            }

            if ($part instanceof RangeNode
                && $part->start instanceof LiteralNode
                && $part->end instanceof LiteralNode
                && 1 === \strlen($part->start->value)
                && 1 === \strlen($part->end->value)
                && \ord($part->start->value) < 0x80
                && \ord($part->end->value) < 0x80
            ) {
                $startOrd = \ord($part->start->value);
                $endOrd = \ord($part->end->value);
                if ($startOrd > $endOrd) {
                    [$startOrd, $endOrd] = [$endOrd, $startOrd];
                }
                for ($ord = $startOrd; $ord <= $endOrd; $ord++) {
                    if (isset($scalarChars[$ord])) {
                        $scalarChars[$ord]['start'] = min($scalarChars[$ord]['start'], $part->startPosition);
                        $scalarChars[$ord]['end'] = max($scalarChars[$ord]['end'], $part->endPosition);
                        $scalarChars[$ord]['fromRange'] = true;
                    } else {
                        $scalarChars[$ord] = [
                            'start' => $part->startPosition,
                            'end' => $part->endPosition,
                            'fromRange' => true,
                        ];
                    }
                }
                $changed = true;

                continue;
            }

            $otherParts[] = $part;
        }

        if (empty($scalarChars)) {
            return [$parts, $changed];
        }

        ksort($scalarChars);

        $normalized = [];
        $hasRange = false;
        $rangeStart = 0;
        $rangeEnd = 0;
        $rangeStartPos = 0;
        $rangeEndPos = 0;
        $rangeHasExplicit = false;
        $rangeCategory = 0;

        foreach ($scalarChars as $ord => $pos) {
            $ord = (int) $ord;
            $posStart = (int) $pos['start'];
            $posEnd = (int) $pos['end'];
            $fromRange = (bool) $pos['fromRange'];

            if (!$hasRange) {
                $rangeStart = $ord;
                $rangeEnd = $ord;
                $rangeStartPos = $posStart;
                $rangeEndPos = $posEnd;
                $rangeHasExplicit = $fromRange;
                $rangeCategory = $this->getCharCategory($ord);
                $hasRange = true;

                continue;
            }

            $category = $this->getCharCategory($ord);
            $canMerge = $ord === $rangeEnd + 1;
            if ($canMerge && $this->ranges && $category !== $rangeCategory) {
                $canMerge = false;
            }

            if ($canMerge) {
                $rangeEnd = $ord;
                $rangeEndPos = max($rangeEndPos, $posEnd);
                $rangeHasExplicit = $rangeHasExplicit || $fromRange;
                $rangeCategory = $category;

                continue;
            }

            $normalized = array_merge(
                $normalized,
                $this->buildRangeOrLiteral(
                    $rangeStart,
                    $rangeEnd,
                    $rangeStartPos,
                    $rangeEndPos,
                    $this->shouldBuildRange($rangeStart, $rangeEnd, $rangeHasExplicit),
                ),
            );
            $rangeStart = $ord;
            $rangeEnd = $ord;
            $rangeStartPos = $posStart;
            $rangeEndPos = $posEnd;
            $rangeHasExplicit = $fromRange;
            $rangeCategory = $category;
        }

        $normalized = array_merge(
            $normalized,
            $this->buildRangeOrLiteral(
                $rangeStart,
                $rangeEnd,
                $rangeStartPos,
                $rangeEndPos,
                $this->shouldBuildRange($rangeStart, $rangeEnd, $rangeHasExplicit),
            ),
        );

        $finalParts = array_merge($normalized, $otherParts);

        return [$finalParts, $changed || \count($finalParts) !== \count($parts)];
    }

    /**
     * @return array<NodeInterface>
     */
    private function buildRangeOrLiteral(int $startOrd, int $endOrd, int $startPos, int $endPos, bool $allowRange = true): array
    {
        $startLiteral = new LiteralNode($this->charFromCodePoint($startOrd), $startPos, $startPos + 1);

        if ($startOrd === $endOrd) {
            return [$startLiteral];
        }

        if (!$allowRange) {
            return $this->buildLiteralSequence($startOrd, $endOrd, $startPos);
        }

        // Only create a range if it covers 3 or more characters to save space
        $coverage = $endOrd - $startOrd + 1;
        if ($coverage < 3) {
            // For 2 characters, return them as separate literals
            $endLiteral = new LiteralNode($this->charFromCodePoint($endOrd), $endPos, $endPos + 1);

            return [$startLiteral, $endLiteral];
        }

        $endLiteral = new LiteralNode($this->charFromCodePoint($endOrd), $endPos, $endPos + 1);

        return [new RangeNode($startLiteral, $endLiteral, $startPos, $endPos)];
    }

    /**
     * @return array<NodeInterface>
     */
    private function buildLiteralSequence(int $startOrd, int $endOrd, int $startPos): array
    {
        $literals = [];
        $pos = $startPos;
        for ($ord = $startOrd; $ord <= $endOrd; $ord++) {
            $literals[] = new LiteralNode($this->charFromCodePoint($ord), $pos, $pos + 1);
            $pos++;
        }

        return $literals;
    }

    private function shouldBuildRange(int $startOrd, int $endOrd, bool $hasExplicitRange): bool
    {
        if ($hasExplicitRange) {
            return true;
        }

        return 45 !== $startOrd && 45 !== $endOrd;
    }

    private function charFromCodePoint(int $codePoint): string
    {
        $char = mb_chr($codePoint);

        return false !== $char ? $char : '';
    }

    /**
     * @param array<NodeInterface> $parts
     */
    private function partsToString(array $parts): string
    {
        return implode('', array_map($this->state->spelling(...), $parts));
    }

    /**
     * Checks if a CharTypeNode can be converted to a CharClassNode.
     */
    private function canConvertCharTypeToCharClass(CharTypeNode $node): bool
    {
        // For now, only handle \d (digits) which can be converted to [0-9]
        if ('d' !== $node->value) {
            return false;
        }

        if ($this->state->unicodeProperties) {
            return false;
        }

        return $this->optimizeDigits;
    }

    /**
     * Converts a CharTypeNode to an equivalent CharClassNode.
     */
    private function convertCharTypeToCharClass(CharTypeNode $node): CharClassNode
    {
        $startPos = $node->startPosition;
        $endPos = $node->endPosition;

        return match ($node->value) {
            'd' => new CharClassNode(
                new RangeNode(
                    new LiteralNode('0', $startPos, $startPos + 1),
                    new LiteralNode('9', $startPos + 1, $startPos + 2),
                    $startPos,
                    $startPos + 2,
                ),
                false,
                $startPos,
                $endPos,
            ),
            default => throw new \LogicException("Unsupported char type: {$node->value}"),
        };
    }

    /**
     * Merges character classes and char types into a single character class.
     *
     * @param array<NodeInterface> $nodes
     */
    private function mergeCharClassesAndCharTypes(array $nodes): CharClassNode
    {
        $allParts = [];
        $startPos = $nodes[0]->getStartPosition();
        $endPos = $nodes[\count($nodes) - 1]->getEndPosition();

        foreach ($nodes as $node) {
            if ($node instanceof CharClassNode) {
                if ($node->expression instanceof AlternationNode) {
                    $allParts = array_merge($allParts, $node->expression->alternatives);
                } else {
                    $allParts[] = $node->expression;
                }
            } elseif ($node instanceof CharTypeNode) {
                // Convert char type to equivalent char class parts
                $charClass = $this->convertCharTypeToCharClass($node);
                $allParts[] = $charClass->expression;
            }
        }

        // Create a new alternation with all parts
        $mergedExpression = new AlternationNode($allParts, $startPos, $endPos);

        return new CharClassNode($mergedExpression, false, $startPos, $endPos);
    }
}
