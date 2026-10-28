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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 ends a repeat whose iteration matched empty: a body that can match
 * empty but holds no unbounded loop splits the input one way, and the
 * heuristics, which judge what the model cannot read, call it a medium
 * risk. A body that also holds a loop stays the hotspot it is.
 */
final class RedosEmptyBodyHeuristicTest extends TestCase
{
    /**
     * PHP 8.4.27, PCRE2 10.49, no JIT: 0.12 ms on 100,000 "b" and a "!".
     */
    #[Test]
    public function test_an_empty_body_without_a_loop_is_a_medium_risk(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/((?(1)|b))+/');

        $this->assertSame(RedosProof::Heuristic, $analysis->proof);
        $this->assertSame(RedosSeverity::Medium, $analysis->severity);
    }

    #[Test]
    public function test_an_empty_body_holding_a_loop_stays_critical(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/((?(1)|a*))+b/');

        $this->assertSame(RedosProof::Heuristic, $analysis->proof);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
    }
}
