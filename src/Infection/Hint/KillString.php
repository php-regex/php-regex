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

namespace PHPRegex\Infection\Hint;

use PHPRegex\Parser\Syntax\Delimiters;

/**
 * A subject written as a PHP double-quoted literal a test can paste as is:
 * printable ASCII stays, every other byte is "\xNN". Escaped too are what a
 * double-quoted literal reads ("$", "\", the quote), what would end the
 * comment or the pattern the literal is written into (the parentheses, the
 * braces, "#", the delimiters), so the literal survives both.
 *
 * @internal
 */
final class KillString
{
    /**
     * The longest literal written, quotes aside: a longer one would crowd
     * the diff, and a cut one would not paste.
     */
    public const MAX_BYTES = 120;

    private const ALWAYS_ESCAPED = ['$', '\\', '"', '(', ')', '{', '}', '#'];

    private function __construct() {}

    /**
     * The literal, or null when it is longer than MAX_BYTES. The strict
     * form, for a pattern the literal stands in as syntax, leaves only
     * letters, digits and spaces as they are.
     */
    public static function encode(string $subject, string $delimiter, bool $strict = false): ?string
    {
        $escaped = [...self::ALWAYS_ESCAPED, $delimiter, Delimiters::closing($delimiter)];

        $literal = '';
        for ($i = 0, $length = \strlen($subject); $i < $length; $i++) {
            $byte = $subject[$i];
            $plain = $strict
                ? ' ' === $byte || ($byte >= '0' && $byte <= '9') || ($byte >= 'A' && $byte <= 'Z') || ($byte >= 'a' && $byte <= 'z')
                : \ord($byte) >= 0x20 && \ord($byte) < 0x7F && !\in_array($byte, $escaped, true);
            $literal .= $plain ? $byte : \sprintf('\x%02x', \ord($byte));
        }

        return \strlen($literal) > self::MAX_BYTES ? null : '"'.$literal.'"';
    }
}
