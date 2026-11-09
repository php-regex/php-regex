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

use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Analysis\LookbehindLength;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;
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
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * Measures a pattern as the first pass of the PCRE2 targeted compiles it:
 * the length that pass counts is the one PCRE2 checks against the limit,
 * though the code it then compiles may be shorter. Each item is added to
 * the length of its branch, which may not pass the limit; each branch but
 * the last of a group is added to the branches before it, which may not
 * pass it either: from 10.48 both report the error at offset 0. The last
 * branch is added where the group ends, by a check that sets no offset, so
 * at the end of the pattern. Every sum checked is part of the length of the
 * whole pattern, so the pattern is too large exactly when that length
 * passes the limit, on every release.
 *
 * An item is counted at the length PCRE2 compiles it to where this class
 * knows that length, and at the floor it is given otherwise, the one
 * CompiledSize measures, with the most it may compile to where that is
 * known: the ceiling. CompiledSize reads its limit and the sizes of its
 * brackets from the constants here.
 *
 * @internal
 */
final class CompiledLength
{
    /**
     * The largest compiled pattern, in code units, with two-byte links.
     */
    public const LIMIT = 65536;

    /**
     * The opening and closing brackets of a group, with their links.
     */
    public const BRACKETS = 6;

    /**
     * A "|": the alternation marker and its link.
     */
    public const ALTERNATION = 3;

    /**
     * What the "may skip" marker and the brackets nesting the next copy add
     * to an optional copy of a counted group.
     */
    public const OPTIONAL_COPY = 7;

    /**
     * A length no pattern reaches, where the counts stop growing.
     */
    private const CAP = 1 << 40;

    /**
     * The characters Turkish case rules give a caseless set of their own:
     * "i" with the dotted capital I, "I" with the dotless i.
     */
    private const TURKISH_CASED = [0x49 => true, 0x69 => true, 0x130 => true, 0x131 => true];

    /**
     * The characters of the caseless sets that start in ASCII, which rules
     * kept to ASCII leave out: "k", "s", the Kelvin sign and the long s.
     */
    private const ASCII_SET_MEMBERS = [0x4B => true, 0x53 => true, 0x6B => true, 0x73 => true, 0x17F => true, 0x212A => true];

    /**
     * Empty script runs, which the parser reads as verbs, and the groups
     * they compile to.
     */
    private const EMPTY_SCRIPT_RUNS = ['sr:' => 6, 'script_run:' => 6, 'asr:' => 12, 'atomic_script_run:' => 12];

    /**
     * Whether the measure passed the limit where a class was compiled, up to
     * 10.47: see measureItems().
     */
    public bool $passedAtClassLists = false;

    /**
     * Where the first pass stops once it finds the pattern too large: past
     * the item, the branch or the condition whose length takes it past the
     * limit, so that nothing written from there on is read; out of the
     * pattern where only the check made once it is read finds it, or where
     * the limit is not passed.
     */
    public int $stoppedAt = \PHP_INT_MAX;

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
     * The capturing groups open around the item measured, each with whether
     * a back reference inside it names it: up to 10.34 such a group is
     * wrapped in an atomic group.
     *
     * @var array<int, bool>
     */
    private array $openGroups = [];

    /**
     * The bytes of the lists the classes measured keep beside the code, from
     * 10.45: counted once for each class written, against the limit too.
     */
    private int $charLists = 0;

    /**
     * Whether the source may set rules that change the cases of "i", "k"
     * or "s", once read.
     */
    private ?bool $caseRulesVary = null;

    /**
     * Whether the case rules kept to ASCII are in force where the item
     * measured is written: "r", "(*CASELESS_RESTRICT)", "(?r)" to the end
     * of its group.
     */
    private bool $restricted = false;

    /**
     * Whether Turkish case rules are set, "(*TURKISH_CASING)", once read.
     */
    private ?bool $turkish = null;

    /**
     * Whether the source may hold an inline "a", which keeps "\d", "\s" and
     * "\w" to ASCII under UCP, once read.
     */
    private ?bool $asciiOptions = null;

    /**
     * The pattern measured, whose groups a lookbehind may call.
     */
    private ?NodeInterface $pattern = null;

    /**
     * The measure of the lengths a lookbehind matches, once one is met.
     */
    private ?LookbehindLength $lookbehinds = null;

    /**
     * Up to PCRE2 10.34, where the measure of lookbehinds stops and the
     * lookbehinds it skips, once read: see unmeasuredLookbehinds().
     *
     * @var array{0: int, 1: array<int, GroupNode>, 2: bool}|null
     */
    private ?array $unmeasured = null;

    /**
     * Whether a class may read differently from its text, under "xx",
     * once read.
     */
    private ?bool $spacesSkipped = null;

    /**
     * The measure of classes, once one is met.
     */
    private ?ClassLength $classes = null;

    /**
     * The length of each class measured, the bytes of its lists and, up to
     * 10.47, the most it counts once its lists are checked, by its text and
     * whether it is caseless.
     *
     * @var array<string, array{0: ?int, 1: int, 2: int}>
     */
    private array $classLengths = [];

    /**
     * The class each extended class of a single item stands for, by the
     * identity of the extended class, kept with it so that no other node
     * takes that identity, nor the one of the class, which may key its
     * length.
     *
     * @var array<int, array{0: ExtendedCharClassNode, 1: CharClassNode}>
     */
    private array $singleItemClasses = [];

    /**
     * Up to 10.47, the most the class last measured counts once its lists
     * are checked, those lists included.
     */
    private int $listsPeak = 0;

    /**
     * The members of each class read, by its text.
     *
     * @var array<string, array{0: list<int>, 1: list<array{0: int, 1: int}>, 2: list<array{0: int, 1: int}>}|null>
     */
    private array $members = [];

    /**
     * The groups of the pattern measured, once a reference or a lookbehind
     * needs them.
     */
    private ?GroupIndex $groups = null;

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
     * @param PcreTarget                   $target the PCRE2 release whose
     *                                             first pass is measured
     * @param CharacterTables|null         $tables the tables PHP hands PCRE2
     *                                             under the locale judged; the
     *                                             lengths they decide are not
     *                                             known without them
     */
    public function __construct(
        private readonly \Closure $floor,
        private readonly string $source,
        private readonly bool $utf,
        private readonly bool $ucp,
        private readonly string $flags,
        private readonly PcreTarget $target,
        private readonly ?CharacterTables $tables = null,
    ) {}

