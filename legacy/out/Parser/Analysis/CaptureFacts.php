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
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Text\Ascii;

/**
 * @internal
 *
 * What a node reads, from the tree alone, for CaptureShapeAnalyzer: its
 * length range, its values when they are a small finite set, and the facts
 * every value satisfies. An answer depends on the node and on the options
 * of the whole pattern only, so each node is measured once, and its answer
 * kept as long as the node lives.
 *
 * Every answer is sound: when the tree alone cannot tell, the values are
 * unknown and the facts are false.
 *
 * @phpstan-import-type LengthRange from LengthRangeCalculator
 *
 * @phpstan-type Facts array{min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool}
 */
final class CaptureFacts
{
    /**
     * @var bool
     * @readonly
     */
    private $unicode = false;
    /**
     * @var bool
     * @readonly
     */
    private $caseless = false;
    /**
     * @var bool
     * @readonly
     */
    private $ucp = false;
    /**
     * Past this many strings, the values are unknown.
     */
    public const MAX_VALUES = 32;

    /**
     * \D, \W, \s, \h, \v and \R never match "0".
     */
    private const ZERO_FREE_TYPES = ['D', 'W', 's', 'h', 'v', 'R'];

    /**
     * POSIX classes that never hold "0", whatever the case.
     */
    private const ZERO_FREE_POSIX = ['alpha', 'upper', 'lower', 'space', 'blank', 'cntrl', 'punct'];

    private static function LOOKAROUNDS(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [GroupType::LookaheadPositive(), GroupType::LookaheadNegative(), GroupType::LookbehindPositive(), GroupType::LookbehindNegative()];
        }

