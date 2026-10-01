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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPUnit\Framework\TestCase;

final class GroupNumberingCollectorEdgeCasesTest extends TestCase
{
    public function test_collect_branch_reset_handles_non_alternation_child(): void
    {
        $collector = new GroupNumberingCollector();
        $group = new GroupNode(new LiteralNode('a', 0, 0), GroupType::BranchReset, null, null, 0, 0);

        $method = (new \ReflectionClass($collector))->getMethod('collectBranchReset');
        $result = $method->invoke($collector, $group);

        $this->assertNull($result);
    }
}