    /**
     * Whether PCRE2 10.48 and later find the pattern too large inside a
     * branch, and report it at offset 0: false when they find it where a
     * group ends, or when that cannot be told for sure.
     */
    public function passedInsideABranch(NodeInterface $pattern): bool
    {
        [$found] = $this->measure($pattern);

        return false === $found && !$this->branchesMayEndPastTheLimit;
    }

    /**
     * Whether the first pass finds the pattern too large for sure: every
     * item counted at its length or, where that is not known, at its floor.
     */
    public function passesTheLimit(NodeInterface $pattern): bool
    {
        return null !== $this->measure($pattern)[0];
    }

    /**
     * The length the first pass counts for the pattern, its end marker
     * included, or null when it is not known for sure or passes the limit.
     */
    public function of(NodeInterface $pattern): ?int
    {
        [$found, $length, $ceiling] = $this->measure($pattern);

        return null === $found && $length === $ceiling ? $length + 1 : null;
    }

    /**
     * @return array{0: bool|null, 1: int, 2: ?int} as measureBranches() does
     *
     * @phpstan-impure it records a group of branches that may end past the limit
     */
    private function measure(NodeInterface $pattern): array
    {
        $this->branchesMayEndPastTheLimit = false;
        $this->openCaptures = 0;
        $this->openGroups = [];
        $this->charLists = 0;
        $this->passedAtClassLists = false;
        $this->stoppedAt = \PHP_INT_MAX;
        $this->restricted = $this->caseRulesVary() && (str_contains($this->flags, 'r') || \in_array('CASELESS_RESTRICT', StartOptions::items($this->source), true));
        if ($this->pattern !== $pattern) {
            $this->pattern = $pattern;
            $this->lookbehinds = null;
            $this->groups = null;
            $this->unmeasured = null;
        }

        // The end marker comes first, then the brackets around the pattern.
        [$found, $length, $ceiling] = $this->measureBranches(self::branchesOf($pattern), 1, [self::BRACKETS, self::BRACKETS], str_contains($this->flags, 'i'));
        if (null !== $found) {
            return [$found, $length, $ceiling];
        }

        // The lists the classes keep beside the code count against the
        // limit once the pattern is measured, by a check that reports offset
        // 0 from 10.48.
        $length += $this->charLists;

        return [1 + $length > self::LIMIT ? false : null, $length, self::sum($ceiling, $this->charLists)];
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
     *                                              the ceiling of the group,
     *                                              the length of no use once
     *                                              the limit is passed
     *
     * @phpstan-impure it records a group of branches that may end past the limit
     */
    private function measureBranches(array $branches, int $before, array $frame, bool $caseless): array
    {
        [$length, $ceiling] = $frame;
        $done = 0;
        $last = \count($branches) - 1;
        foreach ($branches as $index => $branch) {
            [$found, $length, $branchCeiling, $caseless] = $this->measureItems($branch, $length, $caseless, $done);
            $ceiling = self::sum($ceiling, $branchCeiling);
            if (null !== $found) {
                return [$found, $done + $length, null];
            }

            if ($index < $last) {
                $length += self::ALTERNATION;
                $ceiling = self::sum($ceiling, self::ALTERNATION);
                // Up to 10.47 the next branch is checked with the branches
                // before it, the end marker left out.
                if (($this->target->supports(PcreFeature::TooLargeAtGroupEndReportedAtPatternEnd) ? $before : 0) + $done + $length > self::LIMIT) {
                    $this->stoppedAt = $branch->getEndPosition();

                    return [false, $length, null];
                }

                $done += $length;
                $length = 0;
            } elseif ($before + $done + $length > self::LIMIT) {
                $this->stoppedAt = $branch->getEndPosition();

                return [true, $length, null];
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
    private function measureItems(NodeInterface $branch, int $length, bool $caseless, int $before = 0): array
    {
        // Up to 10.47 each item is checked with the branches before it in
        // its group; from 10.48 with its own branch.
        $checked = $this->target->supports(PcreFeature::TooLargeAtGroupEndReportedAtPatternEnd) ? 0 : $before;
        $ceiling = 0;
        foreach (self::itemsOf($branch) as $item) {
            // Most items are one ASCII letter, or one byte without UTF:
            // measured at once.
            $units = $item instanceof LiteralNode && 1 === \strlen($item->value)
                ? (!$caseless || !$this->unicodeCases() || (!isset(PcreTables::caselessSets()[\ord($item->value)]) && !$this->caseRulesVary()) ? 2 : $this->characterLength(\ord($item->value), true))
                : null;
            if (null !== $units) {
                $length += $units;
                $ceiling = null === $ceiling ? null : $ceiling + $units;
                if ($checked + $length > self::LIMIT) {
                    $this->stoppedAt = $item->getEndPosition();

                    return [false, $length, null, $caseless];
                }

                continue;
            }

            if ($item instanceof GroupNode && GroupType::InlineFlags === $item->type && $this->setsOptionsOnly($item)) {
                $caseless = self::caselessAfter((string) $item->flags, $caseless);
                $this->restricted = self::optionAfter((string) $item->flags, 'r', $this->restricted);

                continue;
            }

            $lists = $this->charLists;
            [$found, $itemLength, $itemCeiling] = $this->measureItem($item, $caseless);
            if (null !== $found) {
                return [$found, $length, null, $caseless];
            }

            // Up to 10.47 a class whose lists, with its header, its properties
            // and what the group holds before it, pass the limit is refused as
            // it is compiled, by a check that sets no offset. A class inside
            // a group is checked with what that group holds.
            $lists = $this->charLists - $lists;
            $class = $item instanceof QuantifierNode ? $item->node : $item;
            if ($lists > 0 && ($class instanceof CharClassNode || $class instanceof ExtendedCharClassNode) && !$this->target->supports(PcreFeature::TooLargeAtGroupEndReportedAtPatternEnd) && $before + $length + $this->listsPeak > self::LIMIT) {
                $this->passedAtClassLists = true;
                $this->stoppedAt = $item->getEndPosition();

                return [true, $length, null, $caseless];
            }

            $length += $itemLength;
            $ceiling = self::sum($ceiling, $itemCeiling);
            if ($checked + $length > self::LIMIT) {
                $this->stoppedAt = $item->getEndPosition();

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
        // "(?!)" not counted is a failure, one unit.
        if ($item instanceof GroupNode && GroupType::LookaheadNegative === $item->type && $this->holdsOnlyComments($item->child)) {
            return [null, 1, 1];
        }

        $repeated = $item instanceof QuantifierNode ? $item->node : $item;
        $group = $this->groupOf($repeated, $caseless);
        if (null === $group) {
            $length = $this->leafLength($item, $caseless);

            return null === $length ? [null, ($this->floor)($item), $this->leafCeiling($item)] : [null, $length, $length];
        }

        [$branches, $frame, $bodyCaseless, $condition] = $group;
        if (null !== $condition) {
            // An assertion as the condition is compiled first, as a group,
            // after the callouts before it.
            [$found, $conditionLength, $conditionCeiling] = $condition instanceof SequenceNode ? $this->measureItems($condition, 0, $caseless) : $this->measureItem($condition, $caseless);
            if (null !== $found) {
                return [$found, $conditionLength, null];
            }

            $frame = [$frame[0] + $conditionLength, self::sum($frame[1], $conditionCeiling)];
            if ($frame[0] > self::LIMIT) {
                $this->stoppedAt = $condition->getEndPosition();

                return [false, $conditionLength, null];
            }
        }

        $open = $this->openCaptures;
        $capture = $repeated instanceof GroupNode && \in_array($repeated->type, [GroupType::Capturing, GroupType::Named], true);
        $this->openCaptures = match (true) {
            $capture => $open + 1,
            $repeated instanceof GroupNode && \in_array($repeated->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true) => 0,
            default => $open,
        };
        if ($capture) {
            $this->openGroups[spl_object_id($repeated)] = false;
        }

        // The options a group sets, or its branches set, end with it.
        $restricted = $this->restricted;
        if ($repeated instanceof GroupNode && GroupType::InlineFlags === $repeated->type) {
            $this->restricted = self::optionAfter((string) $repeated->flags, 'r', $restricted);
        }

        [$found, $length, $ceiling] = $this->measureBranches($branches, 0, $frame, $bodyCaseless);
        $this->restricted = $restricted;
        $this->openCaptures = $open;
        $referenced = $capture && $this->openGroups[spl_object_id($repeated)];
        if ($capture) {
            unset($this->openGroups[spl_object_id($repeated)]);
        }

        if (null !== $found) {
            return [$found, $length, null];
        }

        // Up to 10.34 a capture holding a back reference to itself is
        // wrapped in an atomic group.
        if ($referenced && !$this->target->supports(PcreFeature::GroupHoldingItsOwnReferenceBacktracks)) {
            $length += self::BRACKETS;
            $ceiling = self::sum($ceiling, self::BRACKETS);
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
                // "(?=)" compiles to a group, and so does "(?!)" counted.
                GroupType::LookaheadPositive, GroupType::LookaheadNegative => [self::branchesOf($node->child), [self::BRACKETS, self::BRACKETS], $caseless, null],
                // Each branch of a lookbehind of some length starts with
                // the length to move back, in one number or two.
                GroupType::LookbehindPositive, GroupType::LookbehindNegative => [$branches = self::branchesOf($node->child), $this->lookbehindFrame($node, $branches), $caseless, null],
                // A substring scan lists the captures it reads after its bracket.
                GroupType::ScanSubstring => [self::branchesOf($node->child), $this->scanSubstringFrame($node), $caseless, null],
            };
        }

        if ($node instanceof ConditionalNode) {
            // An empty second branch may be written, "(?(1)a|)", or not.
            $written = self::isEmpty($node->no) ? $this->secondBranchWritten($node) : true;
            // The first pass reads each branch past the second as a branch:
            // only the second pass refuses them.
            $branches = true === $written ? [$node->yes, ...self::branchesOf($node->no)] : [$node->yes];
            $ceiling = null === $written ? self::BRACKETS + self::ALTERNATION : self::BRACKETS;
            $condition = $node->condition;
            if ($condition instanceof GroupNode || self::isCalloutsThenAssertion($condition)) {
                return [$branches, [self::BRACKETS, $ceiling], $caseless, $condition];
            }

            // A group number, or the recursion it tests, is a number; a name
            // several groups bear an index and a count; a version is true
            // or false, one unit.
            $length = match (true) {
                $condition instanceof VersionConditionNode => 1,
                ($condition instanceof BackrefNode && 1 === LibraryPcre::match('/^[+-]?\d++$/', $condition->ref))
                    || ($condition instanceof SubroutineNode && 1 === LibraryPcre::match('/^R\d*+$/', $condition->reference)) => 3,
                default => $this->namedConditionLength($condition),
            };

            return [$branches, null === $length ? [self::BRACKETS + ($this->floor)($condition), $ceiling + 5] : [self::BRACKETS + $length, $ceiling + $length], $caseless, null];
        }

        if ($node instanceof DefineNode) {
            // The brackets and the condition that is never true.
            return [self::branchesOf($node->content), [self::BRACKETS + 1, self::BRACKETS + 1], $caseless, null];
        }

        if ($node instanceof ScriptRunNode && null !== $node->content) {
            // An atomic script run is a script run inside an atomic group.
            return [self::branchesOf($node->content), $node->isAtomic ? [2 * self::BRACKETS, 2 * self::BRACKETS] : [self::BRACKETS, self::BRACKETS], $caseless, null];
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
        $atomicOnce = $this->target->supports(PcreFeature::PossessiveRepeatOnceIsAtomic);
        if ($repeated instanceof GroupNode) {
            // A substring scan is an assertion too.
            $assertion = \in_array($repeated->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true);

            // Up to 10.34 an assertion counted at least once is compiled
            // once, and one counted from zero as optional.
            if ($assertion && !$this->target->supports(PcreFeature::RepeatedAssertionKeepsItsCount)) {
                return $min > 0 ? static fn (int $copy): int => $copy : static fn (int $copy): int => self::repeatGroup($copy, 0, 0 === $max ? 0 : 1, $possessive, false, $atomicOnce);
            }

            return static fn (int $copy): int => self::repeatGroup($copy, $min, $assertion && null === $max ? $min + 1 : $max, $possessive, false, $atomicOnce);
        }

        // A script run takes no possessive brackets of its own: a possessive
        // count wraps it as it does a conditional.
        if ($repeated instanceof ScriptRunNode) {
            return static fn (int $copy): int => self::repeatGroup($copy, $min, $max, $possessive, true, $atomicOnce);
        }

        // The first pass counts a DEFINE group, or a conditional on a false
        // version, repeated as any conditional, though the code holds it
        // once.
        if ($repeated instanceof DefineNode || ($repeated instanceof ConditionalNode && ($repeated->condition instanceof GroupNode || $repeated->condition instanceof BackrefNode || $repeated->condition instanceof SubroutineNode || $repeated->condition instanceof VersionConditionNode || self::isCalloutsThenAssertion($repeated->condition)))) {
            return static fn (int $copy): int => self::repeatGroup($copy, $min, $max, $possessive, true, $atomicOnce);
        }

        return null;
    }

    /**
     * A counted group: every mandatory copy as it is, every optional one
     * behind a "may skip" marker inside brackets that nest the next (the
     * innermost not), and an open maximum as a last copy that loops. A
     * possessive count wraps it all in an atomic group, unless it may be
     * written with the possessive brackets of a group repeated at most once
     * first; a conditional has none. Up to 10.33 a possessive count of one
     * is ignored ($atomicOnce false).
     */
    private static function repeatGroup(int $copy, int $min, ?int $max, bool $possessive, bool $conditional, bool $atomicOnce): int
    {
        $wrap = $possessive ? self::BRACKETS : 0;
        if (1 === $min && 1 === $max) {
            return $copy + ($atomicOnce ? $wrap : 0);
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

        $node = $this->classOfSingleItem($node);
        $character = $this->characterOf($node);
        if (null !== $character) {
            return $this->characterLength($character, $caseless);
        }

        return match (true) {
            $node instanceof LiteralNode => $this->literalLength($node->value, $caseless),
            $node instanceof DotNode, $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof KeepNode => 1,
            $node instanceof CommentNode => 0,
            $node instanceof CharTypeNode => $this->typeLength($node),
            $node instanceof UnicodePropNode => $this->propertyLength($node),
            $node instanceof CharClassNode => $this->classLength($node, $caseless),
            $node instanceof ExtendedCharClassNode => $this->extendedClassLength($node, $caseless)[0],
            $node instanceof BackrefNode => $this->referenceLength($node, $caseless),
            // A call that returns groups lists them, a number each.
            $node instanceof SubroutineNode => 3 + 3 * \count($node->returnedGroups),
            // A string callout holds the string as written, delimiters and
            // a doubled delimiter included, after its opcode and four links.
            $node instanceof CalloutNode => \is_string($node->identifier) && $node->isStringIdentifier ? 9 + $node->getEndPosition() - $node->getStartPosition() - 4 : 6,
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
        $item = $this->classOfSingleItem($node->node);
        if ($item instanceof SubroutineNode) {
            return self::repeatCall(3 + 3 * \count($item->returnedGroups), $min, $max, QuantifierType::Possessive === $node->type, $this->target->supports(PcreFeature::PossessiveRepeatOnceIsAtomic));
        }

        // A counted verb, "(*ACCEPT)?", is wrapped in a group first.
        if ($item instanceof PcreVerbNode) {
            $verb = $this->verbLength($item->verb);
            // An empty script run is a group already, wrapped as a
            // conditional is when possessive.
            $scriptRun = isset(self::EMPTY_SCRIPT_RUNS[$item->verb]);

            return null === $verb ? null : self::repeatGroup(($scriptRun ? 0 : self::BRACKETS) + $verb, $min, $max, QuantifierType::Possessive === $node->type, $scriptRun, $this->target->supports(PcreFeature::PossessiveRepeatOnceIsAtomic));
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

        $count = (null === $max && $min < 2) || (0 === $min && 1 === $max) ? 1 : 5;
        if ($item instanceof DotNode || $item instanceof CharTypeNode || $item instanceof UnicodePropNode) {
            $length = $this->leafLength($item, $caseless);

            // A type repeats with its opcode, and a property its two values;
            // "\P{Any}" is a class from 10.45, which takes its count after it.
            if (null !== $length && $length > 3) {
                return $length + $count;
            }

            return null === $length ? null : self::repeatSingle(0, $length, $min, $max);
        }

        // An extended class written as a match of any character repeats as
        // a type does, after what it counted.
        if ($item instanceof ExtendedCharClassNode) {
            [$length, $any] = $this->extendedClassLength($item, $caseless);
            if (null === $length || null === $any) {
                return null;
            }

            return $any ? $length - 1 + self::repeatSingle(0, 1, $min, $max) : $length + $count;
        }

        // A class or a reference takes its count after it: "*", "+" and "?"
        // in one unit, any other count in five. Nothing else repeats. Up to
        // 10.47 a possessive count wraps a reference in an atomic group.
        $length = $item instanceof CharClassNode || $item instanceof BackrefNode ? $this->leafLength($item, $caseless) : null;

        // A class of every character is a match of any, which repeats as a
        // type does.
        if ($item instanceof CharClassNode && 1 === $length) {
            return self::repeatSingle(0, 1, $min, $max);
        }

        $wrap = $item instanceof BackrefNode && QuantifierType::Possessive === $node->type && !$this->target->supports(PcreFeature::PossessiveReferenceRepeatedInPlace) ? self::BRACKETS : 0;

        return null === $length ? null : $length + $count + $wrap;
    }

    /**
     * The least and the most a quantifier repeats, null for no most: a
     * quantifier the parser built is always one PCRE reads.
     *
     * @return array{0: int, 1: ?int}
     */
    private static function boundsOf(QuantifierNode $node): array
    {
        $bounds = $node->bounds;

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
    private static function repeatCall(int $call, int $min, ?int $max, bool $possessive, bool $atomicOnce): int
    {
        if (1 === $min && 1 === $max && (!$possessive || !$atomicOnce)) {
            return $call;
        }

        if ($min > 0 && (1 !== $min || null !== $max)) {
            if ($min === $max) {
                return self::cap($call * $min + ($possessive ? self::BRACKETS : 0));
            }

            return self::cap($call * $min + self::repeatGroup($call + self::BRACKETS, 0, null === $max ? null : $max - $min, $possessive, false, $atomicOnce));
        }

        return self::repeatGroup($call + self::BRACKETS, $min, $max, $possessive, false, $atomicOnce);
    }

    /**
     * The code point a node stands for as one character, a class of one
     * character or of an ASCII letter and its other case included.
     */
    private function characterOf(NodeInterface $node): ?int
    {
        if ($node instanceof CharClassNode) {
            $members = $this->classMembers($node);
            if (null === $members || [] !== $members[1] || [] !== $members[2]) {
                return null;
            }

            // "[^a]" is a negated character, of the same length as "a".
            if (1 === \count($members[0])) {
                return $members[0][0];
            }

            // A letter and its other case is one caseless letter: read as
            // the negated code point, minus one.
            [$first, $second] = $members[0] + [1 => -1];

            return !$node->isNegated && 2 === \count($members[0]) && $this->target->supports(PcreFeature::CasePairClassIsACaselessCharacter) && true === $this->isCasePair($first, $second) ? -1 - $first : null;
        }

        return $this->codePointOf($node);
    }

    /**
     * The opcode and code units of a character, or null: under a caseless
     * Unicode option, a letter with more than one other case is a property
     * of three units, under the case rules in force (hasCaselessSet()).
     *
     * @return array{0: int, 1: int}|null the units of the character, 0 for a
     *                                    property, and the units of the item
     */
    private function characterRepeat(int $codePoint, bool $caseless): ?array
    {
        if ($codePoint < 0) {
            // A letter and its other case: one caseless letter.
            $units = $this->utf ? \strlen((string) mb_chr(-1 - $codePoint, 'UTF-8')) : 1;

            return [$units, 1 + $units];
        }

        if ($caseless && $this->unicodeCases() && $this->hasCaselessSet($codePoint)) {
            return [0, 3];
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

    private function literalLength(string $value, bool $caseless): int
    {
        $characters = $this->utf ? mb_str_split($value, 1, 'UTF-8') : Bytes::split($value);
        $length = 0;
        foreach ($characters as $character) {
            $units = $this->characterLength($this->utf ? (int) mb_ord($character, 'UTF-8') : \ord($character), $caseless);
            if (null === $units) {
                throw InternalFailureException::unexpectedState('A character of a literal has no compiled length.');
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

        return $this->asciiOptions() ? null : 3;
    }

    /**
     * Whether the source may hold an inline "a", which keeps types and
     * POSIX names to ASCII.
     */
    private function asciiOptions(): bool
    {
        return $this->asciiOptions ??= 1 === LibraryPcre::match('/\(\?[\^a-zA-Z]*a/', $this->source);
    }

    /**
     * A class of more than a character, or of a character and a letter that
     * is not its other case: see ClassLength. A pair of cases whose being one
     * is not known here has no length.
     */
    private function classLength(CharClassNode $node, bool $caseless): ?int
    {
        $members = $node->isNegated || !$this->target->supports(PcreFeature::CasePairClassIsACaselessCharacter) ? null : $this->classMembers($node);
        if (null !== $members && [] === $members[1] && [] === $members[2] && 2 === \count($members[0]) && null === $this->isCasePair(...$members[0])) {
            return null;
        }

        // A class written again measures the same.
        $key = ($caseless ? ($this->restricted ? 'r' : 'i') : '-').$this->classKey($node);
        if (!isset($this->classLengths[$key])) {
            $classes = $this->classes();
            $this->classLengths[$key] = [$classes->of($node, $caseless, $this->restricted), $classes->charListBytes, $classes->unitsBeforeLists + $classes->charListBytes];
        }

        [$length, $lists, $this->listsPeak] = $this->classLengths[$key];
        $this->charLists += $lists;

        return $length;
    }

    /**
     * An extended class that holds an operator, or a class in brackets or
     * parentheses: see ExtendedClassLength. Its length, and whether it is
     * written as a match of any character.
     *
     * @return array{0: ?int, 1: ?bool}
     */
    private function extendedClassLength(ExtendedCharClassNode $node, bool $caseless): array
    {
        $classes = new ExtendedClassLength($this->classes());
        $length = $classes->of($node, $caseless, $this->restricted);
        $this->charLists += $classes->charListBytes;
        $this->listsPeak = $classes->listsPeak;

        return [$length, $classes->matchesAnyCharacter];
    }

    /**
     * An extended class of a single item, neither in brackets nor in
     * parentheses, is the class of that item; any other node is itself.
     */
    private function classOfSingleItem(NodeInterface $node): NodeInterface
    {
        $item = $node instanceof ExtendedCharClassNode ? ExtendedClassLength::singleItem($node) : null;
        if (null === $item) {
            return $node;
        }

        $this->singleItemClasses[spl_object_id($node)] ??= [$node, new CharClassNode($item, false, $node->getStartPosition(), $node->getEndPosition())];

        return $this->singleItemClasses[spl_object_id($node)][1];
    }

    /**
     * The brackets of a substring scan and what it lists: a number for each
     * item, three units, or an index and a count for a name several groups
     * bear, five. From 10.47 a group listed before adds nothing, nor a name
     * whose groups all are; a name one group bears is its number.
     *
     * @return array{0: int, 1: ?int}
     */
    private function scanSubstringFrame(GroupNode $node): array
    {
        $once = $this->target->supports(PcreFeature::ScanSubstringListsEachGroupOnce);
        $next = (int) $this->groupIndex()->nextNumberAt($node);
        $listed = [];
        $units = 0;
        foreach ($node->scannedGroups as $group) {
            // "+1" is the group opened next, "-1" the one opened last.
            $number = (int) $group;
            $numbers = match ($group[0]) {
                '<', "'" => $this->groupIndex()->numbersNamed(substr($group, 1, -1)),
                '+' => [$next - 1 + $number],
                '-' => [$next + $number],
                default => [$number],
            };

            if ([] === $numbers) {
                return [self::BRACKETS, null]; // a name no group bears is refused before any measure
            }

            $new = array_diff_key(array_flip($numbers), $listed);
            $listed += $new;
            if (!$once || [] !== $new) {
                $units += \count($numbers) > 1 ? 5 : 3;
            }
        }

        return [self::BRACKETS + $units, self::BRACKETS + $units];
    }

    /**
     * The single characters and the ranges of a class, each as PCRE reads
     * them, and the ranges of the types and POSIX names the map holds, or
     * null when it holds anything else.
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>, 2: list<array{0: int, 1: int}>}|null
     */
    private function classMembers(CharClassNode $node): ?array
    {
        // A class written again holds the same.
        $key = $this->classKey($node);
        if (\array_key_exists($key, $this->members)) {
            return $this->members[$key];
        }

        return $this->members[$key] = $this->readClassMembers($node);
    }

    /**
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>, 2: list<array{0: int, 1: int}>}|null
     */
    private function readClassMembers(CharClassNode $node): ?array
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $characters = [];
        $ranges = [];
        $types = [];
        foreach ($members as $member) {
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

                array_push($types, ...$set);

                continue;
            }

            $codePoint = $this->codePointOf($member);
            if (null === $codePoint) {
                return null;
            }

            $characters[] = $codePoint;
        }

        return [$characters, $ranges, $types];
    }

    /**
     * The characters below 256 a type or a POSIX name of a class matches,
     * when the map of the class holds all it matches: not under UCP, where
     * they follow Unicode, nor "\h" and "\v" in UTF mode, which match
     * characters past 255. Its ranges, a negated one's included.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    private function mapSet(string $name): ?array
    {
        $negated = str_starts_with($name, '^') || (1 === \strlen($name) && Ascii::lower($name) !== $name);
        $ranges = PcreTables::MAP_RANGES[Ascii::lower(ltrim($name, '^'))] ?? null;
        if (null === $ranges || $this->ucp || ($this->utf && \in_array(Ascii::lower($name), ['h', 'v'], true))) {
            return null;
        }

        if (!$negated) {
            return $ranges;
        }

        $set = [];
        $next = 0;
        foreach ($ranges as [$low, $high]) {
            if ($low > $next) {
                $set[] = [$next, $low - 1];
            }

            $next = $high + 1;
        }

        return [...$set, [$next, 0xFF]];
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
     * Whether Turkish case rules are set: from 10.45, as an older release
     * refuses the setting.
     */
    private function turkishCasing(): bool
    {
        return $this->turkish ??= \in_array('TURKISH_CASING', StartOptions::items($this->source), true);
    }

    /**
     * Whether a caseless character compiles to a property of its caseless
     * set: one of more than one other case, but for those of a set that
     * starts in ASCII under rules kept to ASCII ("k", "s", the Kelvin sign
     * and the long s); and under Turkish rules, but where rules kept to
     * ASCII are in force, "i", "I", the dotted capital I and the dotless i.
     */
    private function hasCaselessSet(int $codePoint): bool
    {
        if (isset(self::TURKISH_CASED[$codePoint])) {
            return $this->turkishCasing() && !$this->restricted;
        }

        return isset(PcreTables::caselessSets()[$codePoint]) && !($this->restricted && isset(self::ASCII_SET_MEMBERS[$codePoint]));
    }

    private function classes(): ClassLength
    {
        return $this->classes ??= new ClassLength($this->utf, $this->ucp, $this->turkishCasing(), $this->asciiOptions(), $this->target, $this->tables);
    }

    /**
     * Whether a group of flags sets options for the rest of its group, as
     * "(?i)" does, rather than holding a body, as "(?i:)" does.
     */
    private function setsOptionsOnly(GroupNode $node): bool
    {
        // The flags end at ")" or ":"; under "x" a body of spaces reads as
        // empty too.
        return self::isEmpty($node->child) && InlineFlags::setOptionsOnly($this->source, $node->startPosition);
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
        return self::optionAfter($flags, 'i', $caseless);
    }

    /**
     * Whether an option is on after a group of flags: "^" turns "i" and
     * "r" off, as others it does not set apart.
     */
    private static function optionAfter(string $flags, string $option, bool $on): bool
    {
        if (str_starts_with($flags, '^')) {
            $on = false;
        }

        [$set, $unset] = explode('-', $flags) + [1 => ''];

        return !str_contains($unset, $option) && ($on || str_contains($set, $option));
    }

    /**
     * Whether a class of two characters is a letter and its other case, one
     * PCRE compiles as a caseless letter: an ASCII letter but "k" and "s",
     * which have a third case, unless rules kept to ASCII are in force; past
     * ASCII under a Unicode option, the other case the Unicode tables of the
     * release give; an ASCII character, and a byte without a Unicode
     * option, the other case the tables of the locale give. Null when that
     * is not known, without those tables: the cases past ASCII follow the
     * locale PHP builds its tables from; for "i", whose other case Turkish
     * tables make a byte past ASCII. A byte past ASCII is no case of an
     * ASCII letter, but in Turkish tables the bytes of "i"; one with more
     * than one other case is no pair.
     */
    private function isCasePair(int $first, int $second): ?bool
    {
        // Turkish rules keep the cases of "i" and "I" from such a pair, but
        // where rules kept to ASCII are in force.
        if ($this->turkishCasing() && !$this->restricted && isset(self::TURKISH_CASED[$first])) {
            return false;
        }

        // A character with more than one other case is never read as a
        // pair, a byte without UTF included, but for an ASCII letter under
        // rules kept to ASCII, whose other case is then its ASCII one.
        if (isset(PcreTables::caselessSets()[$first]) && !($this->restricted && $first < 0x80)) {
            return false;
        }

        // Past ASCII, under a Unicode option, the other case is the one the
        // Unicode tables of the release give.
        if ($first > 0x7F && $this->unicodeCases()) {
            $other = $this->classes()->otherCase($first);

            return $other !== $first && $other === $second;
        }

        // The tables of the locale give the other case of an ASCII
        // character, and of a byte without a Unicode option.
        if (null !== $this->tables && $first <= 0xFF) {
            $other = $this->tables->otherCase($first, $this->target);

            return $other !== $first && $other === $second;
        }

        if ($first > 0x7F) {
            return $second <= 0x7F && ($this->utf ? !\in_array($first, [0x130, 0x131], true) : 'i' !== Ascii::lower(\chr($second))) ? false : null;
        }

        $letter = Ascii::lower(\chr($first));
        if (!Ascii::isAlpha($letter) || $first === $second) {
            return false;
        }

        if ($second > 0x7F || $letter !== Ascii::lower(\chr($second))) {
            // The other case of "i" a locale gives is a byte.
            return 'i' === $letter && $second > 0x7F && $second <= 0xFF ? null : false;
        }

        return 'i' === $letter ? null : true;
    }

    /**
     * A property is its opcode and two values. "\p{Any}" is a match of any
     * character from 10.33; "\P{Any}" a class of none from 10.45.
     */
    private function propertyLength(UnicodePropNode $node): int
    {
        if (!self::isAnyProperty($node)) {
            return 3;
        }

        if (str_contains($node->prop, '^')) {
            return $this->target->supports(PcreFeature::ClassesCompiledFromMergedRanges) ? 33 : 3;
        }

        return $this->target->supports(PcreFeature::AnyPropertyMatchesAnyCharacter) ? 1 : 3;
    }

    /**
     * A reference to one group is its opcode and the group number, one to
     * a name several groups bear its opcode, an index and a count; from
     * 10.45 either is one unit more when caseless. The reference marks the
     * capturing groups it names that are open around it.
     */
    private function referenceLength(BackrefNode $node, bool $caseless): ?int
    {
        $numbers = [];
        foreach ($this->groupIndex()->groupsReferencedBy($node) as $group) {
            if (isset($this->openGroups[spl_object_id($group)])) {
                $this->openGroups[spl_object_id($group)] = true;
            }

            $numbers[$this->groupIndex()->numberOf($group) ?? -1] = true;
        }

        if ([] === $numbers) {
            return null; // a reference to no group is refused before any measure
        }

        return (\count($numbers) > 1 ? 5 : 3) + ($caseless && $this->target->supports(PcreFeature::CaselessReferenceHoldsItsCaseRules) ? 1 : 0);
    }

    /**
     * The length of a condition on a name, "(?(<n>)" or "(?(R&n)": a group
     * number, or an index and a count where several groups bear the name.
     */
    private function namedConditionLength(NodeInterface $condition): ?int
    {
        $numbers = match (true) {
            $condition instanceof BackrefNode => array_map(fn (GroupNode $group): ?int => $this->groupIndex()->numberOf($group), $this->groupIndex()->groupsReferencedBy($condition)),
            $condition instanceof SubroutineNode && str_starts_with($condition->reference, 'R&') => $this->groupIndex()->numbersNamed(substr($condition->reference, 2)),
            default => [], // no other condition comes here
        };
        $numbers = array_unique($numbers);

        return match (\count($numbers)) {
            0 => null, // a condition on no group is refused before any measure
            1 => 3,
            default => 5,
        };
    }

    /**
     * The key a class is measured once under: its text, but where "xx" may
     * make spaces in it skipped, its node.
     */
    private function classKey(CharClassNode $node): string
    {
        $this->spacesSkipped ??= 1 === LibraryPcre::match('/\(\?[\^a-zA-Z-]*xx/', $this->source);

        return $this->spacesSkipped ? '#'.spl_object_id($node) : substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
    }

    /**
     * Whether caseless characters past ASCII follow Unicode: in UTF mode,
     * and under UCP without it from 10.35.
     */
    private function unicodeCases(): bool
    {
        return $this->utf || ($this->ucp && $this->target->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf));
    }

    /**
     * Whether a condition is callouts, then an assertion.
     */
    private static function isCalloutsThenAssertion(NodeInterface $condition): bool
    {
        if (!$condition instanceof SequenceNode || [] === $condition->children) {
            return false;
        }

        $children = $condition->children;
        $last = array_pop($children);

        return $last instanceof GroupNode && [] === array_filter($children, static fn (NodeInterface $child): bool => !$child instanceof CalloutNode);
    }

    private function groupIndex(): GroupIndex
    {
        return $this->groups ??= GroupIndex::of($this->pattern ?? new LiteralNode('', 0, 0));
    }

    /**
     * The brackets of a lookbehind and, for each branch of some length, the
     * length to move back: one number, or two where the lookbehind is of
     * varying length and the branch longer than its shortest (from 10.43).
     * When a branch is of no known length, the most each may add.
     *
     * @param list<NodeInterface> $branches
     *
     * @return array{0: int, 1: int}
     */
    private function lookbehindFrame(GroupNode $node, array $branches): array
    {
        // Up to PCRE2 10.34 the measure of lookbehinds skips the first item
        // of a DEFINE group: a lookbehind there is left no length to move
        // back. 10.34 then reads a DEFINE group that opens on a group as if
        // it closed one level early, and stops where the outermost group
        // around it closes: the lookbehinds after are left none either.
        if (!$this->target->supports(PcreFeature::DefineSkippedInLookbehind)) {
            $this->unmeasured ??= $this->unmeasuredLookbehinds();
            [$from, $skipped, $known] = $this->unmeasured;
            if (!$known && $node->getStartPosition() > $from) {
                return [self::BRACKETS, self::BRACKETS + 3 * \count($branches)];
            }

            if ($known && ($node->getStartPosition() >= $from || isset($skipped[spl_object_id($node)]))) {
                return [self::BRACKETS, self::BRACKETS];
            }
        }

        $this->lookbehinds ??= new LookbehindLength(
            $this->groupIndex(),
            $this->utf,
            $this->target->supports(PcreFeature::VariableLengthLookbehind),
            inLookaheads: $this->target->supports(PcreFeature::LookbehindInLookaheadMeasured),
            skipsDefine: $this->target->supports(PcreFeature::DefineSkippedInLookbehind),
            readsNesting: !$this->target->supports(PcreFeature::ExtendedCharClass) || $this->target->supports(PcreFeature::ExtendedClassBesideLookbehindCompiles),
        );
        $lengths = [];
        foreach ($branches as $branch) {
            [$length] = $this->lookbehinds->branch($branch);
            if (null === $length[1]) {
                return [self::BRACKETS, self::BRACKETS + 5 * \count($branches)]; // a branch of no bound is refused before any measure
            }

            $lengths[] = [$length[0], $length[1]];
        }

        $shortest = \PHP_INT_MAX;
        foreach ($lengths as [$least]) {
            $shortest = min($shortest, $least);
        }

        $varying = [] !== array_filter($lengths, static fn (array $length): bool => $length[0] !== $length[1]);
        $units = self::BRACKETS;
        foreach ($lengths as [, $longest]) {
            if ($longest > 0) {
                $units += !$varying || $shortest === $longest ? 3 : 5;
            }
        }

        return [$units, $units];
    }

    /**
     * Up to PCRE2 10.34: where the measure of lookbehinds stops, the end of
     * the outermost item around the first DEFINE group that opens on a
     * group on 10.34; the lookbehinds a DEFINE group opens on, by node; and
     * whether that is known: not for a DEFINE group inside a lookbehind,
     * which another measure reads, nor on 10.34 for a second DEFINE group
     * that opens on a group, where only the start of the first is given.
     *
     * @return array{0: int, 1: array<int, GroupNode>, 2: bool}
     */
    private function unmeasuredLookbehinds(): array
    {
        $found = [];
        foreach (null === $this->pattern ? [] : self::branchesOf($this->pattern) as $branch) {
            foreach (self::itemsOf($branch) as $item) {
                $this->findDefinesOpeningOnAGroup($item, $item->getEndPosition(), false, $found);
            }
        }

        if ([] === $found) {
            return [\PHP_INT_MAX, [], true];
        }

        usort($found, static fn (array $a, array $b): int => $a[0]->getStartPosition() <=> $b[0]->getStartPosition());
        $closesEarly = $this->target->supports(PcreFeature::LookbehindInLookaheadMeasured);
        $skipped = [];
        foreach ($found as [$define, , $inLookbehind, $first]) {
            if ($inLookbehind || ($closesEarly && \count($found) > 1)) {
                return [$define->getStartPosition(), [], false];
            }

            if ($first instanceof GroupNode && \in_array($first->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)) {
                $skipped[spl_object_id($first)] = $first;
            }
        }

        return [$closesEarly ? $found[0][1] : \PHP_INT_MAX, $skipped, true];
    }

    /**
     * Gather in $found each DEFINE group whose first item opens a group,
     * with the end of the outermost item around it, whether it sits in a
     * lookbehind, and that first item.
     *
     * @param list<array{0: DefineNode, 1: int, 2: bool, 3: NodeInterface}> $found
     *
     * @param-out list<array{0: DefineNode, 1: int, 2: bool, 3: NodeInterface}> $found
     */
    private function findDefinesOpeningOnAGroup(NodeInterface $node, int $end, bool $inLookbehind, array &$found): void
    {
        if ($node instanceof DefineNode) {
            $item = $node->content;
            while ($item instanceof SequenceNode || $item instanceof AlternationNode) {
                $children = $item instanceof SequenceNode ? array_values(array_filter($item->children, static fn (NodeInterface $child): bool => !$child instanceof CommentNode)) : $item->alternatives;
                if ([] === $children) {
                    break;
                }

                $item = $children[0];
            }

            $item = $item instanceof QuantifierNode ? $item->node : $item;
            if (($item instanceof GroupNode && !(GroupType::InlineFlags === $item->type && $this->setsOptionsOnly($item))) || $item instanceof ConditionalNode || $item instanceof DefineNode || $item instanceof ScriptRunNode) {
                $found[] = [$node, $end, $inLookbehind, $item];
            }
        }

        $lookbehind = $inLookbehind || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true));
        foreach ($node->getChildren() as $child) {
            $this->findDefinesOpeningOnAGroup($child, $end, $lookbehind, $found);
        }
    }

    /**
     * Whether a conditional's empty second branch is written, "(?(1)a|)",
     * as the source tells: null when something else stands there, as a
     * comment under "x".
     */
    private function secondBranchWritten(ConditionalNode $node): ?bool
    {
        $start = $node->yes->getEndPosition();
        $between = trim(substr($this->source, $start, $node->getEndPosition() - 1 - $start), " \t\n\r\v\f");

        return match ($between) {
            '|' => true,
            '' => false,
            default => null,
        };
    }

    /**
     * Whether a node holds nothing but comments: "(?!)" holding only those
     * is a failure, one holding options a group.
     */
    private function holdsOnlyComments(NodeInterface $node): bool
    {
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (!$this->holdsOnlyComments($child)) {
                    return false;
                }
            }

            return true;
        }

        return self::isEmpty($node) || $node instanceof CommentNode;
    }

    private static function isAnyProperty(UnicodePropNode $node): bool
    {
        return 'any' === Ascii::lower((string) LibraryPcre::replace('/[\s_\-^{}]/', '', $node->prop));
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

        // The opcode, after one that closes each capture open around it; a
        // name is a mark before it.
        if (isset(self::EMPTY_SCRIPT_RUNS[$verb])) {
            return self::EMPTY_SCRIPT_RUNS[$verb];
        }

        if ('ACCEPT' === $verb) {
            return 1 + 3 * $this->openCaptures;
        }

        if (str_starts_with($verb, 'ACCEPT:')) {
            return 3 + \strlen($verb) - 7 + 1 + 3 * $this->openCaptures;
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

        // A sequence seldom nests another: it is its own list of items.
        $nested = false;
        foreach ($branch->children as $child) {
            if ($child instanceof SequenceNode) {
                $nested = true;

                break;
            }
        }

        if (!$nested) {
            return array_values($branch->children);
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
