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

use PHPRegex\Parser\Text\Ascii;

/**
 * The arguments of a call, read from its tokens as PHP binds them.
 *
 * Which argument holds the pattern depends on how the call passes it: by
 * position or by name, after a spread that hides every later position, or
 * inside an option array; whether Nette reads the replacement's values
 * depends on what the replacement is, whatever its value. Each is read in
 * place with the bracket map, stepping over a nested bracket whole, so a
 * call inside another is not read again for each call around it.
 *
 * @internal
 */
final class CallArguments
{
    /**
     * Where an argument holds the pattern: the argument itself, or, for an
     * array literal, the item its option path leads to (the last of a
     * repeated key, as PHP keeps it).
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{int, int}|null
     */
    public static function optionRange(array $tokens, int $start, int $end, PatternFunction $function, array $closers, array $tokenOffsets, string $content): ?array
    {
        if ([] === $function->optionPath) {
            return [$start, $end];
        }

        foreach ($function->optionPath as $key) {
            $items = self::arrayItems($tokens, $start, $end, $closers);
            if (null === $items) {
                // Only the first level may be the pattern itself.
                return $function->optionPathRequired || $key !== $function->optionPath[0] ? null : [$start, $end];
            }

            $found = null;
            foreach ($items as [$keyRange, $valueRange]) {
                if (null === $keyRange) {
                    // A spread may hold the key, replacing what came before.
                    $first = PhpTokens::nextSignificant($tokens, $valueRange[0], $valueRange[1]);
                    if (null !== $first && \is_array($tokens[$first]) && \T_ELLIPSIS === $tokens[$first][0]) {
                        $found = null;
                    }

                    continue;
                }

                [$keyTokens, $keyIndexes] = PhpTokens::slice($tokens, $keyRange[0], $keyRange[1]);
                [$keyTokens, $keyIndexes] = StringExpressionReader::stripParentheses($keyTokens, $keyIndexes);
                $literal = StringExpressionReader::read($keyTokens, $keyIndexes, $tokenOffsets, $content);
                if (null !== $literal && $key === $literal['pattern']) {
                    $found = $valueRange;
                }
            }

            if (null === $found) {
                return null;
            }

            [$start, $end] = $found;
        }

        return [$start, $end];
    }

    /**
     * The name an argument in [$start, $end) is, alone: a constant.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    public static function soleName(array $tokens, int $start, int $end): ?string
    {
        // (FILTER_VALIDATE_REGEXP) is the constant: PHP drops the parentheses.
        [$slice] = StringExpressionReader::stripParentheses(...PhpTokens::slice($tokens, $start, $end));
        $significant = array_values(array_filter($slice, static fn (array|string $token): bool => !PhpTokens::isIgnorable($token)));

        return 1 === \count($significant) ? PhpTokens::name($significant[0]) : null;
    }

    /**
     * Whether a call is a partial application: one of its arguments, by
     * position or by name, is only a placeholder, "?" or "...".
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    public static function isPartialApplication(array $tokens, int $startIndex, int $totalTokens, array $closers): bool
    {
        $argumentStart = $startIndex;
        for ($i = $startIndex; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (')' === $token || ',' === $token) {
                $argument = self::readArgument($tokens, $argumentStart, $i);
                $value = null === $argument ? null : PhpTokens::nextSignificant($tokens, $argument['valueStart'], $i);
                if (null !== $value && ('?' === $tokens[$value] || (\is_array($tokens[$value]) && \T_ELLIPSIS === $tokens[$value][0]))
                    && null === PhpTokens::nextSignificant($tokens, $value + 1, $i)) {
                    return true;
                }
                if (')' === $token) {
                    return false;
                }

                $argumentStart = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $closers[$i] ?? $totalTokens;
            }
        }

        return false;
    }

    /**
     * Locate an argument of a call, passed positionally or by name.
     *
     * A positional argument at the position wins; otherwise the first
     * argument passed under one of the names. A spread before that position
     * makes it unknowable. Nothing is copied, and a bracket is stepped over
     * whole, so that a call nested in another is not read again for each
     * call around it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param list<string>                               $names   parameter names, compared as PHP does, case included
     * @param array<int, int>                            $closers the closing index of each bracket
     *
     * @return array{0: int, 1: int}|null where the argument's value starts and where it ends, past its last token
     */
    public static function find(array $tokens, int $startIndex, int $totalTokens, int $targetArgIndex, array $names, array $closers): ?array
    {
        $position = 0;
        $named = null;
        $argumentStart = $startIndex;
        $i = $startIndex;

        while (true) {
            // Running out of tokens closes the call: the source is cut short.
            $token = $i < $totalTokens ? $tokens[$i] : ')';
            $closesCall = ')' === $token;

            if ($closesCall || ',' === $token) {
                // Null for the empty slot a trailing comma leaves.
                $argument = self::readArgument($tokens, $argumentStart, $i);
                if (null !== $argument && null === $argument['name']) {
                    // A spread makes every later position unknowable; a
                    // name still passes the argument.
                    if ($argument['spread']) {
                        $position = \PHP_INT_MIN;
                    } elseif ($position === $targetArgIndex) {
                        return [$argument['valueStart'], $i];
                    }

                    $position++;
                } elseif (null !== $argument && null === $named && \in_array($argument['name'], $names, true)) {
                    $named = [$argument['valueStart'], $i];
                }

                if ($closesCall) {
                    return $named;
                }

                $argumentStart = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                // Step over the bracket; one left open runs to the end.
                $i = $closers[$i] ?? $totalTokens;
            }

            $i++;
        }
    }

