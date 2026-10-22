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

namespace PHPRegex\Parser\Validation;

class ValidationErrorCategory
{
    public const Syntax = 'syntax';
    public const Semantic = 'semantic';
    public const PcreRuntime = 'pcre-runtime';
    public const Limit = 'limit';
}
