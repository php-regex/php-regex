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

namespace PHPRegex\Explain;

use PHPRegex\Parser\Node\RegexNode;

/**
 * The settings in force where a construct stands, which change what it
 * matches: whether the engine reads the pattern as UTF.
 *
 * @internal
 */
final class MatchSettings
{
    public function __construct(
        /**
         * Whether the pattern is read as UTF-8, a character at a time: the u
         * flag, or a leading "(*UTF)".
         */
        public readonly bool $utf = false,
    ) {}

    /**
     * The settings a pattern starts with, from its flags and its leading
     * options.
     */
    public static function of(RegexNode $regex): self
    {
        return new self($regex->isUnicode());
    }
}
