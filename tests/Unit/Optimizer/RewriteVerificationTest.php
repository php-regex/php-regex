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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A rewrite ships only if it writes the same $matches as the pattern: the
 * same strings are not enough, as /(a|ab)/ and /(ab|a)/ show on "ab". Where
 * the match solver cannot read a pattern, the language solver judges it.
 */
final class RewriteVerificationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRewrites')]
    public function test_a_rewrite_is_judged_on_the_matches_it_writes(string $original, string $rewrite, ?bool $verdict): void
    {
        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertSame($verdict, $verify->invoke(new Optimizer(RegexParser::create()), $original, $rewrite));
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, verdict: bool|null}>
     */
    public static function provideRewrites(): iterable
    {
        yield 'branches swapped: same strings, other groups' => ['original' => '/(a|ab)/', 'rewrite' => '/(ab|a)/', 'verdict' => false];
        yield 'shorter branch first, made lazy' => ['original' => '/a|ab/', 'rewrite' => '/a(?:b)??/', 'verdict' => true];
        yield 'class for an alternation' => ['original' => '/(a|b)c/', 'rewrite' => '/([ab])c/', 'verdict' => true];
        yield 'possessive, judged on the language' => ['original' => '/a+b/', 'rewrite' => '/a++b/', 'verdict' => true];
        yield 'other strings' => ['original' => '/ab/', 'rewrite' => '/ac/', 'verdict' => false];
        yield 'beyond both solvers' => ['original' => '/(a)\1/', 'rewrite' => '/(a)a/', 'verdict' => null];
    }
}
