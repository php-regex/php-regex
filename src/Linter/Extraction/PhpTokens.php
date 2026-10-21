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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;

/**
 * What a token of token_get_all() is, read the one way every token reader
 * of the extraction reads it.
 *
 * The token strategy finds the calls and the value collector follows the
 * names they read: both step over whitespace and comments, pair brackets,
 * read a name, a member name or a string literal. Kept here once, the two
 * cannot drift apart, and a literal reads as PHP reads it wherever it is.
 *
 * @internal
 */
final class PhpTokens
{
    /**
     * The tokens that say nothing: a reader steps over them.
     */
    public const IGNORABLE = [\T_WHITESPACE => true, \T_COMMENT => true, \T_DOC_COMMENT => true];

    /**
     * Matches an identifier, so a reserved word used as a member name — the
     * "match" of Preg::match() is tokenized as T_MATCH, not T_STRING — is
     * still read as one.
     */
    private const IDENTIFIER = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';

    private const NAMES = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE];

    private const OBJECT_OR_STATIC_OPERATORS = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON];

    /**
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function isIgnorable(array|string $token): bool
    {
        return \is_array($token) && isset(self::IGNORABLE[$token[0]]);
    }

    /**
     * Whether the token opens a bracket a plain ")", "]" or "}" closes: the
     * "{$" and "${" of an interpolation and the "#[" of an attribute
     * included.
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function opensNesting(array|string $token): bool
    {
        if (!\is_array($token)) {
            return '(' === $token || '[' === $token || '{' === $token;
        }

        return \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES, \T_ATTRIBUTE], true);
    }

    /**
     * A name as written: Foo, Foo\Bar, \Foo, namespace\Foo.
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function name(array|string $token): ?string
    {
        return \is_array($token) && \in_array($token[0], self::NAMES, true) ? $token[1] : null;
    }

    /**
     * Any token spelling an identifier, a reserved word included: a member
     * name, a named argument or a method name may be one.
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function identifier(array|string $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }

        if (\T_STRING === $token[0]) {
            return $token[1];
        }

        // A variable, a literal, a number or an operator starts with no
        // letter: no pattern needs to say so.
        $first = $token[1][0] ?? '';
        if ('' === $first || ('_' !== $first && !Ascii::isAlpha($first) && \ord($first) < 0x80)) {
            return null;
        }

        return 1 === LibraryPcre::match(self::IDENTIFIER, $token[1]) ? $token[1] : null;
    }

    /**
     * "->", "?->" or "::".
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function isObjectOrStaticOperator(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], self::OBJECT_OR_STATIC_OPERATORS, true);
    }

    /**
     * "=>", between an array key and its value.
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function isDoubleArrow(array|string $token): bool
    {
        return \is_array($token) ? \T_DOUBLE_ARROW === $token[0] : '=>' === $token;
    }

    /**
     * "::", between a class and its member.
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    public static function isDoubleColon(array|string $token): bool
    {
        return \is_array($token) && \T_DOUBLE_COLON === $token[0];
    }

    /**
     * The value of a T_CONSTANT_ENCAPSED_STRING, as PHP reads it. The b of a
     * binary string, b'/a/' or B"/a/", changes nothing.
     *
     * The tokenizer quotes every such token, so the empty string a text that
     * is no quoted literal reads as is never what a source holds.
     */
    public static function stringValue(string $token): string
    {
        if ('b' === Ascii::lower($token[0] ?? '')) {
            $token = substr($token, 1);
        }

        return PhpStringLiteral::decode($token) ?? '';
    }

    /**
     * The first token from $start, before $end, that is no whitespace and
     * no comment. A range left open by a file cut short runs past the last
     * token, and stops there.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    public static function nextSignificant(array $tokens, int $start, int $end): ?int
    {
        // The test of isIgnorable() written in place: both walks run it on
        // most tokens of every file read.
        for ($i = $start, $end = min($end, \count($tokens)); $i < $end; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || !isset(self::IGNORABLE[$token[0]])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The last token at or before $start, not before $floor, that is no
     * whitespace and no comment.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    public static function previousSignificant(array $tokens, int $start, int $floor = 0): ?int
    {
        for ($i = min($start, \count($tokens) - 1); $i >= $floor; $i--) {
            $token = $tokens[$i];
            if (!\is_array($token) || !isset(self::IGNORABLE[$token[0]])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The index of the token closing each bracket: ( [ { and the "{$", "${"
     * and "#[" a plain "}" or "]" closes. An opener left open has none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<int, int>
     */
    public static function closers(array $tokens): array
    {
        $closers = [];
        $open = [];
        foreach ($tokens as $index => $token) {
            if (self::opensNesting($token)) {
                $open[] = $index;
            } elseif ((')' === $token || ']' === $token || '}' === $token) && [] !== $open) {
                $closers[array_pop($open)] = $index;
            }
        }

        return $closers;
    }

    /**
     * The byte offset of each token in the source.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<int, int>
     */
    public static function offsets(array $tokens): array
    {
        $offsets = [];
        $offset = 0;
        foreach ($tokens as $index => $token) {
            $offsets[$index] = $offset;
            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        return $offsets;
    }

    /**
     * The tokens in [$start, $end) and their indexes. A name with no value
     * after it, "pattern:)", leaves nothing; a call whose brackets never
     * balance, as in half-typed code, runs to the end of the file and no
     * further.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{0: list<array{int, string, int}|string>, 1: list<int>}
     */
    public static function slice(array $tokens, int $start, int $end): array
    {
        $end = min($end, \count($tokens));

        return [array_values(\array_slice($tokens, $start, $end - $start)), $end > $start ? range($start, $end - 1) : []];
    }
}
