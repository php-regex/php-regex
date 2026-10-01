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

namespace PhpRegex\Automata\Determinization;

use PhpRegex\Automata\Support\WorkBudget;

/**
 * Allows determinization algorithms to receive a work budget.
 *
 * @internal
 */
interface WorkBudgetAwareDeterminizationAlgorithmInterface
{
    public function setWorkBudget(?WorkBudget $budget): void;
}
