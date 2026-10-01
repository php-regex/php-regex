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

namespace PhpRegex\Redos;

/**
 * Defines how ReDoS findings are reported.
 */
enum RedosMode: string
{
    /**
     * Skip ReDoS analysis entirely.
     */
    case OFF = 'off';

    /**
     * Structural (static) analysis only.
     */
    case THEORETICAL = 'theoretical';

    /**
     * Attempt to confirm findings with bounded runtime evidence.
     */
    case CONFIRMED = 'confirmed';
}
