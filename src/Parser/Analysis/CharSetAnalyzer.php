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
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
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
    private readonly string $flags;

    /**
     * What the start options set, read once for the pattern: the newline
     * convention, whether "\R" takes CR and LF alone, and whether they turn
     * UTF, (*UCP), the caseless restriction or Turkish casing on.
     *
     * @var array{newline: string, anyCrlfBreaks: bool, utf: bool, ucp: bool, restricted: bool, turkish: bool}
     */
    private readonly array $options;

    private readonly bool $unicodeMode;

    private readonly bool $utf;

    private readonly bool $caseless;

    /**
     * Whether r keeps a character of ASCII and one above it from matching
     * each other caselessly: "k" and the Kelvin sign.
     */
    private readonly bool $restricted;

    /**
     * Whether "i" and "I" match the Turkish letters above ASCII caselessly
     * rather than each other: (*TURKISH_CASING), unless r holds.
     */
    private readonly bool $turkish;

    private readonly ByteCharSet $dot;

    /**
     * @param string    $startOptions    the options the pattern opens with, such
     *                                   as "(*CR)(*UTF)": the newline convention
     *                                   they set decides where the dot stops
     * @param bool      $readsAboveAscii whether the sets hold the characters above
     *                                   ASCII, read under PCRE2's own tables
     * @param self|null $samePattern     an analyzer of the same pattern, whose
     *                                   reading of the start options this one
     *                                   takes rather than read them again
     */
    public function __construct(
        string $flags = '',
        private readonly string $startOptions = '',
        private readonly bool $readsAboveAscii = false,
        ?self $samePattern = null
    ) {
        $this->options = null === $samePattern ? self::readStartOptions($startOptions) : $samePattern->options;
        $this->flags = null === $samePattern ? $this->restrictedFromTheStart($flags) : $flags;
        $this->unicodeMode = str_contains($flags, 'u') || $this->options['ucp'];
        $this->utf = str_contains($flags, 'u') || $this->options['utf'];
        $this->caseless = str_contains($flags, 'i');
        $this->restricted = str_contains($this->flags, 'r');
        $this->turkish = $this->options['turkish'] && !$this->restricted;
        $this->dot = $this->dotSet(str_contains($flags, 's'), $this->options['newline']);
    }

    /**
     * An analyzer for the pattern as a whole: its flags and the newline
     * convention its start options set.
     */
    public static function forRegex(RegexNode $regex, bool $readsAboveAscii = false): self
    {
        return new self($regex->flags, StartOptions::of($regex->source ?? ''), $readsAboveAscii);
    }

    /**
     * The analyzer in force at each node under $root, $root included, this
     * one at its start: an option setting changes the flags inside its
     * group, "(?i:...)", or after it up to the end of the group around it,
     * "(?i)", the alternatives after its own included.
     *
     * @return \SplObjectStorage<NodeInterface, self>
     */
    public function inForce(NodeInterface $root): \SplObjectStorage
    {
        /** @var \SplObjectStorage<NodeInterface, self> $inForce */
        $inForce = new \SplObjectStorage();
        $this->mapInForce($root, $inForce);

        return $inForce;
    }

    /**
     * The same pattern under other flags, such as the inline ones in effect
     * at a node.
     */
    public function withFlags(string $flags): self
    {
        return new self($this->restrictedFromTheStart($flags), $this->startOptions, $this->readsAboveAscii, $this);
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
                $at = 0;
            }

            while (!$fromStart && $this->readsAboveAscii && $this->utf && $at > 0 && Utf8::continues($node->value[$at])) {
                $at--;
            }

            $set = ByteCharSet::fromChar($node->value[$at]);

            return $this->caseless ? $set->union($this->otherCasesOfLiteral($node->value, $at)) : $set;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $this->fromCodePoints($node->codePoint, $node->codePoint);
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

            return $this->fromCodePoints(min($start, $end), max($start, $end));
        }

        if ($node instanceof CharClassNode) {
            $set = ByteCharSet::empty();

            $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
            foreach ($parts as $part) {
                // Without UTF a class reads each byte of a character
                // written in several apart: "[é]" is "[\xC3\xA9]".
                $candidate = $this->readsAboveAscii && !$this->utf && $part instanceof LiteralNode ? $this->eachByte($part->value) : $this->walk($part, $fromStart);
                $set = $set->union($candidate);
            }

            return $node->isNegated ? $this->complement($set) : $set;
        }

        // A lookaround reads nothing, whatever its body reads: "(?!x)a"
        // starts with "a", and "a(?=x)" ends with it.
        if ($node instanceof GroupNode) {
            return self::readsNothing($node) ? ByteCharSet::empty() : $this->inside($node)->walk($node->child, $fromStart);
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
            $analyzer = $this;
            foreach ($node->alternatives as $alt) {
                $set = $set->union($analyzer->walk($alt, $fromStart));
                $analyzer = $analyzer->after($alt);
            }

            return $set;
        }

        if ($node instanceof SequenceNode) {
            // From the start, the options an item sets carry to the next as
            // the walk goes; from the end, they are read up front.
            $analyzers = $fromStart ? [] : $this->acrossChildren($node->children);
            $children = $fromStart ? $node->children : array_reverse($node->children, true);
            $analyzer = $this;
            $set = ByteCharSet::empty();

            foreach ($children as $index => $child) {
                $candidate = ($analyzers[$index] ?? $analyzer)->walk($child, $fromStart);
                $set = $set->union($candidate);

                if (!$this->isOptionalNode($child, $fromStart)) {
                    break;
                }

                if ($fromStart && ($child instanceof GroupNode || $child instanceof SequenceNode)) {
                    $analyzer = $analyzer->after($child);
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
            'R' => $this->options['anyCrlfBreaks'] ? self::bytes("\n\r") : $this->verticalSpaces(),
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
     * "\v", and the line breaks "\R" takes, "\r\n" ending as "\n", unless
     * (*BSR_ANYCRLF) holds it to CR and LF.
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
     * The characters from $start to $end, and under i their other case;
     * above ASCII, not known unless the analyzer reads there. Without UTF a
     * character is a byte, and a code point above 0xFF none. Under UTF one
     * above ASCII is known by the byte it starts with, which UTF-8 orders as
     * the code points.
     */
    private function fromCodePoints(int $start, int $end): ByteCharSet
    {
        if ($end <= 0x7F) {
            $set = ByteCharSet::fromRange($start, $end);
        } elseif (!$this->readsAboveAscii || ($this->utf && $end > 0x10FFFF)) {
            return ByteCharSet::unknown();
        } elseif (!$this->utf) {
            $set = ByteCharSet::fromBytes($start, $end);
        } else {
            $ascii = $start <= 0x7F ? ByteCharSet::fromRange($start, 0x7F) : ByteCharSet::empty();
            $set = $ascii->union(ByteCharSet::fromBytes(self::leadByte(max($start, 0x80)), self::leadByte($end)));
        }

        return $this->caseless && !$set->isUnknown() ? $set->union($this->otherCases($start, $end)) : $set;
    }

    /**
     * The characters the characters from $start to $end match besides
     * themselves under i. An ASCII letter matches its other case. Above
     * ASCII, read under PCRE2's own tables, a byte has no other case; under
     * (*UCP) a Latin-1 letter has the other one where it is a byte, "\xC9"
     * for "\xE9". Under UTF a character above ASCII may match characters
     * that start with any byte, and "k" and "s" match the Kelvin sign and
     * the long s, both ways, unless r keeps ASCII apart. Under Turkish
     * casing "i" and "I" are no pair: "i" matches the capital dotted I,
     * U+0130, and "I" the small dotless i, U+0131, both ways.
     */
    private function otherCases(int $start, int $end): ByteCharSet
    {
        $set = ByteCharSet::empty();
        if ($start === $end && $end <= 0x7F) {
            // One ASCII character, the case of most literals.
            $set = Ascii::isAlpha(\chr($start)) ? ByteCharSet::fromChar(\chr($start ^ 0x20)) : $set;
        } else {
            foreach ([[\ord('A'), \ord('Z'), 0x20], [\ord('a'), \ord('z'), -0x20]] as [$first, $last, $shift]) {
                if (max($start, $first) <= min($end, $last)) {
                    $set = $set->union(ByteCharSet::fromRange(max($start, $first) + $shift, min($end, $last) + $shift));
                }
            }
        }

        if ($this->turkish) {
            $set = $set->intersect(self::bytes('iI')->complement(0xFF));
        }

        if (!$this->readsAboveAscii) {
            return $set;
        }

        if ($this->utf) {
            if (!$this->restricted) {
                foreach ([['k', "\u{212A}"], ['s', "\u{17F}"]] as [$letter, $partner]) {
                    if (self::holdsALetter($start, $end, $letter)) {
                        $set = $set->union(ByteCharSet::fromChar($partner));
                    }

                    if ($start <= mb_ord($partner, 'UTF-8') && mb_ord($partner, 'UTF-8') <= $end) {
                        $set = $set->union(self::bytes($letter.Ascii::upper($letter)));
                    }
                }
            }

            if ($this->turkish) {
                foreach ([['i', 0x130], ['I', 0x131]] as [$letter, $partner]) {
                    if ($start <= \ord($letter) && \ord($letter) <= $end) {
                        $set = $set->union(ByteCharSet::fromBytes(self::leadByte($partner), self::leadByte($partner)));
                    }

                    if ($start <= $partner && $partner <= $end) {
                        $set = $set->union(ByteCharSet::fromChar($letter));
                    }
                }
            }

            return $end > 0x7F ? $set->union(ByteCharSet::fromBytes(0x80, 0xFF)) : $set;
        }

        if ($this->unicodeMode) {
            for ($byte = max($start, 0x80); $byte <= min($end, 0xFF); $byte++) {
                $char = (string) mb_chr($byte, 'UTF-8');
                foreach ([\MB_CASE_UPPER_SIMPLE, \MB_CASE_LOWER_SIMPLE] as $case) {
                    $other = mb_ord(mb_convert_case($char, $case, 'UTF-8'), 'UTF-8');
                    if (false !== $other && $other <= 0xFF) {
                        $set = $set->union(ByteCharSet::fromBytes($other, $other));
                    }
                }
            }
        }

        return $set;
    }

    /**
     * Under i, the other case of the character a literal starts at $at,
     * the byte itself without UTF.
     */
    private function otherCasesOfLiteral(string $value, int $at): ByteCharSet
    {
        $codePoint = \ord($value[$at]);
        if ($codePoint > 0x7F && $this->readsAboveAscii && $this->utf) {
            $codePoint = mb_ord(substr($value, $at, Utf8::width($value, $at)), 'UTF-8');
        }

        return false === $codePoint ? ByteCharSet::unknown() : $this->otherCases($codePoint, $codePoint);
    }

    /**
     * Each byte of a literal a class reads without UTF, and under i its
     * other case.
     */
    private function eachByte(string $value): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach (Bytes::split($value) as $byte) {
            $set = $set->union($this->fromCodePoints(\ord($byte), \ord($byte)));
        }

        return $set;
    }

    private static function holdsALetter(int $start, int $end, string $letter): bool
    {
        foreach ([$letter, Ascii::upper($letter)] as $case) {
            if ($start <= \ord($case) && \ord($case) <= $end) {
                return true;
            }
        }

        return false;
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
     * The analyzer inside the group: a scoped option setting, "(?i:...)",
     * changes the flags there.
     */
    private function inside(GroupNode $group): self
    {
        return GroupType::InlineFlags === $group->type && null !== $group->flags && !self::setsOptionsOnly($group)
            ? $this->with($group->flags)
            : $this;
    }

    /**
     * The analyzer after the node: an option setting alone, "(?i)", changes
     * the flags for what follows it, as one at the top of a sequence does.
     */
    private function after(NodeInterface $node): self
    {
        if ($node instanceof GroupNode && self::setsOptionsOnly($node)) {
            return $this->with((string) $node->flags);
        }

        $analyzer = $this;
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $analyzer = $analyzer->after($child);
            }
        }

        return $analyzer;
    }

    /**
     * The analyzer at each child of a sequence whose start reads this one,
     * where an option setting before it changed the flags.
     *
     * @param array<int|string, NodeInterface> $children
     *
     * @return array<int|string, self>
     */
    private function acrossChildren(array $children): array
    {
        $analyzers = [];
        $analyzer = $this;
        foreach ($children as $index => $child) {
            if ($analyzer !== $this) {
                $analyzers[$index] = $analyzer;
            }

            // Only a group or a sequence may set options for what follows.
            if ($child instanceof GroupNode || $child instanceof SequenceNode) {
                $analyzer = $analyzer->after($child);
            }
        }

        return $analyzers;
    }

    /**
     * @param \SplObjectStorage<NodeInterface, self> $inForce
     */
    private function mapInForce(NodeInterface $node, \SplObjectStorage $inForce): void
    {
        $inForce[$node] = $this;
        if ($node instanceof GroupNode) {
            $this->inside($node)->mapInForce($node->child, $inForce);

            return;
        }

        $analyzer = $this;
        foreach ($node->getChildren() as $child) {
            $analyzer->mapInForce($child, $inForce);
            $analyzer = $analyzer->after($child);
        }
    }

    /**
     * The analyzer under the flags an option setting writes: "i", "s-i",
     * "^i". PCRE2's ASCII options ("a", "aD", ...) change nothing read here.
     */
    private function with(string $options): self
    {
        $setting = InlineFlags::read(InlineFlags::withoutAsciiOptions($options), InlineFlags::LETTERS.'r');

        return null === $setting ? $this : new self($setting->applyTo($this->flags), $this->startOptions, $this->readsAboveAscii, $this);
    }

    /**
     * The flags a pattern opens under: (*CASELESS_RESTRICT) holds from the
     * start as r does, and "(?-r)" or "(?^)" turns it off after.
     */
    private function restrictedFromTheStart(string $flags): string
    {
        return $this->options['restricted'] && !str_contains($flags, 'r') ? $flags.'r' : $flags;
    }

    /**
     * What the start options set, read once for the pattern: the analyzers
     * an option setting makes take it as read.
     *
     * @return array{newline: string, anyCrlfBreaks: bool, utf: bool, ucp: bool, restricted: bool, turkish: bool}
     */
    private static function readStartOptions(string $startOptions): array
    {
        $options = StartOptions::items($startOptions);
        // The last of (*BSR_ANYCRLF) and (*BSR_UNICODE) decides.
        $breaks = array_values(array_intersect($options, ['BSR_ANYCRLF', 'BSR_UNICODE']));

        return [
            'newline' => StartOptions::newline($startOptions),
            'anyCrlfBreaks' => 'BSR_ANYCRLF' === ($breaks[\count($breaks) - 1] ?? null),
            'utf' => \in_array('UTF', $options, true) || \in_array('UTF8', $options, true),
            'ucp' => \in_array('UCP', $options, true),
            'restricted' => \in_array('CASELESS_RESTRICT', $options, true),
            'turkish' => \in_array('TURKISH_CASING', $options, true),
        ];
    }

    /**
     * Whether the group is an option setting alone, "(?i)", rather than one
     * holding a body, "(?i:)", whose span its flags and three bytes tell.
     */
    private static function setsOptionsOnly(GroupNode $group): bool
    {
        return GroupType::InlineFlags === $group->type
            && null !== $group->flags
            && $group->getEndPosition() - $group->getStartPosition() === \strlen($group->flags) + 3;
    }

    /**
     * Whether the group is a lookaround, or the scan of a captured
     * substring, which the engine reads without taking a character.
     */
    private static function readsNothing(GroupNode $group): bool
    {
        return \in_array($group->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative, GroupType::ScanSubstring], true);
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

        // A repeat of an item that may match empty may too, whatever its
        // count: "(?=a){1}" reads nothing, and "(a?)+" may.
        if ($node instanceof QuantifierNode) {
            return $this->isOptional($node) || $this->isOptionalNode($node->node, $fromStart);
        }

        if ($node instanceof GroupNode) {
            return self::readsNothing($node) || $this->isOptionalNode($node->child, $fromStart);
        }

        if ($node instanceof ScriptRunNode) {
            return null !== $node->content && $this->isOptionalNode($node->content, $fromStart);
        }

        // An alternation may match empty through any of its branches:
        // "(?:|b)a" matches "a".
        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if ($this->isOptionalNode($alternative, $fromStart)) {
                    return true;
                }
            }

            return false;
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
