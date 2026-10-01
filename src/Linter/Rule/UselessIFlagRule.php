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

use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\UnicodePropNode;

/**
 * Detects a useless 'i' flag: the pattern contains no case-sensitive
 * characters and no backreferences.
 *
 * Stateful: aggregates case-sensitivity facts during traversal and emits
 * in finish().
 *
 * @internal
 */
final class UselessIFlagRule extends AbstractLintRule
{
    private bool $hasCaseSensitiveChars = false;

    private bool $hasBackreferences = false;

    private bool $trackCaseSensitivity = false;

    private bool $unicodeMode = false;

    public function getRuleIds(): array
    {
        return ['regex.lint.flag.useless.i'];
    }

    public function getNodeTypes(): array
    {
        return [
            LiteralNode::class,
            CharClassNode::class,
            CharLiteralNode::class,
            UnicodePropNode::class,
            BackrefNode::class,
        ];
    }

    public function begin(LintContext $context): void
    {
        $this->hasCaseSensitiveChars = false;
        $this->hasBackreferences = false;
        $this->trackCaseSensitivity = $context->pattern->hasFlag('i');
        $this->unicodeMode = $context->pattern->unicodeMode;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if ($node instanceof BackrefNode) {
            $this->hasBackreferences = true;

            return [];
        }

        if (!$this->trackCaseSensitivity || $this->hasCaseSensitiveChars) {
            return [];
        }

        if ($node instanceof LiteralNode) {
            if ($this->stringHasCaseSensitiveLetters($node->value)) {
                $this->hasCaseSensitiveChars = true;
            }

            return [];
        }

        if ($node instanceof CharClassNode) {
            $expression = $node->expression;
            if ($expression instanceof AlternationNode) {
                foreach ($expression->alternatives as $alt) {
                    if ($this->charClassPartHasLetters($alt)) {
                        $this->hasCaseSensitiveChars = true;

                        break;
                    }
                }
            } elseif ($this->charClassPartHasLetters($expression)) {
                $this->hasCaseSensitiveChars = true;
            }

            return [];
        }

        if ($node instanceof CharLiteralNode) {
            if ($this->charLiteralHasCaseSensitiveLetter($node)) {
                $this->hasCaseSensitiveChars = true;
            }

            return [];
        }

        if ($node instanceof UnicodePropNode) {
            if ($this->unicodePropIsCaseSensitive($node->prop)) {
                $this->hasCaseSensitiveChars = true;
            }
        }

        return [];
    }

    public function finish(LintContext $context): array
    {
        if ($context->pattern->hasFlag('i') && !$this->hasCaseSensitiveChars && !$this->hasBackreferences) {
            return [new RuleViolation(
                'regex.lint.flag.useless.i',
                "Flag 'i' is useless: the pattern contains no case-sensitive characters.",
            )];
        }

        return [];
    }

    private function charClassPartHasLetters(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return $this->stringHasCaseSensitiveLetters($node->value);
        }

        if ($node instanceof CharLiteralNode) {
            return $this->charLiteralHasCaseSensitiveLetter($node);
        }

        if ($node instanceof UnicodePropNode) {
            return $this->unicodePropIsCaseSensitive($node->prop);
        }

        if ($node instanceof PosixClassNode) {
            return $this->posixClassHasCaseSensitiveLetters($node->class);
        }

        if ($node instanceof RangeNode) {
            return $this->rangeHasLetters($node);
        }

        // Other types like CharTypeNode are case-insensitive by design.
        return false;
    }

    private function rangeHasLetters(RangeNode $node): bool
    {
        $start = CodePoints::fromNode($node->start, $this->unicodeMode);
        $end = CodePoints::fromNode($node->end, $this->unicodeMode);

        if (null === $start || null === $end) {
            return false;
        }

        $min = min($start, $end);
        $max = max($start, $end);

        if ($this->rangeHasAsciiLetters($min, $max)) {
            return true;
        }

        if (!$this->unicodeMode) {
            return false;
        }

        return $this->codePointHasCase($start) || $this->codePointHasCase($end);
    }

    private function rangeHasAsciiLetters(int $min, int $max): bool
    {
        return ($min <= \ord('Z') && $max >= \ord('A'))
            || ($min <= \ord('z') && $max >= \ord('a'));
    }

    private function stringHasCaseSensitiveLetters(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        if (preg_match('/[A-Za-z]/', $value) > 0) {
            return true;
        }

        if (!$this->unicodeMode) {
            return false;
        }

        $chars = preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars) {
            return false;
        }

        foreach ($chars as $char) {
            $codePoint = mb_ord($char, 'UTF-8');
            if (false !== $codePoint && $this->codePointHasCase($codePoint)) {
                return true;
            }
        }

        return false;
    }

    private function charLiteralHasCaseSensitiveLetter(CharLiteralNode $node): bool
    {
        $codePoint = $node->codePoint;
        if ($codePoint < 0) {
            $codePoint = CodePoints::parseUnicodeEscape($node->originalRepresentation) ?? $codePoint;
        }

        if ($codePoint >= 0) {
            return $this->codePointHasCase($codePoint);
        }

        if (1 === \strlen($node->originalRepresentation)) {
            return $this->stringHasCaseSensitiveLetters($node->originalRepresentation);
        }

        return false;
    }

    private function codePointHasCase(int $codePoint): bool
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF) {
            return false;
        }

        // Without UTF mode PCRE folds the case of ASCII letters only; with
        // it, of every character Unicode gives another case, a circled
        // letter or a Roman numeral included.
        if (!$this->unicodeMode) {
            return ($codePoint >= \ord('A') && $codePoint <= \ord('Z'))
                || ($codePoint >= \ord('a') && $codePoint <= \ord('z'));
        }

        $char = mb_chr($codePoint, 'UTF-8');
        if (false === $char) {
            return false;
        }

        return mb_strtoupper($char, 'UTF-8') !== $char
            || mb_strtolower($char, 'UTF-8') !== $char;
    }

    private function unicodePropIsCaseSensitive(string $prop): bool
    {
        if ('' === $prop) {
            return false;
        }

        $normalized = $this->normalizeUnicodePropName($prop);

        return \in_array($normalized, [
            'lu',
            'll',
            'lt',
            'lc',
            'l&',
            'upper',
            'lower',
            'title',
            'uppercase_letter',
            'lowercase_letter',
            'titlecase_letter',
            'cased_letter',
        ], true);
    }

    private function normalizeUnicodePropName(string $prop): string
    {
        $normalized = ltrim($prop, '^');
        $normalized = trim($normalized, '{}');
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return strtolower($normalized);
    }

    private function posixClassHasCaseSensitiveLetters(string $class): bool
    {
        $normalized = strtolower(ltrim($class, '^'));

        return \in_array($normalized, ['upper', 'lower'], true);
    }
}
