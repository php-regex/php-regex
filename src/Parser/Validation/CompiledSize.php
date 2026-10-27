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

use PHPRegex\Parser\Engine\CharacterTables;
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
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;

/**
 * Whether PCRE2 refuses a pattern as too large to compile, and where it
 * reports it.
 *
 * Measuring a pattern the way the first pass of PCRE2 counts it is the work
 * of CompiledLength, and costs as much as a second parse. Almost every
 * pattern is far from the limit, so it is bracketed first: a ceiling, the
 * most any release may count for it, which spares the measure a pattern
 * that stays under the limit, and a floor, the fewest units every item
 * compiles to, which refuses one that passes it whatever the rest counts.
 * Only a pattern between the two is measured, and CompiledLength counts at
 * its floor each item whose length it does not know.
 *
 * The ceiling and the floor are written against the constants of
 * CompiledLength, so the three measures share one limit and one size for
 * each bracket.
 *
 * @internal
 */
final class CompiledSize
{
    /**
     * What every compiled pattern holds around its body: the end marker,
     * then the opening and closing brackets.
     */
    private const FRAME = 1 + CompiledLength::BRACKETS;

    /**
     * A size no pattern reaches: the floor and the ceiling stop growing
     * there.
     */
    private const CAP = 1 << 24;

    /**
     * The most units the floor counts for one byte of a pattern that holds
     * no "{", so repeats nothing by a count: a class of two members 33 for
     * its four bytes, a group 8 for its two brackets, a branch 3 for its
     * "|", a literal 2 per character, a callout 11 and its string for six
     * bytes and that string, a verb 3 and its name for more, "?" on a group
     * 1, every other item 3 at most for one byte or more.
     */
    private const MAX_UNITS_PER_BYTE_UNCOUNTED = 33;

    /**
     * The pattern between its delimiters, null for a tree built by hand.
     */
    private readonly ?string $source;

    /**
     * Whether the "u" flag itself is set: "(*UTF)" alone reads "\d", "\s",
     * "\w" and the POSIX names as bytes.
     */
    private readonly bool $unicodeFlag;

    /**
     * Whether the pattern may hold a caseless item, which the ceiling
     * allows for.
     */
    private readonly bool $caseless;

    /**
     * Whether a capture in the pattern may be wrapped for a reference to
     * itself (up to 10.34), which the ceiling allows for.
     */
    private readonly bool $mayWrapCaptures;

    /**
     * Whether the pattern may hold an inline "a", which keeps types and
     * POSIX names to ASCII under UCP.
     */
    private readonly bool $asciiOptions;

    /**
     * Whether the pattern may be read under UCP, where Unicode folds the
     * bytes past ASCII of a caseless class without UTF.
     */
    private readonly bool $ucp;

    /**
     * Whether each caseless class of bytes measured may hold every byte, by
     * its ranges: a class written again folds the same.
     *
     * @var array<string, bool>
     */
    private array $foldsToEveryByte = [];

    /**
     * @param RegexNode  $regex  the pattern judged, its source and flags
     * @param bool       $utf    whether PCRE reads the pattern as UTF-8, by
     *                           the "u" flag or "(*UTF)"
     * @param PcreTarget $target the PCRE2 release whose first pass is judged
     */
    public function __construct(
        private readonly RegexNode $regex,
        private readonly bool $utf,
        private readonly PcreTarget $target,
    ) {
        $this->source = $regex->source;
        $source = $regex->source ?? '';
        $this->unicodeFlag = str_contains($regex->flags, 'u');
        $this->caseless = str_contains($regex->flags, 'i') || str_contains($source, '(?');
        $this->ucp = $this->unicodeFlag || str_contains($source, 'UCP');
        $this->mayWrapCaptures = !$target->supports(PcreFeature::GroupHoldingItsOwnReferenceBacktracks) && 1 === LibraryPcre::match('/\\\\[1-9gk]|\\(\\?P=/', $source);
        $this->asciiOptions = 1 === LibraryPcre::match('/\(\?[\^a-zA-Z-]*a/', $source);
    }