    /**
     * What the utf8 argument of a nette/utils call is, by position or by
     * name: false when left out; null when it is no literal true or false,
     * or a spread may hold it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array{int, string}                         $utf8    its position and its name
     * @param array<int, int>                            $closers
     */
    public static function utf8(array $tokens, int $startIndex, int $totalTokens, array $utf8, array $closers): ?bool
    {
        $position = 0;
        $value = null;
        $argumentStart = $startIndex;
        for ($i = $startIndex; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (')' === $token || ',' === $token) {
                $argument = self::readArgument($tokens, $argumentStart, $i);
                if (null !== $argument && $argument['spread']) {
                    return null;
                }

                if (null !== $argument && (null === $argument['name'] ? $position++ === $utf8[0] : $utf8[1] === $argument['name'])) {
                    $value = [$argument['valueStart'], $i];
                }

                if (')' === $token) {
                    break;
                }

                $argumentStart = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $closers[$i] ?? $totalTokens;
            }
        }

        $name = null === $value ? 'false' : self::soleName($tokens, $value[0], $value[1]);
        $literal = null === $name || 1 < substr_count($name, '\\') ? null : Ascii::lower(ltrim($name, '\\'));

        return 'true' === $literal ? true : ('false' === $literal ? false : null);
    }

    /**
     * Whether the argument in [$start, $end) is an object or an array
     * whatever its value, as Nette's Strings::replace() tests its
     * replacement: a closure, an arrow function, a first-class callable, an
     * array, a new object, $this, an (object) or (array) cast, a clone, or
     * Closure::fromCallable(). Each counts only when it is the whole
     * argument: $cb ?? Ascii::upper(...) is not one.
     *
     * Read in place with the bracket map, so a closure holding the next call
     * is not read again.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers the closing index of each bracket
     */
    public static function isObjectOrArray(array $tokens, int $start, int $end, array $closers, NameResolutionContext $context): bool
    {
        // An empty value leaves $first past $last: nothing below matches.
        $first = PhpTokens::nextSignificant($tokens, $start, $end) ?? $end;
        $last = PhpTokens::previousSignificant($tokens, $end - 1) ?? $start;
        while ('(' === ($tokens[$first] ?? null) && ($closers[$first] ?? null) === $last) {
            $first = PhpTokens::nextSignificant($tokens, $first + 1, $last) ?? $last;
            $last = PhpTokens::previousSignificant($tokens, $last - 1) ?? $first;
        }

        $token = $tokens[$first] ?? ';';
        $next = PhpTokens::nextSignificant($tokens, $first + 1, $last + 1) ?? $last + 1;

        if ('[' === $token) {
            return ($closers[$first] ?? null) === $last;
        }

        if (!\is_array($token)) {
            return false;
        }

        $whole = match ($token[0]) {
            \T_ARRAY => '(' === ($tokens[$next] ?? null) && ($closers[$next] ?? null) === $last,
            \T_VARIABLE => '$this' === $token[1] && $first === $last,
            \T_OBJECT_CAST, \T_ARRAY_CAST, \T_CLONE => self::isMemberChain($tokens, $next, $last, $closers),
            \T_NEW => self::constructionEnd($tokens, $next, $last, $closers) === $last,
            \T_ATTRIBUTE, \T_STATIC, \T_FUNCTION, \T_FN => self::closureEnd($tokens, $first, $last, $closers) === $last,
            default => self::isClosureFromCallable($tokens, $first, $next, $last, $closers, $context),
        };

        return $whole || self::isFirstClassCallable($tokens, $first, $last, $closers);
    }

