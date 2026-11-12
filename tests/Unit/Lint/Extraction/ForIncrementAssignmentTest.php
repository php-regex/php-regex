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
 * What a for runs after each turn is written in its head, before the
 * body, and runs after it: an assignment written there reaches a read of
 * the body from the second turn on, as one written at the end of the body
 * would.
 *
 * PHP 8.4 (php -r): $part = 'x'; for ($i = 0; $i < 3; $i++, $part = 'y')
 * { $seen[] = '/a' . $part . '/'; } leaves "/ax/", "/ay/", "/ay/" in
 * $seen.
 *
 * Every case runs through both strategies.
 */
final class ForIncrementAssignmentTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Read alone, the variable is one of the two patterns.
     */
    #[Test]
    public function test_a_variable_read_alone_holds_what_the_head_assigns_after_each_turn(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): void
            {
                $re = '/first-turn/';
                for ($i = 0; $i < 3; $i++, $re = '/next-turns/') {
                    preg_match($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/first-turn/', 'at' => "'/first-turn/'", 'location' => 'via $re'],
            ['pattern' => '/next-turns/', 'at' => "'/next-turns/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * Both assignments are written in the head: the one that starts the
     * loop and the one that follows each turn.
     */
    #[Test]
    public function test_a_variable_assigned_twice_in_the_head_holds_either_value(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): void
            {
                for ($i = 0, $re = '/first-turn/'; $i < 3; $i++, $re = '/next-turns/') {
                    preg_match($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/first-turn/', 'at' => "'/first-turn/'", 'location' => 'via $re'],
            ['pattern' => '/next-turns/', 'at' => "'/next-turns/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * Joined to other texts, the variable is read when one assignment
     * alone may reach the read: here two do, the one before the loop and
     * the one the head runs after each turn, and "/ax/" alone would be a
     * pattern the second turn never builds.
     */
    #[Test]
    public function test_a_composed_read_is_reached_by_what_the_head_assigns_after_each_turn(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): void
            {
                $part = 'x';
                for ($i = 0; $i < 3; $i++, $part = 'y') {
                    preg_match('/a' . $part . '/', $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/a' . \$part . '/'"],
        ], $code);
    }

    /**
     * At file level the first turn assigns "/first-turn/" before the read;
     * the head assigns "/next-turns/" after each turn, and the second turn
     * reads that.
     */
    #[Test]
    public function test_a_variable_of_the_file_level_is_reached_by_what_the_head_assigns_after_each_turn(): void
    {
        $code = <<<'PHP'
            <?php

            for ($turn = 0; $turn < 2; $turn++, $re = '/next-turns/') {
                if ($turn === 0) {
                    $re = '/first-turn/';
                }

                preg_match($re, $subject);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $code);
    }
}
