<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Visitor;

use PhpRegex\Generator\SampleGenerator;
use PHPUnit\Framework\TestCase;

final class SampleGeneratorEdgeCasesTest extends TestCase
{
    /**
     * Tests the private getRandomChar method with an empty array (impossible via the public API).
     * Covers the fallback "return '?';".
     */
    public function test_get_random_char_with_empty_array(): void
    {
        $generator = new SampleGenerator();
        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('getRandomChar');

        $result = $method->invoke($generator, []);
        $this->assertSame('?', $result);
    }

    /**
     * Tests the mt_rand fallback for quantifiers.
     * We can't easily force mt_rand to fail, but we can pass invalid bounds
     * to the private parseQuantifierRange method to see how it reacts.
     */
    public function test_parse_quantifier_range_fallback(): void
    {
        $generator = new SampleGenerator();
        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('parseQuantifierRange');

        // Cas invalide qui ne matche aucune regex connue
        $result = $method->invoke($generator, 'INVALID');
        // The code returns [0, 0] by default via the match
        $this->assertSame([0, 0], $result);
    }
}
