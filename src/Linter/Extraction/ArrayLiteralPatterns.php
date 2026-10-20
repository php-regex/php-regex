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
 * The items of an array literal that hold the patterns of a call.
 *
 * preg_replace(['/a/', '/b/'], ...) reads the values of its array,
 * preg_replace_callback_array(['/a/' => $fn]) its keys, and Nette's
 * Strings::replace() either, the keys when the first key PHP stores is a
 * string. Which items they are follows from the array's tokens alone; what
 * each item reads as is the caller's to say, so the walk hands back each
 * item's tokens, in order.
 *
 * @internal
 */
final class ArrayLiteralPatterns
{
    /**
     * The bracket opening the items of the array literal the tokens are:
     * its "[", or the parenthesis after "array". Null when they are none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    public static function startIndex(array $tokens): ?int
    {
        $totalTokens = \count($tokens);
        $startIndex = PhpTokens::nextSignificant($tokens, 0, $totalTokens);
        if (null === $startIndex) {
            return null;
        }

        $token = $tokens[$startIndex];
        if ('[' === $token) {
            return $startIndex;
        }

        if (\is_array($token) && \T_ARRAY === $token[0]) {
            $openParenIndex = PhpTokens::nextSignificant($tokens, $startIndex + 1, $totalTokens);
            if (null !== $openParenIndex && '(' === $tokens[$openParenIndex]) {
                return $openParenIndex;
            }
        }

        return null;
    }

    /**
     * The tokens of each key or each value holding a pattern, with the
     * index of each token in the file, in the order of the items. An array
     * left open gives the items it closes.
     *
     * @param array<int, array{int, string, int}|string> $tokens       the array literal, its outer parentheses stripped
     * @param array<int, int>                            $tokenIndexes the index of each token in the file
     * @param array<int, int>                            $tokenOffsets
     *
     * @return list<array{list<array{int, string, int}|string>, list<int>}>
     */
    public static function segments(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content, PatternFunction $patternFunction): array
    {
        $startIndex = self::startIndex($tokens);
        if (null === $startIndex) {
            return [];
        }

        // Null until the first item says: a key that is a string selects the
        // keys, no key or an int key the values.
        $useKeys = $patternFunction->keysArePatterns ? (null === $patternFunction->replacementIndex ? true : null) : false;
        $segments = [];
        $totalTokens = \count($tokens);
        $stack = [self::closingTokenFor($tokens[$startIndex])];
        $collecting = true;
        // The arrow functions the current item opened at the top level whose
        // "=>" is still to come.
        $arrowFunctions = 0;
        $segmentTokens = [];
        $segmentTokenIndexes = [];

        for ($i = $startIndex + 1; $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            $tokenIndex = $tokenIndexes[$i] ?? -1;

            if (PhpTokens::isIgnorable($token)) {
                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            // An array() item keeps its keyword: it is an array, as a []
            // item is, and its parenthesis opens a nesting as any other.
            if (PhpTokens::opensNesting($token)) {
                $stack[] = self::closingTokenFor($token);
                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            if (end($stack) === $token) {
                array_pop($stack);
                if ([] === $stack) {
                    if (!$useKeys) {
                        $segments[] = [$segmentTokens, $segmentTokenIndexes];
                    }

                    break;
                }

                if ($collecting) {
                    $segmentTokens[] = $token;
                    $segmentTokenIndexes[] = $tokenIndex;
                }

                continue;
            }

            $atTopLevel = 1 === \count($stack);

            if ($atTopLevel && ',' === $token) {
                if (!$useKeys) {
                    $segments[] = [$segmentTokens, $segmentTokenIndexes];
                }

                $useKeys ??= false;
                $collecting = true;
                $arrowFunctions = 0;
                $segmentTokens = [];
                $segmentTokenIndexes = [];

                continue;
            }

            if ($atTopLevel && PhpTokens::isDoubleArrow($token) && 0 === $arrowFunctions) {
                $useKeys ??= self::isStringKey($segmentTokens, $segmentTokenIndexes, $tokenOffsets, $content);
                if ($useKeys) {
                    $segments[] = [$segmentTokens, $segmentTokenIndexes];
                    $collecting = false;
                }

                // Whatever was collected was the key; the value follows.
                $segmentTokens = [];
                $segmentTokenIndexes = [];

                continue;
            }

            // An arrow function the item opens owns the next "=>": it
            // separates no key from its value.
            if ($atTopLevel && \is_array($token) && \T_FN === $token[0]) {
                $arrowFunctions++;
            } elseif ($atTopLevel && PhpTokens::isDoubleArrow($token)) {
                $arrowFunctions--;
            }

            if ($collecting) {
                $segmentTokens[] = $token;
                $segmentTokenIndexes[] = $tokenIndex;
            }
        }

        return $segments;
    }

    /**
     * Whether an array key is a string once PHP stores it: an int literal or
     * a numeric string is not; a key that is no literal is taken for one.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @param list<int>                            $tokenIndexes
     * @param array<int, int>                      $tokenOffsets
     */
    private static function isStringKey(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content): bool
    {
        [$tokens, $tokenIndexes] = StringExpressionReader::stripParentheses($tokens, $tokenIndexes);
        $significant = array_values(array_filter($tokens, static fn (array|string $token): bool => !PhpTokens::isIgnorable($token)));

        // An int, a float, true or false, signed or not: PHP stores an int.
        $sign = 0;
        while ('+' === ($significant[$sign] ?? null) || '-' === ($significant[$sign] ?? null)) {
            $sign++;
        }

        $scalar = $significant[$sign] ?? null;
        if (\is_array($scalar) && $sign + 1 === \count($significant)) {
            $isNumber = \T_LNUMBER === $scalar[0] || \T_DNUMBER === $scalar[0];
            $isBool = 0 === $sign && null !== PhpTokens::name($scalar) && \in_array(Ascii::lower(ltrim($scalar[1], '\\')), ['true', 'false'], true);
            if ($isNumber || $isBool) {
                return false;
            }
        }

        $key = StringExpressionReader::read($tokens, $tokenIndexes, $tokenOffsets, $content);

        return null === $key || PatternFunction::isStringKey($key['pattern']);
    }

    /**
     * The token closing a bracket: "{$x}" and "${x}" in a string close on
     * "}", "#[" on "]".
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    private static function closingTokenFor(array|string $token): string
    {
        if (\is_array($token)) {
            return \T_ATTRIBUTE === $token[0] ? ']' : '}';
        }

        return match ($token) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            default => ')',
        };
    }
}
