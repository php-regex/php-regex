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

namespace PhpRegex\Automata\Options;

/**
 * Defines how regex matching is interpreted for automata comparisons.
 */
enum MatchMode: string
{
    /**
     * Match the full input (implicit ^...$).
     */
    case Full = 'full';

    /**
     * Match a substring of the input (search semantics).
     */
    case Partial = 'partial';
}
