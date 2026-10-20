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

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * The length the first pass of PCRE2 counts for a class of more than one
 * character that is not a letter and its other case: a map of 32 bytes
 * after its opcode, or, where it may match characters past 255 one by one
 * or holds a property, an extended class: its opcode, link and flags, the
 * map when it holds characters below 256, then its items and the end of
 * the list.
 *
 * Up to 10.44 each member adds its own items, which the first pass counts
 * even when the class needs none. From 10.45 the characters and ranges are
 * sorted and merged first, the cases a caseless class adds among them; six
 * ranges past 255 or more go in lists kept beside the code, counted against
 * the limit as well.
 *
 * The other cases of a caseless class come from the Unicode tables of the
 * release (OtherCases), under the case rules in force where it is written:
 * rules kept to ASCII leave "k" and "s" a letter and its other case, and
 * Turkish rules give "i" and "I" the dotted capital I and the dotless i.
 *
 * A length that depends on what is not known here is null: what the
 * locale PHP builds its tables from adds to a map that may then hold every
 * character, a class of more members than a measure goes through.
 *
 * @internal
 */
final class ClassLength
{
    /**
     * The characters of "\h" and "\v", as ranges.
     */
    private const SPACES = [
        'h' => [[0x09, 0x09], [0x20, 0x20], [0xA0, 0xA0], [0x1680, 0x1680], [0x180E, 0x180E], [0x2000, 0x200A], [0x202F, 0x202F], [0x205F, 0x205F], [0x3000, 0x3000]],
        'v' => [[0x0A, 0x0D], [0x85, 0x85], [0x2028, 0x2029]],
    ];

    /**
     * The POSIX names UCP turns into properties, as the parser of PCRE2
     * substitutes them; "graph", "print" and "punct" are properties too.
     * "xdigit" is one from 10.43, a map before; "blank" is "\h", "ascii" a
     * map.
     */
    private const POSIX_PROPERTIES = ['alpha', 'lower', 'upper', 'alnum', 'cntrl', 'digit', 'graph', 'print', 'punct', 'space', 'word'];

    /**
     * The characters below 256 each type and POSIX name matches in the
     * tables PCRE2 ships, which a locale may extend past ASCII.
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
     * The lists past 255 a class of six ranges or more keeps beside the
     * code, from the highest: where each starts and ends, and the bytes of
     * each item.
     */
    private const CHAR_LISTS = [[0x10000, 0x7FFFFFFF, 4], [0x8000, 0xFFFF, 2], [0x100, 0x7FFF, 2]];

    private const MAX_CODE_POINT = 0x10FFFF;

    /**
     * The characters of more than one other case, each set with its other
     * cases, the same in the Unicode tables of every release (pcre2_ucd.c,
     * ucd_caseless_sets, but for the Turkish casing ones).
     */
    private const CASELESS_SETS = [
        [0x0053, 0x0073, 0x017F],
        [0x01C4, 0x01C5, 0x01C6],
        [0x01C7, 0x01C8, 0x01C9],
        [0x01CA, 0x01CB, 0x01CC],
        [0x01F1, 0x01F2, 0x01F3],
        [0x0345, 0x0399, 0x03B9, 0x1FBE],
        [0x00B5, 0x039C, 0x03BC],
        [0x03A3, 0x03C2, 0x03C3],
        [0x0392, 0x03B2, 0x03D0],
        [0x0398, 0x03B8, 0x03D1, 0x03F4],
        [0x03A6, 0x03C6, 0x03D5],
        [0x03A0, 0x03C0, 0x03D6],
        [0x039A, 0x03BA, 0x03F0],
        [0x03A1, 0x03C1, 0x03F1],
        [0x0395, 0x03B5, 0x03F5],
        [0x0412, 0x0432, 0x1C80],
        [0x0414, 0x0434, 0x1C81],
        [0x041E, 0x043E, 0x1C82],
        [0x0421, 0x0441, 0x1C83],
        [0x0422, 0x0442, 0x1C84, 0x1C85],
        [0x042A, 0x044A, 0x1C86],
        [0x0462, 0x0463, 0x1C87],
        [0x1E60, 0x1E61, 0x1E9B],
        [0x03A9, 0x03C9, 0x2126],
        [0x004B, 0x006B, 0x212A],
        [0x00C5, 0x00E5, 0x212B],
        [0x1C88, 0xA64A, 0xA64B],
    ];

