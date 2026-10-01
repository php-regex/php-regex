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

namespace PhpRegex\Linter;

enum DiagnosticType: string
{
    case Syntax = 'syntax';
    case Semantic = 'semantic';
    case Lint = 'lint';
    case Security = 'security';
    case Optimization = 'optimization';
}
