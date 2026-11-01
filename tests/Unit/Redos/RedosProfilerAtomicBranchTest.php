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

use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Finding;
use PHPRegex\Redos\RedosProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repeat whose body matches empty is a hotspot; when the body also calls
 * the pattern and one of its branches is atomic and nullable, the engine
 * gives up part of the backtracking, and the finding says so. Both shapes
 * compile on PHP 8.4 with PCRE2 10.49; a match attempt exhausts the engine's
 * stack, the unguarded recursion being the point.
 */
final class RedosProfilerAtomicBranchTest extends TestCase
{
    #[Test]
    #[DataProvider('bodies')]
    public function test_an_atomic_nullable_branch_beside_a_recursion_tempers_the_empty_repeat(string $pattern, string $risk): void
    {
        $this->assertNull((new PcreEngine())->compile($pattern), 'The engine compiles the pattern.');

        $profiler = new RedosProfiler();
        RegexParser::create()->parse($pattern)->accept($profiler);

        $emptyRepeat = array_values(array_filter($profiler->getResult()['findings'], static fn (Finding $finding): bool => 'quantifier repeating empty' === $finding->trigger));

        $this->assertCount(1, $emptyRepeat);
        $this->assertSame($risk, $emptyRepeat[0]->falsePositiveRisk);
    }

    /**
     * @return iterable<string, array{pattern: string, risk: string}>
     */
    public static function bodies(): iterable
    {
        yield 'atomic nullable branch' => [
            'pattern' => '/(?:a|(?R)?|(?>b?))+/',
            'risk' => 'Medium false-positive risk; possessive or atomic branches with recursion can reduce backtracking.',
        ];
        yield 'plain nullable branch' => [
            'pattern' => '/(?:a|(?R)?|b?)+/',
            'risk' => 'Medium false-positive risk; an iteration that matches empty ends the repeat, and the body holds no loop.',
        ];
    }
}