    /**
     * The Latin-1 characters whose other case is past 255: the micro sign
     * (one of its two), the sharp s and the y with a diaeresis.
     */
    private const OTHER_CASE_PAST_255 = [0xB5 => 0x3BC, 0xDF => 0x1E9E, 0xFF => 0x178];

    /**
     * Under Turkish case rules, the set of "i" and the dotted capital I, and
     * that of "I" and the dotless i, by each of them.
     */
    private const TURKISH_SETS = [0x69 => [0x69, 0x130], 0x130 => [0x69, 0x130], 0x49 => [0x49, 0x131], 0x131 => [0x49, 0x131]];

    /**
     * The characters past 255 that have a caseless set but no other case of
     * their own from 10.43, the long s and the Kelvin sign, then those only
     * Turkish rules give one, the dotted capital I and the dotless i.
     */
    private const CASED_WITHOUT_OTHER_CASE = [0x17F, 0x212A];

    private const CASED_UNDER_TURKISH_RULES = [0x130, 0x131];

    /**
     * The bytes of the lists the class last measured keeps beside the code:
     * a copy of the class in a repeated group shares them.
     */
    public int $charListBytes = 0;

    /**
     * The set of each character of CASELESS_SETS, once read.
     *
     * @var array<int, list<int>>|null
     */
    private static ?array $sets = null;

    /**
     * The other cases of the release, once a character past 255 needs them.
     */
    private ?OtherCases $cases = null;

    /**
     * Up to 10.44, the first and the last character of the member whose
     * other cases are added: an other case inside it adds nothing.
     *
     * @var array{0: int, 1: int}
     */
    private array $member = [0, 0];

    /**
     * @param bool       $utf          whether PCRE reads the pattern as UTF-8
     * @param bool       $ucp          whether types and POSIX names follow
     *                                 Unicode
     * @param bool       $turkish      whether Turkish case rules are set, from
     *                                 10.45
     * @param bool       $asciiOptions whether the source may hold an inline
     *                                 "a", which keeps types and POSIX names
     *                                 to ASCII
     * @param PcreTarget $target       the PCRE2 release whose first pass is
     *                                 measured
     */
    public function __construct(
        private readonly bool $utf,
        private readonly bool $ucp,
        private readonly bool $turkish,
        private readonly bool $asciiOptions,
        private readonly PcreTarget $target,
    ) {}

    /**
     * The length of the class in the code, without the lists it keeps
     * beside it (charListBytes), under rules kept to ASCII or not.
     */
    public function of(CharClassNode $node, bool $caseless, bool $restricted): ?int
    {
        $this->charListBytes = 0;
        $members = $this->membersOf($node);
        if (null === $members) {
            return null;
        }

        if (($this->ucp || !$this->utf) && $this->asciiOptions && [] !== array_filter($members, static fn (array $member): bool => 'type' === $member[0] || 'posix' === $member[0])) {
            return null;
        }

        return $this->target->supports(PcreFeature::ClassesCompiledFromMergedRanges)
            ? $this->merged($members, $node->isNegated, $caseless, $restricted)
            : $this->memberByMember($members, $node->isNegated, $caseless, $restricted);
    }

    /**
     * The other case of a character in the Unicode tables of the release,
     * or the character itself when it has none. Below 256 they are the same
     * in every release.
     */
    public function otherCase(int $codePoint): int
    {
        if ($codePoint < 0x100) {
            return self::OTHER_CASE_PAST_255[$codePoint] ?? self::otherCaseBelow256($codePoint, true) ?? $codePoint;
        }

        return ($this->cases ??= OtherCases::of($this->target))->otherCase($codePoint);
    }

