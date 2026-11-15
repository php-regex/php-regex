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

// PHP-Parser 4 reads "throw $e;" as a statement of its own, which 5 no
// longer has: the node 4 gives, declared here only where the installed
// parser lacks it.

namespace PhpParser\Node\Stmt;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;

if (!class_exists(Throw_::class)) {
    final class Throw_ extends Stmt
    {
        /**
         * @param array<string, mixed> $attributes
         */
        public function __construct(public Expr $expr, array $attributes = [])
        {
            parent::__construct($attributes);
        }

        public function getSubNodeNames(): array
        {
            return ['expr'];
        }

        public function getType(): string
        {
            return 'Stmt_Throw';
        }
    }
}

return new Throw_(new Variable('e'));
