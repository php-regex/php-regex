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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\Rule\Support\CodePoints;
use PHPRegex\Parser\Analysis\GroupIndex as PcreGroups;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Validation\OtherCases;
use PHPRegex\Parser\Validation\PcreTables;

/**
 * Detects a useless 'i' flag: the pattern contains no character, class or
 * backreference whose case the flag decides. A class that holds the other
 * case of every character it holds matches the same characters with i,
 * "[aA]", and under UTF "[kK\x{212A}]", whose caseless set the Kelvin sign
 * completes; a reference to a group that captures nothing with a case
 * matches that text alone, "(1)\1"; a node after an inline option that
 * turns i off, or sets it again, reads its case alike with the pattern's
 * own i and without it: "a" in "/(?-i)a/i" and in "/(?i)a/i".
 *
 * Stateful: aggregates case-sensitivity facts during traversal and emits
 * in finish().
 *
 * @internal
 */
final class UselessIFlagRule extends AbstractLintRule
{
    public const ID = 'regex.lint.flag.useless.i';

    /**
     * Whether the flag decides the case of a node, or the pattern has none.
     */
    private bool $flagMatters = false;

    /**
     * The flags at the start of the pattern, less its own i: where an
     * inline option sets i again, they hold it as well.
     */
    private string $flagsWithoutI = '';

    private bool $unicodeMode = false;

    /**
     * Whether a character past ASCII has Unicode's case: under UTF, and
     * without it under (*UCP), which from PCRE2 10.35 gives a byte the case
     * of the character of the same value whatever the locale:
     * "/(*UCP)\xE9/i" matches "\xC9".
     */
    private bool $unicodeCase = false;

    /**
     * Whether UCP is set, by the u modifier or by "(*UCP)": a POSIX class
     * then reads as a Unicode property.
     */
    private bool $ucp = false;

    /**
     * The body of the pattern, as written.
     */
    private string $source = '';

    /**
     * Under another locale's tables than PCRE2's own, those tables, which
     * give the other case of each byte, "\xE9" and "\xC9" under Latin-1's:
     * null under PCRE2's own.
     */
    private ?CharacterTables $tables = null;

    /**
     * The PHP, PCRE2 and locale the pattern is read for.
     */
    private PcreTarget $target;

    /**
     * The other case PCRE2's Unicode tables of the target's release give
     * each character, read at the first character past ASCII under UTF or
     * (*UCP): null until then.
     */
    private ?OtherCases $otherCases = null;

    /**
     * Whether Turkish rules fold "i" with the dotted capital I and "I" with
     * the dotless small i, "(*TURKISH_CASING)".
     */
    private bool $turkish = false;

    /**
     * The groups of the pattern, numbered as PCRE2 numbers them: null where
     * the linter was given none.
     */
    private ?PcreGroups $groups = null;

    public function getRuleIds(): array
    {
        return [self::ID];
    }

    public function getNodeTypes(): array
    {
        return [
            LiteralNode::class,
            CharClassNode::class,
            CharLiteralNode::class,
            UnicodePropNode::class,
            BackrefNode::class,
            ExtendedCharClassNode::class,
        ];
    }

    public function begin(LintContext $context): void
    {
        $this->flagMatters = !$context->pattern->hasFlag('i');
        $this->flagsWithoutI = str_replace('i', '', $context->activeFlags());
        $this->unicodeMode = $context->pattern->unicodeMode;
        $this->source = $context->pattern->source;
        $ucpVerb = str_contains(StartOptions::of($this->source), '(*UCP)');
        $this->ucp = $ucpVerb || $context->pattern->hasFlag('u');
        $this->unicodeCase = $this->unicodeMode
            || ($ucpVerb && $context->target->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf));
        // The target read the tables of its locale when it was made.
        $this->tables = CharacterTables::BUILT_IN === $context->target->locale ? null : CharacterTables::forLocale($context->target->locale);
        $this->target = $context->target;
        $this->otherCases = null;
        $this->turkish = str_contains(StartOptions::of($context->pattern->source), '(*TURKISH_CASING)');
        $this->groups = $context->groups->pcre;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        // Where an inline option turns i off, the node reads its case as
        // written with the pattern's i and without it.
        if ($this->flagMatters || !str_contains($context->activeFlags(), 'i')) {
            return [];
        }

