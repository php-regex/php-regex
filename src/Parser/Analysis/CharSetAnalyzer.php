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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Text\Utf8;

/**
 * Approximates leading/trailing character sets for AST nodes.
 *
 * Used to detect mutually exclusive boundaries that avoid catastrophic backtracking.
 *
 * The sets stop at ASCII unless the analyzer reads above it, as the ReDoS
 * analysis asks: the linter's rules ask the engine and the tables of the
 * target's locale there. Read above ASCII, without UTF a character is a
 * byte, and a set holds the bytes the node may start or end with. Under
 * UTF a character above ASCII is two to four bytes, and a set knows it by
 * the byte it starts with, the last character of a node as its first: "é"
 * is 0xC3 at either end, so a node that ends with it meets one that starts
 * with it.
 *
 * @internal
 */
final class CharSetAnalyzer
{
    /**
     * What an option setting is written with after "(?": its letters, "-"
     * before the ones it unsets, "^" for a reset.
     */
    private const OPTION_LETTERS = '^-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private readonly bool $unicodeMode;

    private readonly bool $utf;

    private readonly bool $caseless;

    private readonly ByteCharSet $dot;

    /**
     * @param string $startOptions    the options the pattern opens with, such
     *                                as "(*CR)(*UTF)": the newline convention
     *                                they set decides where the dot stops
     * @param bool   $readsAboveAscii whether the sets hold the characters above
     *                                ASCII, read under PCRE2's own tables
     */
    public function __construct(
        string $flags = '',
        private readonly string $startOptions = '',
        private readonly bool $readsAboveAscii = false
    ) {
        $this->unicodeMode = str_contains($flags, 'u') || str_contains($startOptions, '(*UCP)');
        $this->utf = str_contains($flags, 'u') || str_contains($startOptions, '(*UTF');
        $this->caseless = str_contains($flags, 'i');
        $this->dot = $this->dotSet(str_contains($flags, 's'), StartOptions::newline($startOptions));
    }

    /**
     * An analyzer for the pattern as a whole: its flags and the newline
     * convention its start options set, read under i when an option
     * setting may set it at some point of the pattern.
     */
    public static function forRegex(RegexNode $regex, bool $readsAboveAscii = false): self
    {
        $source = $regex->source ?? '';

        return new self(self::mayBeSetCaseless($source) ? $regex->flags.'i' : $regex->flags, StartOptions::of($source), $readsAboveAscii);
    }

    /**
     * The same pattern under other flags, such as the inline ones in effect
     * at a node.
     */
    public function withFlags(string $flags): self
    {
        return new self($flags, $this->startOptions, $this->readsAboveAscii);
    }

    public function firstChars(NodeInterface $node): ByteCharSet
    {
        return $this->walk($node, true);
    }

    public function lastChars(NodeInterface $node): ByteCharSet
    {
        return $this->walk($node, false);
    }

    private function walk(NodeInterface $node, bool $fromStart): ByteCharSet
    {
        if ($node instanceof RegexNode) {
            return $this->walk($node->pattern, $fromStart);
        }

        if ($node instanceof LiteralNode) {
            $at = \strlen($node->value) - 1;
            if (-1 === $at) {
                return ByteCharSet::empty();
            }

            if ($fromStart) {
                return ByteCharSet::fromChar($node->value[0]);
            }

            while ($this->readsAboveAscii && $this->utf && $at > 0 && Utf8::continues($node->value[$at])) {
                $at--;
            }

            return ByteCharSet::fromChar($node->value[$at]);
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $this->caseMayChange($node->codePoint, $node->codePoint)
                ? ByteCharSet::unknown()
                : $this->fromCodePoints($node->codePoint, $node->codePoint);
        }

        if ($node instanceof CharTypeNode) {
            return $this->fromCharType($node->value);
        }

        if ($node instanceof DotNode) {
            return $this->dot;
        }

        if ($node instanceof QuantifierNode) {
            return $this->walk($node->node, $fromStart);
        }

        if ($node instanceof RangeNode) {
            $start = $this->codePoint($node->start);
            $end = $this->codePoint($node->end);

            if (null === $start || null === $end) {
                return ByteCharSet::unknown();
            }

            [$start, $end] = [min($start, $end), max($start, $end)];

            // The case of letters written as such is not followed, here as
            // for a literal; a range written otherwise is read only where
            // the case cannot change it.
            $writtenInAscii = $node->start instanceof LiteralNode && $node->end instanceof LiteralNode && $end <= 0x7F;

            return !$writtenInAscii && $this->caseMayChange($start, $end)
                ? ByteCharSet::unknown()
                : $this->fromCodePoints($start, $end);
        }

        if ($node instanceof CharClassNode) {
            $set = ByteCharSet::empty();

            $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
            foreach ($parts as $part) {
                // Without UTF a class reads each byte of a character
                // written in several apart: "[é]" is "[\xC3\xA9]".
                $candidate = $this->readsAboveAscii && !$this->utf && $part instanceof LiteralNode ? self::bytes($part->value) : $this->walk($part, $fromStart);
                $set = $set->union($candidate);
            }

            return $node->isNegated ? $this->complement($set) : $set;
        }

        if ($node instanceof GroupNode) {
            return $this->walk($node->child, $fromStart);
        }

        // A script run matches what its body matches, in one script.
        if ($node instanceof ScriptRunNode) {
            return null === $node->content ? ByteCharSet::unknown() : $this->walk($node->content, $fromStart);
        }

        if ($node instanceof ExtendedCharClassNode) {
            return $this->walk($node->expression, $fromStart);
        }

        if ($node instanceof ClassSetOperationNode) {
            $right = $this->walk($node->right, $fromStart);
            if (null === $node->left) {
                return $this->complement($right);
            }

            $left = $this->walk($node->left, $fromStart);

            return match ($node->operator) {
                ClassSetOperator::Intersection => $left->intersect($right),
                ClassSetOperator::Difference => $left->intersect($this->complement($right)),
                ClassSetOperator::SymmetricDifference => $left->union($right)->intersect($this->complement($left->intersect($right))),
                default => $left->union($right),
            };
        }

        if ($node instanceof AlternationNode) {
            $set = ByteCharSet::empty();
            foreach ($node->alternatives as $alt) {
                $set = $set->union($this->walk($alt, $fromStart));
            }

            return $set;
        }

        if ($node instanceof SequenceNode) {
            $children = $fromStart ? $node->children : array_reverse($node->children);
            $set = ByteCharSet::empty();

            foreach ($children as $child) {
                $candidate = $this->walk($child, $fromStart);
                $set = $set->union($candidate);

                if (!$this->isOptionalNode($child, $fromStart)) {
                    break;
                }
            }

            return $set;
        }

        return ByteCharSet::unknown();
    }