    /**
     * Whether no release can count the pattern past the limit, by its text
     * alone: one that holds no "{" and is short enough cannot reach it,
     * whatever it holds, and is not measured. "(*ACCEPT)" closes each
     * capture open around it, three units each.
     */
    public static function belowTheLimit(RegexNode $regex): bool
    {
        $source = $regex->source;

        return null !== $source && !str_contains($source, '{') && !str_contains($source, 'ACCEPT')
            && \strlen($source) * self::MAX_UNITS_PER_BYTE_UNCOUNTED + self::FRAME <= CompiledLength::LIMIT;
    }

    /**
     * Whether the first pass of PCRE2 finds the pattern too large: only a
     * pattern whose ceiling passes the limit is measured, and it is too
     * large when its floor passes it too, or the length that pass counts,
     * each item whose length is not known for sure counted at its floor.
     */
    public function isTooLarge(): bool
    {
        // Under Turkish case rules "i" and "I" are each a property of three
        // units, which the ceiling leaves out: such a pattern is measured.
        if (!str_contains($this->regex->source ?? '', 'TURKISH_CASING') && $this->ceiling($this->regex->pattern) + self::FRAME <= CompiledLength::LIMIT) {
            return false;
        }

        if ($this->floor($this->regex->pattern) + self::FRAME > CompiledLength::LIMIT) {
            return true;
        }

        return $this->length()->passesTheLimit($this->regex->pattern);
    }

    /**
     * Where PCRE reports a pattern too large to compile: at its end up to
     * PCRE2 10.46, at offset 0 on 10.47. From 10.48 the check made where a
     * group or the pattern ends sets no offset, so a pattern that only that
     * check finds too large is reported at its end again.
     */
    public function errorOffset(): int
    {
        $end = \strlen($this->regex->source ?? '');
        if (!$this->target->supports(PcreFeature::TooLargeAtPatternStart)) {
            return $end;
        }

        // On 10.47 a class whose lists pass the limit as it is compiled is
        // refused by a check that sets no offset.
        if (!$this->target->supports(PcreFeature::TooLargeAtGroupEndReportedAtPatternEnd)) {
            $length = $this->length();
            $length->passesTheLimit($this->regex->pattern);

            return $length->passedAtClassLists ? $end : 0;
        }

        // Past the limit inside a branch, at offset 0; where a group ends,
        // or when that cannot be told for sure, at the end, as up to 10.46.
        return $this->length()->passedInsideABranch($this->regex->pattern) ? 0 : $end;
    }

    /**
     * Where the first pass stops on a pattern too large: past the end of the
     * pattern where that is not known for sure.
     */
    public function firstPassStop(): int
    {
        $length = $this->length();
        $length->passesTheLimit($this->regex->pattern);

        return $length->stoppedAt;
    }

    /**
     * The smallest size PCRE could compile the node to, in code units. Every
     * item counts at most what PCRE spends on it, so a pattern whose floor
     * passes the limit is one PCRE refuses: a literal character is two units,
     * a group its brackets and its body, a branch three, a call or a
     * reference three, anything else one or nothing. A counted group is
     * compiled once per repetition, a counted single item once.
     */
    public function floor(NodeInterface $node): int
    {
        $size = match (true) {
            $node instanceof SequenceNode => $this->floorOfAll($node->children),
            $node instanceof AlternationNode => $this->floorOfAll($node->alternatives) + CompiledLength::ALTERNATION * (\count($node->alternatives) - 1),
            $node instanceof GroupNode => $this->groupFloor($node),
            $node instanceof ConditionalNode => $this->conditionalFloor($node),
            $node instanceof QuantifierNode => $this->repeatedFloor($node),
            $node instanceof LiteralNode => 2 * mb_strlen($node->value, 'UTF-8'),
            // A reference by number may be an octal character, "\101".
            $node instanceof BackrefNode => 2,
            $node instanceof SubroutineNode => 3,
            $node instanceof CharClassNode => $this->classFloor($node),
            // Under UCP, "\d", "\s" and "\w" are properties, but where an
            // inline "a" may keep them to ASCII.
            $node instanceof CharTypeNode => $this->unicodeFlag && !$this->asciiOptions && str_contains('dDsSwW', $node->value) ? 3 : 1,
            // "\p{Any}" is a match of any character, one unit, from 10.33.
            $node instanceof UnicodePropNode => self::isAnyCharacterProperty($node) ? 1 : 3,
            // A DEFINE group is brackets and a condition never true, a script
            // run brackets, inside an atomic group when atomic.
            $node instanceof DefineNode => $this->floor($node->content) + CompiledLength::BRACKETS + 1,
            $node instanceof ScriptRunNode => null === $node->content ? 0 : $this->floor($node->content) + ($node->isAtomic ? 2 : 1) * CompiledLength::BRACKETS,
            $node instanceof DotNode, $node instanceof CharLiteralNode => 1,
            // A callout carries its number or its string; a verb its name.
            $node instanceof CalloutNode => \is_string($node->identifier) && $node->isStringIdentifier ? 11 + \strlen($node->identifier) : 6,
            $node instanceof PcreVerbNode => 1 === LibraryPcre::match('/^(?:MARK|PRUNE|SKIP|THEN|COMMIT):(.+)$/s', $node->verb, $name) ? 3 + \strlen($name[1]) : 0,
            default => 0,
        };

        return min($size, self::CAP);
    }

