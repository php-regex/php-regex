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

namespace PHPRegex\Redos\Internal;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * The last code unit PCRE2 requires in the subject before it tries an
 * attempt (pcre2test's "Last code unit"), read as its compiler reads it.
 *
 * Each alternative keeps a first and a required code unit: a literal is the
 * first when none is set yet, the required one after it; another item that
 * reads a character leaves no first. A repeat that may match nothing takes
 * both back to what they were before the item; a literal repeated at least
 * twice is required, and so is the first code unit a group repeated at least
 * twice set. The alternatives of a group keep a code unit only when they all
 * have the same one, a first code unit turning into a required one where
 * the first ones differ. A lookahead gives its required code unit when it
 * has a first one too; an accepting verb takes the required one away.
 *
 * Under u a code unit is a byte: a character of several gives its last
 * byte, and none when caseless. Under i a letter with more than one other
 * case under u (k, s, and their kind) is a property, and gives none; the
 * others keep the caseless flag, compared as written, which PCRE2 keeps in
 * the end only for a character with another case. A class of one
 * character is that character, and so is a class of a letter and its only
 * other case, caseless, as first written.
 *
 * Pinned against pcre2test 10.49. PCRE2 drops the last code unit when the
 * start-up study finds the same one first: kept here, the witness then
 * carries a character the search did not need.
 *
 * @internal
 */
final class LastCodeUnit
{
    private const UNSET = -1;

    private const NONE = -2;

    private const CASELESS = 1;

    private const MULTILINE = 2;

    private const RESTRICT = 4;

    /**
     * The ASCII letters with more than one other case under u: "k" with the
     * Kelvin sign, "s" with the long s.
     */
    private const ASCII_CASE_SETS = [0x6B, 0x73];

    private const NOT_SET = [self::NONE, 0, 0];

    /**
     * The first code unit of the alternative read so far: its flags (UNSET,
     * NONE, or 0 and CASELESS once set), the code unit, and the character it
     * comes from.
     *
     * @var array{int, int, int}
     */
    private array $first = [self::UNSET, 0, 0];

    /**
     * @var array{int, int, int}
     */
    private array $required = [self::UNSET, 0, 0];

    /**
     * What a repeat that may match nothing takes the first code unit back
     * to.
     *
     * @var array{int, int, int}
     */
    private array $zeroFirst = [self::UNSET, 0, 0];

    /**
     * @var array{int, int, int}
     */
    private array $zeroRequired = [self::UNSET, 0, 0];

    /**
     * Whether the last group set the first code unit.
     */
    private bool $groupSetFirst = false;

    private bool $accepts = false;

    private function __construct(private readonly bool $unicode, private readonly string $source) {}

    /**
     * A character that holds the code unit PCRE2 requires last, the one the
     * code unit was read from; null when it requires none.
     */
    public static function of(RegexNode $regex): ?int
    {
        return self::read($regex)[0] ?? null;
    }

    /**
     * The character of of(), and whether PCRE2 looks for its code unit in
     * either case: read caseless, and a character with another case, as
     * PCRE2 keeps the flag only then.
     *
     * @return array{int, bool}|null
     */
    public static function read(RegexNode $regex): ?array
    {
        $reader = new self($regex->isUnicode(), $regex->source ?? '');
        $options = 0;
        foreach (['i' => self::CASELESS, 'm' => self::MULTILINE, 'r' => self::RESTRICT] as $letter => $bit) {
            if (str_contains($regex->flags, $letter)) {
                $options |= $bit;
            }
        }

        [, $required] = $reader->group(self::alternatives($regex->pattern), $options);
        if ($reader->accepts || $required[0] < 0) {
            return null;
        }

        return [$required[2], 0 !== ($required[0] & self::CASELESS) && $reader->otherCase($required[2]) !== $required[2]];
    }

    /**
     * The character of a literal of one character or of an escape of one.
     */
    public static function literalCharacter(NodeInterface $node, bool $unicode): ?int
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        $characters = $node instanceof LiteralNode ? Utf8::decode($node->value, $unicode) : null;

        return null !== $characters && 1 === \count($characters) ? $characters[0] : null;
    }

    /**
     * @return list<NodeInterface>
     */
    private static function alternatives(NodeInterface $node): array
    {
        return $node instanceof AlternationNode ? array_values($node->alternatives) : [$node];
    }

    /**
     * The first and required code units of a group's alternatives, joined
     * as PCRE2 joins them. An option set in one alternative holds in the
     * ones after it.
     *
     * @param list<NodeInterface> $alternatives
     *
     * @return array{array{int, int, int}, array{int, int, int}}
     */
    private function group(array $alternatives, int $options): array
    {
        $first = [self::UNSET, 0, 0];
        $required = [self::UNSET, 0, 0];
        foreach ($alternatives as $index => $alternative) {
            [$branchFirst, $branchRequired] = $this->branch($alternative, $options);
            if (0 === $index) {
                [$first, $required] = [$branchFirst, $branchRequired];

                continue;
            }

            if ($first[0] !== $branchFirst[0] || $first[1] !== $branchFirst[1]) {
                if ($first[0] >= 0 && $required[0] < 0) {
                    $required = $first;
                }

                $first = self::NOT_SET;
            }

            if ($first[0] < 0 && $branchFirst[0] >= 0 && $branchRequired[0] < 0) {
                $branchRequired = $branchFirst;
            }

            $required = $required[0] === $branchRequired[0] && $required[1] === $branchRequired[1] ? $branchRequired : self::NOT_SET;
        }

        return [$first, $required];
    }

    /**
     * @return array{array{int, int, int}, array{int, int, int}}
     */
    private function branch(NodeInterface $node, int &$options): array
    {
        $outer = [$this->first, $this->required, $this->zeroFirst, $this->zeroRequired, $this->groupSetFirst];
        $this->first = $this->required = $this->zeroFirst = $this->zeroRequired = [self::UNSET, 0, 0];
        $this->groupSetFirst = false;

        foreach (self::items($node) as $item) {
            if ($item instanceof GroupNode && GroupType::InlineFlags === $item->type && $this->isBareOptionSetting($item)) {
                $options = self::apply($options, (string) $item->flags);

                continue;
            }

            $this->item($item, $options);
        }

        $branch = [$this->first, $this->required];
        [$this->first, $this->required, $this->zeroFirst, $this->zeroRequired, $this->groupSetFirst] = $outer;

        return $branch;
    }

    /**
     * The items of an alternative, a sequence inside it read in line: the
     * parser splits a multibyte literal without u into one, its last byte
     * under the repeat.
     *
     * @return list<NodeInterface>
     */
    private static function items(NodeInterface $node): array
    {
        if (!$node instanceof SequenceNode) {
            return [$node];
        }

        $items = [];
        foreach ($node->children as $child) {
            array_push($items, ...self::items($child));
        }

        return $items;
    }

    private function item(NodeInterface $node, int $options): void
    {
        if ($node instanceof QuantifierNode) {
            $this->repeat($node, $options);

            return;
        }

        if ($node instanceof LiteralNode) {
            foreach (Utf8::decode($node->value, $this->unicode) ?? [] as $codePoint) {
                $this->character($codePoint, $options);
            }

            return;
        }

        $character = $this->characterOf($node);
        if (null !== $character) {
            $this->character($character, $options);

            return;
        }

        $partner = $node instanceof CharClassNode ? $this->caseClass($node, $options) : null;
        if (null !== $partner) {
            $this->character($partner, $options | self::CASELESS, false);

            return;
        }

        match (true) {
            $node instanceof GroupNode => $this->subpattern(
                $this->group(self::alternatives($node->child), GroupType::InlineFlags === $node->type ? self::apply($options, (string) $node->flags) : $options),
                self::isGroup($node),
                GroupType::LookaheadPositive === $node->type,
            ),
            // A conditional of one branch has an empty second one.
            $node instanceof ConditionalNode => $this->subpattern(
                $node->no instanceof LiteralNode && '' === $node->no->value ? [self::NOT_SET, self::NOT_SET] : $this->group([$node->yes, $node->no], $options),
                true,
                false,
            ),
            $node instanceof ScriptRunNode => $this->subpattern(null === $node->content ? [self::NOT_SET, self::NOT_SET] : $this->group(self::alternatives($node->content), $options), true, false),
            $node instanceof DefineNode => $this->subpattern([self::NOT_SET, self::NOT_SET], true, false),
            $node instanceof AnchorNode => $this->anchor($node, $options),
            $node instanceof PcreVerbNode => $this->verb($node),
            $node instanceof BackrefNode => $this->noFirst(true, false),
            $node instanceof SubroutineNode => $this->subroutine(),
            // An escape that reads no character.
            $node instanceof AssertionNode, $node instanceof KeepNode => $this->noFirst(false, true),
            $node instanceof CommentNode, $node instanceof CalloutNode, $node instanceof LimitMatchNode => null,
            // A class, a dot, a type, a property.
            default => $this->noFirst(true, true),
        };
    }

    private function repeat(QuantifierNode $node, int $options): void
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        $min = $bounds->min ?? 0;
        $this->item($node->node, $options);

        if (0 === $min || (null !== $bounds && 0 === $bounds->max)) {
            $this->first = $this->zeroFirst;
            $this->required = $this->zeroRequired;

            return;
        }

        if ($min < 2) {
            return;
        }

        $literal = $this->repeatedCodeUnit($node->node, $options);
        if (null !== $literal) {
            $this->required = $literal;
        } elseif (self::isGroup($node->node) && $this->groupSetFirst && $this->required[0] < 0) {
            $this->required = $this->first;
        }
    }

    /**
     * The code unit of a repeated literal of one code unit, which the repeat
     * requires; null for any other item.
     *
     * @return array{int, int, int}|null
     */
    private function repeatedCodeUnit(NodeInterface $node, int $options): ?array
    {
        $character = $this->characterOf($node);
        $caseless = 0 !== ($options & self::CASELESS);
        if (null !== $character && $caseless && $this->hasCaseSet($character, $options)) {
            return null;
        }

        if (null === $character && $node instanceof CharClassNode) {
            $character = $this->caseClass($node, $options);
            $caseless = true;
        }

        if (null === $character || 1 !== \strlen(Utf8::character($character, $this->unicode))) {
            return null;
        }

        return [$caseless ? self::CASELESS : 0, $character, $character];
    }

    /**
     * Reads a literal character. A class of a letter and its other case is
     * caseless whatever its case set.
     */
    private function character(int $codePoint, int $options, bool $readsCaseSet = true): void
    {
        $caseless = 0 !== ($options & self::CASELESS);
        if ($readsCaseSet && $caseless && $this->hasCaseSet($codePoint, $options)) {
            if (self::UNSET === $this->first[0]) {
                $this->first = $this->zeroFirst = self::NOT_SET;
            }

            return;
        }

        $encoded = $this->unicode ? Utf8::character($codePoint, true) : \chr($codePoint);
        $units = \strlen($encoded);
        $last = \ord($encoded[$units - 1]);
        $flags = $caseless ? self::CASELESS : 0;
        $known = 1 === $units || !$caseless;
        if (self::UNSET !== $this->first[0]) {
            $this->zeroFirst = $this->first;
            $this->zeroRequired = $this->required;
            if ($known) {
                $this->required = [$flags, $last, $codePoint];
            }

            return;
        }

        $this->zeroFirst = self::NOT_SET;
        $this->zeroRequired = $this->required;
        if (!$known) {
            $this->first = $this->required = self::NOT_SET;

            return;
        }

        $this->first = [$flags, \ord($encoded[0]), $codePoint];
        if ($units > 1) {
            $this->required = [0, $last, $codePoint];
        }
    }

    /**
     * Takes in the first and required code units of a group, or of an
     * assertion.
     *
     * @param array{array{int, int, int}, array{int, int, int}} $units
     */
    private function subpattern(array $units, bool $group, bool $lookahead): void
    {
        [$first, $required] = $units;
        $this->zeroRequired = $this->required;
        $this->zeroFirst = $this->first;
        $this->groupSetFirst = false;

        if (!$group) {
            if ($lookahead && $required[0] >= 0 && $first[0] >= 0) {
                $this->required = $required;
            }

            return;
        }

        if (self::UNSET === $this->first[0] && self::UNSET !== $first[0]) {
            $this->groupSetFirst = $first[0] >= 0;
            $this->first = $this->groupSetFirst ? $first : self::NOT_SET;
            $this->zeroFirst = self::NOT_SET;
        } elseif ($first[0] >= 0 && $required[0] < 0) {
            $required = $first;
        }

        if ($required[0] >= 0) {
            $this->required = $required;
        }
    }

    private function anchor(AnchorNode $node, int $options): void
    {
        if ('^' === $node->value && 0 !== ($options & self::MULTILINE) && self::UNSET === $this->first[0]) {
            $this->first = $this->zeroFirst = self::NOT_SET;
        }
    }

    private function verb(PcreVerbNode $node): void
    {
        if (str_starts_with($node->verb, 'ACCEPT')) {
            $this->accepts = true;
            if (self::UNSET === $this->first[0]) {
                $this->first = self::NOT_SET;
            }
        }
    }

    private function subroutine(): void
    {
        $this->groupSetFirst = false;
        if (self::UNSET === $this->first[0]) {
            $this->first = self::NOT_SET;
        }

        $this->zeroFirst = $this->first;
    }

    /**
     * An item that is no literal: when it reads a character, no first code
     * unit if none is set yet; and what a repeat of it that may match
     * nothing takes the code units back to.
     */
    private function noFirst(bool $reads, bool $saves): void
    {
        if ($reads && self::UNSET === $this->first[0]) {
            $this->first = self::NOT_SET;
            if (!$saves) {
                $this->zeroFirst = self::NOT_SET;
            }
        }

        if ($saves) {
            $this->zeroFirst = $this->first;
            $this->zeroRequired = $this->required;
        }
    }

    private static function isGroup(NodeInterface $node): bool
    {
        return $node instanceof ConditionalNode || $node instanceof ScriptRunNode
            || ($node instanceof GroupNode && !\in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true));
    }

    /**
     * The character of a literal of one character, an escape, or a positive
     * class of one character, in a class a range of one.
     */
    private function characterOf(NodeInterface $node): ?int
    {
        $character = self::literalCharacter($node, $this->unicode);
        if (null !== $character) {
            return $character;
        }

        if ($node instanceof CharClassNode && !$node->isNegated && !$node->expression instanceof AlternationNode) {
            return $this->characterOf($node->expression);
        }

        // A range of one character is that character.
        if ($node instanceof RangeNode) {
            $from = $this->characterOf($node->start);

            return null !== $from && $from === $this->characterOf($node->end) ? $from : null;
        }

        return null;
    }

    /**
     * The first character of a positive class of two, when the second is
     * its only other case: PCRE2 reads the class as that character,
     * caseless. A character with more than one other case is not taken,
     * unless r holds and both are ASCII.
     */
    private function caseClass(CharClassNode $node, int $options): ?int
    {
        if ($node->isNegated || !$node->expression instanceof AlternationNode || 2 !== \count($node->expression->alternatives)) {
            return null;
        }

        [$first, $second] = array_map($this->characterOf(...), array_values($node->expression->alternatives));
        if (null === $first || null === $second || $first === $second || $this->otherCase($first) !== $second) {
            return null;
        }

        $restricted = 0 !== ($options & self::RESTRICT) && $first < 0x80 && $second < 0x80;

        return $restricted || !$this->hasCaseSet($first, $options & ~self::RESTRICT, true) ? $first : null;
    }

    /**
     * Whether the character has more than one other case, which PCRE2
     * matches as a property when caseless under u. Under r a set holding an
     * ASCII character is not taken.
     *
     * @param bool $anyMode whether the set counts without u too, as for a class
     */
    private function hasCaseSet(int $codePoint, int $options, bool $anyMode = false): bool
    {
        if ($codePoint < 0x80) {
            return ($anyMode || $this->unicode) && 0 === ($options & self::RESTRICT) && \in_array($codePoint | 0x20, self::ASCII_CASE_SETS, true);
        }

        if (!$this->unicode) {
            return false;
        }

        $set = ClassSetProvider::query(\sprintf('\\x{%X}', $codePoint), true, 0 !== ($options & self::RESTRICT) ? 'ir' : 'i');
        $size = 0;
        foreach ($set->ranges ?? [] as [$from, $to]) {
            $size += $to - $from + 1;
        }

        return $size > 2;
    }

    /**
     * The other case PCRE2 pairs a character with: an ASCII letter's, or
     * under u the simple case mapping's; the character itself when it has
     * none.
     */
    private function otherCase(int $codePoint): int
    {
        if ($codePoint < 0x80) {
            $lower = $codePoint | 0x20;

            return $lower >= 0x61 && $lower <= 0x7A ? $codePoint ^ 0x20 : $codePoint;
        }

        if (!$this->unicode) {
            return $codePoint;
        }

        $character = Utf8::character($codePoint, true);
        foreach ([\MB_CASE_UPPER_SIMPLE, \MB_CASE_LOWER_SIMPLE] as $mode) {
            $other = mb_convert_case($character, $mode, 'UTF-8');
            if ($other !== $character) {
                return (int) mb_ord($other, 'UTF-8');
            }
        }

        return $codePoint;
    }

    /**
     * Whether the group only sets options for what follows, as "(?i)" does.
     */
    private function isBareOptionSetting(GroupNode $node): bool
    {
        $text = substr($this->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
        $length = \strlen($text);

        return $length >= 3 && str_starts_with($text, '(?') && str_ends_with($text, ')')
            && $length - 3 === strspn($text, '^-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 2, $length - 3);
    }

    /**
     * The options after a setting such as "i", "-i" or "^": i, m and r are
     * the ones a code unit depends on.
     */
    private static function apply(int $options, string $letters): int
    {
        if (str_starts_with($letters, '^')) {
            $options &= ~(self::CASELESS | self::MULTILINE | self::RESTRICT);
            $letters = substr($letters, 1);
        }

        $on = true;
        foreach (str_split($letters) as $letter) {
            $bit = match ($letter) {
                'i' => self::CASELESS,
                'm' => self::MULTILINE,
                'r' => self::RESTRICT,
                default => 0,
            };
            if ('-' === $letter) {
                $on = false;
            } elseif ($on) {
                $options |= $bit;
            } else {
                $options &= ~$bit;
            }
        }

        return $options;
    }
}