    private function fromCharType(string $type): ByteCharSet
    {
        if ($this->unicodeMode && \in_array($type, ['d', 'D', 'w', 'W'], true)) {
            return ByteCharSet::unknown();
        }

        return match ($type) {
            'd' => ByteCharSet::fromRange(\ord('0'), \ord('9')),
            'D' => $this->complement(ByteCharSet::fromRange(\ord('0'), \ord('9'))),
            'w' => self::wordCharacters(),
            'W' => $this->complement(self::wordCharacters()),
            's' => $this->spaces(),
            'S' => $this->complement($this->spaces()),
            'v' => $this->verticalSpaces(),
            'V' => $this->complement($this->verticalSpaces()),
            'R' => $this->verticalSpaces(),
            'h' => $this->horizontalSpaces(),
            'H' => $this->complement($this->horizontalSpaces()),
            default => ByteCharSet::unknown(),
        };
    }

    private static function wordCharacters(): ByteCharSet
    {
        return ByteCharSet::fromRange(\ord('0'), \ord('9'))
            ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
            ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
            ->union(ByteCharSet::fromChar('_'));
    }

    /**
     * "\s": the white space of ASCII, and under (*UCP) the next line and
     * the no-break space.
     */
    private function spaces(): ByteCharSet
    {
        return self::bytes("\t\n\v\f\r ")->union($this->spacesAboveAscii($this->unicodeMode ? "\x85\xA0" : ''));
    }

    /**
     * "\v", and the line breaks "\R" takes, "\r\n" ending as "\n".
     */
    private function verticalSpaces(): ByteCharSet
    {
        return self::bytes("\n\v\f\r")->union($this->spacesAboveAscii("\x85"));
    }

    private function horizontalSpaces(): ByteCharSet
    {
        return self::bytes("\t ")->union($this->spacesAboveAscii("\xA0"));
    }

    /**
     * The spaces above ASCII a class of spaces takes: without UTF the bytes
     * given, the next line 0x85 or the no-break space 0xA0. Under UTF each
     * one, from U+0085 to U+3000, starts with 0xC2, 0xE1, 0xE2 or 0xE3.
     */
    private function spacesAboveAscii(string $bytes): ByteCharSet
    {
        if (!$this->readsAboveAscii) {
            return ByteCharSet::empty();
        }

        return self::bytes($this->utf ? "\xC2\xE1\xE2\xE3" : $bytes);
    }

    /**
     * Without dotall, the dot matches every character but a newline, which
     * the convention defines. Under (*CRLF) only the pair is a newline, so
     * a single byte of it is an ordinary character. (*ANY) adds the next
     * line, 0x85 without UTF; under UTF, U+0085 starts with 0xC2 as other
     * characters the dot takes do. Read in ASCII, the newlines above it lie
     * outside the sets.
     */
    private function dotSet(bool $dotAll, string $newline): ByteCharSet
    {
        $newlines = match (true) {
            $dotAll, 'CRLF' === $newline => '',
            'CR' === $newline => "\r",
            'NUL' === $newline => "\0",
            'ANYCRLF' === $newline => "\n\r",
            'ANY' === $newline => $this->readsAboveAscii && !$this->utf ? "\n\v\f\r\x85" : "\n\v\f\r",
            default => "\n",
        };

        return $this->complement(self::bytes($newlines));
    }

