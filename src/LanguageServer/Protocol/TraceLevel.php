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

namespace PHPRegex\LanguageServer\Protocol;

/**
 * The protocol's TraceValue: how much of each frame the server logs.
 *
 * @internal
 */
enum TraceLevel: string
{
    case Off = 'off';
    case Messages = 'messages';
    case Verbose = 'verbose';
}
