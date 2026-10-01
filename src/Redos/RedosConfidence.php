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
 * Represents confidence in static ReDoS findings.
 */
enum RedosConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
