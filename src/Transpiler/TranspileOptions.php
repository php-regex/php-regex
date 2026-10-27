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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * Configuration for regex transpilation.
 */
final class TranspileOptions
{
    /**
     * @param bool        $allowLookbehind whether the JavaScript targets may write a lookbehind
     * @param string|null $pythonVersion   the oldest Python the pattern runs on, as "3.11" or "3.11.4": from
     *                                     3.11 atomic groups and possessive quantifiers are written; before
     *                                     it, or with null, which assumes none, they are refused
     *
     * @throws InvalidRegexOptionException when the Python version is no release number
     */
    public function __construct(public readonly bool $allowLookbehind = true, public readonly ?string $pythonVersion = null)
    {
        if (null !== $pythonVersion && 1 !== LibraryPcre::match('/^\d+\.\d+(?:\.\d+)?$/D', $pythonVersion)) {
            throw new InvalidRegexOptionException(\sprintf('"pythonVersion" must be a Python release, as "3.11" or "3.11.4", "%s" given.', $pythonVersion));
        }
    }
}
