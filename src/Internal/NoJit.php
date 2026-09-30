<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Internal;

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
    private const WHITE_SPACE = " \t\n\r\v\f";

    private const HELD_BY_THE_VERB = ['*', '_', ')'];

    private const REPLACEMENTS = ["\x01", '#', '~', '%', '!', '@', ';', ','];

    public static function pattern(string $regex): string
    {
        $trimmed = ltrim($regex, self::WHITE_SPACE);
        $delimiter = substr($trimmed, 0, 1);
        if (!\in_array($delimiter, self::HELD_BY_THE_VERB, true)) {
            return $delimiter.'(*NO_JIT)'.substr($trimmed, 1);
        }

        $end = strrpos($trimmed, $delimiter);
        if (false === $end || 0 === $end) {
            return $regex;
        }

        $body = substr($trimmed, 1, $end - 1);
        foreach (self::REPLACEMENTS as $replacement) {
            if (!str_contains($body, $replacement)) {
                return $replacement.'(*NO_JIT)'.$body.$replacement.substr($trimmed, $end + 1);
            }
        }

        return $regex;
    }
}
