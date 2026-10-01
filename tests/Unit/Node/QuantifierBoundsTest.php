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

namespace PhpRegex\Tests\Unit\Node;

use PhpRegex\Parser\Node\QuantifierBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantifierBoundsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null, int|null}>
     */
    public static function provide_quantifiers(): iterable
    {
        yield 'star' => ['*', 0, null];
        yield 'plus' => ['+', 1, null];
        yield 'question' => ['?', 0, 1];
        yield 'exact' => ['{3}', 3, 3];
        yield 'open range' => ['{2,}', 2, null];
        yield 'closed range' => ['{2,5}', 2, 5];
        yield 'omitted lower bound' => ['{,5}', 0, 5];
        yield 'zero exact' => ['{0}', 0, 0];
        yield 'lazy star' => ['*?', 0, null];
        yield 'possessive plus' => ['++', 1, null];
        yield 'lazy range' => ['{2,5}?', 2, 5];
        yield 'possessive range' => ['{2,}+', 2, null];
        yield 'whitespace inside braces' => ['{ 2 }', 2, 2];
        yield 'whitespace around comma' => ['{ 2 , 5 }', 2, 5];
        yield 'invalid empty braces' => ['{}', null, null];
        yield 'invalid bare comma' => ['{,}', null, null];
        yield 'invalid text' => ['{a}', null, null];
        yield 'not a quantifier' => ['abc', null, null];
    }

    #[DataProvider('provide_quantifiers')]
    public function test_parse(string $quantifier, ?int $min, ?int $max): void
    {
        $bounds = QuantifierBounds::parse($quantifier);

        if (null === $min) {
            $this->assertNotInstanceOf(QuantifierBounds::class, $bounds, "Expected '$quantifier' to be unparseable");

            return;
        }

        $this->assertInstanceOf(QuantifierBounds::class, $bounds, "Expected '$quantifier' to parse");
        $this->assertSame($min, $bounds->min);
        $this->assertSame($max, $bounds->max);
    }

    public function test_is_unbounded(): void
    {
        $this->assertTrue(QuantifierBounds::parse('*')?->isUnbounded());
        $this->assertTrue(QuantifierBounds::parse('{2,}')?->isUnbounded());
        $this->assertFalse(QuantifierBounds::parse('{2,5}')?->isUnbounded());
        $this->assertFalse(QuantifierBounds::parse('?')?->isUnbounded());
    }
}