    /**
     * Up to 10.44: each member writes its items past the map, counted as it
     * is written; an extended class is compiled when there are any, but for
     * a class holding a negated type without UCP and no property, all past
     * 255 being in it or out of it.
     *
     * @param list<array{0: string, 1: int|string, 2: int|bool}> $members
     */
    private function memberByMember(array $members, bool $negated, bool $caseless, bool $restricted): int
    {
        $items = 0;
        $map = false;
        $flip = false;
        $everyWideCharacter = false;
        $property = false;
        foreach ($members as [$kind, $first, $second]) {
            if ('range' === $kind) {
                \assert(\is_int($first) && \is_int($second));
                // A caseless member in UTF mode writes the other cases of its
                // characters, each member its own.
                if ($caseless && $this->utf) {
                    $this->member = [$first, $second];
                    [$units, $inMap] = $this->withOtherCasesWritten($first, $second, $restricted, true);
                    $items += $units;
                    $map = $map || $inMap;

                    continue;
                }

                $map = $map || $first <= 0xFF;
                if ($this->utf && $second > 0xFF) {
                    $items += self::itemUnits(max($first, 0x100), $second);
                }

                continue;
            }

            if ('property' === $kind) {
                $items += 3;
                $property = true;

                continue;
            }

            [$name, $negatedMember] = 'type' === $kind ? [Ascii::lower((string) $first), (string) $first !== Ascii::lower((string) $first)] : [(string) $first, (bool) $second];
            if ($this->ucp && ('type' === $kind ? \in_array($name, ['d', 's', 'w'], true) : (\in_array($name, self::POSIX_PROPERTIES, true) || ('xdigit' === $name && $this->target->supports(PcreFeature::AsciiOptions))))) {
                $items += 3;
                $property = true;

                continue;
            }

            $map = true;
            if ('blank' === $name && $this->ucp) {
                $name = 'h';
            }

            if ('h' === $name || 'v' === $name) {
                if ($this->utf) {
                    $ranges = $negatedMember ? self::complement(self::SPACES[$name]) : self::SPACES[$name];
                    foreach ($ranges as [$low, $high]) {
                        $items += $high > 0xFF ? self::itemUnits(max($low, 0x100), $high) : 0;
                    }
                }

                continue;
            }

            // A POSIX name sets whether the class flips, a negated type
            // only turns it on; under UCP a negated POSIX name makes every
            // character past 255 an item.
            if ('posix' === $kind) {
                $flip = $negatedMember;
            } elseif ($negatedMember) {
                $flip = true;
            }

            if ($negatedMember && $this->ucp) {
                $everyWideCharacter = $everyWideCharacter || $this->utf;
            }
        }

        if ($items > 0 && ($this->ucp || $property || !$flip)) {
            $wide = $everyWideCharacter || ($this->utf && $flip && !$negated && !$this->ucp) ? self::itemUnits(0x100, self::MAX_CODE_POINT) : 0;

            return $items + 4 + ($map ? 32 : 0) + $wide + 1;
        }

        return $items + 33;
    }

