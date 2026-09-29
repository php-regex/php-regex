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

namespace RegexParser\Tests\TestUtils;

/**
 * Where the running PHP reports that a pattern does not compile.
 *
 * PCRE2 moved many of these offsets across its releases (10.47 reports most
 * syntax errors past the character at fault), so a test that pins the offset
 * one release gives only holds on that release: the running PHP is the judge.
 */
final class PhpErrorOffset
{
    public static function of(string $pattern): ?int
    {
        $message = null;
        set_error_handler(static function (int $level, string $text) use (&$message): bool {
            $message = $text;

            return true;
        });

        try {
            $compiled = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if (false !== $compiled || null === $message || 1 !== preg_match('/at offset (\d+)/', $message, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Whether the running PHP links PCRE2 10.47 or later, the release whose
     * offsets the pinned expectations were written against.
     */
    public static function runsPcre1047(): bool
    {
        return version_compare(explode(' ', \PCRE_VERSION)[0], '10.47', '>=');
    }
}
