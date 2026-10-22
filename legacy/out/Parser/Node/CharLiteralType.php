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

namespace PHPRegex\Parser\Node;

class CharLiteralType
{
    public const Unicode = 'unicode';
    public const UnicodeNamed = 'unicode_named';
    public const Octal = 'octal';
    public const OctalLegacy = 'octal_legacy';
    public function label(): string
    {
        switch ($this) {
            case self::Unicode:
                return 'Unicode';
            case self::UnicodeNamed:
                return 'Unicode named';
            case self::Octal:
                return 'Octal';
            case self::OctalLegacy:
                return 'Legacy Octal';
        }
    }
}