    /**
     * The items of the array literal that is the whole of [$start, $end),
     * each its key range, if any, and its value range; null when the range
     * is no array literal.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     *
     * @return list<array{array{int, int}|null, array{int, int}}>|null
     */
    private static function arrayItems(array $tokens, int $start, int $end, array $closers): ?array
    {
        $first = PhpTokens::nextSignificant($tokens, $start, $end);
        $last = PhpTokens::previousSignificant($tokens, $end - 1);
        while (null !== $first && null !== $last && '(' === $tokens[$first] && ($closers[$first] ?? null) === $last) {
            $first = PhpTokens::nextSignificant($tokens, $first + 1, $last);
            $last = PhpTokens::previousSignificant($tokens, $last - 1);
        }

        if (null === $first || null === $last) {
            return null;
        }

        $open = $first;
        if (\is_array($tokens[$first]) && \T_ARRAY === $tokens[$first][0]) {
            $open = PhpTokens::nextSignificant($tokens, $first + 1, $last + 1);
            if (null === $open || '(' !== $tokens[$open]) {
                return null;
            }
        } elseif ('[' !== $tokens[$first]) {
            return null;
        }

        if (($closers[$open] ?? null) !== $last) {
            return null;
        }

        $items = [];
        $itemStart = $open + 1;
        for ($i = $open + 1; $i <= $last; $i++) {
            $token = $tokens[$i];
            if (',' !== $token && $i !== $last) {
                if (PhpTokens::opensNesting($token)) {
                    $i = $closers[$i] ?? $last;
                }

                continue;
            }

            $arrow = null;
            for ($j = $itemStart; $j < $i; $j++) {
                if (PhpTokens::isDoubleArrow($tokens[$j])) {
                    $arrow = $j;

                    break;
                }

                if (PhpTokens::opensNesting($tokens[$j])) {
                    $j = $closers[$j] ?? $i;
                }
            }

            if (null !== PhpTokens::nextSignificant($tokens, $itemStart, $i)) {
                $items[] = null === $arrow ? [null, [$itemStart, $i]] : [[$itemStart, $arrow], [$arrow + 1, $i]];
            }

            $itemStart = $i + 1;
        }

        return $items;
    }

    /**
     * What an argument is, from its first tokens: a spread, an argument
     * passed by name, or a positional one.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{name: string|null, spread: bool, valueStart: int}|null null for an empty slot
     */
    private static function readArgument(array $tokens, int $start, int $end): ?array
    {
        $first = PhpTokens::nextSignificant($tokens, $start, $end);
        if (null === $first) {
            return null;
        }

        $token = $tokens[$first];
        if (\is_array($token) && \T_ELLIPSIS === $token[0]) {
            return ['name' => null, 'spread' => true, 'valueStart' => $start];
        }

        // "pattern: '/re/'": an identifier, any reserved word included,
        // followed by a single colon.
        $name = PhpTokens::identifier($token);
        $colon = null === $name ? null : PhpTokens::nextSignificant($tokens, $first + 1, $end);
        if (null !== $colon && ':' === $tokens[$colon]) {
            return ['name' => $name, 'spread' => false, 'valueStart' => $colon + 1];
        }

        return ['name' => null, 'spread' => false, 'valueStart' => $start];
    }