    /**
     * From 10.45: the characters, ranges and space types merged first, the
     * properties after; past 255, an item for each range, or lists beside
     * the code for six or more. A class of every byte compiles to a match
     * of any character, one past every character in UTF mode too, as does
     * one whose ranges reach past 255 to the last character.
     *
     * @param list<array{0: string, 1: int|string, 2: int|bool}> $members
     */
    private function merged(array $members, bool $negated, bool $caseless, bool $restricted): ?int
    {
        $ranges = [];
        $map = false;
        $flip = false;
        $properties = 0;
        $localeMaps = false;
        $complements = [];
        foreach ($members as [$kind, $first, $second]) {
            if ('range' === $kind) {
                \assert(\is_int($first) && \is_int($second));
                $map = $map || !$this->utf;
                array_push($ranges, ...($caseless ? $this->withOtherCases($first, $second, $restricted) : [[$first, $second]]));

                continue;
            }

            if ('property' === $kind) {
                // "\p{Any}" is every character, "\P{Any}" none.
                if ('any' === $first) {
                    if (!$second) {
                        $ranges[] = [0, $this->utf ? self::MAX_CODE_POINT : 0xFF];
                    }

                    continue;
                }

                $properties++;

                continue;
            }

            [$name, $negatedMember] = 'type' === $kind ? [Ascii::lower((string) $first), (string) $first !== Ascii::lower((string) $first)] : [(string) $first, (bool) $second];
            if ($this->ucp && ('type' === $kind ? \in_array($name, ['d', 's', 'w'], true) : (\in_array($name, self::POSIX_PROPERTIES, true) || 'xdigit' === $name))) {
                $properties++;

                continue;
            }

            $map = true;
            if ('blank' === $name && $this->ucp) {
                $name = 'h';
            }

            if ('h' === $name || 'v' === $name) {
                $spaces = $negatedMember ? self::complement(self::SPACES[$name]) : self::SPACES[$name];
                array_push($ranges, ...($this->utf ? $spaces : self::below256($spaces)));

                continue;
            }

            $localeMaps = true;
            $complements[$name][(int) $negatedMember] = true;
            $set = self::MAP_RANGES[$name] ?? [];
            array_push($ranges, ...self::below256($negatedMember ? self::complement($set, 0xFF) : $set));
            if ($negatedMember) {
                $flip = true;
                if ($this->utf) {
                    $ranges[] = [0x100, self::MAX_CODE_POINT];
                }
            }
        }

        $ranges = self::mergeRanges($ranges);
        // In UTF mode a range below 256 brings the map; without UTF the
        // members that set bits do, "\p{Any}" not.
        $map = $map || ($this->utf && [] !== $ranges && $ranges[0][0] <= 0xFF);
        $last = [] === $ranges ? null : $ranges[\count($ranges) - 1];
        $everyWide = $this->utf && null !== $last && self::MAX_CODE_POINT === $last[1] && $last[0] <= 0x100;
        $mapFromProperties = false;
        if ($everyWide) {
            // Every character past 255 is in the class: no properties are
            // written, and the map decides, which the characters below 256
            // of each property join.
            $flip = true;
            $mapFromProperties = $properties > 0;
            $properties = 0;
            $wide = [];
        } else {
            $wide = array_values(array_filter($ranges, static fn (array $range): bool => $range[1] > 0xFF));
        }

        if ($properties > 0 || [] !== $wide) {
            $length = 4 + ($map ? 32 : 0) + 3 * $properties;
            if (\count($wide) >= 6) {
                $this->charListBytes = self::charListBytes($ranges);

                return $length + 4;
            }

            foreach ($wide as [$low, $high]) {
                $length += self::itemUnits(max($low, 0x100), $high);
            }

            return $length + 1;
        }

        // A map of every byte, once a negated class is turned over, is a
        // match of any character: always without UTF, in UTF mode where the
        // class is negated once in all. What a type, a POSIX name or a
        // caseless letter without UTF matches past ASCII follows the locale
        // PHP builds its tables from: a class that holds all of ASCII may
        // hold every byte there. A negated one holds some ASCII already.
        if (!$this->utf || $negated !== $flip) {
            $bytes = 0;
            $ascii = 0;
            foreach ($ranges as [$low, $high]) {
                if ($low <= 0xFF) {
                    $bytes += min($high, 0xFF) - $low + 1;
                    $ascii += $low <= 0x7F ? min($high, 0x7F) - $low + 1 : 0;
                }
            }

            // A type or a POSIX name with its negation holds every byte,
            // whatever the locale.
            if ([] !== array_filter($complements, static fn (array $forms): bool => 2 === \count($forms))) {
                return $negated ? 33 : 1;
            }

            // What a property adds below 256 is not known here, unless the
            // map holds every byte already.
            if ($mapFromProperties) {
                return 256 === $bytes && !$localeMaps && !$negated ? 1 : null;
            }

            if ($negated) {
                return 0 === $bytes && !$localeMaps ? 1 : 33;
            }

            if (($localeMaps || ($caseless && !$this->utf)) && 0x80 === $ascii) {
                return 256 === $bytes && !$localeMaps ? 1 : null;
            }

            if (256 === $bytes) {
                return 1;
            }
        }

        return 33;
    }

