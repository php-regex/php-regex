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

namespace PHPRegex\Toolkit;

/**
 * Output formats accepted by Regex::explain() and Regex::highlight().
 */
enum OutputFormat: string
{
    case Text = 'text';
    case Html = 'html';
    case Console = 'console';
}