    /**
     * The most units the first pass of any release may count for the node:
     * a pattern whose ceiling stays under the limit is not measured further.
     * A literal is two units a byte at most, three for a caseless "k" or
     * "s"; a class of ASCII characters its map and what a caseless one adds
     * past it, any other its header, map and end and 64 units a byte of it;
     * a group its brackets, its number, an atomic group around a capture
     * holding a reference to itself (up to 10.34) and five units for each
     * branch of a lookbehind; a counted group each copy, seven units more
     * for each optional one, a "may skip" marker and atomic brackets around
     * a possessive count; "(*ACCEPT)" closes up to 250 captures.
     */
    public function ceiling(NodeInterface $node): int
    {
        $size = match (true) {
            $node instanceof SequenceNode => $this->ceilingOfAll($node->children),
            $node instanceof AlternationNode => $this->ceilingOfAll($node->alternatives) + CompiledLength::ALTERNATION * (\count($node->alternatives) - 1),
            $node instanceof GroupNode => $this->ceiling($node->child) + $this->groupFrameCeiling($node),
            $node instanceof ConditionalNode => ($node->condition instanceof GroupNode || $node->condition instanceof SequenceNode ? $this->ceiling($node->condition) : 5) + $this->ceiling($node->yes) + $this->ceiling($node->no) + CompiledLength::BRACKETS + CompiledLength::ALTERNATION,
            $node instanceof DefineNode => $this->ceiling($node->content) + CompiledLength::BRACKETS + 1,
            $node instanceof ScriptRunNode => (null === $node->content ? 0 : $this->ceiling($node->content)) + 2 * CompiledLength::BRACKETS,
            $node instanceof QuantifierNode => $this->repeatedCeiling($node),
            $node instanceof LiteralNode => 2 * \strlen($node->value) + ($this->caseless ? self::caselessSetBytes($node->value) : 0),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => 5,
            $node instanceof DotNode, $node instanceof CharTypeNode => 3,
            // "\P{Any}" is a class of none from 10.45.
            $node instanceof UnicodePropNode => str_contains($node->prop, '^') ? 33 : 3,
            $node instanceof CharClassNode => $this->classCeiling($node),
            $node instanceof BackrefNode => 6,
            $node instanceof SubroutineNode => 3 + 3 * \count($node->returnedGroups),
            $node instanceof CalloutNode => 9 + $node->getEndPosition() - $node->getStartPosition(),
            // An empty script run reads as a verb: two groups at most.
            $node instanceof PcreVerbNode => 4 + \strlen($node->verb) + (str_starts_with($node->verb, 'ACCEPT') ? 750 : 0) + (str_ends_with($node->verb, ':') ? 2 * CompiledLength::BRACKETS : 0),
            $node instanceof AnchorNode, $node instanceof AssertionNode, $node instanceof KeepNode => 1,
            $node instanceof CommentNode, $node instanceof LimitMatchNode => 0,
            default => self::CAP, // an extended class, which PHP does not let a pattern open: measured then
        };

        return min($size, self::CAP);
    }

