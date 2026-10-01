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

namespace PhpRegex\Linter\Extraction;

/**
 * A call whose argument at a known position carries a regex pattern.
 *
 * Covers the native preg_* functions as well as the wrappers shipped by
 * userland libraries (composer/pcre, nette/utils, ...) and any project
 * specific helper declared through configuration.
 *
 * @internal
 */
final readonly class PatternFunction
{
    /**
     * @param string $label           name used in reports, e.g. "preg_match" or "Preg::match"
     * @param int    $argumentIndex   zero-based position of the pattern argument
     * @param bool   $keysArePatterns when the argument is an array literal, whether its keys
     *                                hold the patterns (preg_replace_callback_array) rather
     *                                than its values (preg_replace)
     */
    public function __construct(
        public string $label,
        public int $argumentIndex = 0,
        public bool $keysArePatterns = false,
    ) {}
}
