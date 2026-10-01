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
use PHPUnit\Framework\TestCase;

final class LengthRangeNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_alternation_with_infinite_branch_returns_null_max(): void
    {
        $visitor = new LengthRangeCalculator();
        $literal = new LiteralNode('a', 0, 0);
        $infinite = new QuantifierNode(new LiteralNode('b', 0, 0), '*', QuantifierType::Greedy, 0, 0);
        $alternation = new AlternationNode([$literal, $infinite], 0, 0);

        $range = $alternation->accept($visitor);

        $this->assertSame([0, null], $range);
    }
}
