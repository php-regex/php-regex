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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
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
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * Measures a pattern as the first pass of PCRE2 10.48 and later compiles
 * it, to tell which check finds it too large. Each item is added to the
 * length of its branch, which may not pass the limit; each branch but the
 * last of a group is added to the branches before it, which may not pass it
 * either: both report the error at offset 0. The last branch is added where
 * the group ends, by a check that sets no offset, so at the end of the
 * pattern.
 *
 * An item is counted at the length PCRE2 compiles it to where this class
 * knows that length, and at the floor the validator measures otherwise,
 * with the most it may compile to where that is known: the ceiling.
 *
 * @internal
 */
final class CompiledLength
{
    /**
     * The largest compiled pattern, in code units, with two-byte links.
     */
    private const LIMIT = 65536;

    /**
     * The opening and closing brackets of a group, with their links.
     */
    private const BRACKETS = 6;

    /**
     * A "|": the alternation marker and its link.
     */
    private const ALTERNATION = 3;

    /**
     * What the "may skip" marker and the brackets nesting the next copy add
     * to an optional copy of a counted group.
     */
    private const OPTIONAL_COPY = 7;

    /**
     * A length no pattern reaches, where the counts stop growing.
     */
    private const CAP = 1 << 40;

    /**
     * The characters below 256 each type and POSIX name of a class matches
     * when UCP is off, as ranges. PCRE matches the vertical tab with "\s".
     */
    private const MAP_RANGES = [
        'd' => [[0x30, 0x39]],
        'digit' => [[0x30, 0x39]],
        's' => [[0x09, 0x0D], [0x20, 0x20]],
        'space' => [[0x09, 0x0D], [0x20, 0x20]],
        'w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]],
        'word' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]],
        'h' => [[0x09, 0x09], [0x20, 0x20], [0xA0, 0xA0]],
        'v' => [[0x0A, 0x0D], [0x85, 0x85]],
        'alpha' => [[0x41, 0x5A], [0x61, 0x7A]],
        'alnum' => [[0x30, 0x39], [0x41, 0x5A], [0x61, 0x7A]],
        'upper' => [[0x41, 0x5A]],
        'lower' => [[0x61, 0x7A]],
        'xdigit' => [[0x30, 0x39], [0x41, 0x46], [0x61, 0x66]],
        'blank' => [[0x09, 0x09], [0x20, 0x20]],
        'cntrl' => [[0x00, 0x1F], [0x7F, 0x7F]],
        'punct' => [[0x21, 0x2F], [0x3A, 0x40], [0x5B, 0x60], [0x7B, 0x7E]],
        'graph' => [[0x21, 0x7E]],
        'print' => [[0x20, 0x7E]],
        'ascii' => [[0x00, 0x7F]],
    ];

    /**
     * The characters of PCRE2 10.48's caseless sets, each with more than one
     * other case (pcre2_ucd.c, ucd_caseless_sets): under a caseless Unicode
     * option, one of them compiles to a property of its set.
     */
    private const CASELESS_SETS = [
        0x0053 => true, 0x0073 => true, 0x017F => true,
        0x01C4 => true, 0x01C5 => true, 0x01C6 => true,
        0x01C7 => true, 0x01C8 => true, 0x01C9 => true,
        0x01CA => true, 0x01CB => true, 0x01CC => true,
        0x01F1 => true, 0x01F2 => true, 0x01F3 => true,
        0x0345 => true, 0x0399 => true, 0x03B9 => true, 0x1FBE => true,
        0x00B5 => true, 0x039C => true, 0x03BC => true,
        0x03A3 => true, 0x03C2 => true, 0x03C3 => true,
        0x0392 => true, 0x03B2 => true, 0x03D0 => true,
        0x0398 => true, 0x03B8 => true, 0x03D1 => true, 0x03F4 => true,
        0x03A6 => true, 0x03C6 => true, 0x03D5 => true,
        0x03A0 => true, 0x03C0 => true, 0x03D6 => true,
        0x039A => true, 0x03BA => true, 0x03F0 => true,
        0x03A1 => true, 0x03C1 => true, 0x03F1 => true,
        0x0395 => true, 0x03B5 => true, 0x03F5 => true,
        0x0412 => true, 0x0432 => true, 0x1C80 => true,
        0x0414 => true, 0x0434 => true, 0x1C81 => true,
        0x041E => true, 0x043E => true, 0x1C82 => true,
        0x0421 => true, 0x0441 => true, 0x1C83 => true,
        0x0422 => true, 0x0442 => true, 0x1C84 => true, 0x1C85 => true,
        0x042A => true, 0x044A => true, 0x1C86 => true,
        0x0462 => true, 0x0463 => true, 0x1C87 => true,
        0x1E60 => true, 0x1E61 => true, 0x1E9B => true,
        0x03A9 => true, 0x03C9 => true, 0x2126 => true,
        0x004B => true, 0x006B => true, 0x212A => true,
        0x00C5 => true, 0x00E5 => true, 0x212B => true,
        0x1C88 => true, 0xA64A => true, 0xA64B => true,
    ];

    /**
     * Whether a group of several branches was measured whole before the
     * limit was passed, and may have passed it where it ends: its length is
     * not known and its ceiling is past the limit, or not known either.
     */
    private bool $branchesMayEndPastTheLimit = false;

    /**
     * The captures open around the item measured, since the assertion it
     * stands in: "(*ACCEPT)" closes each of them.
     */
    private int $openCaptures = 0;

    /**
     * Whether the source may set rules that change the cases of "i", "k"
     * or "s", once read.
     */
    private ?bool $caseRulesVary = null;

    /**
     * Whether the source may hold an inline "a", which keeps "\d", "\s" and
     * "\w" to ASCII under UCP, once read.
     */
    private ?bool $asciiOptions = null;

    /**
     * @param \Closure(NodeInterface): int $floor  the fewest units a node may
     *                                             compile to
     * @param string                       $source the pattern, between its
     *                                             delimiters
     * @param bool                         $utf    whether PCRE reads the
     *                                             pattern as UTF-8
     * @param bool                         $ucp    whether "\d", "\w", "\s" and
     *                                             caseless letters follow
     *                                             Unicode
     * @param string                       $flags  the flags after the
     *                                             closing delimiter
     */
    public function __construct(
        private readonly \Closure $floor,
        private readonly string $source,
        private readonly bool $utf,
        private readonly bool $ucp,
        private readonly string $flags,
    ) {}

    /**
     * Whether PCRE2 10.48 and later find the pattern too large inside a
     * branch, and report it at offset 0: false when they find it where a
     * group ends, or when that cannot be told for sure.
     */
    public function passedInsideABranch(NodeInterface $pattern): bool
    {
        $this->branchesMayEndPastTheLimit = false;
        $this->openCaptures = 0;
        // The end marker comes first, then the brackets around the pattern.
        [$found] = $this->measureBranches(self::branchesOf($pattern), 1, [self::BRACKETS, self::BRACKETS], str_contains($this->flags, 'i'));

        return false === $found && !$this->branchesMayEndPastTheLimit;
    }

    /**
     * The length PCRE2 10.48 and later compile the pattern to, its end
     * marker included, or null when it is not known for sure or passes the
     * limit.
     */
    public function of(NodeInterface $pattern): ?int
    {
        $this->branchesMayEndPastTheLimit = false;
        $this->openCaptures = 0;
        [$found, $length, $ceiling] = $this->measureBranches(self::branchesOf($pattern), 1, [self::BRACKETS, self::BRACKETS], str_contains($this->flags, 'i'));

        return null === $found && $length === $ceiling ? $length + 1 : null;
    }

    /**
     * Measure a group: each item is added to its branch, which may not pass
     * the limit; each branch but the last is then added to what comes before
     * the group, and the last one where the group ends.
     *
     * @param list<NodeInterface>    $branches
     * @param int                    $before   the units counted before the group
     * @param array{0: int, 1: ?int} $frame    the length and the ceiling of what
     *                                         the group holds besides its branches
     *
     * @return array{0: bool|null, 1: int, 2: ?int} whether the limit is passed
     *                                              where the group ends
     *                                              (true), inside a branch
     *                                              (false), or not at all
     *                                              (null); and the length and
     *                                              the ceiling of the group
     *
     * @phpstan-impure it records a group of branches that may end past the limit
     */
    private function measureBranches(array $branches, int $before, array $frame, bool $caseless): array
    {
        [$length, $ceiling] = $frame;
        $done = 0;
        $last = \count($branches) - 1;
        foreach ($branches as $index => $branch) {
            [$found, $length, $branchCeiling, $caseless] = $this->measureItems($branch, $length, $caseless);
            $ceiling = self::sum($ceiling, $branchCeiling);
            if (null !== $found) {
                return [$found, $done + $length, null];
            }

            if ($index < $last) {
                $length += self::ALTERNATION;
                $ceiling = self::sum($ceiling, self::ALTERNATION);
                if ($before + $done + $length > self::LIMIT) {
                    return [false, $done + $length, null];
                }

                $done += $length;
                $length = 0;
            } elseif ($before + $done + $length > self::LIMIT) {
                return [true, $done + $length, null];
            }
        }

        return [null, $done + $length, $ceiling];
    }

    /**
     * Add the items of $branch to the $length of the branch so far, a group
     * measured on its own first. An option set in a branch holds to the end
     * of its group, in the branches after it too.
     *
     * @return array{0: bool|null, 1: int, 2: ?int, 3: bool} as
     *                                                       measureBranches()
     *                                                       does, with the
     *                                                       length of the
     *                                                       branch and the
     *                                                       ceiling of its
     *                                                       items, and whether
     *                                                       it ends caseless
     *
     * @phpstan-impure it records a group of branches that may end past the limit
     */
    private function measureItems(NodeInterface $branch, int $length, bool $caseless): array
    {
        $ceiling = 0;
        foreach (self::itemsOf($branch) as $item) {
            // Most items are one ASCII letter, or one byte without UTF:
            // measured at once.
            $units = $item instanceof LiteralNode && 1 === \strlen($item->value)
                ? (!$caseless || !($this->utf || $this->ucp) || (!isset(self::CASELESS_SETS[\ord($item->value)]) && !$this->caseRulesVary()) ? 2 : $this->characterLength(\ord($item->value), true))
                : null;
            if (null !== $units) {
                $length += $units;
                $ceiling = null === $ceiling ? null : $ceiling + $units;
                if ($length > self::LIMIT) {
                    return [false, $length, null, $caseless];
                }

                continue;
            }

            if ($item instanceof GroupNode && GroupType::InlineFlags === $item->type && $this->setsOptionsOnly($item)) {
                $caseless = self::caselessAfter((string) $item->flags, $caseless);

                continue;
            }

            [$found, $itemLength, $itemCeiling] = $this->measureItem($item, $caseless);
            if (null !== $found) {
                return [$found, $length + $itemLength, null, $caseless];
            }

            $length += $itemLength;
            $ceiling = self::sum($ceiling, $itemCeiling);
            if ($length > self::LIMIT) {
                return [false, $length, null, $caseless];
            }
        }

        return [null, $length, $ceiling, $caseless];
    }

    /**
     * @return array{0: bool|null, 1: int, 2: ?int} as measureBranches()
     *                                              does, for one item
     *
     * @phpstan-impure it records a group of branches that may end past the limit
     */
    private function measureItem(NodeInterface $item, bool $caseless): array
    {
        $repeated = $item instanceof QuantifierNode ? $item->node : $item;
        $group = $this->groupOf($repeated, $caseless);
        if (null === $group) {
            $length = $this->leafLength($item, $caseless);

            return null === $length ? [null, ($this->floor)($item), $this->leafCeiling($item)] : [null, $length, $length];
        }

        [$branches, $frame, $bodyCaseless, $condition] = $group;
        if (null !== $condition) {
            // An assertion as the condition is compiled first, as a group.
            [$found, $conditionLength, $conditionCeiling] = $this->measureItem($condition, $caseless);
            if (null !== $found) {
                return [$found, $conditionLength, null];
            }

            $frame = [$frame[0] + $conditionLength, self::sum($frame[1], $conditionCeiling)];
            if ($frame[0] > self::LIMIT) {
                return [false, $frame[0], null];
            }
        }

        $open = $this->openCaptures;
        $this->openCaptures = match (true) {
            $repeated instanceof GroupNode && \in_array($repeated->type, [GroupType::Capturing, GroupType::Named], true) => $open + 1,
            $repeated instanceof GroupNode && \in_array($repeated->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true) => 0,
            default => $open,
        };
        [$found, $length, $ceiling] = $this->measureBranches($branches, 0, $frame, $bodyCaseless);
        $this->openCaptures = $open;
        if (null !== $found) {
            return [$found, $length, null];
        }

        if ($this->mayHoldBranches($repeated, $branches) && (null === $ceiling || $ceiling > self::LIMIT)) {
            $this->branchesMayEndPastTheLimit = true;
        }

        if (!$item instanceof QuantifierNode) {
            return [null, $length, $ceiling];
        }

        $repeat = $this->groupRepeat($item, $repeated);
        if (null === $repeat) {
            return [null, ($this->floor)($item), null];
        }

        return [null, $repeat($length), null === $ceiling ? null : $repeat($ceiling)];
    }

    /**
     * What a group-like node holds, when PCRE compiles it as a group with
     * checks of its own: its branches, the length and ceiling of the rest
     * of it, whether its body starts caseless, and a condition to measure
     * first.
     *
     * @return array{0: list<NodeInterface>, 1: array{0: int, 1: ?int}, 2: bool, 3: ?NodeInterface}|null
     */
    private function groupOf(NodeInterface $node, bool $caseless): ?array
    {
        if ($node instanceof GroupNode) {
            return match ($node->type) {
                GroupType::Capturing, GroupType::Named => [self::branchesOf($node->child), [self::BRACKETS + 2, self::BRACKETS + 2], $caseless, null],
                GroupType::NonCapturing, GroupType::Atomic, GroupType::BranchReset => [self::branchesOf($node->child), [self::BRACKETS, self::BRACKETS], $caseless, null],
                GroupType::InlineFlags => [self::branchesOf($node->child), [self::BRACKETS, self::BRACKETS], self::caselessAfter((string) $node->flags, $caseless), null],
                // "(?=)" compiles to a group, "(?!)" to a failure, and so
                // may one that holds only options or comments: left to the
                // floor.
                GroupType::LookaheadPositive, GroupType::LookaheadNegative => $this->isBlank($node->child) ? null : [self::branchesOf($node->child), [self::BRACKETS, self::BRACKETS], $caseless, null],
                // Each branch of a lookbehind of some length starts with
                // the length to move back, in one number or two.
                GroupType::LookbehindPositive, GroupType::LookbehindNegative => $this->isBlank($node->child) ? null : [$branches = self::branchesOf($node->child), [self::BRACKETS, self::BRACKETS + 5 * \count($branches)], $caseless, null],
                default => [self::branchesOf($node->child), [self::BRACKETS, null], $caseless, null],
            };
        }

        if ($node instanceof ConditionalNode) {
            $branches = self::isEmpty($node->no) ? [$node->yes] : [$node->yes, $node->no];
            // An empty second branch may be written, "(?(1)a|)", or not.
            $ceiling = self::isEmpty($node->no) ? self::BRACKETS + self::ALTERNATION : self::BRACKETS;
            $condition = $node->condition;
            if ($condition instanceof GroupNode) {
                return [$branches, [self::BRACKETS, $ceiling], $caseless, $condition];
            }

            // A group number, or the recursion it tests, is a number; a name
            // may stand for several groups, or the condition be a version.
            $length = ($condition instanceof BackrefNode && 1 === LibraryPcre::match('/^[+-]?\d++$/', $condition->ref))
                || ($condition instanceof SubroutineNode && 1 === LibraryPcre::match('/^R\d*+$/', $condition->reference)) ? 3 : null;

            return [$branches, null === $length ? [self::BRACKETS + ($this->floor)($condition), $ceiling + 5] : [self::BRACKETS + $length, $ceiling + $length], $caseless, null];
        }

        if ($node instanceof DefineNode) {
            // The brackets and the condition that is never true.
            return [self::branchesOf($node->content), [self::BRACKETS + 1, self::BRACKETS + 1], $caseless, null];
        }

        if ($node instanceof ScriptRunNode && null !== $node->content) {
            // An atomic script run is a script run inside an atomic group.
            return [self::branchesOf($node->content), $node->atomic ? [self::BRACKETS, 2 * self::BRACKETS] : [self::BRACKETS, self::BRACKETS], $caseless, null];
        }

        return null;
    }

    /**
     * How a counted group, conditional or call grows with its count, as a
     * function of the length of one copy; null when this class does not
     * know it.
     *
     * @return (\Closure(int): int)|null
     */
    private function groupRepeat(QuantifierNode $node, NodeInterface $repeated): ?\Closure
    {
        [$min, $max] = self::boundsOf($node);
        $possessive = QuantifierType::Possessive === $node->type;
        if ($repeated instanceof GroupNode) {
            $assertion = \in_array($repeated->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true);

            return static fn (int $copy): int => self::repeatGroup($copy, $min, $assertion && null === $max ? $min + 1 : $max, $possessive, false);
        }

        // A conditional on a version may be false, and its repeat ignored.
        if ($repeated instanceof ConditionalNode && ($repeated->condition instanceof GroupNode || $repeated->condition instanceof BackrefNode || $repeated->condition instanceof SubroutineNode)) {
            return static fn (int $copy): int => self::repeatGroup($copy, $min, $max, $possessive, true);
        }

        return null;
    }

    /**
     * A counted group: every mandatory copy as it is, every optional one
     * behind a "may skip" marker inside brackets that nest the next (the
     * innermost not), and an open maximum as a last copy that loops. A
     * possessive count wraps it all in an atomic group, unless it may be
     * written with the possessive brackets of a group repeated at most once
     * first; a conditional has none.
     */
    private static function repeatGroup(int $copy, int $min, ?int $max, bool $possessive, bool $conditional): int
    {
        $wrap = $possessive ? self::BRACKETS : 0;
        if (1 === $min && 1 === $max) {
            return $copy + $wrap;
        }

        if (0 === $min) {
            return self::cap(match (true) {
                // Kept, behind a marker that skips it.
                0 === $max => $copy + 1,
                null === $max => $copy + 1 + ($conditional ? $wrap : 0),
                default => $copy + 1 + ($max - 1) * ($copy + self::OPTIONAL_COPY) + $wrap,
            });
        }

        if (null === $max) {
            return self::cap($min * $copy + ($conditional ? $wrap : 0) + ($min > 1 ? $wrap : 0));
        }

        $optional = $max - $min;

        return self::cap($min * $copy + ($optional > 0 ? $optional * ($copy + self::OPTIONAL_COPY) - self::BRACKETS : 0) + $wrap);
    }

    /**
     * The length of an item that holds no group PCRE checks on its own, or
     * null when this class does not know it.
     */
    private function leafLength(NodeInterface $node, bool $caseless): ?int
    {
        if ($node instanceof QuantifierNode) {
            return $this->repeatedLength($node, $caseless);
        }

        $character = $this->characterOf($node);
        if (null !== $character) {
            return $this->characterLength($character, $caseless);
        }

        return match (true) {
            $node instanceof LiteralNode => $this->literalLength($node->value, $caseless),
            $node instanceof DotNode, $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof KeepNode => 1,
            $node instanceof CommentNode => 0,
            $node instanceof CharTypeNode => $this->typeLength($node),
            // "\p{Any}" is a match of any character, "\P{Any}" a class of none.
            $node instanceof UnicodePropNode => self::isAnyProperty($node) ? (str_contains($node->prop, '^') ? 33 : 1) : 3,
            $node instanceof CharClassNode => $this->classLength($node, $caseless),
            $node instanceof BackrefNode => self::isGroupNumber($node) ? ($caseless ? 4 : 3) : null,
            $node instanceof SubroutineNode => 3,
            $node instanceof CalloutNode => \is_string($node->identifier) && $node->isStringIdentifier ? null : 6,
            $node instanceof PcreVerbNode => $this->verbLength($node->verb),
            $node instanceof LimitMatchNode => 0,
            default => null,
        };
    }

    /**
     * The most an item whose length is not known may compile to, or null.
     */
    private function leafCeiling(NodeInterface $node): ?int
    {
        [$min, $max] = $node instanceof QuantifierNode ? self::boundsOf($node) : [1, 1];
        $item = $node instanceof QuantifierNode ? $node->node : $node;

        // A character whose cases are not known is a caseless character, or
        // a property of three units: the longer of the two. A count of zero
        // or of one leaves the item as it is.
        $codePoint = $this->codePointOf($item);
        if (null !== $codePoint && $codePoint <= 0x10FFFF && ($this->utf || $codePoint <= 0xFF)) {
            $units = $this->utf ? \strlen((string) mb_chr($codePoint, 'UTF-8')) : 1;

            return 0 === $max || (1 === $min && 1 === $max) ? max(1 + $units, 3) : max(self::repeatSingle($units, 1 + $units, $min, $max), self::repeatSingle(0, 3, $min, $max));
        }

        return match (true) {
            $node instanceof QuantifierNode => null,
            // A reference to a name that stands for several groups, caseless.
            $node instanceof BackrefNode => 6,
            $node instanceof GroupNode && $this->isBlank($node->child) => self::BRACKETS,
            default => null,
        };
    }

    /**
     * A counted item that is not a group: a character or a type becomes one
     * repeat item, a class or a reference takes a count after it.
     */
    private function repeatedLength(QuantifierNode $node, bool $caseless): ?int
    {
        [$min, $max] = self::boundsOf($node);
        $item = $node->node;
        if ($item instanceof SubroutineNode) {
            return self::repeatCall($min, $max, QuantifierType::Possessive === $node->type);
        }

        // A counted verb, "(*ACCEPT)?", is wrapped in a group first.
        if ($item instanceof PcreVerbNode) {
            return null;
        }

        // A count of zero drops the item, but its length stays counted.
        if (0 === $max || (1 === $min && 1 === $max)) {
            return $this->leafLength($item, $caseless);
        }

        $character = $this->characterOf($item);
        if (null !== $character) {
            $repeat = $this->characterRepeat($character, $caseless);

            return null === $repeat ? null : self::repeatSingle($repeat[0], $repeat[1], $min, $max);
        }

        if ($item instanceof DotNode || $item instanceof CharTypeNode || $item instanceof UnicodePropNode) {
            $length = $this->leafLength($item, $caseless);

            // A type repeats with its opcode, and a property its two values;
            // "\P{Any}" is a class.
            return null === $length || $length > 3 ? null : self::repeatSingle(0, $length, $min, $max);
        }

        // A class or a reference takes its count after it: "*", "+" and "?"
        // in one unit, any other count in five. Nothing else repeats.
        $length = $item instanceof CharClassNode || $item instanceof BackrefNode ? $this->leafLength($item, $caseless) : null;

        return null === $length ? null : $length + ((null === $max && $min < 2) || (0 === $min && 1 === $max) ? 1 : 5);
    }

    /**
     * The least and the most a quantifier repeats, null for no most: a
     * quantifier the parser built is always one PCRE reads.
     *
     * @return array{0: int, 1: ?int}
     */
    private static function boundsOf(QuantifierNode $node): array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max];
    }

    /**
     * A character or a type repeated: one opcode for "*", "+" and "?", one
     * and a count for an upper bound, an exact count and a second opcode for
     * a count of at least two; the item after each opcode. $mc is the units
     * of a character, 0 for a type of $type units.
     */
    private static function repeatSingle(int $mc, int $type, int $min, ?int $max): int
    {
        $unit = 0 === $mc ? $type : $mc;
        $item = 0 === $mc ? $type : 1 + $mc;
        $open = null === $max;

        return match (true) {
            0 === $min => ($open || 1 === $max ? 1 : 3) + $unit,
            1 === $min => $open ? 1 + $unit : $item + 3 + $unit,
            $min === $max => 3 + $unit,
            default => 3 + $unit + ($open || 1 === $max - $min ? 1 : 3) + $unit,
        };
    }

    /**
     * A counted call to a group: the mandatory copies as they are, then the
     * call wrapped in brackets and repeated as a group for what is left.
     */
    private static function repeatCall(int $min, ?int $max, bool $possessive): int
    {
        if (1 === $min && 1 === $max && !$possessive) {
            return 3;
        }

        if ($min > 0 && (1 !== $min || null !== $max)) {
            if ($min === $max) {
                return self::cap(3 * $min + ($possessive ? self::BRACKETS : 0));
            }

            return self::cap(3 * $min + self::repeatGroup(3 + self::BRACKETS, 0, null === $max ? null : $max - $min, $possessive, false));
        }

        return self::repeatGroup(3 + self::BRACKETS, $min, $max, $possessive, false);
    }

    /**
     * The code point a node stands for as one character, a class of one
     * character or of an ASCII letter and its other case included.
     */
    private function characterOf(NodeInterface $node): ?int
    {
        if ($node instanceof CharClassNode) {
            $members = $this->classMembers($node);
            if (null === $members || [] !== $members[1]) {
                return null;
            }

            // "[^a]" is a negated character, of the same length as "a".
            if (1 === \count($members[0])) {
                return $members[0][0];
            }

            // A letter and its other case is one caseless letter: read as
            // the negated code point, minus one.
            [$first, $second] = $members[0] + [1 => -1];

            return !$node->isNegated && 2 === \count($members[0]) && true === $this->isCasePair($first, $second) ? -1 - $first : null;
        }

        return $this->codePointOf($node);
    }

    /**
     * The opcode and code units of a character, or null: under a caseless
     * Unicode option, a letter with more than one other case is a property
     * of three units; where rules that change the cases of "i", "k" and "s"
     * may be set, the letters they touch are not known here.
     *
     * @return array{0: int, 1: int}|null the units of the character, 0 for a
     *                                    property, and the units of the item
     */
    private function characterRepeat(int $codePoint, bool $caseless): ?array
    {
        if ($codePoint < 0) {
            // A letter and its other case: one caseless ASCII letter.
            return [1, 2];
        }

        if ($caseless && ($this->utf || $this->ucp)) {
            $set = isset(self::CASELESS_SETS[$codePoint]);
            if ($this->caseRulesVary() && ($set || \in_array($codePoint, [0x49, 0x69, 0x130, 0x131], true))) {
                return null;
            }

            if ($set) {
                return [0, 3];
            }
        }

        if (!$this->utf) {
            return $codePoint > 0xFF ? null : [1, 2];
        }

        $units = \strlen((string) mb_chr($codePoint, 'UTF-8'));

        return [$units, 1 + $units];
    }

    private function characterLength(int $codePoint, bool $caseless): ?int
    {
        return $this->characterRepeat($codePoint, $caseless)[1] ?? null;
    }

    private function literalLength(string $value, bool $caseless): ?int
    {
        $characters = $this->utf ? mb_str_split($value, 1, 'UTF-8') : Bytes::split($value);
        $length = 0;
        foreach ($characters as $character) {
            $units = $this->characterLength($this->utf ? (int) mb_ord($character, 'UTF-8') : \ord($character), $caseless);
            if (null === $units) {
                return null;
            }

            $length += $units;
        }

        return $length;
    }

    private function typeLength(CharTypeNode $node): ?int
    {
        if (!str_contains('dDsSwW', $node->value)) {
            return 1;
        }

        if (!$this->ucp) {
            return 1;
        }

        // An inline "a" may hold these types to ASCII.
        $this->asciiOptions ??= 1 === LibraryPcre::match('/\(\?[\^a-zA-Z]*a/', $this->source);

        return $this->asciiOptions ? null : 3;
    }

    /**
     * A class of more than a character, or of a pair of cases, is a map of
     * 32 bytes after its opcode, when all it holds fits in the map: no
     * types, properties or POSIX names, which may reach past it, nothing
     * past 255, and, under a caseless Unicode option, nothing past ASCII,
     * whose other cases may. There, the Kelvin sign and the long s, other
     * cases of "k" and "s", join the class after the map. One that holds
     * every character compiles to less.
     */
    private function classLength(CharClassNode $node, bool $caseless): ?int
    {
        $members = $this->classMembers($node);
        if (null === $members || (!$node->isNegated && [] === $members[1] && 2 === \count($members[0]) && null === $this->isCasePair(...$members[0]))) {
            return null;
        }

        $ranges = $members[1];
        foreach ($members[0] as $codePoint) {
            $ranges[] = [$codePoint, $codePoint];
        }

        $covered = [];
        foreach ($ranges as [$low, $high]) {
            if ($high > 0xFF || ($caseless && ($this->utf || $this->ucp) && ($this->caseRulesVary() || $high > 0x7F))) {
                return null;
            }

            for ($codePoint = $low; $codePoint <= $high; $codePoint++) {
                $covered[$codePoint] = true;
                if ($caseless && Ascii::isAlpha(\chr($codePoint)) && $codePoint < 0x80) {
                    $covered[\ord(Ascii::lower(\chr($codePoint)))] = true;
                    $covered[\ord(Ascii::upper(\chr($codePoint)))] = true;
                }
            }
        }

        if (\count($covered) >= 256) {
            return null;
        }

        // The opcode, its link, its flags, the map, each character past it
        // (its tag and its UTF-8 bytes) and the end of the list.
        $past = $caseless && $this->utf ? (isset($covered[0x6B]) ? 4 : 0) + (isset($covered[0x73]) ? 3 : 0) : 0;

        return 0 === $past ? 33 : 33 + 3 + $past + 1;
    }

    /**
     * The single characters and the ranges of a class, each as PCRE reads
     * them, the types and POSIX names the map holds as ranges too, or null
     * when it holds anything else.
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     */
    private function classMembers(CharClassNode $node): ?array
    {
        $characters = [];
        $ranges = [];
        foreach ($node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression] as $member) {
            if ($member instanceof RangeNode) {
                $low = $this->codePointOf($member->start);
                $high = $this->codePointOf($member->end);
                if (null === $low || null === $high) {
                    return null;
                }

                // "[a-a]" is read as the character alone.
                if ($low === $high) {
                    $characters[] = $low;
                } else {
                    $ranges[] = [$low, $high];
                }

                continue;
            }

            if ($member instanceof LiteralNode) {
                foreach ($this->utf ? mb_str_split($member->value, 1, 'UTF-8') : Bytes::split($member->value) as $character) {
                    if ('' !== $character) {
                        $characters[] = $this->utf ? (int) mb_ord($character, 'UTF-8') : \ord($character);
                    }
                }

                continue;
            }

            if ($member instanceof CharTypeNode || $member instanceof PosixClassNode) {
                $set = $this->mapSet($member instanceof CharTypeNode ? $member->value : $member->class);
                if (null === $set) {
                    return null;
                }

                foreach ($set as $codePoint) {
                    $ranges[] = [$codePoint, $codePoint];
                }

                continue;
            }

            $codePoint = $this->codePointOf($member);
            if (null === $codePoint) {
                return null;
            }

            $characters[] = $codePoint;
        }

        return [$characters, $ranges];
    }

    /**
     * The characters below 256 a type or a POSIX name of a class matches,
     * when the map of the class holds all it matches: not under UCP, where
     * they follow Unicode, nor "\h" and "\v" in UTF mode, which match
     * characters past 255.
     *
     * @return list<int>|null
     */
    private function mapSet(string $name): ?array
    {
        $negated = str_starts_with($name, '^') || (1 === \strlen($name) && Ascii::lower($name) !== $name);
        $ranges = self::MAP_RANGES[Ascii::lower(ltrim($name, '^'))] ?? null;
        if (null === $ranges || $this->ucp || ($this->utf && \in_array(Ascii::lower($name), ['h', 'v'], true))) {
            return null;
        }

        $set = [];
        for ($codePoint = 0; $codePoint < 256; $codePoint++) {
            $in = false;
            foreach ($ranges as [$low, $high]) {
                $in = $in || ($low <= $codePoint && $codePoint <= $high);
            }

            if ($in !== $negated) {
                $set[] = $codePoint;
            }
        }

        return $set;
    }

    private function codePointOf(NodeInterface $node): ?int
    {
        return match (true) {
            $node instanceof LiteralNode && $this->utf && 1 === mb_strlen($node->value, 'UTF-8') => (int) mb_ord($node->value, 'UTF-8'),
            $node instanceof LiteralNode && !$this->utf && 1 === \strlen($node->value) => \ord($node->value),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $node->codePoint,
            default => null,
        };
    }

    /**
     * Whether the source may set rules that change the cases of "i", "k" or
     * "s": the Turkish ones, or those that keep ASCII apart.
     */
    private function caseRulesVary(): bool
    {
        return $this->caseRulesVary ??= str_contains($this->flags, 'r') || str_contains($this->source, 'TURKISH_CASING') || str_contains($this->source, 'CASELESS_RESTRICT')
            || 1 === LibraryPcre::match('/\(\?[\^a-zA-Z]*r/', $this->source);
    }

    /**
     * Whether a group of flags sets options for the rest of its group, as
     * "(?i)" does, rather than holding a body, as "(?i:)" does.
     */
    private function setsOptionsOnly(GroupNode $node): bool
    {
        return self::isEmpty($node->child) && ':' !== ($this->source[$node->getEndPosition() - 2] ?? '');
    }

    /**
     * @param list<NodeInterface> $branches
     */
    private function mayHoldBranches(NodeInterface $node, array $branches): bool
    {
        return \count($branches) > 1 || ($node instanceof ConditionalNode && self::isEmpty($node->no));
    }

    private static function caselessAfter(string $flags, bool $caseless): bool
    {
        if (str_starts_with($flags, '^')) {
            $caseless = false;
            $flags = substr($flags, 1);
        }

        [$on, $off] = explode('-', $flags, 2) + [1 => ''];

        return str_contains($off, 'i') ? false : ($caseless || str_contains($on, 'i'));
    }

    /**
     * Whether a class of two characters is a letter and its other case, one
     * PCRE compiles as a caseless letter: an ASCII letter but "k" and "s",
     * which have a third case; null when that is not known, past ASCII
     * under Unicode rules, or where rules that change the cases of "i",
     * "k" and "s" may be set.
     */
    private function isCasePair(int $first, int $second): ?bool
    {
        if ($first > 0x7F) {
            return $this->utf || $this->ucp ? null : false;
        }

        if (!Ascii::isAlpha(\chr($first)) || $first === $second || Ascii::lower(\chr($first)) !== Ascii::lower(\chr($second))) {
            return false;
        }

        if (\in_array(Ascii::lower(\chr($first)), ['i', 'k', 's'], true) && $this->caseRulesVary()) {
            return null;
        }

        return !\in_array(Ascii::lower(\chr($first)), ['k', 's'], true);
    }

    private static function isAnyProperty(UnicodePropNode $node): bool
    {
        return 'any' === Ascii::lower((string) LibraryPcre::replace('/[\s_\-^{}]/', '', $node->prop));
    }

    /**
     * Whether a reference names its group by number: "\1" to "\9" and the
     * "\g" forms are always references, "\12" may be a character.
     */
    private static function isGroupNumber(BackrefNode $node): bool
    {
        return 1 === LibraryPcre::match('/^\\\\(?:[1-9]|g\{?[+-]?\d++\}?)$/', $node->ref);
    }

    private function verbLength(string $verb): ?int
    {
        // A setting of the opening run compiles to nothing.
        if (isset(StartOptions::NAMES[$verb])) {
            return 0;
        }

        if (\in_array($verb, ['FAIL', 'F', 'COMMIT', 'PRUNE', 'SKIP', 'THEN'], true)) {
            return 1;
        }

        // The opcode, after one that closes each capture open around it.
        if ('ACCEPT' === $verb) {
            return 1 + 3 * $this->openCaptures;
        }

        // The opcode, the length of the name, the name and its terminator.
        if (1 === LibraryPcre::match('/^(?:MARK|PRUNE|SKIP|THEN|COMMIT)?:(.+)$/s', $verb, $name)) {
            return 3 + \strlen($name[1]);
        }

        return null;
    }

    /**
     * Whether a node compiles to nothing: empty, a comment, options for the
     * rest of the group, or a sequence of those.
     */
    private function isBlank(NodeInterface $node): bool
    {
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!$this->isBlank($child)) {
                    return false;
                }
            }

            return true;
        }

        return self::isEmpty($node) || $node instanceof CommentNode
            || ($node instanceof GroupNode && GroupType::InlineFlags === $node->type && $this->setsOptionsOnly($node));
    }

    private static function isEmpty(NodeInterface $node): bool
    {
        return $node instanceof LiteralNode && '' === $node->value;
    }

    /**
     * @return list<NodeInterface>
     */
    private static function branchesOf(NodeInterface $node): array
    {
        return $node instanceof AlternationNode ? array_values($node->alternatives) : [$node];
    }

    /**
     * @return list<NodeInterface>
     */
    private static function itemsOf(NodeInterface $branch): array
    {
        if (!$branch instanceof SequenceNode) {
            return [$branch];
        }

        $items = [];
        foreach ($branch->children as $child) {
            array_push($items, ...self::itemsOf($child));
        }

        return $items;
    }

    private static function sum(?int $ceiling, ?int $more): ?int
    {
        return null === $ceiling || null === $more ? null : self::cap($ceiling + $more);
    }

    private static function cap(int $length): int
    {
        return min($length, self::CAP);
    }
}