        $this->flagMatters = $this->hasCase($node) && !$this->setsIAgain($node, $context);

        return [];
    }

    public function finish(LintContext $context): array
    {
        if (!$this->flagMatters) {
            return [new RuleViolation(
                self::ID,
                "Flag 'i' is useless: the pattern contains no case-sensitive characters.",
            )];
        }

        return [];
    }

    /**
     * Whether i changes what the node matches: a reference matches the
     * text its group captured in either case, which has none where the
     * group captures no character with a case.
     */
    private function hasCase(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return $this->stringHasCaseSensitiveLetters($node->value);
        }

        if ($node instanceof CharClassNode) {
            return $this->classHasCase($node->expression);
        }

        if ($node instanceof CharLiteralNode) {
            return $this->charLiteralHasCaseSensitiveLetter($node);
        }

        if ($node instanceof UnicodePropNode) {
            return $this->unicodePropIsCaseSensitive($node->prop);
        }

        if ($node instanceof ExtendedCharClassNode) {
            return $this->holdsAPosixOperandWithCase($node->expression);
        }

        return $node instanceof BackrefNode && $this->referenceMayMatchCase($node);
    }

    /**
     * Whether a POSIX class i widens stands on its own among the operands
     * of an extended class, "(?[ [:upper:] - [A] ])": the classes, the
     * characters and the properties among them are read as the nodes they
     * are, each matching the same with i where it holds no case, and so
     * does any set built from them.
     */
    private function holdsAPosixOperandWithCase(NodeInterface $operand): bool
    {
        if ($operand instanceof PosixClassNode) {
            return $this->posixClassHasCaseSensitiveLetters($operand->class);
        }

        if ($operand instanceof ClassSetOperationNode) {
            foreach ($operand->getChildren() as $child) {
                if ($this->holdsAPosixOperandWithCase($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a group the reference names may capture a character with a
     * case: a reference no group answers is read as one that does.
     */
    private function referenceMayMatchCase(BackrefNode $node): bool
    {
        $groups = $this->groups?->groupsReferencedBy($node) ?? [];
        foreach ($groups as $group) {
            if ($this->mayCaptureCase($group)) {
                return true;
            }
        }

        return [] === $groups;
    }

    /**
     * Whether the node may match a character with a case: a literal or a
     * class with one, a negated class, any character, a type but the digits
     * and the white space under PCRE2's own tables, a reference or a call.
     * An anchor, an assertion or a comment matches no character; a
     * lookaround is read as a group.
     */
    private function mayCaptureCase(NodeInterface $node): bool
    {
        if ($node instanceof SequenceNode || $node instanceof AlternationNode || $node instanceof GroupNode || $node instanceof QuantifierNode) {
            foreach ($node->getChildren() as $child) {
                if ($this->mayCaptureCase($child)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof CharClassNode) {
            return $node->isNegated || $this->classMayMatchCase($node->expression);
        }

        if ($node instanceof CharTypeNode) {
            return !$this->typeHasNoCase($node);
        }

        if ($node instanceof LiteralNode || $node instanceof CharLiteralNode) {
            return $this->hasCase($node);
        }

        return !$node instanceof AnchorNode && !$node instanceof AssertionNode && !$node instanceof CommentNode;
    }

    /**
     * Whether a member of the class may be a character with a case: one
     * written, one of a range, or one a type, a POSIX class or a property
     * holds, but the digits and the white space under PCRE2's own tables.
     */
    private function classMayMatchCase(NodeInterface $expression): bool
    {
        foreach ($expression instanceof AlternationNode ? $expression->alternatives : [$expression] as $part) {
            $hasCase = match (true) {
                $part instanceof LiteralNode, $part instanceof CharLiteralNode, $part instanceof RangeNode => $this->charClassPartHasLetters($part),
                $part instanceof CharTypeNode => !$this->typeHasNoCase($part),
                $part instanceof PosixClassNode => null !== $this->tables || !\in_array(Ascii::lower($part->class), ['digit', 'space', 'blank', 'cntrl'], true),
                default => true,
            };
            if ($hasCase) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the type holds no character with a case: the digits, the
     * white space and the horizontal and vertical space, under PCRE2's own
     * tables or Unicode's, which give none of them a case.
     */
    private function typeHasNoCase(CharTypeNode $node): bool
    {
        return null === $this->tables && \in_array($node->value, ['d', 's', 'h', 'v'], true);
    }

    /**
     * Whether an inline option sets i again before the node, which then
     * reads its case alike without the pattern's own i: "(?i)" in
     * "/(?i)a/i".
     */
    private function setsIAgain(NodeInterface $node, LintContext $context): bool
    {
        return str_contains($context->flagsAt($node, $context->parents()[0] ?? $node, $this->flagsWithoutI), 'i');
    }

    /**
     * Whether the class matches other characters with i than without it:
     * a member has a case, and the class does not hold the other case of
     * every byte it holds.
     */
    private function classHasCase(NodeInterface $expression): bool
    {
        $parts = $expression instanceof AlternationNode ? $expression->alternatives : [$expression];
        foreach ($parts as $part) {
            if ($this->charClassPartHasLetters($part)) {
                return !$this->holdsEveryOtherCase($parts);
            }
        }

        return false;
    }

    /**
     * Whether, without UTF, the class holds the other case of every byte
     * it holds, the one a caseless class adds to it: "[aA]", and under
     * (*UCP) "[\xC9\xE9]" or "[\xC0-\xFE]", where the multiplication sign,
     * the division sign and the sharp s have none that is a byte. A type
     * matches the same bytes with i, and is read apart: "[aA\d]" holds
     * every other case, "[aA[:upper:]]" does not, which i turns into
     * "[aA[:alpha:]]".
     *
     * @param array<NodeInterface> $parts
     */
    private function holdsEveryOtherCase(array $parts): bool
    {
        // Under UTF a character may have several other cases, "k" the
        // capital and the Kelvin sign. A class of two characters may
        // compile to one matched caselessly, which reads ASCII from another
        // locale's tables, and Turkish rules fold "i" otherwise.
        if ($this->unicodeMode) {
            return null === $this->tables && !$this->turkish && $this->holdsEveryCaselessSet($parts);
        }

        $bytes = [];
        foreach ($parts as $part) {
            if ($part instanceof LiteralNode) {
                $bytes += count_chars($part->value, 1);
            } elseif ($part instanceof RangeNode || $part instanceof CharLiteralNode) {
                $low = $this->endpoint($part instanceof RangeNode ? $part->start : $part);
                $high = $this->endpoint($part instanceof RangeNode ? $part->end : $part);
                // PCRE2 refuses a code point past a byte without UTF.
                if (null === $low || null === $high || max($low, $high) > 0xFF) {
                    return false;
                }

                $bytes += array_flip(range($low, $high));
            } elseif ($this->charClassPartHasLetters($part)) {
                return false;
            }
        }

        foreach (array_keys($bytes) as $byte) {
            $other = $this->otherByte($byte);
            if (null === $other || !isset($bytes[$other])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether, under UTF, the class holds every character a caseless class
     * adds to one it holds: its caseless set, "K", "k" and the Kelvin sign
     * for "k", or its other case, from PCRE2's Unicode tables of the
     * target's release. A type, a POSIX class or a property without a case
     * matches the same characters with i, and is read apart.
     *
     * @param array<NodeInterface> $parts
     */
    private function holdsEveryCaselessSet(array $parts): bool
    {
        // The ends of each member, an end no character gives leaving the
        // class unread.
        $ends = [];
        foreach ($parts as $part) {
            if ($part instanceof LiteralNode) {
                foreach (mb_str_split($part->value, 1, 'UTF-8') as $char) {
                    $ends[] = [mb_ord($char, 'UTF-8'), mb_ord($char, 'UTF-8')];
                }
            } elseif ($part instanceof RangeNode || $part instanceof CharLiteralNode) {
                $ends[] = [$this->endpoint($part instanceof RangeNode ? $part->start : $part), $this->endpoint($part instanceof RangeNode ? $part->end : $part)];
            } elseif ($this->charClassPartHasLetters($part)) {
                $ends[] = [null, null];
            }
        }

        $ranges = [];
        foreach ($ends as [$low, $high]) {
            if (!\is_int($low) || !\is_int($high)) {
                return false;
            }

            $ranges[] = [min($low, $high), max($low, $high)];
        }

        $this->otherCases ??= OtherCases::of($this->target);
        $sets = PcreTables::caselessSets();
        foreach ($ranges as [$low, $high]) {
            $cased = array_keys($this->otherCases->between($low, $high));
            foreach (array_keys($sets) as $codePoint) {
                if ($low <= $codePoint && $codePoint <= $high) {
                    $cased[] = $codePoint;
                }
            }

            foreach ($cased as $codePoint) {
                foreach ($sets[$codePoint] ?? [$this->otherCases->otherCase($codePoint)] as $other) {
                    if (!self::inRanges($other, $ranges)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param list<array{int, int}> $ranges
     */
    private static function inRanges(int $codePoint, array $ranges): bool
    {
        foreach ($ranges as [$low, $high]) {
            if ($low <= $codePoint && $codePoint <= $high) {
                return true;
            }
        }

        return false;
    }

    /**
     * The other case of a byte, or the byte itself: under (*UCP), of a
     * byte past ASCII from PCRE2's Unicode tables of the target's release,
     * where it is a byte, whatever the locale's tables give; of an ASCII
     * letter; from the locale's tables, null where they leave it unsettled.
     * Under (*UCP) a class reads ASCII from Unicode up to 10.44 and from
     * the tables from 10.45: an ASCII byte they pair otherwise than Unicode
     * is unsettled there.
     */
    private function otherByte(int $byte): ?int
    {
        if ($this->unicodeCase && $byte >= 0x80) {
            $other = OtherCases::of($this->target)->otherCase($byte);

            return 0 === $other >> 8 ? $other : $byte;
        }

        $ascii = CodePoints::isAsciiLetter($byte) ? $byte ^ 0x20 : $byte;
        if (null === $this->tables) {
            return $ascii;
        }

        $other = $this->tables->settledOtherCase($byte);

        return !$this->unicodeCase || $other === $ascii ? $other : null;
    }

    private function charClassPartHasLetters(NodeInterface $node): bool
    {
        if ($node instanceof LiteralNode) {
            return $this->stringHasCaseSensitiveLetters($node->value);
        }

        if ($node instanceof CharLiteralNode) {
            return $this->charLiteralHasCaseSensitiveLetter($node);
        }

        if ($node instanceof UnicodePropNode) {
            return $this->unicodePropIsCaseSensitive($node->prop);
        }

        if ($node instanceof PosixClassNode) {
            return $this->posixClassHasCaseSensitiveLetters($node->class);
        }

        if ($node instanceof RangeNode) {
            return $this->rangeHasLetters($node);
        }

        // Other types like CharTypeNode are case-insensitive by design.
        return false;
    }

    private function rangeHasLetters(RangeNode $node): bool
    {
        $start = $this->endpoint($node->start);
        $end = $this->endpoint($node->end);

        // An endpoint not read leaves the range unknown: it may hold
        // letters, so the flag is not called useless on its account.
        if (null === $start || null === $end) {
            return true;
        }

        $min = min($start, $end);
        $max = max($start, $end);

        if ($this->rangeHasAsciiLetters($min, $max)) {
            return true;
        }

        // Another locale's tables may give a byte of the range a case, or
        // under UTF or (*UCP) an ASCII character.
        for ($codePoint = $min; $codePoint <= $max && $this->tablesDecide($codePoint); $codePoint++) {
            if ($this->codePointHasCase($codePoint)) {
                return true;
            }
        }

        // Without UTF, (*UCP) reads every byte of the range past ASCII from
        // PCRE2's tables: "[\xD7-\xF7]" holds "\xD8" to "\xF6".
        if (!$this->unicodeMode) {
            return $this->unicodeCase && $this->tablesGiveCase($min, $max);
        }

        // Under UTF every character of the range is read from PCRE2's
        // tables, not its ends alone: "[×-÷]" holds Ø to ö. mbstring reads
        // the ends, as it reads a character on its own: past an end, the
        // long s and the Kelvin sign come with "ž" and "Å", which the
        // tables give another case.
        return $this->tablesGiveCase($min, $max)
            || self::mapsToAnotherCase($start) || self::mapsToAnotherCase($end);
    }

    /**
     * The code point of a range endpoint: "\cA" is \x01, as PCRE reads it.
     */
    private function endpoint(NodeInterface $node): ?int
    {
        return $node instanceof ControlCharNode ? $node->codePoint : CodePoints::fromNode($node, $this->unicodeMode);
    }

    private function rangeHasAsciiLetters(int $min, int $max): bool
    {
        return ($min <= \ord('Z') && $max >= \ord('A'))
            || ($min <= \ord('z') && $max >= \ord('a'));
    }

    private function stringHasCaseSensitiveLetters(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        if (LibraryPcre::match('/[A-Za-z]/', $value) > 0) {
            return true;
        }

        if (!$this->unicodeMode) {
            // Another locale's tables may give a byte above ASCII a case,
            // and (*UCP) Unicode's.
            if (null !== $this->tables || $this->unicodeCase) {
                foreach (Bytes::split($value) as $byte) {
                    if ($this->codePointHasCase(\ord($byte))) {
                        return true;
                    }
                }
            }

            return false;
        }

        $chars = LibraryPcre::split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars) {
            return false;
        }

        foreach ($chars as $char) {
            $codePoint = mb_ord($char, 'UTF-8');
            if (false !== $codePoint && $this->codePointHasCase($codePoint)) {
                return true;
            }
        }

        return false;
    }

    private function charLiteralHasCaseSensitiveLetter(CharLiteralNode $node): bool
    {
        $codePoint = $node->codePoint;
        if ($codePoint < 0) {
            $codePoint = CodePoints::parseUnicodeEscape($node->originalRepresentation) ?? $codePoint;
        }

        if ($codePoint >= 0) {
            return $this->codePointHasCase($codePoint);
        }

        if (1 === \strlen($node->originalRepresentation)) {
            return $this->stringHasCaseSensitiveLetters($node->originalRepresentation);
        }

        return false;
    }

    private function codePointHasCase(int $codePoint): bool
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF) {
            return false;
        }

        // A character they decide has a case where they pair it with
        // another, or where the engine's paths or releases read it otherwise.
        if ($this->tablesDecide($codePoint)) {
            return $codePoint !== $this->tables->settledOtherCase($codePoint);
        }

        // Without UTF mode PCRE folds the case of ASCII letters only, and
        // under (*UCP) of a byte past ASCII PCRE2's tables pair with another
        // byte; with it, of every character Unicode gives another case, a
        // circled letter or a Roman numeral included.
        if (!$this->unicodeMode) {
            return ($codePoint >= \ord('A') && $codePoint <= \ord('Z'))
                || ($codePoint >= \ord('a') && $codePoint <= \ord('z'))
                || ($this->unicodeCase && $this->tablesGiveCase($codePoint, $codePoint));
        }

        return $this->tablesGiveCase($codePoint, $codePoint) || self::mapsToAnotherCase($codePoint);
    }

    /**
     * Whether PCRE2's own Unicode tables of the target's release give a
     * character from $low to $high another case: U+A7CE has one from
     * 10.48, which the mbstring of PHP 8.4 does not give it. They are not
     * read for ASCII alone, where they give the letters alone a case, which
     * the checks read without them.
     *
     * Without UTF, under (*UCP), the other case is a byte too: a subject
     * holds bytes alone, and "\xB5", "\xDF" and "\xFF", whose other cases
     * are U+039C, U+1E9E and U+0178, match no other byte.
     */
    private function tablesGiveCase(int $low, int $high): bool
    {
        if ($high < 0x80) {
            return false;
        }

        $this->otherCases ??= OtherCases::of($this->target);
        $otherCases = $this->otherCases->between($low, $high);

        // The least other case is a byte where any is.
        return [] !== $otherCases && ($this->unicodeMode || 0 === min($otherCases) >> 8);
    }

    /**
     * Whether mbstring maps the character to another case, by a full
     * mapping too: "ŉ" to "ʼN", which PCRE2 does not fold. It maps the long
     * s and the Kelvin sign, which PCRE2 folds with "s" and "k" though its
     * tables give them no other case from 10.43, and "İ" and "ı", which it
     * folds with "i" and "I" under Turkish rules.
     */
    private static function mapsToAnotherCase(int $codePoint): bool
    {
        $char = mb_chr($codePoint, 'UTF-8');
        if (false === $char) {
            return false;
        }

        return mb_strtoupper($char, 'UTF-8') !== $char
            || mb_strtolower($char, 'UTF-8') !== $char;
    }

    /**
     * Whether another locale's tables decide the case of the character: a
     * byte without UTF or (*UCP), an ASCII character with either, where one
     * above ASCII takes Unicode's case.
     *
     * @phpstan-assert-if-true CharacterTables $this->tables
     */
    private function tablesDecide(int $codePoint): bool
    {
        return null !== $this->tables && $codePoint <= ($this->unicodeCase ? 0x7F : 0xFF);
    }

    /**
     * Whether i changes what the property matches: "\p{Lu}", "\p{Ll}" and
     * "\p{Lt}" match every cased letter under i from 10.45, as written
     * before. "\p{Lc}", "\p{L&}", "\p{Upper}" and "\p{Lower}" match as
     * written on every release.
     */
    private function unicodePropIsCaseSensitive(string $prop): bool
    {
        if ('' === $prop) {
            return false;
        }

        return \in_array($this->normalizeUnicodePropName($prop), ['lu', 'll', 'lt'], true)
            && $this->target->supports(PcreFeature::CaselessCasePropertiesMatchCasedLetters);
    }

    /**
     * The name of a property without its braces and its negation, "\P{Lu}"
     * and "\p{^Lu}" read as "lu".
     */
    private function normalizeUnicodePropName(string $prop): string
    {
        $normalized = ltrim(trim($prop, '{}'), '^');
        $normalized = str_replace(['-', ' ', '_'], '', $normalized);

        return Ascii::lower($normalized);
    }

    /**
     * Whether i changes what the POSIX class matches: "[:upper:]" and
     * "[:lower:]" match the letters under i where the tables are read for
     * them. Under UCP they read as "\p{Lu}" and "\p{Ll}", which i leaves
     * as written in a class on every release, the conversion of 10.45 to
     * every cased letter reading a property written as one alone. An
     * ASCII option, "(?aP)" or "(?a)" from 10.43, gives the POSIX classes
     * back to the tables: a pattern that sets any keeps the flag, the
     * options in force at the class not being told apart. After "(?^" the
     * pattern's own i holds no more.
     */
    private function posixClassHasCaseSensitiveLetters(string $class): bool
    {
        $normalized = Ascii::lower(ltrim($class, '^'));

        return \in_array($normalized, ['upper', 'lower'], true)
            && (!$this->ucp || 1 === LibraryPcre::match('/\(\?[a-zA-Z-]*a/', $this->source));
    }
}
