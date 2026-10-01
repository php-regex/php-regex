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

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Redos\ConfirmationOptions;
use PhpRegex\Redos\ConfirmationRunner;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReDoSConfirmationRunnerTest extends TestCase
{
    /**
     * Oracle: without the JIT, "^(a+)+$" over twenty-one "a" and a "!" fails
     * with no error under a billion backtracks, and takes tens of
     * milliseconds doing so: far past the one millisecond allowed here. The
     * run stops at the first length, before the longer one.
     */
    #[Test]
    public function test_a_run_slower_than_the_timeout_stops_timed_out_without_confirming(): void
    {
        $limit = (string) \ini_get('pcre.backtrack_limit');
        \ini_set('pcre.backtrack_limit', '1000000000');

        try {
            $oracle = preg_match('/(*NO_JIT)^(a+)+$/', str_repeat('a', 21).'!');
        } finally {
            \ini_set('pcre.backtrack_limit', $limit);
        }
        $this->assertSame(0, $oracle, 'The oracle matches, or gives up.');

        $confirmation = (new ConfirmationRunner())->confirm(
            '/^(a+)+$/',
            new RedosAnalysis(RedosSeverity::High, 8),
            new ConfirmationOptions(
                minInputLength: 22,
                maxInputLength: 23,
                steps: 2,
                iterations: 3,
                timeoutMs: 1.0,
                backtrackLimit: 1_000_000_000,
                recursionLimit: 1_000_000,
            ),
        );

        $this->assertTrue($confirmation->timedOut);
        $this->assertFalse($confirmation->confirmed);
        $this->assertNull($confirmation->evidence);
        $this->assertNull($confirmation->error);
        $this->assertCount(1, $confirmation->samples);
        $this->assertSame(22, $confirmation->samples[0]->inputLength);
        $this->assertGreaterThan(1.0, $confirmation->samples[0]->durationMs);
        $this->assertNull($confirmation->samples[0]->pregErrorCode);
    }
}
