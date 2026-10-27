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
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * @internal
 *
 * What a node reads, from the tree alone, for CaptureShapeAnalyzer: its
 * length range, its values when they are a small finite set, and the facts
 * every value satisfies. An answer depends on the node, on the options in
 * force where it stands and on the groups it calls, so each node is
 * measured once, and its answer kept as long as the node lives.
 *
 * Every answer is sound: when the tree alone cannot tell, the values are
 * unknown and the facts are false.
 *
 * @phpstan-import-type LengthRange from LengthRangeCalculator
 *
 * @phpstan-type Facts array{min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool, decimalInt: bool, lowercase: bool, uppercase: bool}
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

    /**
     * \d, \s, \h, \v, \R and \W never match an ASCII letter, caseless or
     * not, under UCP too: preg_match('/^\W$/i', 'A') is 0.
     */
    private const LETTER_FREE_TYPES = ['d', 's', 'h', 'v', 'R', 'W'];

    /**
     * POSIX classes that never hold an ASCII letter, caseless or not, under
     * UCP too.
     */
    private const LETTER_FREE_POSIX = ['digit', 'space', 'blank', 'cntrl', 'punct'];

    /**
     * Unicode properties that hold no ASCII letter when read with case:
     * titlecase letters and numbers. Caseless, \p{Lt} matches "A".
     */
    private const LETTER_FREE_PROPERTIES = ['Lt', 'N', 'Nd'];

    /**
     * The most digits a decimal integer may run to and still be below
     * PHP_INT_MAX, which (int) caps: 18 on a 64-bit build, 9 on a 32-bit one.
     */
    private const MAX_INT_DIGITS = \PHP_INT_SIZE >= 8 ? 18 : 9;

    /**
     * Groups that read what their content reads.
     */
    private const READ_THROUGH = [GroupType::Capturing, GroupType::Named, GroupType::NonCapturing, GroupType::Atomic, GroupType::BranchReset, GroupType::InlineFlags];

    /**
     * Groups that read no character of the subject: the lookarounds, and a
     * substring scan, which matches inside a capture already read.
     */
    private const LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring];

    private readonly LengthRangeCalculator $calculator;

    /**
     * @var \WeakMap<NodeInterface, LengthRange>
     */
    private \WeakMap $lengths;

    /**
     * @var \WeakMap<NodeInterface, Facts>
     */
    private readonly \WeakMap $facts;

    /**
     * @var \WeakMap<NodeInterface, Facts>
     */
    private readonly \WeakMap $groupFacts;

    /**
     * Characters and classes the engine reads caselessly, by object id: a
     * value that may fold is not read there.
     *
     * @var array<int, true>
     */
    private array $caseless = [];

    /**
     * Whether a group runs itself again through the calls it holds, by
     * object id.
     *
     * @var array<int, bool>
     */
    private array $recursive = [];

    /**
     * Nodes the scan reaches before any part of the pattern may have read
     * a character, by object id: an alternation there is read at the start.
     *
     * @var array<int, true>
     */
    private array $atStart = [];

    /**
     * Nodes nothing in the pattern follows, by object id: an alternation
     * there is not left by backtracking from a later part that fails.
     *
     * @var array<int, true>
     */
    private array $atEnd = [];

    /**
     * @param bool            $unicode lengths count code points, and literals read as UTF-8
     * @param bool            $ucp     \d and [[:digit:]] match non-ASCII digits: /u, or (*UCP)
     * @param GroupIndex|null $groups  the groups of the pattern, which its calls read
     */
    public function __construct(
        private readonly bool $unicode = false,
        private readonly bool $ucp = false,
        private readonly ?GroupIndex $groups = null,
    ) {
        $this->calculator = new LengthRangeCalculator($unicode, $this->calledBody(...));
        $this->lengths = new \WeakMap();
        $this->facts = new \WeakMap();
        $this->groupFacts = new \WeakMap();
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
     * its values, whether every value is truthy (nonFalsy), whether every
     * value is a non-empty run of ASCII digits (digitsOnly), whether every
     * value is a decimal integer as (string) (int) $v === $v reads it
     * (decimalInt), and whether no value holds an ASCII letter A-Z
     * (lowercase) or a-z (uppercase).
     *
     * A group's facts hold for every match of a scan, so they never take
     * the dominance of a zero-width first branch: a scan resumes after an
     * empty match, where "^" no longer matches.
     *
     * @return Facts
     */
    public function of(NodeInterface $node, bool $group = false): array
    {
        $cache = $group ? $this->groupFacts : $this->facts;
        if (isset($cache[$node])) {
            return $cache[$node];
        }

        [$min, $max] = $this->lengths($node);
        $values = $this->values($node, $group);

        return $cache[$node] = [
            'min' => $min,
            'max' => $max,
            'values' => $values,
            'nonFalsy' => $this->nonFalsy($node, $min, $values),
            // a digit run of any length is digits or the empty string; the
            // readers compose the empty arm with the length themselves
            'digitsOnly' => $this->digitsOnly($node),
            'decimalInt' => $this->decimalInt($node, $values, $group),
            // strtolower() and strtoupper() fold ASCII alone since PHP 8.2
            'lowercase' => null !== $values ? self::allValues($values, static fn (string $value): bool => Ascii::lower($value) === $value) : $this->holdsNoLetter($node, true),
            'uppercase' => null !== $values ? self::allValues($values, static fn (string $value): bool => Ascii::upper($value) === $value) : $this->holdsNoLetter($node, false),
        ];
    }

    /**
     * What each branch reads, when the node is an alternation, seen through
     * the groups that read what their content reads: (a|\d) reads 'a' or a
     * digit. Empty for any other node.
     *
     * @return list<Facts>
     */
    public function arms(NodeInterface $node, bool $group = false): array
    {
        $node = self::readThrough($node);
        if (!$node instanceof AlternationNode) {
            return [];
        }

        return array_map(fn (NodeInterface $branch): array => $this->of($branch, $group), $node->alternatives);
    }

    /**
     * Marks a node the scan reaches before any part of the pattern may have
     * read a character. The analysis collects them before it asks for facts.
     */
    public function markAtStart(NodeInterface $node): void
    {
        $this->atStart[spl_object_id($node)] = true;
    }

    /**
     * Marks a character or a class the engine reads caselessly. The
     * analysis collects them before it asks for facts.
     */
    public function markCaseless(NodeInterface $node): void
    {
        $this->caseless[spl_object_id($node)] = true;
    }

    /**
     * Marks a node nothing in the pattern follows. The analysis collects
     * them before it asks for facts.
     */
    public function markAtEnd(NodeInterface $node): void
    {
        $this->atEnd[spl_object_id($node)] = true;
    }

    /**
     * The strings a node can match, when they are a small finite set read
     * from literals alone.
     *
     * @return list<string>|null
     */
    private function values(NodeInterface $node, bool $scan = false): ?array
    {
        return match (true) {
            $node instanceof LiteralNode => $this->folds($node->value, $this->readsCaselessly($node)) ? null : [$node->value],
            $node instanceof CharLiteralNode => $this->character($node->codePoint, $this->readsCaselessly($node)),
            $node instanceof SequenceNode => $this->product(array_map(fn (NodeInterface $child): ?array => $this->values($child, $scan), $node->children)),
            $node instanceof AlternationNode => $this->alternationValues($node, $scan),
            $node instanceof GroupNode => self::isLookaround($node) ? [''] : $this->values($node->child, $scan),
            $node instanceof QuantifierNode => $this->repeat($node, $scan),
            $node instanceof CharClassNode => $this->classMembers($node),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof CommentNode,
            $node instanceof KeepNode, $node instanceof DefineNode, $node instanceof CalloutNode => [''],
            $node instanceof PcreVerbNode => str_starts_with($node->verb, 'ACCEPT') ? null : [''],
            $node instanceof SubroutineNode => null === ($body = $this->calledBody($node)) ? null : $this->values($body, $scan),
            default => null,
        };
    }

    /**
     * The body a call runs, read with the options of its group; null for a
     * call of the whole pattern or of no group, and for a group that runs
     * itself again: what a recursion reads is unknown.
     */
    private function calledBody(SubroutineNode $call): ?NodeInterface
    {
        $group = $this->groups?->groupsCalledBy($call)[0] ?? null;
        if (null === $group) {
            return null;
        }

        $seen = [];

        return ($this->recursive[spl_object_id($group)] ??= $this->reaches($group, $group, $seen)) ? null : $group->child;
    }

    /**
     * Whether a call the group holds runs the target, directly or through
     * the groups it calls in turn.
     *
     * @param array<int, true> $seen the groups already followed
     */
    private function reaches(GroupNode $group, GroupNode $target, array &$seen): bool
    {
        foreach (self::callsIn($group->child) as $call) {
            $called = $this->groups?->groupsCalledBy($call)[0] ?? null;
            if ($called === $target) {
                return true;
            }

            if (null === $called || isset($seen[$id = spl_object_id($called)])) {
                continue;
            }

            $seen[$id] = true;
            if ($this->reaches($called, $target, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<SubroutineNode>
     */
    private static function callsIn(NodeInterface $node): array
    {
        if ($node instanceof SubroutineNode) {
            return [$node];
        }

        $calls = [];
        foreach ($node->getChildren() as $child) {
            $calls = [...$calls, ...self::callsIn($child)];
        }

        return $calls;
    }

    private function readsCaselessly(NodeInterface $node): bool
    {
        return isset($this->caseless[spl_object_id($node)]);
    }

    /**
     * The branches' values — or the empty string alone, when the alternation
     * sits at the start of the pattern, nothing follows it, and its first
     * branch is a zero-width anchor that wins the match before any later
     * branch is ever reached: the engine reads the branches left to right at
     * each position and takes the leftmost, and "^" and "\b" read empty at
     * the scan start. Anything after the alternation could fail and send
     * the engine back into the later branches: /(^|a)b/ matches "ab".
     *
     * @return list<string>|null
     */
    private function alternationValues(AlternationNode $node, bool $scan = false): ?array
    {
        $id = spl_object_id($node);
        if (!$scan && ($this->atStart[$id] ?? false) && ($this->atEnd[$id] ?? false) && $this->dominatesFromStart($node->alternatives)) {
            return [''];
        }

        return $this->union(array_map(fn (NodeInterface $branch): ?array => $this->values($branch, $scan), $node->alternatives));
    }

    /**
     * Whether a first branch of "^" or "\A" — which matches at the scan
     * start whatever the subject holds — or of "\b", which matches at or
     * before wherever a later branch starting with a word character can
     * begin, keeps the later branches from ever filling the match.
     *
     * @param list<NodeInterface> $alternatives
     */
    private function dominatesFromStart(array $alternatives): bool
    {
        $first = $alternatives[0] ?? null;

        if ($first instanceof AnchorNode && ('^' === $first->value || '\\A' === $first->value)) {
            return true;
        }

        if (!$first instanceof AssertionNode || 'b' !== $first->value) {
            return false;
        }

        foreach (\array_slice($alternatives, 1) as $alternative) {
            $char = $this->firstConsumedChar($alternative);
            if (null === $char || !self::isWordChar($char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first character a node may read, when it starts with a literal
     * one; null for anything else.
     */
    private function firstConsumedChar(NodeInterface $node): ?string
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value ? null : $node->value[0];
        }

        if ($node instanceof CharLiteralNode) {
            $chars = $this->character($node->codePoint, $this->readsCaselessly($node));

            return null === $chars ? null : $chars[0];
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (0 !== ($this->lengths($child)[1] ?? 1)) {
                    return $this->firstConsumedChar($child);
                }
            }

            return null;
        }

        if ($node instanceof GroupNode && !\in_array($node->type, self::LOOKAROUNDS, true)) {
            return $this->firstConsumedChar($node->child);
        }

        return null;
    }

    private static function isWordChar(string $char): bool
    {
        return ($char >= '0' && $char <= '9')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= 'a' && $char <= 'z')
            || '_' === $char;
    }

    /**
     * The members a class holds, when each of them is a small finite set of
     * characters the pattern spells: literals and short ranges. A member too
     * big to spell — a type, a property, a long range, a negated class —
     * leaves the whole class unread, as it always did, and so does one that
     * may fold under caseless matching.
     *
     * @return list<string>|null
     */
    private function classMembers(CharClassNode $node): ?array
    {
        if ($node->isNegated) {
            return null;
        }

        $caseless = $this->readsCaselessly($node);
        $members = $node->expression instanceof AlternationNode
            ? $node->expression->alternatives
            : [$node->expression];

        $values = [];
        foreach ($members as $member) {
            if ($member instanceof RangeNode) {
                $from = $this->codePointOf($member->start);
                $to = $this->codePointOf($member->end);
                // the parser refuses a reversed range; a member the code
                // point of which it does not spell — a type, a property —
                // leaves the class unread
                if (null === $from || null === $to || $to < $from || $to - $from + 1 > self::MAX_VALUES) {
                    return null;
                }

                for ($codePoint = $from; $codePoint <= $to; $codePoint++) {
                    $character = $this->character($codePoint, $caseless);
                    if (null === $character) {
                        return null;
                    }

                    $values[] = $character[0];
                }

                continue;
            }

            if ($member instanceof LiteralNode) {
                // the pattern never writes an empty literal into a class;
                // a reader that hands one in enumerates no member, and an
                // empty enumeration reads as no constant downstream. Without
                // /u a class matches one byte: [é] matches "\xc3" and "\xa9"
                $characters = $this->unicode ? mb_str_split($member->value, encoding: 'UTF-8') : Bytes::split($member->value);
                foreach ($characters as $character) {
                    if ($this->folds($character, $caseless)) {
                        return null;
                    }

                    $values[] = $character;
                }

                continue;
            }

            // anything else a class holds — a type, a POSIX class, a
            // property — spells a set too large to enumerate
            $memberCodePoint = $this->codePointOf($member);
            // a code point no character of the encoding spells — a
            // surrogate under /u, past 255 without it — leaves the class unread
            $character = null === $memberCodePoint ? null : $this->character($memberCodePoint, $caseless);
            if (null === $character) {
                return null;
            }

            $values[] = $character[0];
        }

        if (\count($values) > self::MAX_VALUES) {
            return null;
        }

        return $this->union([$values]);
    }

    /**
     * The code point of a member a class spells by a single character,
     * whatever the spelling; null for every other member.
     */
    private function codePointOf(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        if ($node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        if ($node instanceof LiteralNode && 1 === mb_strlen($node->value, 'UTF-8')) {
            $codePoint = mb_ord($node->value, 'UTF-8');

            return false === $codePoint ? null : $codePoint;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function repeat(QuantifierNode $node, bool $scan = false): ?array
    {
        $bounds = $node->bounds;
        $body = $this->values($node->node, $scan);
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
    private function character(int $codePoint, bool $caseless): ?array
    {
        if ($caseless && $this->foldsCodePoint($codePoint)) {
            return null;
        }

        if ($this->unicode) {
            $character = mb_chr($codePoint, 'UTF-8');

            return false === $character ? null : [$character];
        }

        return $codePoint <= 0xFF ? [\chr($codePoint)] : null;
    }

    /**
     * Whether caseless matching may fold a code point into another: an
     * ASCII letter always folds — preg_match('/(ab£)/i', 'AB£') matches —
     * and without /u nothing else does — preg_match('/(é)/i', 'É') is 0 —
     * while under /u the engine folds by Unicode — preg_match('/é/iu',
     * 'É') is 1 — where past 7 bits nothing is provable.
     */
    private function foldsCodePoint(int $codePoint): bool
    {
        if (self::isAsciiLetter($codePoint)) {
            return true;
        }

        return $this->unicode && $codePoint >= 0x80;
    }

    /**
     * Whether any byte of a string read caselessly may fold, by the code
     * point of each of them: past 7 bits, a byte is a code point in its own
     * right without /u, and a piece of one under it.
     */
    private function folds(string $value, bool $caseless): bool
    {
        if (!$caseless) {
            return false;
        }

        for ($i = 0, $n = \strlen($value); $i < $n; $i++) {
            if ($this->foldsCodePoint(\ord($value[$i]))) {
                return true;
            }
        }

        return false;
    }

    private static function isAsciiLetter(int $codePoint): bool
    {
        return ($codePoint >= 0x41 && $codePoint <= 0x5A) || ($codePoint >= 0x61 && $codePoint <= 0x7A);
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
            $node instanceof SubroutineNode => null === ($body = $this->calledBody($node)) || $this->mayBeZero($body),
            $node instanceof CharClassNode => $node->isNegated ? !$this->holdsZero($node->expression) : $this->classMayHoldZero($node->expression),
            $node instanceof SequenceNode => $this->sequenceMayBeZero($node->children),
            $node instanceof AlternationNode => self::any($node->alternatives, $this->mayBeZero(...)),
            $node instanceof GroupNode => !self::isLookaround($node) && $this->mayBeZero($node->child),
            $node instanceof QuantifierNode => 0 !== $node->bounds?->max && $this->mayBeZero($node->node),
            self::consumesNothing($node) => false,
            default => $this->readsZero($node, false) ?? true,
        };
    }

    /**
     * Whether a concatenation may read "0" alone: some child reads it while
     * every other child reads nothing — a mandatory character anywhere else
     * pins the value off zero.
     *
     * @param list<NodeInterface> $children
     */
    private function sequenceMayBeZero(array $children): bool
    {
        foreach ($children as $candidate) {
            if (!$this->mayBeZero($candidate)) {
                continue;
            }

            $pinned = false;
            foreach ($children as $child) {
                if ($child !== $candidate && $this->lengths($child)[0] >= 1) {
                    $pinned = true;

                    break;
                }
            }

            if (!$pinned) {
                return true;
            }
        }

        return false;
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
            $node instanceof SubroutineNode => null !== ($body = $this->calledBody($node)) && $this->digitsOnly($body),
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
     * Whether every value the node reads is a decimal integer as
     * (string) (int) $v === $v reads it, PHPStan's decimal-int-string: "0",
     * or ASCII digits after an optional "-", the first of them not "0", no
     * more of them than PHP_INT_MAX always holds. A cut by (*ACCEPT) may
     * leave the sign alone. False when unsure.
     *
     * @param list<string>|null $values what the node reads
     */
    private function decimalInt(NodeInterface $node, ?array $values, bool $scan): bool
    {
        if (null !== $values) {
            return [] !== $values && self::allValues($values, static fn (string $value): bool => (string) (int) $value === $value);
        }

        if (self::accepts($node)) {
            return false;
        }

        $node = self::readThrough($node);
        if ($node instanceof AlternationNode) {
            return self::all($node->alternatives, fn (NodeInterface $branch): bool => $this->of($branch, $scan)['decimalInt']);
        }

        // what reads nothing, an anchor or an assertion, leaves the value as it is
        $parts = array_values(array_filter($node instanceof SequenceNode ? $node->children : [$node], fn (NodeInterface $part): bool => 0 !== $this->lengths($part)[1]));
        if (1 === \count($parts) && $parts[0] !== $node) {
            return $this->of($parts[0], $scan)['decimalInt'];
        }

        // the node itself, read through its groups, holds no value set
        $signs = [] === $parts || $parts[0] === $node ? null : $this->of($parts[0], $scan)['values'];
        if (null !== $signs && \in_array('-', $signs, true) && [] === array_diff($signs, ['', '-'])) {
            array_shift($parts);
        }

        if ([] === $parts || !self::all($parts, $this->digitsOnly(...))) {
            return false;
        }

        $digits = 0;
        foreach ($parts as $part) {
            $max = $this->lengths($part)[1];
            if (null === $max) {
                return false;
            }
            $digits += $max;
        }

        return $digits <= self::MAX_INT_DIGITS && $this->startsWithNonZero($parts[0], $scan);
    }

    /**
     * Whether every value the node reads is non-empty and starts with
     * another character than "0". False when unsure.
     */
    private function startsWithNonZero(NodeInterface $node, bool $scan): bool
    {
        $values = $this->values($node, $scan);
        if (null !== $values) {
            return [] !== $values && self::allValues($values, static fn (string $value): bool => '' !== $value && '0' !== $value[0]);
        }

        $node = self::readThrough($node);

        return match (true) {
            $node instanceof AlternationNode => self::all($node->alternatives, fn (NodeInterface $branch): bool => $this->startsWithNonZero($branch, $scan)),
            $node instanceof SequenceNode => $this->sequenceStartsWithNonZero($node->children, $scan),
            $node instanceof QuantifierNode => ($node->bounds->min ?? 0) >= 1 && $this->startsWithNonZero($node->node, $scan),
            $node instanceof SubroutineNode => null !== ($body = $this->calledBody($node)) && $this->startsWithNonZero($body, $scan),
            $node instanceof CharClassNode, $node instanceof CharTypeNode => $this->lengths($node)[0] >= 1 && !$this->mayBeZero($node),
            default => false,
        };
    }

    /**
     * Whether the first child that may read a character reads one at least,
     * and never starts with "0".
     *
     * @param list<NodeInterface> $children
     */
    private function sequenceStartsWithNonZero(array $children, bool $scan): bool
    {
        $reading = array_values(array_filter($children, fn (NodeInterface $child): bool => 0 !== $this->lengths($child)[1]));

        return [] !== $reading && $this->lengths($reading[0])[0] >= 1 && $this->startsWithNonZero($reading[0], $scan);
    }

    /**
     * Whether no value the node reads holds an ASCII letter of the case:
     * A-Z when $upper, a-z otherwise, the only letters strtolower() and
     * strtoupper() fold. Where the engine reads caselessly a letter may read
     * in either case, and under /u a code point past ASCII may fold into
     * one: preg_match('/\x{212A}/iu', 'K') is 1. False when unsure.
     */
    private function holdsNoLetter(NodeInterface $node, bool $upper): bool
    {
        $caseless = $this->readsCaselessly($node);

        return match (true) {
            $node instanceof LiteralNode => $this->stringHoldsNoLetter($node->value, $caseless, $upper),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $this->rangeHoldsNoLetter($node->codePoint, $node->codePoint, $caseless, $upper),
            $node instanceof CharTypeNode => \in_array($node->value, self::LETTER_FREE_TYPES, true),
            $node instanceof CharClassNode => !$node->isNegated && $this->memberHoldsNoLetter($node->expression, $caseless, $upper),
            $node instanceof UnicodePropNode => self::propertyHoldsNoLetter($node, $caseless, $upper),
            $node instanceof SequenceNode => self::all($node->children, fn (NodeInterface $child): bool => $this->holdsNoLetter($child, $upper)),
            $node instanceof AlternationNode => self::all($node->alternatives, fn (NodeInterface $branch): bool => $this->holdsNoLetter($branch, $upper)),
            $node instanceof GroupNode => self::isLookaround($node) || $this->holdsNoLetter($node->child, $upper),
            $node instanceof QuantifierNode => $this->holdsNoLetter($node->node, $upper),
            $node instanceof SubroutineNode => null !== ($body = $this->calledBody($node)) && $this->holdsNoLetter($body, $upper),
            default => self::consumesNothing($node),
        };
    }

    /**
     * Whether a class member holds no ASCII letter of the case, read with
     * the caselessness of its class.
     */
    private function memberHoldsNoLetter(NodeInterface $member, bool $caseless, bool $upper): bool
    {
        return match (true) {
            $member instanceof AlternationNode => self::all($member->alternatives, fn (NodeInterface $alternative): bool => $this->memberHoldsNoLetter($alternative, $caseless, $upper)),
            $member instanceof LiteralNode => $this->stringHoldsNoLetter($member->value, $caseless, $upper),
            $member instanceof CharLiteralNode, $member instanceof ControlCharNode => $this->rangeHoldsNoLetter($member->codePoint, $member->codePoint, $caseless, $upper),
            $member instanceof RangeNode => null !== ($range = $this->range($member)) && $this->rangeHoldsNoLetter($range[0], $range[1], $caseless, $upper),
            $member instanceof CharTypeNode => \in_array($member->value, self::LETTER_FREE_TYPES, true),
            $member instanceof PosixClassNode => \in_array($member->class, self::LETTER_FREE_POSIX, true)
                || (!$caseless && ($upper ? 'lower' : 'upper') === $member->class),
            $member instanceof UnicodePropNode => self::propertyHoldsNoLetter($member, $caseless, $upper),
            default => false,
        };
    }

    /**
     * Whether no byte of a literal is an ASCII letter of the case, or, read
     * caselessly, may fold into one.
     */
    private function stringHoldsNoLetter(string $value, bool $caseless, bool $upper): bool
    {
        for ($i = 0, $n = \strlen($value); $i < $n; $i++) {
            if (!$this->rangeHoldsNoLetter(\ord($value[$i]), \ord($value[$i]), $caseless, $upper)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether no code point from $from to $to is an ASCII letter of the
     * case, or, read caselessly, may fold into one: an ASCII letter folds
     * into the other case, and under /u a code point past ASCII may fold
     * into an ASCII letter. Without /u nothing past ASCII folds.
     */
    private function rangeHoldsNoLetter(int $from, int $to, bool $caseless, bool $upper): bool
    {
        if ($caseless) {
            return !($this->unicode && $to >= 0x80) && !self::overlaps($from, $to, 0x41, 0x5A) && !self::overlaps($from, $to, 0x61, 0x7A);
        }

        return $upper ? !self::overlaps($from, $to, 0x41, 0x5A) : !self::overlaps($from, $to, 0x61, 0x7A);
    }

    private static function overlaps(int $from, int $to, int $low, int $high): bool
    {
        return $from <= $high && $to >= $low;
    }

    /**
     * Whether a Unicode property holds no ASCII letter of the case: \p{Ll}
     * holds no A-Z, \p{Lu} no a-z, and the titlecase letters and the numbers
     * neither. Caseless, \p{Ll}, \p{Lu} and \p{Lt} match any cased letter,
     * and no property is read; nor is a negated one.
     */
    private static function propertyHoldsNoLetter(UnicodePropNode $node, bool $caseless, bool $upper): bool
    {
        if ($caseless) {
            return false;
        }

        $property = trim($node->prop, '{}');

        return \in_array($property, self::LETTER_FREE_PROPERTIES, true) || ($upper ? 'Ll' : 'Lu') === $property;
    }

    /**
     * Whether the node holds an (*ACCEPT), which may end the match anywhere
     * after it.
     */
    private static function accepts(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode) {
            return str_starts_with($node->verb, 'ACCEPT');
        }

        foreach ($node->getChildren() as $child) {
            if (self::accepts($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The node under the groups that read what their content reads.
     */
    private static function readThrough(NodeInterface $node): NodeInterface
    {
        while ($node instanceof GroupNode && \in_array($node->type, self::READ_THROUGH, true) && !self::isBareOption($node)) {
            $node = $node->child;
        }

        return $node;
    }

    /**
     * @param list<string>           $values
     * @param callable(string): bool $test
     */
    private static function allValues(array $values, callable $test): bool
    {
        foreach ($values as $value) {
            if (!$test($value)) {
                return false;
            }
        }

        return true;
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

    /**
     * Whether the group is a bare "(?i)", which spans its flags and three
     * bytes and reads nothing, rather than a scoped "(?i:...)".
     */
    private static function isBareOption(GroupNode $node): bool
    {
        return GroupType::InlineFlags === $node->type && $node->getEndPosition() - $node->getStartPosition() === \strlen($node->flags ?? '') + 3;
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
