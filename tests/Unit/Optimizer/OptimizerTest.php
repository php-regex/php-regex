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

namespace RegexParser\Tests\Unit\Optimizer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Optimizer\Optimizer;
use RegexParser\Regex;
use RegexParser\RegexParser;

final class OptimizerTest extends TestCase
{
    #[Test]
    public function test_the_optimization_is_the_one_the_facade_gives(): void
    {
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));

        $this->assertEquals(Regex::create(['cache' => null])->optimize('/[0-9]+/'), $optimizer->optimize('/[0-9]+/'));
    }

    /**
     * The automata read no backreference: the rewrite they cannot check is
     * kept as it is, not dropped.
     */
    #[Test]
    public function test_a_rewrite_the_automata_cannot_check_is_kept(): void
    {
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));

        $this->assertSame('/(a)\\d\\1/', $optimizer->optimize('/(a)[0-9]\\1/', ['verify_with_automata' => true])->optimized);
        $this->assertSame('/\\d+/', $optimizer->optimize('/[0-9]+/', ['verify_with_automata' => true])->optimized);
    }
}
