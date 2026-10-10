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

use PHPRegex\Parser\Internal\Ascii;
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

    private const LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative];

    private readonly LengthRangeCalculator $calculator;

    /**
     * @var \WeakMap<NodeInterface, LengthRange>
     */
    private \WeakMap $lengths;

    /**
     * @var \WeakMap<NodeInterface, Facts>
     */
    private \WeakMap $facts;

    /**
     * @param bool $unicode  lengths count code points, and literals read as UTF-8
     * @param bool $caseless some part of the pattern matches caselessly: no value set is read
     * @param bool $ucp      \d and [[:digit:]] match non-ASCII digits: /u, or (*UCP)
     */
    public function __construct(
        private readonly bool $unicode = false,
        private readonly bool $caseless = false,
        private readonly bool $ucp = false,
    ) {
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
        return $this->lengths[$node] ??= $node->accept($this->calculator);
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

        return match (true) {
            $node instanceof LiteralNode => [$node->value],
            $node instanceof CharLiteralNode => $this->character($node->codePoint),
            $node instanceof SequenceNode => $this->product(array_map($this->values(...), $node->children)),
            $node instanceof AlternationNode => $this->union(array_map($this->values(...), $node->alternatives)),
            $node instanceof GroupNode => self::isLookaround($node) ? [''] : $this->values($node->child),
            $node instanceof QuantifierNode => $this->repeat($node),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof CommentNode,
            $node instanceof KeepNode, $node instanceof DefineNode, $node instanceof CalloutNode => [''],
            $node instanceof PcreVerbNode => str_starts_with($node->verb, 'ACCEPT') ? null : [''],
            default => null,
        };
    }

    /**
     * @return list<string>|null
     */
    private function repeat(QuantifierNode $node): ?array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        $body = $this->values($node->node);
        if (null === $bounds || null === $body) {
            return null;
        }

        if (0 === $bounds->min && 1 === $bounds->max) {
            return $this->union([[''], $body]);
        }

        if ($bounds->min !== $bounds->max || $bounds->min > 4) {
            return null;
        }

        return $this->product(array_fill(0, $bounds->min, $body));
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

            $strings = [...$strings, ...$part];
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
        return match (true) {
            $node instanceof CharClassNode => $node->isNegated ? !$this->holdsZero($node->expression) : $this->classMayHoldZero($node->expression),
            $node instanceof SequenceNode => $this->lengths($node)[0] <= 1 && self::any($node->children, $this->mayBeZero(...)),
            $node instanceof AlternationNode => self::any($node->alternatives, $this->mayBeZero(...)),
            $node instanceof GroupNode => !self::isLookaround($node) && $this->mayBeZero($node->child),
            $node instanceof QuantifierNode => 0 !== QuantifierBounds::parse($node->quantifier)?->max && $this->mayBeZero($node->node),
            self::consumesNothing($node) => false,
            default => $this->readsZero($node, false) ?? true,
        };
    }

    /**
     * Whether a class member may hold "0". True when unsure.
     */
    private function classMayHoldZero(NodeInterface $member): bool
    {
        return match (true) {
            $member instanceof AlternationNode => self::any($member->alternatives, $this->classMayHoldZero(...)),
            $member instanceof RangeNode => null === ($range = $this->range($member)) || ($range[0] <= 0x30 && 0x30 <= $range[1]),
            $member instanceof PosixClassNode => !\in_array($member->class, self::ZERO_FREE_POSIX, true),
            default => $this->readsZero($member, false) ?? true,
        };
    }

    /**
     * Whether a class member surely holds "0". False when unsure.
     */
    private function holdsZero(NodeInterface $member): bool
    {
        if ($member instanceof AlternationNode) {
            return self::any($member->alternatives, $this->holdsZero(...));
        }

        return $this->readsZero($member, true) ?? false;
    }

    /**
     * Whether a character or a shorthand reads "0": surely, or possibly.
     * Null for any other node.
     */
    private function readsZero(NodeInterface $atom, bool $surely): ?bool
    {
        return match (true) {
            $atom instanceof LiteralNode, $atom instanceof CharLiteralNode => 0x30 === $this->codePoint($atom),
            $atom instanceof CharTypeNode => $surely ? 'd' === $atom->value : !\in_array($atom->value, self::ZERO_FREE_TYPES, true),
            default => null,
        };
    }

    /**
     * Whether every character the node reads is an ASCII digit. No case
     * folds into 0-9; under UCP \d and [[:digit:]] read other digits too.
     * False when unsure.
     */
    private function digitsOnly(NodeInterface $node): bool
    {
        return match (true) {
            $node instanceof LiteralNode => '' === $node->value || Ascii::isDigit($node->value),
            $node instanceof CharLiteralNode, $node instanceof CharTypeNode => $this->isDigitMember($node),
            $node instanceof CharClassNode => !$node->isNegated && $this->isDigitMember($node->expression),
            $node instanceof SequenceNode => self::all($node->children, $this->digitsOnly(...)),
            $node instanceof AlternationNode => self::all($node->alternatives, $this->digitsOnly(...)),
            $node instanceof GroupNode => self::isLookaround($node) || $this->digitsOnly($node->child),
            $node instanceof QuantifierNode => $this->digitsOnly($node->node),
            default => self::consumesNothing($node),
        };
    }

    /**
     * Whether a class member, or a single character, holds ASCII digits only.
     */
    private function isDigitMember(NodeInterface $member): bool
    {
        return match (true) {
            $member instanceof AlternationNode => self::all($member->alternatives, $this->isDigitMember(...)),
            $member instanceof LiteralNode, $member instanceof CharLiteralNode => self::isDigit($this->codePoint($member)),
            $member instanceof RangeNode => null !== ($range = $this->range($member)) && self::isDigit($range[0]) && self::isDigit($range[1]),
            $member instanceof CharTypeNode => 'd' === $member->value && !$this->ucp,
            $member instanceof PosixClassNode => 'digit' === $member->class && !$this->ucp,
            default => false,
        };
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
        return \in_array($node->type, self::LOOKAROUNDS, true);
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
