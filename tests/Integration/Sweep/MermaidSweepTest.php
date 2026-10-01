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

namespace PhpRegex\Tests\Integration\Sweep;

use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Parser\Node\LimitMatchNode;
use PHPUnit\Framework\TestCase;

/**
 * A sweep of patterns through Mermaid.
 *
 * These cases were written to reach branches rather than to describe a
 * behaviour, and they were spread over eight files named after the metric
 * they served. They are grouped by what they exercise instead.
 */
final class MermaidSweepTest extends TestCase
{
    public function test_mermaid_visitor_limit_match(): void
    {
        $visitor = new MermaidRenderer();
        $node = new LimitMatchNode(1000, 0, 16);
        $result = $node->accept($visitor);
        $this->assertNotEmpty($result);
    }
}
