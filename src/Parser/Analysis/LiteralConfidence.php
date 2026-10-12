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

namespace PHPRegex\Parser\Analysis;

/**
 * How much the literals of a pattern say about its matches: High when they
 * cover the whole match, Medium when a match also holds text they do not
 * fix, Low when no literal was found.
 */
enum LiteralConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