    /**
     * The characters outside the set, in ASCII unless the analyzer reads
     * above it. Under UTF a byte above ASCII stands for every character
     * that starts with it, and some may be outside the set while others
     * are in: those bytes stay.
     */
    private function complement(ByteCharSet $set): ByteCharSet
    {
        if (!$this->readsAboveAscii) {
            return $set->complement();
        }

        $complement = $set->complement(0xFF);

        return $this->utf ? $complement->union(ByteCharSet::fromBytes(0x80, 0xFF)) : $complement;
    }

    /**
     * The characters from $start to $end; above ASCII, not known unless the
     * analyzer reads there. Without UTF a character is a byte, and a code
     * point above 0xFF none. Under UTF one above ASCII is known by the byte
     * it starts with, which UTF-8 orders as the code points.
     */
    private function fromCodePoints(int $start, int $end): ByteCharSet
    {
        if ($end <= 0x7F) {
            return ByteCharSet::fromRange($start, $end);
        }

        if (!$this->readsAboveAscii || ($this->utf && $end > 0x10FFFF)) {
            return ByteCharSet::unknown();
        }

        if (!$this->utf) {
            return ByteCharSet::fromBytes($start, $end);
        }

        $ascii = $start <= 0x7F ? ByteCharSet::fromRange($start, 0x7F) : ByteCharSet::empty();

        return $ascii->union(ByteCharSet::fromBytes(self::leadByte(max($start, 0x80)), self::leadByte($end)));
    }

    /**
     * The byte UTF-8 starts a code point above ASCII with.
     */
    private static function leadByte(int $codePoint): int
    {
        return match (true) {
            $codePoint < 0x800 => 0xC0 | $codePoint >> 6,
            $codePoint < 0x10000 => 0xE0 | $codePoint >> 12,
            default => 0xF0 | $codePoint >> 18,
        };
    }

    /**
     * Whether under i a character from $start to $end may match another:
     * an ASCII letter, and under UTF or (*UCP) a character above ASCII,
     * "\xC9" an "\xE9", "\x{212A}" a "k".
     */
    private function caseMayChange(int $start, int $end): bool
    {
        return $this->caseless
            && (($start <= \ord('Z') && $end >= \ord('A'))
                || ($start <= \ord('z') && $end >= \ord('a'))
                || ($end > 0x7F && ($this->utf || $this->unicodeMode)));
    }

    /**
     * Whether an option setting of the source names i: "(?i)", "(?^i)",
     * and "(?s-i:" too. Read on the text, a "(?i" in a comment or in a
     * class counts as well: either only leaves an escape unread.
     */
    private static function mayBeSetCaseless(string $source): bool
    {
        for ($at = strpos($source, '(?'); false !== $at; $at = strpos($source, '(?', $at + 2)) {
            $options = substr($source, $at + 2, strspn($source, self::OPTION_LETTERS, $at + 2));
            if (str_contains($options, 'i')) {
                return true;
            }
        }

        return false;
    }

    private static function bytes(string $bytes): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach (Bytes::split($bytes) as $byte) {
            $set = $set->union(ByteCharSet::fromChar($byte));
        }

        return $set;
    }

    /**
     * The code point a range starts or ends with: a letter of several
     * bytes is one under UTF, and without it the class reads its bytes
     * apart, so the range is not known.
     */
    private function codePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        if (!$node instanceof LiteralNode || '' === $node->value) {
            return null;
        }

        if (1 === \strlen($node->value)) {
            return \ord($node->value);
        }

        $codePoint = $this->utf ? mb_ord($node->value, 'UTF-8') : false;

        return false === $codePoint ? null : $codePoint;
    }

    /**
     * The tree already reads "{,5}" as the target does: a quantifier from
     * PCRE2 10.43, text before.
     */
    private function isOptional(QuantifierNode $node): bool
    {
        return 0 === $node->bounds?->min;
    }

    private function isOptionalNode(NodeInterface $node, bool $fromStart): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        if ($node instanceof QuantifierNode) {
            return $this->isOptional($node);
        }

        if ($node instanceof GroupNode) {
            return $this->isOptionalNode($node->child, $fromStart);
        }

        if ($node instanceof ScriptRunNode) {
            return null !== $node->content && $this->isOptionalNode($node->content, $fromStart);
        }

        if ($node instanceof SequenceNode) {
            $children = $fromStart ? $node->children : array_reverse($node->children);
            foreach ($children as $child) {
                if ($this->isOptionalNode($child, $fromStart)) {
                    continue;
                }

                return false;
            }

            return true;
        }

        return false;
    }
}
