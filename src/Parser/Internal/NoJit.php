<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Internal;

/**
 * A pattern the engine runs without its JIT, which crashes PHP on some
 * pattern and subject pairs in PCRE2 10.40 to 10.49: "(*NO_JIT)" leads it.
 *
 * The verb follows the opening delimiter. A delimiter the verb holds, as
 * "_", "*" or ")", would end the pattern inside it: the pattern moves to a
 * delimiter its body does not hold. A delimiter escaped in the body keeps
 * its meaning there, a backslash before a character that is not a letter or
 * a digit being that character.
 *
 * @internal
 */
final class NoJit
{
    public const VERB = '(*NO_JIT)';

    private const WHITE_SPACE = " \t\n\r\v\f";

    private const HELD_BY_THE_VERB = ['*', '_', ')'];

    private const REPLACEMENTS = ["\x01", '#', '~', '%', '!', '@', ';', ','];

    private const CLOSING_BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * The pattern with the verb after its opening delimiter; the pattern as
     * it is when PHP refuses its delimiters, or when its body holds every
     * other delimiter.
     */
    public static function pattern(string $regex): string
    {
        $parts = self::split($regex);
        if (null === $parts) {
            return $regex;
        }

        [$open, $body, $close, $flags] = $parts;
        if (!\in_array($open, self::HELD_BY_THE_VERB, true)) {
            return $open.self::VERB.$body.$close.$flags;
        }

        foreach (self::REPLACEMENTS as $replacement) {
            if (!str_contains($body, $replacement)) {
                return $replacement.self::VERB.$body.$replacement.$flags;
            }
        }

        return $regex;
    }

    /**
     * The opening delimiter, the body, the closing delimiter and the flags,
     * read as PHP reads them: white space skipped, the body ending at the
     * first delimiter no backslash escapes (at the bracket closing the
     * opening one, for a bracket pair). Null when PHP refuses the pattern
     * before its body: empty, a letter, a digit, a backslash or NUL as the
     * delimiter, no closing delimiter.
     *
     * @return array{string, string, string, string}|null
     */
    public static function split(string $regex): ?array
    {
        $trimmed = ltrim($regex, self::WHITE_SPACE);
        if ('' === $trimmed) {
            return null;
        }

        $open = $trimmed[0];
        if (Ascii::isAlnum($open) || '\\' === $open || "\0" === $open) {
            return null;
        }

        $close = self::CLOSING_BRACKETS[$open] ?? $open;
        $length = \strlen($trimmed);
        $depth = 1;
        for ($index = 1; $index < $length; $index++) {
            $character = $trimmed[$index];
            if ('\\' === $character && $index + 1 < $length) {
                $index++;

                continue;
            }

            if ($close === $character) {
                $depth--;
                if ($open === $close || 0 === $depth) {
                    return [$open, substr($trimmed, 1, $index - 1), $close, substr($trimmed, $index + 1)];
                }
            } elseif ($open === $character) {
                $depth++;
            }
        }

        return null;
    }
}
