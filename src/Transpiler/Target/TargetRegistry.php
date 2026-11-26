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

namespace PHPRegex\Transpiler\Target;

use PHPRegex\Transpiler\Dialect;
use PHPRegex\Transpiler\Target\JavaScript\HtmlPatternTarget;
use PHPRegex\Transpiler\Target\JavaScript\JavaScriptTarget;
use PHPRegex\Transpiler\Target\Python\PythonTarget;

/**
 * The target that writes each dialect.
 *
 * @internal
 */
final class TargetRegistry
{
    public function get(Dialect $dialect): TargetInterface
    {
        return match ($dialect) {
            Dialect::JavaScript => new JavaScriptTarget(),
            Dialect::HtmlPattern => new HtmlPatternTarget(),
            Dialect::Python => new PythonTarget(),
        };
    }
}
