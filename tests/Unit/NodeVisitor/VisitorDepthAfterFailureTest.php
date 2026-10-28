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

use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\Analysis\MetricsCollector;
use PHPRegex\Parser\Node\AbstractNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A visitor reused after a run that failed half-way reads the next tree as
 * a fresh one: the depth it keeps comes back to zero whatever happened.
 */
final class VisitorDepthAfterFailureTest extends TestCase
{
    #[Test]
    public function test_the_scorer_scores_the_next_tree_as_a_fresh_one(): void
    {
        $scorer = new ComplexityScorer();
        $tree = Regex::create(['cache' => null])->parse('/a+/');
        $fresh = $tree->accept($scorer);

        $this->assertFailsHalfway($scorer);

        $this->assertSame($fresh, $tree->pattern->accept($scorer));
    }

    #[Test]
    public function test_the_collector_counts_the_next_tree_as_a_fresh_one(): void
    {
        $collector = new MetricsCollector();
        $tree = Regex::create(['cache' => null])->parse('/a+/');
        $fresh = $tree->accept($collector);

        $this->assertFailsHalfway($collector);

        $this->assertSame($fresh, $tree->accept($collector));
    }

    /**
     * @param NodeVisitorInterface<mixed> $visitor
     */
    private function assertFailsHalfway(NodeVisitorInterface $visitor): void
    {
        $failing = new class(0, 1) extends AbstractNode {
            public function accept(NodeVisitorInterface $visitor): never
            {
                throw new \LogicException('halfway');
            }
        };

        try {
            (new QuantifierNode($failing, '+', QuantifierType::Greedy, 0, 2))->accept($visitor);
            $this->fail('The run did not fail.');
        } catch (\LogicException $e) {
            $this->assertSame('halfway', $e->getMessage());
        }
    }
}