    /**
     * The members of a class as ranges of characters, types, POSIX names
     * and properties, or null when it holds anything else, or more members
     * than a measure goes through.
     *
     * @return list<array{0: string, 1: int|string, 2: int|bool}>|null
     */
    private function membersOf(CharClassNode $node): ?array
    {
        $nodes = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        if (\count($nodes) > 256) {
            return null;
        }

        $members = [];
        foreach ($nodes as $member) {
            if ($member instanceof RangeNode) {
                $low = $this->codePointOf($member->start);
                $high = $this->codePointOf($member->end);
                if (null === $low || null === $high) {
                    return null;
                }

                $members[] = ['range', $low, $high];

                continue;
            }

            if ($member instanceof LiteralNode) {
                foreach ($this->utf ? mb_str_split($member->value, 1, 'UTF-8') : Bytes::split($member->value) as $character) {
                    if ('' !== $character) {
                        $codePoint = $this->utf ? (int) mb_ord($character, 'UTF-8') : \ord($character);
                        $members[] = ['range', $codePoint, $codePoint];
                    }
                }

                continue;
            }

            if ($member instanceof CharTypeNode) {
                if (!str_contains('dDsSwWhHvV', $member->value) || 1 !== \strlen($member->value)) {
                    return null; // PCRE refuses any other type in a class before any measure
                }

                $members[] = ['type', $member->value, false];

                continue;
            }

            if ($member instanceof PosixClassNode) {
                $name = Ascii::lower(ltrim($member->class, '^'));
                if (!isset(self::MAP_RANGES[$name])) {
                    return null; // PCRE refuses an unknown POSIX name before any measure
                }

                $members[] = ['posix', $name, str_starts_with($member->class, '^')];

                continue;
            }

            if ($member instanceof UnicodePropNode) {
                $any = 'any' === Ascii::lower(str_replace([' ', '_', '-', '^', '{', '}'], '', $member->prop));
                $members[] = ['property', $any ? 'any' : 'other', str_contains($member->prop, '^')];

                continue;
            }

            $codePoint = $this->codePointOf($member);
            if (null === $codePoint) {
                return null; // a character past the last one is refused before any measure
            }

            $members[] = ['range', $codePoint, $codePoint];
        }

        return $members;
    }

    private function codePointOf(NodeInterface $node): ?int
    {
        $codePoint = match (true) {
            $node instanceof LiteralNode && $this->utf && 1 === mb_strlen($node->value, 'UTF-8') => (int) mb_ord($node->value, 'UTF-8'),
            $node instanceof LiteralNode && !$this->utf && 1 === \strlen($node->value) => \ord($node->value),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $node->codePoint,
            default => null,
        };

        return null === $codePoint || $codePoint > ($this->utf ? self::MAX_CODE_POINT : 0xFF) ? null : $codePoint;
    }

    /**
     * Up to 10.44, in UTF mode: a caseless range as PCRE2 adds it to the
     * class, add_to_class() and get_othercase_range(). For each character
     * of more than one other case, each run of its set that does not start
     * on it, unless rules kept to ASCII leave out a set that starts in
     * ASCII; for each run of characters whose other cases follow one
     * another, the run of those other cases, which extends the range where
     * it touches it, adds nothing where it falls inside the member, and is
     * added on its own otherwise; then the range, an item past 255. The
     * units of its items past the map, and whether it sets a character of
     * the map.
     *
     * @return array{0: int, 1: bool}
     */
    private function withOtherCasesWritten(int $start, int $end, bool $restricted, bool $caseless): array
    {
        $units = 0;
        $map = false;
        $from = $start;
        while ($caseless && null !== $run = $this->otherCaseRun($from, $end, $restricted)) {
            [$from, $set, $low, $high] = $run;
            $added = [];
            if (null !== $set) {
                $added = self::runsOf($set, $low);
            } elseif ($low >= $this->member[0] && $high <= $this->member[1]) {
                continue;
            } elseif ($low < $start && $high >= $start - 1) {
                $start = $low;
            } elseif ($high > $end && $low <= $end + 1) {
                $end = $high;
            } else {
                $added[] = [$low, $high];
            }

            foreach ($added as [$first, $last]) {
                [$more, $inMap] = $this->withOtherCasesWritten($first, $last, $restricted, false);
                $units += $more;
                $map = $map || $inMap;
            }
        }

        // A run of other cases inside the member adds nothing.
        if ($start > $this->member[0] && $end < $this->member[1]) {
            return [$units, $map];
        }

        return [$units + ($end > 0xFF ? self::itemUnits(max($start, 0x100), $end) : 0), $map || $start <= 0xFF];
    }

