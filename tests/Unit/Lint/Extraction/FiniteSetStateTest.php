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
 * A local variable several assignments may reach holds one of several
 * patterns: each is read where it is written, as before, and the argument
 * says it is a finite set. One distinct pattern is a constant; an
 * assignment that is no whole pattern gives the argument the state of the
 * least known of them, and none of the patterns is read, as before.
 *
 * Every case runs through both strategies.
 */
final class FiniteSetStateTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_three_reaching_assignments_are_a_finite_set(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const WORD = '/\w+/';

                public function run(string $s, int $kind): int
                {
                    if (1 === $kind) {
                        $re = '/first/';
                    } elseif (2 === $kind) {
                        $re = '/sec' . 'ond/i';
                    } else {
                        $re = self::WORD;
                    }

                    return preg_match($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/\w+/', 'at' => "'/\\w+/'", 'location' => 'via $re'],
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
            ['pattern' => '/second/i', 'at' => "'/sec' . 'ond/i'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Two assignments of the same pattern are one pattern: each literal is
     * still reported where it is written.
     */
    #[Test]
    public function test_reaching_assignments_of_one_pattern_are_a_constant(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $re = '/same/';
                if ($flag) {
                    $re = '/sa' . 'me/';
                }

                return preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/same/', 'at' => "'/same/'", 'location' => 'via $re'],
            ['pattern' => '/same/', 'at' => "'/sa' . 'me/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * 32 distinct patterns are the most a set holds: each is read.
     */
    #[Test]
    public function test_thirty_two_reaching_assignments_are_still_a_finite_set(): void
    {
        $body = '';
        $expected = ['/candidate-01/'];
        for ($i = 2; $i <= 32; $i++) {
            $body .= \sprintf("    if (%d === \$kind) {\n        \$re = '/candidate-%02d/';\n    }\n", $i, $i);
            $expected[] = \sprintf('/candidate-%02d/', $i);
        }
        $code = "<?php\n\nfunction run(string \$s, int \$kind): int\n{\n    \$re = '/candidate-01/';\n".$body."\n    return preg_match(\$re, \$s);\n}\n";

        $patterns = $this->patternsReadByBoth($code);
        $this->assertSame($expected, $patterns);
        $this->assertCount(32, array_unique($patterns));

        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * @return iterable<string, array{assignment: string, state: string}>
     */
    public static function provideAssignmentsThatAreNoWholePattern(): iterable
    {
        yield 'a pattern around a parameter' => ['assignment' => "\$re = '/^' . \$word . '$/';", 'state' => 'raw_shell'];
        yield 'a pattern around preg_quote()' => ['assignment' => "\$re = '/^' . preg_quote(\$word, '/') . '$/';", 'state' => 'quoted_shell'];
        yield 'a pattern around preg_quote() and a parameter' => ['assignment' => "\$re = '/^' . preg_quote(\$word, '/') . \$word . '$/';", 'state' => 'raw_shell'];
        yield 'a call' => ['assignment' => '$re = load_pattern();', 'state' => 'opaque'];
        yield 'a parameter' => ['assignment' => '$re = $word;', 'state' => 'opaque'];
        yield 'a parameter before any delimiter' => ['assignment' => "\$re = \$word . '/i';", 'state' => 'opaque'];
    }

    /**
     * The other assignment is a whole pattern: it is not read, and the
     * argument is as known as the least known value it may hold.
     */
    #[Test]
    #[DataProvider('provideAssignmentsThatAreNoWholePattern')]
    public function test_an_assignment_that_is_no_whole_pattern_gives_the_argument_its_state(string $assignment, string $state): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$word, bool \$flag): int\n{\n    \$re = '/whole/';\n    if (\$flag) {\n        ".$assignment."\n    }\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A shell in each branch: the raw one decides.
     */
    #[Test]
    public function test_a_quoted_shell_and_a_raw_shell_are_a_raw_shell(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $word, bool $flag): int
            {
                $re = '/^' . preg_quote($word, '/') . '$/';
                if ($flag) {
                    $re = '/^' . $word . '$/';
                }

                return preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A value assigned after the only read reaches it in no way: the
     * argument holds one pattern.
     */
    #[Test]
    public function test_an_assignment_after_the_read_leaves_the_argument_constant(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $re = '/before/';
                $found = preg_match($re, $s);
                $re = '/after/';

                return $found;
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/before/', 'at' => "'/before/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * In a loop the value assigned after the read reaches it on the next
     * turn: two patterns.
     */
    #[Test]
    public function test_an_assignment_after_the_read_in_a_loop_reaches_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(array $subjects): int
            {
                $found = 0;
                $re = '/first-turn/';
                foreach ($subjects as $s) {
                    $found += preg_match($re, $s);
                    $re = '/next-turns/';
                }

                return $found;
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
}
