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

/**
 * Categorizes validation errors for diagnostics and UX.
 */
enum ValidationErrorCategory: string
{
    case Syntax = 'syntax';
    case Semantic = 'semantic';
    case PcreRuntime = 'pcre-runtime';
}
