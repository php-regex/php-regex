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

namespace RegexParser\Automata\Minimization;

use RegexParser\Automata\Support\WorkBudget;

/**
 * Optional hook to enforce work budgets inside minimization algorithms.
 *
 * @internal
 */
interface WorkBudgetAwareMinimizationAlgorithmInterface
{
    public function setWorkBudget(?WorkBudget $budget): void;
}
