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
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPUnit\Framework\TestCase;

final class GroupNumberingCollectorEdgeCasesTest extends TestCase
{
    /**
     * "(?|(a))": one branch, so the groups after it count on from its own.
     */
    public function test_collect_branch_reset_handles_non_alternation_child(): void
    {
        $reset = new GroupNode(new GroupNode(new LiteralNode('a', 3, 4), GroupType::Capturing, null, null, 2, 5), GroupType::BranchReset, null, null, 0, 6);
        $after = new GroupNode(new LiteralNode('b', 7, 8), GroupType::Capturing, null, null, 6, 9);
        $root = new RegexNode(new SequenceNode([$reset, $after], 0, 9), '', '/', 0, 9);

        $result = (new GroupNumberingCollector())->collect($root);

        $this->assertSame(2, $result->maxGroupNumber);
        $this->assertSame([1, 2], $result->captureSequence);
        $this->assertSame([], $result->namedGroups);
    }
}
