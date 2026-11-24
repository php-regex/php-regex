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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Validation\OtherCases;

/**
 * Detects a useless 'i' flag: the pattern contains no character, class or
 * backreference whose case the flag decides. A class that holds the other
 * case of every byte it holds matches the same bytes with i, "[aA]"; a
 * node after an inline option that turns i off, or sets it again, reads
 * its case alike with the pattern's own i and without it: "a" in
 * "/(?-i)a/i" and in "/(?i)a/i".
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
        ];
    }

    public function begin(LintContext $context): void
    {
        $this->flagMatters = !$context->pattern->hasFlag('i');
        $this->flagsWithoutI = str_replace('i', '', $context->activeFlags());
        $this->unicodeMode = $context->pattern->unicodeMode;
        $this->unicodeCase = $this->unicodeMode
            || (str_contains(StartOptions::of($context->pattern->source), '(*UCP)') && $context->target->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf));
        // The target read the tables of its locale when it was made.
        $this->tables = CharacterTables::BUILT_IN === $context->target->locale ? null : CharacterTables::forLocale($context->target->locale);
        $this->target = $context->target;
        $this->otherCases = null;
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
     * text its group captured in either case.
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

        return $node instanceof BackrefNode;
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
        // capital and the Kelvin sign. Under (*UCP) a class reads ASCII from
        // Unicode up to 10.44 and from the locale's tables from 10.45.
        if ($this->unicodeMode || (null !== $this->tables && $this->unicodeCase)) {
            return false;
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
     * The other case of a byte, or the byte itself: from the locale's
     * tables, null where they leave it unsettled; of an ASCII letter; under
     * (*UCP), of a byte past ASCII from PCRE2's Unicode tables of the
     * target's release, where it is a byte. Those tables are not read for
     * ASCII, where they give the letters alone a case.
     */
    private function otherByte(int $byte): ?int
    {
        if (null !== $this->tables) {
            return $this->tables->settledOtherCase($byte);
        }

        if (!$this->unicodeCase || $byte < 0x80) {
            return CodePoints::isAsciiLetter($byte) ? $byte ^ 0x20 : $byte;
        }

        $other = OtherCases::of($this->target)->otherCase($byte);

        return 0 === $other >> 8 ? $other : $byte;
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

    private function unicodePropIsCaseSensitive(string $prop): bool
    {
        if ('' === $prop) {
            return false;
        }

        $normalized = $this->normalizeUnicodePropName($prop);

        return \in_array($normalized, [
            'lu',
            'll',
            'lt',
            'lc',
            'l&',
            'upper',
            'lower',
            'title',
            'uppercase_letter',
            'lowercase_letter',
            'titlecase_letter',
            'cased_letter',
        ], true);
    }

    private function normalizeUnicodePropName(string $prop): string
    {
        $normalized = ltrim($prop, '^');
        $normalized = trim($normalized, '{}');
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return Ascii::lower($normalized);
    }

    private function posixClassHasCaseSensitiveLetters(string $class): bool
    {
        $normalized = Ascii::lower(ltrim($class, '^'));

        return \in_array($normalized, ['upper', 'lower'], true);
    }
}