        return $cache;
    }

    /**
     * @readonly
     * @var \PHPRegex\Parser\Analysis\LengthRangeCalculator
     */
    private $calculator;

    /**
     * @var \WeakMap<NodeInterface, LengthRange>
     */
    private $lengths;

    /**
     * @var \WeakMap<NodeInterface, Facts>
     */
    private $facts;

    /**
     * @param bool $unicode  lengths count code points, and literals read as UTF-8
     * @param bool $caseless some part of the pattern matches caselessly: no value set is read
     * @param bool $ucp      \d and [[:digit:]] match non-ASCII digits: /u, or (*UCP)
     */
    public function __construct(
        bool $unicode = false,
        bool $caseless = false,
        bool $ucp = false
    ) {
        $this->unicode = $unicode;
        $this->caseless = $caseless;
        $this->ucp = $ucp;
        $this->calculator = new LengthRangeCalculator($unicode);
        $this->lengths = new \WeakMap();
        $this->facts = new \WeakMap();
    }

    /**
     * The length range of what the node reads.
     *
     * @return LengthRange
     */
    public function lengths(NodeInterface $node): array
    {
        return $this->lengths[$node] = $this->lengths[$node] ?? $node->accept($this->calculator);
    }

    /**
     * What the node reads, as a capture holds it once set: its length range,
     * its values, whether every value is truthy (nonFalsy), and whether
     * every value is a non-empty run of ASCII digits (digitsOnly).
     *
     * @return Facts
     */
    public function of(NodeInterface $node): array
    {
        if (isset($this->facts[$node])) {
            return $this->facts[$node];
        }

        [$min, $max] = $this->lengths($node);
        $values = $this->values($node);

        return $this->facts[$node] = [
            'min' => $min,
            'max' => $max,
            'values' => $values,
            'nonFalsy' => $this->nonFalsy($node, $min, $values),
            'digitsOnly' => $min > 0 && $this->digitsOnly($node),
        ];
    }

    /**
     * The strings a node can match, when they are a small finite set read
     * from literals alone.
     *
     * @return list<string>|null
     */
    private function values(NodeInterface $node): ?array
    {
        if ($this->caseless) {
            return null;
        }

        switch (true) {
            case $node instanceof LiteralNode:
                return [$node->value];
            case $node instanceof CharLiteralNode:
                return $this->character($node->codePoint);
            case $node instanceof SequenceNode:
                return $this->product(array_map(\Closure::fromCallable([$this, 'values']), $node->children));
            case $node instanceof AlternationNode:
                return $this->union(array_map(\Closure::fromCallable([$this, 'values']), $node->alternatives));
            case $node instanceof GroupNode:
                return self::isLookaround($node) ? [''] : $this->values($node->child);
            case $node instanceof QuantifierNode:
                return $this->repeat($node);
            case $node instanceof AnchorNode:
            case $node instanceof AssertionNode:
            case $node instanceof CommentNode:
            case $node instanceof KeepNode:
            case $node instanceof DefineNode:
            case $node instanceof CalloutNode:
                return [''];
            case $node instanceof PcreVerbNode:
                return strncmp($node->verb, 'ACCEPT', strlen('ACCEPT')) === 0 ? null : [''];
            default:
                return null;
        }
    }

    /**
     * @return list<string>|null
     */
    private function repeat(QuantifierNode $node): ?array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        $body = $this->values($node->node);
        if (null === $bounds || null === $body || null === $bounds->max) {
            return null;
        }

        // A body that reads nothing reads nothing at any count.
        if ([''] === $body) {
            return [''];
        }

        // Each count adds a string longer than any before: past MAX_VALUES counts, the values are unknown.
        if ($bounds->max > self::MAX_VALUES) {
            return null;
        }

        // The body read once per count, from the least to the most; lazy or
        // possessive, the quantifier still stops at one of these counts.
        $values = 0 === $bounds->min ? [''] : [];
        $power = [''];
        for ($count = 1; $count <= $bounds->max; $count++) {
            $power = $this->product([$power, $body]);
            $values = $count < $bounds->min || null === $power ? $values : $this->union([$values, $power]);
            if (null === $power || null === $values) {
                return null;
            }
        }

        return $values;
    }

    /**
     * @return list<string>|null
     */
    private function character(int $codePoint): ?array
    {
        if ($this->unicode) {
            $character = mb_chr($codePoint, 'UTF-8');

            return false === $character ? null : [$character];
        }

        return $codePoint <= 0xFF ? [\chr($codePoint)] : null;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function product(array $parts): ?array
    {
        $strings = [''];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $next = [];
            foreach ($strings as $prefix) {
                foreach ($part as $suffix) {
                    $next[] = $prefix.$suffix;
                }
            }

            $strings = array_values(array_unique($next));
            if (\count($strings) > self::MAX_VALUES) {
                return null;
            }
        }

        return $strings;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function union(array $parts): ?array
    {
        $strings = [];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $strings = array_merge($strings, $part);
        }

        $strings = array_values(array_unique($strings));

        return \count($strings) > self::MAX_VALUES ? null : $strings;
    }

    /**
     * Whether every value is truthy: neither '' nor '0'.
     *
     * @param list<string>|null $values
     */
    private function nonFalsy(NodeInterface $node, int $min, ?array $values): bool
    {
        if (null !== $values) {
            return !\in_array('', $values, true) && !\in_array('0', $values, true);
        }

        return $min >= 2 || (1 === $min && !$this->mayBeZero($node));
    }

    /**
     * Whether the node may match the string "0" alone. True when unsure.
     */
    private function mayBeZero(NodeInterface $node): bool
    {
        switch (true) {
            case $node instanceof CharClassNode:
                return $node->isNegated ? !$this->holdsZero($node->expression) : $this->classMayHoldZero($node->expression);
            case $node instanceof SequenceNode:
                return $this->lengths($node)[0] <= 1 && self::any($node->children, \Closure::fromCallable([$this, 'mayBeZero']));
            case $node instanceof AlternationNode:
                return self::any($node->alternatives, \Closure::fromCallable([$this, 'mayBeZero']));
            case $node instanceof GroupNode:
                return !self::isLookaround($node) && $this->mayBeZero($node->child);
            case $node instanceof QuantifierNode:
                return 0 !== (($nullsafeVariable1 = QuantifierBounds::parse($node->quantifier)) ? $nullsafeVariable1->max : null) && $this->mayBeZero($node->node);
            case self::consumesNothing($node):
                return false;
            default:
                return $this->readsZero($node, false) ?? true;
        }
    }

    /**
     * Whether a class member may hold "0". True when unsure.
     */
    private function classMayHoldZero(NodeInterface $member): bool
    {
        switch (true) {
            case $member instanceof AlternationNode:
                return self::any($member->alternatives, \Closure::fromCallable([$this, 'classMayHoldZero']));
            case $member instanceof RangeNode:
                return null === ($range = $this->range($member)) || ($range[0] <= 0x30 && 0x30 <= $range[1]);
            case $member instanceof PosixClassNode:
                return !\in_array($member->class, self::ZERO_FREE_POSIX, true);
            default:
                return $this->readsZero($member, false) ?? true;
        }
    }

    /**
     * Whether a class member surely holds "0". False when unsure.
     */
    private function holdsZero(NodeInterface $member): bool
    {
        if ($member instanceof AlternationNode) {
            return self::any($member->alternatives, \Closure::fromCallable([$this, 'holdsZero']));
        }

        return $this->readsZero($member, true) ?? false;
    }

    /**
     * Whether a character or a shorthand reads "0": surely, or possibly.
     * Null for any other node.
     */
    private function readsZero(NodeInterface $atom, bool $surely): ?bool
    {
        switch (true) {
            case $atom instanceof LiteralNode:
            case $atom instanceof CharLiteralNode:
                return 0x30 === $this->codePoint($atom);
            case $atom instanceof CharTypeNode:
                return $surely ? 'd' === $atom->value : !\in_array($atom->value, self::ZERO_FREE_TYPES, true);
            default:
                return null;
        }
    }

    /**
     * Whether every character the node reads is an ASCII digit. No case
     * folds into 0-9; under UCP \d and [[:digit:]] read other digits too.
     * False when unsure.
     */
    private function digitsOnly(NodeInterface $node): bool
    {
        switch (true) {
            case $node instanceof LiteralNode:
                return '' === $node->value || Ascii::isDigit($node->value);
            case $node instanceof CharLiteralNode:
            case $node instanceof CharTypeNode:
                return $this->isDigitMember($node);
            case $node instanceof CharClassNode:
                return !$node->isNegated && $this->isDigitMember($node->expression);
            case $node instanceof SequenceNode:
                return self::all($node->children, \Closure::fromCallable([$this, 'digitsOnly']));
            case $node instanceof AlternationNode:
                return self::all($node->alternatives, \Closure::fromCallable([$this, 'digitsOnly']));
            case $node instanceof GroupNode:
                return self::isLookaround($node) || $this->digitsOnly($node->child);
            case $node instanceof QuantifierNode:
                return $this->digitsOnly($node->node);
            default:
                return self::consumesNothing($node);
        }
    }

    /**
     * Whether a class member, or a single character, holds ASCII digits only.
     */
    private function isDigitMember(NodeInterface $member): bool
    {
        switch (true) {
            case $member instanceof AlternationNode:
                return self::all($member->alternatives, \Closure::fromCallable([$this, 'isDigitMember']));
            case $member instanceof LiteralNode:
            case $member instanceof CharLiteralNode:
                return self::isDigit($this->codePoint($member));
            case $member instanceof RangeNode:
                return null !== ($range = $this->range($member)) && self::isDigit($range[0]) && self::isDigit($range[1]);
            case $member instanceof CharTypeNode:
                return 'd' === $member->value && !$this->ucp;
            case $member instanceof PosixClassNode:
                return 'digit' === $member->class && !$this->ucp;
            default:
                return false;
        }
    }

    private static function isDigit(?int $codePoint): bool
    {
        return null !== $codePoint && $codePoint >= 0x30 && $codePoint <= 0x39;
    }

    /**
     * The code points a range spans, when both ends are single characters.
     *
     * @return array{0: int, 1: int}|null
     */
    private function range(RangeNode $range): ?array
    {
        $start = $this->codePoint($range->start);
        $end = $this->codePoint($range->end);

        return null === $start || null === $end ? null : [$start, $end];
    }

    /**
     * The code point of a node that reads one character, or null.
     */
    private function codePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        if (!$node instanceof LiteralNode || '' === $node->value) {
            return null;
        }

        if (!$this->unicode) {
            return 1 === \strlen($node->value) ? \ord($node->value) : null;
        }

        if (1 !== mb_strlen($node->value, 'UTF-8')) {
            return null;
        }

        $codePoint = mb_ord($node->value, 'UTF-8');

        return false === $codePoint ? null : $codePoint;
    }

    private static function isLookaround(GroupNode $node): bool
    {
        return \in_array($node->type, self::LOOKAROUNDS(), true);
    }

    /**
     * Whether the node reads no character: an anchor, an assertion, a verb.
     */
    private static function consumesNothing(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode || $node instanceof AssertionNode || $node instanceof CommentNode
            || $node instanceof KeepNode || $node instanceof DefineNode || $node instanceof CalloutNode
            || $node instanceof PcreVerbNode || $node instanceof LimitMatchNode;
    }

    /**
     * array_any() of PHP 8.4.
     *
     * @param array<NodeInterface>          $nodes
     * @param callable(NodeInterface): bool $test
     */
    private static function any(array $nodes, callable $test): bool
    {
        foreach ($nodes as $node) {
            if ($test($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_all() of PHP 8.4.
     *
     * @param array<NodeInterface>          $nodes
     * @param callable(NodeInterface): bool $test
     */
    private static function all(array $nodes, callable $test): bool
    {
        foreach ($nodes as $node) {
            if (!$test($node)) {
                return false;
            }
        }

        return true;
    }
}
