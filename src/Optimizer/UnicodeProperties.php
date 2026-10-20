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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\RegexNode;

/**
 * Whether "\d", "\s" and "\w" read Unicode properties in a pattern: under
 * "/u", or a "(*UCP)" in the run of options the pattern opens with, the
 * only place PCRE reads it. They then match more than the ASCII classes
 * they abbreviate: "\d" takes U+0663 under "(*UTF)(*UCP)".
 *
 * @internal
 */
final class UnicodeProperties
{
    public static function on(RegexNode $ast): bool
    {
        return str_contains($ast->flags, 'u')
            || 1 === LibraryPcre::match('/^(?:\(\*[A-Z_0-9]++(?:=\d++)?\))*?\(\*UCP\)/', $ast->source ?? '');
    }
}