    /**
     * Up to 10.44: from the first character from $from to $to that has
     * another case, where the range goes on, and either its caseless set,
     * unless rules kept to ASCII leave it out, or the run of the other cases
     * of it and of the characters after it whose other cases follow; null
     * when none has another case.
     *
     * @return array{0: int, 1: list<int>|null, 2: int, 3: int}|null
     */
    private function otherCaseRun(int $from, int $to, bool $restricted): ?array
    {
        for ($codePoint = $this->nextCased($from, $to); null !== $codePoint; $codePoint = $this->nextCased($codePoint + 1, $to)) {
            $set = self::sets()[$codePoint] ?? null;
            if (null !== $set && (!$restricted || $set[0] > 0x7F)) {
                return [$codePoint + 1, $set, $codePoint, $codePoint];
            }

            $low = $this->otherCase($codePoint);
            if ($low === $codePoint) {
                continue;
            }

            // A character of a caseless set ends the run.
            for ($high = $low; $codePoint < $to && !isset(self::sets()[$codePoint + 1]) && $this->otherCase($codePoint + 1) === $high + 1; $high++) {
                $codePoint++;
            }

            return [$codePoint + 1, null, $low, $high];
        }

        return null;
    }

    /**
     * From 10.45: a range and the other cases of its characters, as ranges.
     * In UTF mode, each character brings its caseless set, unless rules
     * kept to ASCII leave out a set that starts in ASCII, or its other case;
     * Turkish rules give "i" and the dotted capital I one set, "I" and the
     * dotless i another. Without UTF only the map: the other case of an
     * ASCII letter, under UCP of a Latin-1 one below 256.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function withOtherCases(int $low, int $high, bool $restricted): array
    {
        $ranges = [[$low, $high]];
        if (!$this->utf) {
            for ($codePoint = $low; $codePoint <= $high; $codePoint++) {
                $other = $codePoint < 0x80 || $this->ucp ? $this->otherCase($codePoint) : $codePoint;
                if ($other !== $codePoint && $other <= 0xFF) {
                    $ranges[] = [$other, $other];
                }
            }

            return $ranges;
        }

        for ($codePoint = $this->nextCased($low, $high); null !== $codePoint; $codePoint = $this->nextCased($codePoint + 1, $high)) {
            if ($this->turkish && !$restricted && isset(self::TURKISH_SETS[$codePoint])) {
                $others = self::TURKISH_SETS[$codePoint];
            } else {
                $set = self::sets()[$codePoint] ?? null;
                $others = null !== $set && (!$restricted || $set[0] > 0x7F) ? $set : [$this->otherCase($codePoint)];
            }

            foreach ($others as $other) {
                $ranges[] = [$other, $other];
            }
        }

        return $ranges;
    }

    /**
     * The first character from $from to $to that has another case or a
     * caseless set, or that Turkish rules give one, or null. Past 255 the
     * long s and the Kelvin sign are the only characters of a set without
     * another case of their own, from 10.43.
     */
    private function nextCased(int $from, int $to): ?int
    {
        for ($codePoint = $from; $codePoint <= min($to, 0xFF); $codePoint++) {
            if (isset(self::sets()[$codePoint]) || $this->otherCase($codePoint) !== $codePoint) {
                return $codePoint;
            }
        }

        if ($to <= 0xFF) {
            return null;
        }

        $from = max($from, 0x100);
        $next = ($this->cases ??= OtherCases::of($this->target))->nextCased($from) ?? \PHP_INT_MAX;
        foreach ($this->turkish ? [...self::CASED_UNDER_TURKISH_RULES, ...self::CASED_WITHOUT_OTHER_CASE] : self::CASED_WITHOUT_OTHER_CASE as $codePoint) {
            if ($codePoint >= $from && $codePoint < $next) {
                $next = $codePoint;
            }
        }

        return $next <= $to ? $next : null;
    }

