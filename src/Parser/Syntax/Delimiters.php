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

namespace PHPRegex\Parser\Syntax;

/**
 * The delimiters PHP reads around a pattern: a bracket pair, or one
 * character on both sides. It depends on nothing, so the engine and the
 * printer read it without the pattern parser.
 *
 * @internal
 */
final class Delimiters
{
    /**
     * The four bracket pairs PHP reads as delimiters, each opening bracket
     * with the one that closes it.
     */
    public const BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    private function __construct() {}

    /**
     * The delimiter that closes a pattern opened with $delimiter.
     */
    public static function closing(string $delimiter): string
    {
        return self::BRACKETS[$delimiter] ?? $delimiter;
    }
}