    /**
     * The measure of the first pass, which counts at their floor the items
     * whose length it does not know.
     */
    private function length(): CompiledLength
    {
        $source = $this->regex->source ?? '';

        return new CompiledLength($this->floor(...), $source, $this->utf, $this->unicodeFlag || \in_array('UCP', StartOptions::items($source), true), $this->regex->flags, $this->target, CharacterTables::forLocale($this->target->locale));
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function ceilingOfAll(array $nodes): int
    {
        $size = 0;
        foreach ($nodes as $node) {
            $size = min($size + $this->ceiling($node), self::CAP);
        }

        return $size;
    }

    /**
     * The bytes of a literal that may stand for a character of more than
     * one other case, a property of three units when caseless: "k" and "s",
     * and without UTF the micro sign and the a with a ring.
     */
    private static function caselessSetBytes(string $value): int
    {
        return \strlen($value) - \strlen(str_replace(['k', 'K', 's', 'S', "\xB5", "\xC5", "\xE5"], '', $value));
    }

    private function groupFrameCeiling(GroupNode $node): int
    {
        $capture = GroupType::Capturing === $node->type || GroupType::Named === $node->type;
        $branches = $node->child instanceof AlternationNode ? \count($node->child->alternatives) : 1;

        return CompiledLength::BRACKETS + ($capture ? 2 + ($this->mayWrapCaptures ? CompiledLength::BRACKETS : 0) : 0)
            + (\in_array($node->type, [GroupType::LookbehindPositive, GroupType::LookbehindNegative], true) ? 5 * $branches : 0)
            + (GroupType::ScanSubstring === $node->type ? 5 * (\count($node->scannedGroups) + 1) : 0);
    }

    /**
     * A class of ASCII characters and ranges is a map of 33 units at most.
     * Any other is an extended class at most: its header, map and end, a
     * range past 255 that a negated type adds, the lists beside the code,
     * and for each member what it adds past the map: nothing to 49 units,
     * the Kelvin sign and the long s a caseless ASCII member adds, up to
     * three other cases for a caseless character past ASCII; a caseless
     * range past ASCII adds the other cases of each of its characters, with
     * no bound.
     */
    private function classCeiling(CharClassNode $node): int
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $items = 0;
        $map = !$this->caseless;
        $negated = false;
        $wide = false;
        foreach ($members as $member) {
            $ceiling = $this->classMemberCeiling($member);
            if (null === $ceiling) {
                return self::CAP;
            }

            [$units, $character, $past255, $negatedType] = $ceiling;
            $items += $units;
            $map = $map && $character && !$past255;
            $negated = $negated || $negatedType;
            $wide = $wide || $past255;
        }

        if ($map) {
            return 33;
        }

        return 38 + $items + ($negated ? 7 : 0) + ($wide ? 23 : 0);
    }

    /**
     * What a member of a class may add past the map, whether it is ASCII
     * characters, whether it may reach past 255 or the lists beside the
     * code, and whether it is a negated type; null for no bound.
     *
     * @return array{0: int, 1: bool, 2: bool, 3: bool}|null
     */
    private function classMemberCeiling(NodeInterface $member): ?array
    {
        $caseless = $this->caseless;
        if ($member instanceof RangeNode) {
            $ascii = $this->isAsciiCharacter($member->start) && $this->isAsciiCharacter($member->end);
            if (!$ascii && $caseless) {
                return null;
            }

            return [$ascii ? ($caseless ? 14 : 0) : 9, true, !$ascii, false];
        }

        if ($member instanceof LiteralNode) {
            $ascii = 1 !== LibraryPcre::match('/[\x80-\xff]/', $member->value);

            return [\strlen($member->value) * ($ascii ? ($caseless ? 14 : 0) : ($caseless ? 15 : 5)), true, !$ascii, false];
        }

        if ($member instanceof CharLiteralNode || $member instanceof ControlCharNode) {
            $ascii = $member->codePoint < 0x80;

            return [$ascii ? ($caseless ? 14 : 0) : ($caseless ? 15 : 5), true, !$ascii, false];
        }

        if ($member instanceof CharTypeNode) {
            $spaces = str_contains('hHvV', $member->value);

            return [match ($member->value) {
                'h' => 27,
                'H' => 49,
                'v' => 7,
                'V' => 14,
                default => 3,
            }, false, $spaces, str_contains('DSW', $member->value)];
        }

        if ($member instanceof PosixClassNode) {
            $blank = str_ends_with($member->class, 'blank');

            return [$blank ? 49 : 3, false, $blank, str_starts_with($member->class, '^')];
        }

        return $member instanceof UnicodePropNode ? [3, false, false, false] : null;
    }

