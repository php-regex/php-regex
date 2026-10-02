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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Hir\Utf8;

/**
 * Writes raw bytes as the inside of a PHP double-quoted literal that reads
 * back as the same bytes: printable ASCII as it is but the characters PHP
 * interprets, "\n" and "\t", "\u{..}" for a code point past ASCII under
 * /u, "\xHH" for any other byte, the null byte included.
 *
 * @internal
 */
final class WitnessRenderer
{
    private const ESCAPES = ['"' => '\\"', '\\' => '\\\\', '$' => '\\$', "\n" => '\\n', "\t" => '\\t'];

    public static function literal(string $text, bool $unicode): string
    {
        $characters = $unicode ? Utf8::decode($text, true) : null;
        if (null === $characters) {
            $characters = Utf8::decode($text, false) ?? [];
            $unicode = false;
        }

        $literal = '';
        foreach ($characters as $codePoint) {
            if ($codePoint < 0x80) {
                $character = \chr($codePoint);
                $literal .= self::ESCAPES[$character]
                    ?? ($codePoint >= 0x20 && $codePoint < 0x7F ? $character : \sprintf('\\x%02X', $codePoint));

                continue;
            }

            $literal .= $unicode ? \sprintf('\\u{%X}', $codePoint) : \sprintf('\\x%02X', $codePoint);
        }

        return $literal;
    }
}
