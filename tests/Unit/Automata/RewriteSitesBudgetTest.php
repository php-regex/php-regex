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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Solver\RewriteSites;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RewriteSitesBudgetTest extends TestCase
{
    #[Test]
    public function test_alignment_is_bounded_before_allocating_its_table(): void
    {
        $left = new ConcatHir(array_fill(0, 256, new LiteralHir([97])));
        $right = new ConcatHir(array_fill(0, 257, new LiteralHir([98])));

        $this->expectException(ComplexityException::class);
        RewriteSites::between($left, $right, aligned: true);
    }

    #[Test]
    public function test_the_alignment_budget_is_shared_by_all_sites(): void
    {
        $left = new ConcatHir(array_fill(0, 181, new LiteralHir([97])));
        $right = new ConcatHir(array_fill(0, 182, new LiteralHir([98])));

        // Either table fits alone, but their combined work exceeds the cap.
        $this->assertNotEmpty(RewriteSites::between($left, $right, aligned: true));
        $this->expectException(ComplexityException::class);
        RewriteSites::between(new ConcatHir([$left, $left]), new ConcatHir([$right, $right]), aligned: true);
    }
}
