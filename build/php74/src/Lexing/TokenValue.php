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

use PHPRegex\Parser\Token\TokenType;

/**
 * The value a token of the lexer carries, cut from the text its regex
 * matched: the escape without its backslash, the name of a verb without its
 * parentheses, a property name with its negations folded into one "^".
 *
 * It reads the matched text alone, and whether it stands in a class, where
 * "\b" is a backspace: the token keeps the length of the text it was cut
 * from, whatever its value.
 *
 * @internal
 */
final class TokenValue
{
    private const OFFSET_VERB_START = 2; // After (* or (?
    private const OFFSET_VERB_END = -1;
    private const OFFSET_CALLOUT_START = 3; // After (?C
    private const OFFSET_CALLOUT_END = -1;
    private const OFFSET_BACKSLASH = 1; // After backslash
    private const OFFSET_UNICODE_NAMED_START = 3; // After \N{
    private const OFFSET_UNICODE_NAMED_END = -1;
    private const OFFSET_CONTROL_CHAR = 2; // After \c

    private const ESCAPE_BACKSPACE = "\x08";
    private const ESCAPE_TAB = "\t";
    private const ESCAPE_NEWLINE = "\n";
    private const ESCAPE_CARRIAGE_RETURN = "\r";
    private const ESCAPE_FORM_FEED = "\f";
    private const ESCAPE_ESCAPE = "\x1B";
    private const ESCAPE_BELL = "\x07";

    private const PATTERN_UNICODE_PROP_BRACE_START = 1; // After {
    private const PATTERN_UNICODE_PROP_BRACE_END = -1; // Before }
    private const PATTERN_UNICODE_PROP_NEGATION_START = 1; // After ^

    /**
     * @param array<int|string, string|null> $matches     the groups of the token regex
     * @param bool                           $inCharClass whether the token stands in a class
     */
    public static function of(TokenType $type, string $matchedValue, array $matches, bool $inCharClass): string
    {
        switch (true) {
            case $type === TokenType::LiteralEscaped():
                return self::escapedLiteral($matchedValue, $inCharClass);
            case $type === TokenType::PcreVerb():
                return self::verb($matchedValue);
            case $type === TokenType::Callout():
                return (string) substr($matchedValue, self::OFFSET_CALLOUT_START, self::OFFSET_CALLOUT_END);
            case $type === TokenType::Assertion():
            case $type === TokenType::CharType():
            case $type === TokenType::Keep():
                return (string) substr($matchedValue, self::OFFSET_BACKSLASH);
            case $type === TokenType::Backref():
                return $matchedValue;
            case $type === TokenType::OctalLegacy():
                return (string) substr($matchedValue, self::OFFSET_BACKSLASH);
            case $type === TokenType::PosixClass():
                return \is_string($matches['v_posix'] ?? null) ? $matches['v_posix'] : '';
            case $type === TokenType::Unicode():
                return $matchedValue;
            case $type === TokenType::UnicodeProp():
                return self::unicodeProperty($matchedValue);
            case $type === TokenType::UnicodeNamed():
                return (string) substr($matchedValue, self::OFFSET_UNICODE_NAMED_START, self::OFFSET_UNICODE_NAMED_END);
            case $type === TokenType::ControlChar():
                return (string) substr($matchedValue, self::OFFSET_CONTROL_CHAR);
            default:
                return $matchedValue;
        }
    }

    /**
     * The verb as written between "(*" and ")", "NAME:arg".
     */
    public static function verb(string $matchedValue): string
    {
        return (string) substr($matchedValue, self::OFFSET_VERB_START, self::OFFSET_VERB_END);
    }

    /**
     * The name of "\p{...}" or "\P{...}", braces kept, with "^" before it
     * when the escape negates it once: "\P{^L}" is "{L}".
     */
    public static function unicodeProperty(string $matchedValue): string
    {
        $isNegated = 0 === strncmp($matchedValue, '\\P', strlen('\\P'));
        $prop = (string) substr($matchedValue, self::OFFSET_VERB_START); // Strip "\p" or "\P"

        $hasBraces = 0 === strncmp($prop, '{', strlen('{')) && 0 === substr_compare($prop, '}', -strlen('}'));

        if ($hasBraces) {
            $prop = (string) substr($prop, self::PATTERN_UNICODE_PROP_BRACE_START, self::PATTERN_UNICODE_PROP_BRACE_END);
        }

        // PCRE2 skips the spaces before the "^": "\P{ ^L}" is "\p{L}".
        $isPropNegated = 0 === strncmp(ltrim($prop, " \t\n\v\f\r"), '^', strlen('^'));
        if ($isPropNegated) {
            $prop = (string) substr(ltrim($prop, " \t\n\v\f\r"), self::PATTERN_UNICODE_PROP_NEGATION_START);
        }

        if ('' === $prop) {
            return '';
        }

        $negated = $isNegated !== $isPropNegated;

        $normalized = $negated ? '^'.$prop : $prop;

        return $hasBraces ? '{'.$normalized.'}' : $normalized;
    }

    /**
     * The character an escaped literal stands for: the control characters
     * "\t", "\n" and their kin, "\b" a backspace in a class, the character
     * after the backslash otherwise.
     */
    private static function escapedLiteral(string $matchedValue, bool $inCharClass): string
    {
        $char = (string) substr($matchedValue, self::OFFSET_BACKSLASH);

        // Inside character classes, \b means backspace (0x08), not word boundary
        if ($inCharClass && 'b' === $char) {
            return self::ESCAPE_BACKSPACE;
        }

        switch (true) {
            case 't' === $char:
                return self::ESCAPE_TAB;
            case 'n' === $char:
                return self::ESCAPE_NEWLINE;
            case 'r' === $char:
                return self::ESCAPE_CARRIAGE_RETURN;
            case 'f' === $char:
                return self::ESCAPE_FORM_FEED;
            case 'e' === $char:
                return self::ESCAPE_ESCAPE;
            case 'a' === $char:
                return self::ESCAPE_BELL;
            default:
                return $char;
        }
    }
}