    /**
     * Where a closure or an arrow function starting at $index ends: an arrow
     * function runs to the end of the argument, a closure to the brace
     * closing its body. Null when the tokens start no closure.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function closureEnd(array $tokens, int $index, int $last, array $closers): ?int
    {
        // Attributes, then static; past $last stands the comma or the
        // parenthesis ending the argument.
        while (\is_array($tokens[$index] ?? null) && \in_array($tokens[$index][0], [\T_ATTRIBUTE, \T_STATIC], true)) {
            $after = \T_ATTRIBUTE === $tokens[$index][0] ? ($closers[$index] ?? $last) : $index;
            $index = PhpTokens::nextSignificant($tokens, $after + 1, $last + 1) ?? $last + 1;
        }

        $token = $tokens[$index] ?? ';';
        if (\is_array($token) && \T_FN === $token[0]) {
            return $last;
        }

        if (!\is_array($token) || \T_FUNCTION !== $token[0]) {
            return null;
        }

        return self::bodyEnd($tokens, $index + 1, $last, $closers);
    }

    /**
     * Where the object a new expression builds ends, from the token after
     * "new": the brace closing an anonymous class, the parenthesis closing
     * the arguments, or the end of the argument when there are none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function constructionEnd(array $tokens, int $index, int $last, array $closers): ?int
    {
        $token = $tokens[$index] ?? null;
        if (\is_array($token) && \T_CLASS === $token[0]) {
            return self::bodyEnd($tokens, $index + 1, $last, $closers);
        }

        for ($i = $index; $i <= $last; $i++) {
            if ('(' === $tokens[$i]) {
                return $closers[$i] ?? null;
            }

            // new $classes['a'] names its class with a bracket.
            if (PhpTokens::opensNesting($tokens[$i])) {
                $i = $closers[$i] ?? $last;
            }
        }

        return $last;
    }

    /**
     * The brace closing the first body opened from $index, stepping over
     * the parameter list and use() before it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function bodyEnd(array $tokens, int $index, int $last, array $closers): ?int
    {
        // With no body, $i passes $last, where no bracket opens.
        $i = $index;
        while ($i <= $last && '{' !== $tokens[$i]) {
            $i = (PhpTokens::opensNesting($tokens[$i]) ? ($closers[$i] ?? $last) : $i) + 1;
        }

        return $closers[$i] ?? null;
    }

    /**
     * Whether $tokens[$from..$to] is a variable, a name or a member chain on
     * them, with nothing else: $a, $this->b['c'], Foo::$d, Ascii::upper(...).
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function isMemberChain(array $tokens, int $from, int $to, array $closers): bool
    {
        // A keyword is a member name after -> or :: only: "$a or $b" is no chain.
        $afterOperator = false;
        for ($i = $from; $i <= $to; $i++) {
            $token = $tokens[$i];
            if (PhpTokens::isIgnorable($token) || '$' === $token) {
                continue;
            }

            if (PhpTokens::opensNesting($token)) {
                $i = $closers[$i] ?? $to + 1;
                $afterOperator = false;

                continue;
            }

            if ($afterOperator ? null === PhpTokens::identifier($token) && !self::isVariableToken($token) : !self::startsMemberChain($token)) {
                return false;
            }

            $afterOperator = PhpTokens::isObjectOrStaticOperator($token);
        }

        return $from <= $to && $i === $to + 1;
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private static function startsMemberChain(array|string $token): bool
    {
        return self::isVariableToken($token) || PhpTokens::isObjectOrStaticOperator($token)
            || null !== PhpTokens::name($token) || (\is_array($token) && \T_STATIC === $token[0]);
    }

    /**
     * @param array{0:int, 1:string, 2?:int}|string $token
     */
    private static function isVariableToken(array|string $token): bool
    {
        return \is_array($token) && \T_VARIABLE === $token[0];
    }

    /**
     * foo(...), Foo::bar(...), $this->bar(...): a member chain whose last
     * call takes "..." alone.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function isFirstClassCallable(array $tokens, int $first, int $last, array $closers): bool
    {
        $ellipsis = PhpTokens::previousSignificant($tokens, $last - 1) ?? $last;
        $open = PhpTokens::previousSignificant($tokens, $ellipsis - 1) ?? $ellipsis;
        $token = $tokens[$ellipsis];

        return \is_array($token) && \T_ELLIPSIS === $token[0] && ($closers[$open] ?? null) === $last
            && $open > $first && self::isMemberChain($tokens, $first, $last, $closers);
    }

    /**
     * Closure::fromCallable(...), with the class resolved as PHP does.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function isClosureFromCallable(array $tokens, int $first, int $next, int $last, array $closers, NameResolutionContext $context): bool
    {
        $class = PhpTokens::name($tokens[$first]);
        if (null === $class || 'closure' !== Ascii::lower($context->resolveClass($class)) || !PhpTokens::isDoubleColon($tokens[$next] ?? '')) {
            return false;
        }

        $method = PhpTokens::nextSignificant($tokens, $next + 1, $last + 1) ?? $last;
        $open = PhpTokens::nextSignificant($tokens, $method + 1, $last + 1) ?? $last;

        return 'fromcallable' === Ascii::lower(PhpTokens::identifier($tokens[$method]) ?? '')
            && '(' === $tokens[$open] && ($closers[$open] ?? null) === $last;
    }
}
