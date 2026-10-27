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

namespace PHPRegex\Parser\Lexing;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\Syntax\GroupNameReader;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenType;

/**
 * How PCRE judges the name of a verb where it stands, and where a name or
 * the condition of "(?(" is due, which decides whether a verb is read at
 * all.
 *
 * It reads the text, the tokens read before the verb, and whether "x" is in
 * force there, under which white space and line comments are nothing; it
 * keeps nothing, and refuses a name by throwing the error PCRE reports, at
 * the offset PCRE reports it.
 *
 * @internal
 */
final class VerbNames
{
    /**
     * PCRE reads the name of a verb as it reads a group name, and refuses
     * one too long as one. PCRE2 10.32 reads it as a run of letters and wants
     * a ":" or a ")" right after it; it refuses a name that starts with a
     * digit, or that runs past 32 letters, as a group name. As the condition
     * of "(?(" it reads no verb: it wants a group name on the "*". A setting
     * the pattern opens with is no verb, and is read before.
     *
     * @param string      $name     the verb as written between "(*" and ")", "NAME:arg",
     *                              or up to the first character no name holds
     * @param list<Token> $tokens   the tokens read before it
     * @param bool        $extended whether "x" is in force where the verb stands
     */
    public static function refuse(PatternText $text, string $name, int $start, array $tokens, bool $extended): void
    {
        if ('' === $name || self::followsNameOpener($text, $start)) {
            return;
        }

        // As the condition, 10.32 wants a name on the "*" right after "(?(";
        // after a callout the condition must be an assertion, which the
        // parser refuses on the "(" of the verb, before its name is read.
        // What PCRE reads as nothing between them, a comment, "\E", "\Q\E"
        // or under "x" a space or a line comment, does not count.
        $at = self::nothingReadBefore($text, $start, $extended);
        while ([] !== $tokens && $tokens[\count($tokens) - 1]->position >= $at) {
            array_pop($tokens);
        }

        $condition = self::opensCondition($tokens, $at);
        if (!$text->syntax->readsAlphabeticAssertions && $condition) {
            $previous = $tokens[\count($tokens) - 1] ?? null;
            if (null !== $previous && TokenType::GroupModifierOpen === $previous->type && $at === $start) {
                throw self::conditionWantsAName($start + 1, $text->pattern);
            }

            return;
        }

        // From 10.33 the name is the run of word characters, held to the
        // length of a group name, and refused where it ends.
        if ($text->syntax->namesReadWhole) {
            $length = strspn($name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_');
            // As the condition, only an alphabetic name is read before the
            // parser refuses what is no assertion.
            if (!$condition || 1 === LibraryPcre::match('/^[a-z]/', $name)) {
                self::refuseTooLong($text, $length, $start);
            }

            // As the condition, the parser refuses it on its "(".
            if (!$condition) {
                self::refuseSettingWithArgument($text, $name, $length, $start);
                self::refuseAlphabeticNameEndingThePattern($text, $name, $length, $start);
            }

            return;
        }

        // A limit that ends the opening settings is read as one, whatever
        // its value: the settings reader refuses a value it cannot take.
        $settingsEnd = self::leadingSettingsEnd($text);
        if ($start < $settingsEnd || ($start === $settingsEnd && 1 === LibraryPcre::match('/^LIMIT_(?:MATCH|DEPTH|HEAP|RECURSION)=/', $name))) {
            return;
        }

        if (Ascii::isDigit($name[0])) {
            throw LexerException::withContext(
                \sprintf('Invalid verb name at position %d: PCRE2 before 10.33 reads it as a group name, which must not start with a digit.', $start + 2),
                ErrorCode::GroupNameInvalid,
                $start + 2,
                $text->pattern,
            );
        }

        $letters = strspn($name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
        self::refuseLongLetterRun($text, $letters, $start);

        $next = $name[$letters] ?? '';
        if (':' !== $next && '' !== $next) {
            throw LexerException::withContext(
                \sprintf('Invalid or unsupported PCRE verb at position %d: PCRE2 before 10.33 reads a verb name as letters only.', $start + 2 + $letters),
                ErrorCode::VerbInvalid,
                $start + 2 + $letters,
                $text->pattern,
            );
        }

        self::refuseSettingWithArgument($text, $name, $letters, $start);
    }

    /**
     * Where the run of what PCRE reads as nothing that ends at $position
     * starts: "(?#...)" comments, "\E" and "\Q\E", and under "x" white
     * space and line comments.
     *
     * @param bool $extended whether "x" is in force there
     */
    public static function nothingReadBefore(PatternText $text, int $position, bool $extended): int
    {
        $pattern = $text->pattern;
        do {
            $before = $position;
            // Nothing stands before the start: a negative offset would read
            // the end of the pattern.
            $previous = $position > 0 ? $pattern[$position - 1] : '';
            if ($extended && '' !== $previous && '' === trim($previous, " \t\n\r\f\v")) {
                // A space, or the line break that ends a "#" comment.
                $position--;
                if ("\n" === $previous) {
                    $lineStart = self::lastBefore($text, "\n", $position) + 1;
                    $hash = strpos(substr($pattern, $lineStart, $position - $lineStart), '#');
                    $position = false === $hash ? $position : $lineStart + $hash;
                }
            } elseif (')' === $previous) {
                // A "(?#...)" comment holds no ")": it opens past the one before.
                $from = self::lastBefore($text, ')', $position - 1) + 1;
                $open = strpos(substr($pattern, $from, $position - 1 - $from), '(?#');
                $position = false === $open ? $position : $from + $open;
            } elseif ('E' === $previous && 1 === strspn(strrev(substr($pattern, max(0, $position - 65), min($position - 1, 64))), '\\') % 2) {
                $position -= '\\Q' === substr($pattern, max(0, $position - 4), 2) && $position >= 4 ? 4 : 2;
            }
        } while ($position !== $before);

        return $position;
    }

    /**
     * From PCRE2 10.33 a verb name is held to the length of a group name,
     * and refused as one too long where it ends.
     *
     * @param int $length the length of the name
     * @param int $start  the offset of the "(" of the verb
     */
    public static function refuseTooLong(PatternText $text, int $length, int $start): void
    {
        $longest = GroupNameReader::maxNameLength($text->syntax->target);
        if ($length > $longest) {
            throw LexerException::withContext(
                \sprintf('Verb name too long at position %d: PCRE takes at most %d characters.', $start + 2 + $length, $longest),
                ErrorCode::GroupNameTooLong,
                $start + 2 + $length,
                $text->pattern,
            );
        }
    }

    /**
     * PCRE2 10.32 refuses a verb name of more than 32 letters at the 33rd,
     * as a group name too long.
     */
    public static function refuseLongLetterRun(PatternText $text, int $letters, int $start): void
    {
        // PCRE2 10.32 reads a verb name as the 32 letters a group name holds.
        if ($letters > GroupNameReader::MAX_NAME_LENGTH_BEFORE_PCRE_1044) {
            $offset = $start + 2 + GroupNameReader::MAX_NAME_LENGTH_BEFORE_PCRE_1044 + 1;

            throw LexerException::withContext(
                \sprintf('Verb name too long at position %d: PCRE2 before 10.33 takes at most 32 letters.', $offset),
                ErrorCode::GroupNameTooLong,
                $offset,
                $text->pattern,
            );
        }
    }

    /**
     * Whether $position stands where PCRE reads a name: after "(?<", "(?'",
     * "(?P<", a call by name, "(?&" or "(?P>", a reference, "(?P=", or the
     * name of a condition, "(?(", "(?(<", "(?('", "(?(R&", and the start of
     * a name, which a "(" ends.
     */
    public static function followsNameOpener(PatternText $text, int $position): bool
    {
        $window = min($position, 2 * GroupNameReader::MAX_NAME_LENGTH);
        $before = substr($text->pattern, $position - $window, $window);

        return 1 === LibraryPcre::match('/\(\?(?:[<\'&]|P[<>=]|\((?:[<\']|R&)?)\w*+\z/', $before);
    }

    public static function conditionWantsAName(int $offset, string $pattern): LexerException
    {
        return LexerException::withContext(
            \sprintf('Invalid conditional construct at position %d. Condition must be a group reference, lookaround, or (DEFINE).', $offset),
            ErrorCode::ConditionalInvalid,
            $offset,
            $pattern,
        );
    }

    /**
     * Whether what starts at $position is where the condition of "(?(" is
     * due, the assertion, after the callout "(?(?C1)" may run first.
     *
     * The tokens are read from the end, not copied: a body opens at each
     * token of a long pattern.
     *
     * @param list<Token> $tokens the tokens read before $position
     */
    public static function opensCondition(array $tokens, int $position): bool
    {
        $last = array_key_last($tokens);
        $previous = null === $last ? null : $tokens[$last];
        if (null !== $previous && TokenType::Callout === $previous->type && $previous->end() === $position) {
            $position = $previous->position;
            $previous = $tokens[$last - 1] ?? null;
        }

        return null !== $previous && TokenType::GroupModifierOpen === $previous->type && $previous->end() === $position;
    }

    /**
     * Where $needle last stands before $position, -1 for nowhere.
     */
    private static function lastBefore(PatternText $text, string $needle, int $position): int
    {
        $at = $position > 0 ? strrpos($text->pattern, $needle, $position - 1 - $text->length) : false;

        return false === $at ? -1 : $at;
    }

    /**
     * PCRE reads a setting only as it is written, "(*UTF)": given an
     * argument, "(*UTF:x)", it is a verb PCRE does not know, refused where
     * its name ends, wherever it stands.
     *
     * @param string $name   the verb as written between "(*" and ")"
     * @param int    $length the length of its name, as the release reads it
     */
    private static function refuseSettingWithArgument(PatternText $text, string $name, int $length, int $start): void
    {
        if (':' !== ($name[$length] ?? '') || !isset(StartOptions::NAMES[substr($name, 0, $length)])) {
            return;
        }

        throw LexerException::withContext(
            \sprintf('Invalid or unsupported PCRE verb at position %d: "(*%s)" is a setting, which takes no argument.', $start + 2 + $length, substr($name, 0, $length)),
            ErrorCode::VerbInvalid,
            $start + 2 + $length,
            $text->pattern,
        );
    }

    /**
     * From PCRE2 10.47 an alphabetic name closed with no colon, "(*pla)", is
     * refused past its ")". Where that ")" ends the pattern, PCRE refuses
     * the name before it finds a group left open there.
     *
     * @param string $name   the verb as written between "(*" and ")"
     * @param int    $length the length of its name
     */
    private static function refuseAlphabeticNameEndingThePattern(PatternText $text, string $name, int $length, int $start): void
    {
        if (!$text->syntax->target->supports(PcreFeature::ErrorOffsetPastTheFault) || $length !== \strlen($name) || 1 !== LibraryPcre::match('/^[a-z]/', $name) || $start + $length + 3 !== $text->length) {
            return;
        }

        throw LexerException::withContext(
            \sprintf('Unknown alphabetic assertion "(*%s)" at position %d: an alphabetic name is followed by ":".', $name, $text->length),
            ErrorCode::VerbInvalid,
            $text->length,
            $text->pattern,
        );
    }

    /**
     * Where the settings the pattern opens with, "(*UTF)(*NO_JIT)", end:
     * PCRE reads them before the pattern, and a setting it does not know
     * yet ends them.
     */
    private static function leadingSettingsEnd(PatternText $text): int
    {
        $settings = StartOptions::of($text->pattern);
        if (!$text->syntax->target->supports(PcreFeature::CasingSettingVerbs)) {
            foreach (['(*CASELESS_RESTRICT)', '(*TURKISH_CASING)'] as $unknown) {
                $at = strpos($settings, $unknown);
                $settings = false === $at ? $settings : substr($settings, 0, $at);
            }
        }

        return \strlen($settings);
    }
}
