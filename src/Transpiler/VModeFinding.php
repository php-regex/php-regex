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

namespace PHPRegex\Transpiler;

/**
 * One construct of a pattern that a browser reads otherwise than PCRE under
 * the v flag, or refuses to compile, as VModeCompatibility::check() finds
 * it.
 */
final class VModeFinding
{
    /**
     * @internal built by VModeCompatibility::check()
     *
     * @param int    $offset    where the construct is written: a byte offset into the body of the pattern
     * @param string $construct what is written there, as "possessive", "atomic", "class_syntax",
     *                          "escape" or "property"; an open set
     * @param string $message   what the browser does with it, and what to write instead when there is a spelling
     * @param bool   $refused   whether the browser refuses to compile the pattern for it; false when it
     *                          compiles the pattern and reads the construct otherwise
     */
    public function __construct(
        public readonly int $offset,
        public readonly string $construct,
        public readonly string $message,
        public readonly bool $refused,
    ) {}
}
