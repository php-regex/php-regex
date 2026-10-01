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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Parser\Node\CharTypeNode;
use PHPUnit\Framework\TestCase;

final class GeneratorEdgeCaseTest extends TestCase
{
    /**
     * Tests the default case for an unknown character type.
     * The parser blocks this normally, so we inject the node manually.
     */
    public function test_generate_unknown_char_type(): void
    {
        $generator = new SampleGenerator();
        // 'Z' does not exist as a standard character type
        $node = new CharTypeNode('Z', 0, 0);

        $result = $node->accept($generator);

        // The default of the match is '?'
        $this->assertSame('?', $result);
    }

    /**
     * Tests parseQuantifierRange with a string that matches nothing.
     * (Defensive code via Reflection)
     */
    public function test_parse_quantifier_range_fallback(): void
    {
        $generator = new SampleGenerator();
        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('parseQuantifierRange');

        // Invalid value that doesn't match *, +, ? nor {n,m}
        $result = $method->invoke($generator, 'INVALID');

        // The default returns [0, 0]
        $this->assertSame([0, 0], $result);
    }
}