    private function isAsciiCharacter(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && 1 === \strlen($node->value) && \ord($node->value) < 0x80)
            || (($node instanceof CharLiteralNode || $node instanceof ControlCharNode) && $node->codePoint < 0x80);
    }

    /**
     * A class or a reference takes its count after it, "*", "+" and "?" in
     * one unit, any other in five, and up to 10.47 a possessive count wraps
     * a reference; a character or a type is at most two items and their
     * counts; a group, a call or a verb one copy for each repetition, seven
     * units more for each optional one, a "may skip" marker and atomic
     * brackets around a possessive count.
     */
    private function repeatedCeiling(QuantifierNode $node): int
    {
        $item = $node->node;
        $copy = $this->ceiling($item);
        [$min, $max] = QuantifierCounts::of($node->quantifier);
        $short = (0 === $min && (-1 === $max || 1 === $max)) || (1 === $min && -1 === $max);
        if ($item instanceof CharClassNode || $item instanceof BackrefNode) {
            return $copy + ($short ? 1 : 5) + ($item instanceof BackrefNode ? CompiledLength::BRACKETS : 0);
        }

        $possessive = QuantifierType::Possessive === $node->type;
        if (!$item instanceof GroupNode && !$item instanceof ConditionalNode && !$item instanceof DefineNode && !$item instanceof ScriptRunNode
            && !$item instanceof SubroutineNode && !$item instanceof PcreVerbNode) {
            return match (true) {
                0 === $max || (1 === $min && 1 === $max) => $copy,
                $short => $copy + 1,
                default => 2 * $copy + 6,
            };
        }

        // A call or a verb is wrapped in a group to be repeated.
        if ($item instanceof SubroutineNode || $item instanceof PcreVerbNode) {
            $copy += CompiledLength::BRACKETS;
        }

        $optional = -1 === $max ? 1 : max($max - $min, 0);

        return min(max($min, 1) * $copy + $optional * ($copy + CompiledLength::OPTIONAL_COPY) + ($possessive ? 2 * CompiledLength::BRACKETS : 0) + (0 === $min ? 1 : 0), self::CAP);
    }

    /**
     * @param array<NodeInterface> $nodes
     */
    private function floorOfAll(array $nodes): int
    {
        $size = 0;
        foreach ($nodes as $node) {
            $size += $this->floor($node);
        }

        return $size;
    }

    /**
     * A class is a 32-byte map. In UTF mode, what the map cannot hold,
     * characters past 255, goes in an extended class: its header, then its
     * items; so do properties, and the types and POSIX classes UCP reads.
     * PCRE compiles smaller a class of one member, a pair of case variants
     * such as "[aA]" or "[ǅǆ]", or of a byte and the other case the tables
     * of the locale give it, and one that may match every character, a
     * caseless one whose bytes and their other cases are all the bytes
     * included: those count one unit, and so does a type or a POSIX class
     * the map would hold. Characters PCRE may merge into one range, or fold
     * under a caseless option set anywhere, count as a single item. All to
     * stay a lower bound.
     */
    private function classFloor(CharClassNode $node): int
    {
        $members = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        $extended = 0;
        $codePoints = [];
        $ranges = [];
        $covered = 0;
        $pastMap = false;
        foreach ($members as $member) {
            // A class holding "\p{Any}" may compile to a match of any
            // character, one unit, from 10.45, where "\P{Any}" adds nothing.
            if ($member instanceof UnicodePropNode && 'any' === Ascii::lower(str_replace([' ', '_', '-', '^', '{', '}'], '', $member->prop))) {
                if (self::isAnyCharacterProperty($member)) {
                    return 1;
                }

                continue;
            }

            // Under UCP a type but "\h" and "\v", or a POSIX name but
            // "ascii", "blank" and "xdigit", is a property; those are lists
            // of characters, which with their negations may hold them all.
            if ($member instanceof UnicodePropNode
                || ($this->unicodeFlag && !$this->asciiOptions && $member instanceof CharTypeNode && str_contains('dDsSwW', $member->value))
                || ($this->unicodeFlag && !$this->asciiOptions && $member instanceof PosixClassNode && !\in_array(Ascii::lower(ltrim($member->class, '^')), ['ascii', 'blank', 'xdigit'], true))) {
                $extended += 3;

                continue;
            }

            $ends = array_map($this->classCodePoint(...), $member instanceof RangeNode ? [$member->start, $member->end] : [$member]);
            if (\in_array(null, $ends, true)) {
                return 1;
            }

            // Ranges to the last character hold every character past 255:
            // from 10.45 what the class holds below 256 then decides, and it
            // may be a match of any character.
            if ($this->utf && 0x10FFFF === max($ends)) {
                return 1;
            }

            $codePoints[] = min($ends);
            $codePoints[] = max($ends);
            $ranges[] = [min($ends), max($ends)];
            $covered += max($ends) - min($ends) + 1;
            $pastMap = $pastMap || ($this->utf && max($ends) > 255);
        }

        // One item at least: a character past 255 is its tag and two bytes.
        $extended += $pastMap ? 3 : 0;

        $distinct = array_values(array_unique($codePoints));
        if ([] === $distinct) {
            // Only properties and UCP types: an extended class of those; a
            // class of "\P{Any}" alone may be one of every character, negated.
            return 0 === $extended ? 1 : 5 + $extended;
        }

        if (\count($distinct) < 2 || $covered >= ($this->utf ? 0x110000 : 256) || ($this->caseless && !$this->utf && $this->mayFoldToEveryByte($ranges, $covered))) {
            return 1;
        }

        // A pair of case variants is one caseless character: two units.
        if (2 === \count($distinct) && (mb_strtolower((string) mb_chr($distinct[0], 'UTF-8'), 'UTF-8') === mb_strtolower((string) mb_chr($distinct[1], 'UTF-8'), 'UTF-8') || $this->mayBeCasePairInTables($distinct[0], $distinct[1]))) {
            return 2;
        }

        // So is a pair the Unicode tables of the target make one, which the
        // running PHP may not know.
        if (2 === \count($distinct) && OtherCases::of($this->target)->otherCase($distinct[0]) === $distinct[1]) {
            return 2;
        }

        // An extended class that holds a character below 256 holds the map
        // too, but where its characters reach the last one, all past 255
        // being in it.
        $map = $this->utf && min($distinct) <= 0xFF && max($distinct) < 0x10FFFF;

        return 0 < $extended ? 5 + $extended + ($map ? 32 : 0) : 33;
    }

    /**
     * Whether a class of two characters may be a pair of cases in the tables
     * of the locale: an ASCII character, or a byte without UTF, whose other
     * case is the other one.
     */
    private function mayBeCasePairInTables(int $first, int $second): bool
    {
        $tables = CharacterTables::forLocale($this->target->locale);
        $last = $this->utf ? 0x7F : 0xFF;

        return ($first <= $last && $tables->otherCase($first, $this->target) === $second)
            || ($second <= $last && $tables->otherCase($second, $this->target) === $first);
    }

    /**
     * Whether a caseless class of bytes may hold every byte, a match of any
     * character from 10.45, once the tables add the other case of each: a
     * class of fewer than 128 bytes does not. Under UCP, which the pattern
     * may set, Unicode folds the bytes past ASCII too.
     *
     * @param list<array{0: int, 1: int}> $ranges
     */
    private function mayFoldToEveryByte(array $ranges, int $covered): bool
    {
        if ($covered < 128 || !$this->target->supports(PcreFeature::ClassesCompiledFromMergedRanges)) {
            return false;
        }

        return $this->foldsToEveryByte[($this->ucp ? 'u' : '-').json_encode($ranges)] ??= $this->foldToEveryByte($ranges);
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    private function foldToEveryByte(array $ranges): bool
    {
        $tables = CharacterTables::forLocale($this->target->locale);
        $held = [];
        foreach ($ranges as [$low, $high]) {
            for ($byte = $low; $byte <= min($high, 0xFF); $byte++) {
                $held[$byte] = $held[$tables->otherCase($byte, $this->target)] = true;
                // Unicode gives a Latin-1 letter its other case below 256,
                // but for the micro sign, the sharp s and the y with a
                // diaeresis.
                if ($this->ucp && $byte >= 0xC0 && 0xD7 !== $byte && 0xF7 !== $byte && 0xDF !== $byte && 0xFF !== $byte) {
                    $held[$byte ^ 0x20] = true;
                }
            }
        }

        return 256 === \count($held);
    }

    /**
     * The code point a class member stands for, or null when it stands for
     * more than one character.
     */
    private function classCodePoint(NodeInterface $member): ?int
    {
        return match (true) {
            // Without UTF mode, a character is a byte.
            $member instanceof LiteralNode && $this->utf && 1 === mb_strlen($member->value, 'UTF-8') => (int) mb_ord($member->value, 'UTF-8'),
            $member instanceof LiteralNode && !$this->utf && 1 === \strlen($member->value) => \ord($member->value),
            $member instanceof CharLiteralNode => $member->codePoint,
            default => null,
        };
    }

    /**
     * A conditional is a group holding its branches, the second one behind
     * a branch marker.
     */
    private function conditionalFloor(ConditionalNode $node): int
    {
        $no = $this->floor($node->no);

        return $this->floor($node->yes) + $no + CompiledLength::BRACKETS + ($no > 0 ? CompiledLength::ALTERNATION : 0);
    }

    /**
     * The brackets of a compiled group: those of a capturing group also hold
     * its number. A "(?i)" that scopes nothing compiles to no group at all,
     * and a lookaround that holds nothing to one unit at most.
     */
    private function groupFloor(GroupNode $node): int
    {
        // The body is measured once: measuring it again at every level
        // would double the work with each group nested in another.
        $body = $this->floor($node->child);

        return $body + $this->groupBrackets($node, $body);
    }

    private function groupBrackets(GroupNode $node, int $body): int
    {
        return match (true) {
            // A lookaround that holds nothing compiles to at most one unit:
            // "(?!)" is a plain failure.
            self::isLookaround($node) && 0 === $body => 0,
            // "(?i)" sets options for the rest of the group; "(?i:)" is a
            // group, and so is "(?x: )", whose space "x" skips.
            GroupType::InlineFlags === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value && (null === $this->source || InlineFlags::setOptionsOnly($this->source, $node->startPosition)) => 0,
            GroupType::Capturing === $node->type, GroupType::Named === $node->type => CompiledLength::BRACKETS + 2,
            default => CompiledLength::BRACKETS,
        };
    }

    private static function isAnyCharacterProperty(UnicodePropNode $node): bool
    {
        return !str_contains($node->prop, '^') && 'any' === Ascii::lower(str_replace([' ', '_', '-', '{', '}'], '', $node->prop));
    }

    private static function isLookaround(GroupNode $node): bool
    {
        return \in_array($node->type, [
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true);
    }

    /**
     * A counted group, conditional, call or "(*ACCEPT)" is compiled once per
     * repetition: every mandatory copy as it is, every optional one inside
     * brackets that nest the next (the innermost only behind its "may skip"
     * marker), and an open maximum as one more copy that loops. Any other
     * item is compiled once, with its count.
     */
    private function repeatedFloor(QuantifierNode $node): int
    {
        $copy = $this->floor($node->node);

        // "(*ACCEPT)" is wrapped in a group to be repeated; a name adds its
        // length and three units.
        if ($node->node instanceof PcreVerbNode && 1 === LibraryPcre::match('/^ACCEPT(?::(.*))?$/s', $node->node->verb, $accept)) {
            $copy = CompiledLength::BRACKETS + 1 + (isset($accept[1]) && '' !== $accept[1] ? 3 + \strlen($accept[1]) : 0);
        } elseif (!$node->node instanceof GroupNode && !$node->node instanceof ConditionalNode && !$node->node instanceof SubroutineNode && !$node->node instanceof DefineNode && !$node->node instanceof ScriptRunNode) {
            // A count other than "*", "+", "?", zero or one is at least two
            // units more: "a{2}" is an opcode, the count and the letter.
            [$min, $max] = QuantifierCounts::of($node->quantifier);
            $short = (0 === $min && (-1 === $max || 1 === $max)) || (1 === $min && -1 === $max);

            return 0 === $max || (1 === $min && 1 === $max) || $short ? $copy : $copy + 2;
        }

        // Up to PCRE2 10.34 an assertion is compiled once whatever its count.
        if ($node->node instanceof GroupNode && self::isLookaround($node->node)) {
            return $copy;
        }

        [$min, $max] = QuantifierCounts::of($node->quantifier);
        if (-1 === $max) {
            return max($min, 1) * $copy;
        }

        $optional = $max - $min;

        return $min * $copy + ($optional > 0 ? ($optional - 1) * ($copy + CompiledLength::OPTIONAL_COPY) + $copy + 1 : 0);
    }
}
