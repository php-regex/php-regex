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

namespace PhpRegex\Parser\Node;

/**
 * Categorizes the different character literal escape syntaxes.
 */
enum CharLiteralType: string
{
    case Unicode = 'unicode';
    case UnicodeNamed = 'unicode_named';
    case Octal = 'octal';
    case OctalLegacy = 'octal_legacy';

    public function label(): string
    {
        return match ($this) {
            self::Unicode => 'Unicode',
            self::UnicodeNamed => 'Unicode named',
            self::Octal => 'Octal',
            self::OctalLegacy => 'Legacy Octal',
        };
    }
}
