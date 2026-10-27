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
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $this->assertSame('/(a)\\d\\1/', $optimizer->optimize('/(a)[0-9]\\1/', new OptimizerOptions(verifyWithAutomata: true))->optimized);
        $this->assertSame('/\\d+/', $optimizer->optimize('/[0-9]+/', new OptimizerOptions(verifyWithAutomata: true))->optimized);
    }

    /**
     * PHP does not count an escaped bracket of the delimiter, and counts one
     * in a comment: the optimized pattern keeps the escape, or its body ends
     * early. "{(?#{)\}b(?#})\d}x" matches "}b1" as the input does (PCRE2
     * 10.49).
     */
    #[Test]
    public function test_the_optimization_keeps_an_escaped_bracket_a_comment_does_not_balance(): void
    {
        $regex = Regex::create(['cache' => new NullCache()]);

        $this->assertSame('{(?#{)\}b(?#})\d}x', $regex->optimize('{(?#{)\}b(?#})[0-9]}x')->optimized);
    }

    #[Test]
    public function test_the_optimization_of_an_escaped_bracket_in_a_class_after_a_lookbehind_compiles(): void
    {
        $pattern = '<(?<!b)[\>](*MARK:>)[0-9]>x';
        $this->assertSame([1, 0], [preg_match($pattern, '>1'), preg_match($pattern, 'b>1')], 'The oracle on the input.');

        $optimized = Regex::create(['cache' => new NullCache()])->optimize($pattern)->optimized;

        $this->assertSame([1, 0], [@preg_match($optimized, '>1'), @preg_match($optimized, 'b>1')], $optimized);
    }
}
