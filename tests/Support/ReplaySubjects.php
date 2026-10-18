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

namespace PHPRegex\Tests\Support;

/**
 * The subjects a rewrite is replayed on: shared ones (empty, a newline, bytes
 * above 0x7F, words), and around every string literal of the code: the
 * literal, repeated, surrounded, cut, with a newline, between high bytes and
 * upper-cased.
 */
final class ReplaySubjects
{
    public const SHARED = ['', "\n", "\r\n", "\xff", "\x80", "\0", 'x', ',', ',,', 'foo', 'FOO', 'GET', 'POST', 'aaa', "foo\n", 'it\'s', 'a$b'];

    /**
     * @return list<string>
     */
    public static function of(string $code): array
    {
        $subjects = self::SHARED;
        foreach (self::literals($code) as $literal) {
            if ('' === $literal) {
                continue;
            }

            array_push(
                $subjects,
                $literal,
                $literal.$literal,
                $literal.$literal.$literal,
                'x'.$literal,
                $literal.'x',
                'x'.$literal.'y'.$literal.'z',
                $literal."\n",
                "\n".$literal,
                "\xff".$literal."\x80",
                substr($literal, 1),
                substr($literal, 0, -1),
                strtoupper($literal),
            );
        }

        return array_values(array_unique($subjects));
    }

    /**
     * The values of the quoted string literals of the code, in order.
     *
     * @return list<string>
     */
    public static function literals(string $code): array
    {
        $literals = [];
        foreach (token_get_all($code) as $token) {
            if (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $literals[] = self::value($token[1]);
            }
        }

        return $literals;
    }

    /**
     * The value of a quoted PHP string token without interpolation.
     */
    private static function value(string $token): string
    {
        $body = substr($token, 1, -1);

        return "'" === $token[0] ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body);
    }
}
