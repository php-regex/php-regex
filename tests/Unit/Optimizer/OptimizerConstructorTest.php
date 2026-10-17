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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The check of a rewrite builds no DFA a cache could keep: the match solver
 * builds a priority automaton per pattern, and the proof of a changed part
 * an automaton of that part alone. The optimizer takes its parser and
 * nothing else.
 */
final class OptimizerConstructorTest extends TestCase
{
    #[Test]
    public function test_the_optimizer_takes_its_parser_alone(): void
    {
        $parameters = (new \ReflectionMethod(Optimizer::class, '__construct'))->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame(RegexParser::class, (string) $parameters[0]->getType());
    }

    /**
     * A rewrite the solver refuses, here a possessive over a branch of
     * several characters, is kept back on every run of one instance.
     */
    #[Test]
    public function test_a_refusal_keeps_the_rewrite_back_on_every_run(): void
    {
        $optimizer = new Optimizer(Regex::create()->parser());

        $this->assertSame('/(?:ab|a)+c/', $optimizer->optimize('/(?:ab|a)+c/', ['possessive' => true])->optimized);
        $this->assertSame('/(?:ab|a)+c/', $optimizer->optimize('/(?:ab|a)+c/', ['possessive' => true])->optimized);
        $this->assertSame('/a++b/', $optimizer->optimize('/a+b/', ['possessive' => true])->optimized);
    }
}
