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

use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regex::optimize() and Regex::analyze() offer a rewrite only once the
 * automata prove it writes the same $matches, as the lint, the PHPStan
 * rule and the editor's quick fix do: one verdict wherever a rewrite is
 * shown. A rewrite the automata cannot prove comes back as the pattern
 * was written, unless the caller turns the proof off.
 */
final class VerifiedByDefaultTest extends TestCase
{
    #[Test]
    public function test_the_options_verify_by_default(): void
    {
        $this->assertTrue((new OptimizerOptions())->verifyWithAutomata);
        $this->assertTrue(OptimizerOptions::fromArray([])->verifyWithAutomata);
        $this->assertFalse(OptimizerOptions::fromArray(['verify_with_automata' => false])->verifyWithAutomata);
    }

    #[Test]
    public function test_a_proven_rewrite_ships_and_an_unproven_one_waits_for_the_caller(): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame('/\d+/', $regex->optimize('/[0-9]+/')->optimized);
        $this->assertSame('/\d+/', $regex->analyze('/[0-9]+/')->optimization->optimized);

        // Four lookbehinds counted: the automata read no repeated
        // lookbehind, so the rewrite is offered only when asked for.
        $unproven = '/\$(?<!ab)(?<!ab)(?<!ab)(?<!ab)/';
        $this->assertSame($unproven, $regex->optimize($unproven)->optimized);
        $this->assertSame('/\$(?<!ab){4}/', $regex->optimize($unproven, new OptimizerOptions(verifyWithAutomata: false))->optimized);
    }
}