    /**
     * The runs of consecutive characters of a caseless set, as PCRE2 adds
     * them to a class for one of them: that one left out where a run would
     * start on it, kept where a run started before holds it.
     *
     * @param list<int> $set
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function runsOf(array $set, int $except): array
    {
        $runs = [];
        $count = \count($set);
        for ($index = 0; $index < $count; $index++) {
            if ($set[$index] === $except) {
                continue;
            }

            $first = $set[$index];
            while ($index + 1 < $count && $set[$index] + 1 === $set[$index + 1]) {
                $index++;
            }

            $runs[] = [$first, $set[$index]];
        }

        return $runs;
    }

    /**
     * The other case below 256 of a character: an ASCII letter's, and in
     * UTF mode a Latin-1 letter's, but for the micro sign, the sharp s and
     * the y with a diaeresis, whose other cases are past 255.
     */
    private static function otherCaseBelow256(int $codePoint, bool $utf): ?int
    {
        if ($codePoint < 0x80) {
            $character = \chr($codePoint);

            return Ascii::isAlpha($character) ? \ord(Ascii::lower($character) === $character ? Ascii::upper($character) : Ascii::lower($character)) : null;
        }

        return match (true) {
            !$utf || 0xD7 === $codePoint || 0xF7 === $codePoint || 0xDF === $codePoint => null,
            $codePoint >= 0xC0 && $codePoint <= 0xDE => $codePoint + 0x20,
            $codePoint >= 0xE0 && $codePoint <= 0xFE => $codePoint - 0x20,
            default => null,
        };
    }

    /**
     * @return array<int, list<int>>
     */
    private static function sets(): array
    {
        if (null === self::$sets) {
            self::$sets = [];
            foreach (self::CASELESS_SETS as $set) {
                foreach ($set as $codePoint) {
                    self::$sets[$codePoint] = $set;
                }
            }
        }

        return self::$sets;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function mergeRanges(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [];
        $current = null;
        foreach ($ranges as [$low, $high]) {
            if (null !== $current && $current[1] + 1 >= $low) {
                $current = [$current[0], max($current[1], $high)];

                continue;
            }

            if (null !== $current) {
                $merged[] = $current;
            }

            $current = [$low, $high];
        }

        if (null !== $current) {
            $merged[] = $current;
        }

        return $merged;
    }

    /**
     * The characters a sorted list of ranges leaves out, up to $last.
     *
     * @param list<array{0: int, 1: int}> $ranges
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function complement(array $ranges, int $last = self::MAX_CODE_POINT): array
    {
        $complement = [];
        $next = 0;
        foreach ($ranges as [$low, $high]) {
            if ($low > $next) {
                $complement[] = [$next, $low - 1];
            }

            $next = $high + 1;
        }

        if ($next <= $last) {
            $complement[] = [$next, $last];
        }

        return $complement;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function below256(array $ranges): array
    {
        $below = [];
        foreach ($ranges as [$low, $high]) {
            if ($low <= 0xFF) {
                $below[] = [$low, min($high, 0xFF)];
            }
        }

        return $below;
    }

    /**
     * The bytes of the lists a class keeps beside the code for its ranges
     * past 255, as PCRE2 10.45 and later lay them out, rounded up to four:
     * in each list, an item for the end of each range and one for its start
     * where it starts inside the list, and a count once there are three.
     *
     * @param list<array{0: int, 1: int}> $ranges sorted and merged
     */
    private static function charListBytes(array $ranges): int
    {
        $index = \count($ranges) - 1;
        [$start, $end] = $ranges[$index];
        $bytes = 0;
        foreach (self::CHAR_LISTS as [$listStart, $listEnd, $size]) {
            $items = 0;
            while ($start >= $listStart) {
                if ($start === $end || $end < $listEnd) {
                    $items++;
                }

                if ($start < $end && $start > $listStart) {
                    $items++;
                }

                if ($index > 0) {
                    [$start, $end] = $ranges[--$index];

                    continue;
                }

                // No range is left.
                $start = $end = \PHP_INT_MIN;
            }

            if ($end >= $listStart && $end < $listEnd) {
                $items++;
            }

            $bytes += ($items >= 3 ? $items + 1 : $items) * $size;
            if ($start < 0x100) {
                break;
            }
        }

        return ($bytes + 3) & ~3;
    }

    /**
     * An item past 255: a tag, then the character, or the first and the
     * last of a range, in UTF-8.
     */
    private static function itemUnits(int $low, int $high): int
    {
        return 1 + ($low < $high ? self::utf8Length($low) : 0) + self::utf8Length($high);
    }

    private static function utf8Length(int $codePoint): int
    {
        return match (true) {
            $codePoint < 0x80 => 1,
            $codePoint < 0x800 => 2,
            $codePoint < 0x10000 => 3,
            default => 4,
        };
    }
}
