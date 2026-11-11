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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A variable of the file level read in a loop holds what the turn
 * assigned it before the read, unless something written after the read
 * runs before it at a later turn: an assignment of the loop written
 * after the read, or the assignment whose own value holds the read. A
 * goto does the same of the whole file. An assignment written after the
 * loop has ended reaches no read of the loop.
 *
 * PHP 8.4 (php -r): $turn = 0; while ($turn < 3) { if ($turn === 0) { $re
 * = '/first-turn/'; } $seen[] = $re; $re = '/next-turns/'; $turn = $turn
 * + 1; } leaves "/first-turn/", "/next-turns/", "/next-turns/" in $seen.
 *
 * Every case runs through both strategies.
 */
final class FileLevelLoopTurnTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The first turn assigns "/first-turn/" before the read; the second
     * reads the "/next-turns/" assigned after the read of the first.
     */
    #[Test]
    public function test_an_assignment_after_the_read_reaches_it_at_the_next_turn(): void
    {
        $code = <<<'PHP'
            <?php

            $turn = 0;
            while ($turn < 3) {
                if ($turn === 0) {
                    $re = '/first-turn/';
                }

                preg_match($re, $subject);
                $re = '/next-turns/';
                $turn = $turn + 1;
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $code);
    }

    /**
     * The read stands in the value of the assignment: the second turn
     * reads "/matched/" or "/missed/", which that assignment gave the
     * variable at the first.
     */
    #[Test]
    public function test_the_assignment_whose_value_holds_the_read_reaches_it_at_the_next_turn(): void
    {
        $code = <<<'PHP'
            <?php

            $turn = 0;
            while ($turn < 3) {
                if ($turn === 0) {
                    $re = '/first-turn/';
                }

                $re = preg_match($re, $subject) ? '/matched/' : '/missed/';
                $turn = $turn + 1;
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $code);
    }

    /**
     * "/after-the-loop/" is assigned once the loop has ended: no turn
     * reads it.
     */
    #[Test]
    public function test_an_assignment_after_the_loop_reaches_no_read_of_the_loop(): void
    {
        $code = <<<'PHP'
            <?php

            $turn = 0;
            while ($turn < 3) {
                $re = '/in-the-turn/';
                preg_match($re, $subject);
                $turn = $turn + 1;
            }

            $re = '/after-the-loop/';
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/in-the-turn/', 'at' => "'/in-the-turn/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $code);
    }

    /**
     * The goto runs the file again from its label: the second pass reads
     * the "/next-passes/" assigned after the read of the first.
     */
    #[Test]
    public function test_an_assignment_after_the_read_reaches_it_once_a_goto_runs_the_file_again(): void
    {
        $code = <<<'PHP'
            <?php

            again:
            if ($argc === 1) {
                $re = '/first-pass/';
            }

            preg_match($re, $subject);
            $re = '/next-passes/';
            if ($argc > 1) {
                goto again;
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $code);
    }
}
