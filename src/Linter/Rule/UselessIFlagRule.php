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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Validation\OtherCases;

/**
 * Detects a useless 'i' flag: the pattern contains no case-sensitive
 * characters and no backreferences.
 *
 * Stateful: aggregates case-sensitivity facts during traversal and emits
 * in finish().
 *
 * @internal
 */
final class UselessIFlagRule extends AbstractLintRule
{
    public const ID = 'regex.lint.flag.useless.i';

    private bool $hasCaseSensitiveChars = false;

    private bool $hasBackreferences = false;

    private bool $trackCaseSensitivity = false;

    private bool $unicodeMode = false;

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
     * each character, read at the first character past ASCII under UTF:
     * null until then.
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
        $this->hasCaseSensitiveChars = false;
        $this->hasBackreferences = false;
        $this->trackCaseSensitivity = $context->pattern->hasFlag('i');
        $this->unicodeMode = $context->pattern->unicodeMode;
        // The target read the tables of its locale when it was made.
        $this->tables = CharacterTables::BUILT_IN === $context->target->locale ? null : CharacterTables::forLocale($context->target->locale);
        $this->target = $context->target;
        $this->otherCases = null;
    }

    public function check(NodeInterface $node, LintContext $context): array
    {
        if ($node instanceof BackrefNode) {
            $this->hasBackreferences = true;

            return [];
        }

        if (!$this->trackCaseSensitivity || $this->hasCaseSensitiveChars) {
            return [];
        }

        if ($node instanceof LiteralNode) {
            if ($this->stringHasCaseSensitiveLetters($node->value)) {
                $this->hasCaseSensitiveChars = true;
            }

            return [];
        }

        if ($node instanceof CharClassNode) {
            $expression = $node->expression;
            if ($expression instanceof AlternationNode) {
                foreach ($expression->alternatives as $alt) {
                    if ($this->charClassPartHasLetters($alt)) {
                        $this->hasCaseSensitiveChars = true;

                        break;
                    }
                }
            } elseif ($this->charClassPartHasLetters($expression)) {
                $this->hasCaseSensitiveChars = true;
            }

            return [];
        }

        if ($node instanceof CharLiteralNode) {
            if ($this->charLiteralHasCaseSensitiveLetter($node)) {
                $this->hasCaseSensitiveChars = true;
            }

            return [];
        }

        if ($node instanceof UnicodePropNode) {
            if ($this->unicodePropIsCaseSensitive($node->prop)) {
                $this->hasCaseSensitiveChars = true;
            }
        }

        return [];
    }

    public function finish(LintContext $context): array
    {
        if ($context->pattern->hasFlag('i') && !$this->hasCaseSensitiveChars && !$this->hasBackreferences) {
            return [new RuleViolation(
                self::ID,
                "Flag 'i' is useless: the pattern contains no case-sensitive characters.",
            )];
        }

        return [];
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
        // under UTF an ASCII character.
        for ($codePoint = $min; $codePoint <= $max && $this->tablesDecide($codePoint); $codePoint++) {
            if ($this->codePointHasCase($codePoint)) {
                return true;
            }
        }

        if (!$this->unicodeMode) {
            return false;
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
            // Another locale's tables may give a byte above ASCII a case.
            if (null !== $this->tables) {
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

        // Without UTF mode PCRE folds the case of ASCII letters only; with
        // it, of every character Unicode gives another case, a circled
        // letter or a Roman numeral included.
        if (!$this->unicodeMode) {
            return ($codePoint >= \ord('A') && $codePoint <= \ord('Z'))
                || ($codePoint >= \ord('a') && $codePoint <= \ord('z'));
        }

        return $this->tablesGiveCase($codePoint, $codePoint) || self::mapsToAnotherCase($codePoint);
    }

    /**
     * Whether PCRE2's own Unicode tables of the target's release give a
     * character from $low to $high another case: U+A7CE has one from
     * 10.48, which the mbstring of PHP 8.4 does not give it. They are not
     * read for ASCII alone, where they give the letters alone a case, which
     * the checks read without them.
     */
    private function tablesGiveCase(int $low, int $high): bool
    {
        if ($high < 0x80) {
            return false;
        }

        $this->otherCases ??= OtherCases::of($this->target);

        return [] !== $this->otherCases->between($low, $high);
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
     * byte without UTF, an ASCII character with it, where one above ASCII
     * takes Unicode's case.
     *
     * @phpstan-assert-if-true CharacterTables $this->tables
     */
    private function tablesDecide(int $codePoint): bool
    {
        return null !== $this->tables && $codePoint <= ($this->unicodeMode ? 0x7F : 0xFF);
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
