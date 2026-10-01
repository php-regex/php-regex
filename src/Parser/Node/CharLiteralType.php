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
    case UNICODE = 'unicode';
    case UNICODE_NAMED = 'unicode_named';
    case OCTAL = 'octal';
    case OCTAL_LEGACY = 'octal_legacy';

    public function label(): string
    {
        return match ($this) {
            self::UNICODE => 'Unicode',
            self::UNICODE_NAMED => 'Unicode named',
            self::OCTAL => 'Octal',
            self::OCTAL_LEGACY => 'Legacy Octal',
        };
    }
}
