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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LengthRangeCalculatorEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_alternation_with_infinite_branch_returns_null_max(): void
    {
        $visitor = new LengthRangeCalculator();
        $literal = new LiteralNode('a', 0, 0);
        $infinite = new QuantifierNode(new LiteralNode('b', 0, 0), '*', QuantifierType::Greedy, 0, 0);
        $alternation = new AlternationNode([$literal, $infinite], 0, 0);

        $range = $alternation->accept($visitor);

        $this->assertSame([0, null], $range);
    }

    /**
     * The parser takes a length no integer holds, which PCRE refuses as too
     * large (PHP 8.4.27, PCRE2 10.49):
     *   preg_match('/(?:(?:(?:(?:a{65535}){65535}){65535}){65535}){65535}/', '') -> false,
     *   "regular expression is too large". The minimum saturates at
     * PHP_INT_MAX, which stays below what the pattern reads, and the maximum
     * reads as unbounded: both are integers, or null, never a float.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLengthsPastPhpIntMax(): iterable
    {
        yield 'a product of repeats' => ['pattern' => '/(?:(?:(?:(?:a{65535}){65535}){65535}){65535}){65535}/'];
        yield 'a sum of two repeats each within an integer' => ['pattern' => '/(?:(?:(?:a{65535}){65535}){65535}){20000}(?:(?:(?:a{65535}){65535}){65535}){20000}/'];
        yield 'a repeat of a sum' => ['pattern' => '/(?:(?:(?:(?:a{65535}){65535}){65535}){20000}b){2}/'];
    }

    #[Test]
    #[DataProvider('provideLengthsPastPhpIntMax')]
    public function test_a_length_past_php_int_max_saturates_instead_of_turning_float(string $pattern): void
    {
        $range = RegexParser::create()->parse($pattern)->accept(new LengthRangeCalculator());

        $this->assertSame([\PHP_INT_MAX, null], $range);
    }

    #[Test]
    public function test_a_length_within_php_int_max_stays_exact(): void
    {
        // 65535 ** 3 * 20000 = 5629241840107500000, below PHP_INT_MAX.
        $range = RegexParser::create()->parse('/(?:(?:(?:a{65535}){65535}){65535}){20000}/')->accept(new LengthRangeCalculator());

        $this->assertSame([5629241840107500000, 5629241840107500000], $range);
    }
}
