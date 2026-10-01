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

namespace PHPRegex\Redos;

/**
 * Defines how ReDoS findings are reported.
 */
enum RedosMode: string
{
    /**
     * Skip ReDoS analysis entirely.
     */
    case Off = 'off';

    /**
     * Structural (static) analysis only.
     */
    case Theoretical = 'theoretical';

    /**
     * Attempt to confirm findings with bounded runtime evidence.
     */
    case Confirmed = 'confirmed';
}
