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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The target of a foreach holds what the head of its loop gives it, and
 * which loop that is depends on where the read stands: one function may
 * iterate several arrays into one variable, one loop after another or one
 * inside another.
 *
 * In the body of a loop the target is one of the values of that loop's
 * array. After the loop it holds what the last turn left, which is not
 * followed. A loop inside another that names the same target takes it
 * again: the outer loop no longer proves what it holds. An assignment of
 * the target written out of the loop writes it before the head does, or
 * after the last turn; a call the target is passed to between the head and
 * the read may have written it, and so may one written after the read in
 * a loop that runs both again.
 *
 * PHP 8.4 (php -r): after foreach (['/a/', '/b/'] as $re) {}, $re is
 * "/b/"; with foreach (['/c/'] as $re) {} written in the body of that
 * loop, $re is "/c/" after the inner loop at each turn of the outer one.
 *
 * Every case runs through both strategies.
 */
final class ForeachTargetPlaceTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_each_of_three_loops_over_one_target_gives_its_own_array(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $t, string $u): void
            {
                foreach (['/first/'] as $re) {
                    preg_match($re, $s);
                }

                foreach (['/second/'] as $re) {
                    preg_match($re, $t);
                }

                foreach (['/third-a/', '/third-b/'] as $re) {
                    preg_match($re, $u);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
            ['pattern' => '/second/', 'at' => "'/second/'", 'location' => 'via $re'],
            ['pattern' => '/third-a/', 'at' => "'/third-a/'", 'location' => 'via $re'],
            ['pattern' => '/third-b/', 'at' => "'/third-b/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $t)'],
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $u)'],
        ], $code);
    }

    /**
     * The second loop is not read for the first: a read in the first loop
     * holds the values of the first array only, and one in the second
     * those of the second.
     *
     * @return iterable<string, array{first: string, second: string, patterns: list<string>}>
     */
    public static function provideTwoLoopsWithOneRead(): iterable
    {
        yield 'read in the first loop' => [
            'first' => 'preg_match($re, $s);',
            'second' => '$flag = !$flag;',
            'patterns' => ['/a/', '/b/'],
        ];

        yield 'read in the second loop' => [
            'first' => '$flag = !$flag;',
            'second' => 'preg_match($re, $s);',
            'patterns' => ['/c/', '/d/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideTwoLoopsWithOneRead')]
    public function test_a_read_holds_the_array_of_the_loop_it_stands_in(string $first, string $second, array $patterns): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n"
            ."    foreach (['/a/', '/b/'] as \$re) {\n        ".$first."\n    }\n\n"
            ."    foreach (['/c/', '/d/'] as \$re) {\n        ".$second."\n    }\n}\n";

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * $re is "/b/" after the loop, or was never assigned when the array is
     * empty: what the last turn left is not followed.
     */
    #[Test]
    public function test_a_target_read_after_its_loop_is_not_read(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): void
            {
                foreach (['/a/', '/b/'] as $re) {
                    $flag = !$flag;
                }

                preg_match($re, $s);

                foreach (['x', 'y'] as $part) {
                    $flag = !$flag;
                }

                preg_match('/^' . $part . '$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '$/'"],
        ], $code);
    }

    /**
     * The inner loop takes the target again. In its body the target is one
     * of the values of the inner array; around it, in the body of the
     * outer loop, nothing is known: before the inner loop the reading
     * gives up, after it the target holds what the inner loop left.
     *
     * @return iterable<string, array{before: string, inside: string, after: string, patterns: list<string>, state: string}>
     */
    public static function provideReadsAroundAnInnerLoop(): iterable
    {
        yield 'read before the inner loop' => [
            'before' => 'preg_match($re, $s);',
            'inside' => '$flag = !$flag;',
            'after' => '$flag = !$flag;',
            'patterns' => [],
            'state' => 'opaque',
        ];

        yield 'read in the inner loop' => [
            'before' => '$flag = !$flag;',
            'inside' => 'preg_match($re, $s);',
            'after' => '$flag = !$flag;',
            'patterns' => ['/c/', '/d/'],
            'state' => 'finite_set',
        ];

        yield 'read after the inner loop' => [
            'before' => '$flag = !$flag;',
            'inside' => '$flag = !$flag;',
            'after' => 'preg_match($re, $s);',
            'patterns' => [],
            'state' => 'opaque',
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideReadsAroundAnInnerLoop')]
    public function test_a_loop_inside_another_takes_the_target_again(string $before, string $inside, string $after, array $patterns, string $state): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n"
            ."    foreach (['/a/', '/b/'] as \$re) {\n        ".$before."\n"
            ."        foreach (['/c/', '/d/'] as \$re) {\n            ".$inside."\n        }\n"
            .'        '.$after."\n    }\n}\n";

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A loop inside another, then a third after both: the read in the
     * third holds its array, whatever the two before it iterate.
     */
    #[Test]
    public function test_a_loop_after_two_nested_ones_gives_its_own_array(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): void
            {
                foreach (['/a/', '/b/'] as $re) {
                    foreach (['/c/', '/d/'] as $re) {
                        $flag = !$flag;
                    }
                }

                foreach (['/e/', '/f/'] as $re) {
                    preg_match($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/e/', 'at' => "'/e/'", 'location' => 'via $re'],
            ['pattern' => '/f/', 'at' => "'/f/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * The head assigns the target at each turn: an assignment written
     * before the loop is overwritten, one written after it runs after the
     * last read. The loop ends with its closing brace: an assignment
     * written right after it, with no space between, is not in the loop.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideAssignmentsOutOfTheLoop(): iterable
    {
        yield 'before the loop' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    \$re = '/z/';\n    foreach (['/a/', '/b/'] as \$re) {\n        preg_match(\$re, \$s);\n    }\n}\n",
        ];

        yield 'after the loop' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    foreach (['/a/', '/b/'] as \$re) {\n        preg_match(\$re, \$s);\n    }\n\n    \$re = '/z/';\n}\n",
        ];

        yield 'right after the closing brace' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    foreach (['/a/', '/b/'] as \$re) {\n        preg_match(\$re, \$s);\n    }\$re = '/z/';\n}\n",
        ];
    }

    #[Test]
    #[DataProvider('provideAssignmentsOutOfTheLoop')]
    public function test_an_assignment_out_of_the_loop_leaves_the_target_read(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'location' => 'via $re'],
            ['pattern' => '/b/', 'at' => "'/b/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A call the target is passed to may take it by reference. Between the
     * head and the read it may have written the target, and so may one
     * written after the read in a loop of the body, which runs it before
     * the read at the next turn of that loop: the target is then a part
     * the file does not prove, and what is written around it is kept.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideCallsThatMayWriteTheTarget(): iterable
    {
        yield 'before the read' => [
            'body' => "        fill(\$part);\n        preg_match('/^' . \$part . '$/', \$s);",
        ];

        yield 'after the read, in a loop of the body' => [
            'body' => "        while (\$flag) {\n            preg_match('/^' . \$part . '$/', \$s);\n            fill(\$part);\n        }",
        ];

        // The call ends the only statement of the loop: it is in the loop.
        yield 'after the read, ending the statement of a loop without braces' => [
            'body' => "        for (\$i = 0; \$i < 2; \$i++) preg_match('/^' . \$part . '$/', \$s) && fill(\$part);",
        ];
    }

    #[Test]
    #[DataProvider('provideCallsThatMayWriteTheTarget')]
    public function test_a_call_the_target_is_passed_to_leaves_a_part_not_read(string $body): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n    foreach (['a', 'b'] as \$part) {\n".$body."\n    }\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The head assigns the target again after a call written after the
     * read, and after one that runs before the loop starts or once it has
     * ended: '/^' . $part . '$/' is "/^a$/" or "/^b$/".
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideCallsTheHeadRunsAfter(): iterable
    {
        yield 'after the read, in the same turn' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    foreach (['a', 'b'] as \$part) {\n        preg_match('/^' . \$part . '$/', \$s);\n        fill(\$part);\n    }\n}\n",
        ];

        yield 'before the loop' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    fill(\$part);\n    foreach (['a', 'b'] as \$part) {\n        preg_match('/^' . \$part . '$/', \$s);\n    }\n}\n",
        ];

        yield 'after the loop, in a loop that holds it' => [
            'code' => "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n    while (\$flag) {\n        foreach (['a', 'b'] as \$part) {\n            preg_match('/^' . \$part . '$/', \$s);\n        }\n\n        fill(\$part);\n    }\n}\n",
        ];
    }

    #[Test]
    #[DataProvider('provideCallsTheHeadRunsAfter')]
    public function test_a_call_the_head_runs_after_leaves_the_target_read(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/^a$/', 'at' => "'/^' . \$part . '$/'", 'location' => null],
            ['pattern' => '/^b$/', 'at' => "'/^' . \$part . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$part . '$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A goto may run the body again from anywhere, past the head: the
     * target is not followed there.
     */
    #[Test]
    public function test_a_target_in_a_body_a_goto_runs_again_is_not_read(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): void
            {
                again:
                foreach (['/a/', '/b/'] as $re) {
                    preg_match($re, $s);
                }

                if ($flag) {
                    goto again;
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }
}
