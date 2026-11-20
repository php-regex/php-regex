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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\Backtrack\Budget;
use PHPRegex\Redos\Exception\ModelLimitException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A budget drawn from a pool takes its steps from the pool too: the budgets
 * that share one stop together once it runs out, each within its own limit
 * still.
 */
final class BudgetPoolTest extends TestCase
{
    #[Test]
    public function test_the_budgets_of_one_pool_stop_once_it_runs_out(): void
    {
        $pool = new Budget(10, 1);
        $first = new Budget(8, 100, $pool);
        $first->step(6);
        $second = new Budget(8, 100, $pool);
        $second->step(4);

        try {
            $second->step();
            $this->fail('The eleventh step of the pool goes past it.');
        } catch (ModelLimitException $limit) {
            $this->assertTrue($limit->budgetExceeded);
            $this->assertSame('Steps over the analysis budget', $limit->getMessage());
        }

        $this->expectException(ModelLimitException::class);
        $first->state();
    }

    #[Test]
    public function test_a_budget_of_a_pool_stops_at_its_own_limit_first(): void
    {
        $pool = new Budget(100, 1);
        $budget = new Budget(5, 100, $pool);
        $budget->step(5);

        try {
            $budget->step();
            $this->fail('The sixth step goes past the budget\'s own limit.');
        } catch (ModelLimitException $limit) {
            $this->assertTrue($limit->budgetExceeded);
        }

        // The step past the budget's own limit is not drawn from the pool.
        $other = new Budget(100, 100, $pool);
        $other->step(95);
        $this->expectException(ModelLimitException::class);
        $other->step();
    }
}
