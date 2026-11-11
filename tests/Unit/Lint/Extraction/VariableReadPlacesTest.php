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
 * What a variable holds is told by its name and by the place it is last
 * read at: as the pattern itself, the assignments that may reach its last
 * read as a pattern; through another variable, those that may reach the
 * place it is named there. Two variables are two, however alike their
 * names and wherever their reads stand.
 *
 * Every case runs through both strategies.
 */
final class VariableReadPlacesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * $a is read as a pattern while it holds "/first/" alone. It is
     * assigned "/second/" after that, then named in the value of $b: $b
     * holds what $a may hold there, which is not what the first call
     * read.
     */
    #[Test]
    public function test_a_variable_read_as_a_pattern_then_through_another_holds_what_reaches_each_read(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $a = '/first/';
                $found = preg_match($a, $s);
                $a = '/second/';
                $b = $a;

                return $found + preg_match($b, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $a'],
            ['pattern' => '/second/', 'at' => "'/second/'", 'location' => 'via $b'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$a', 'at' => '$a, $s)'],
            ['state' => 'finite_set', 'expression' => '$b', 'at' => '$b, $s)'],
        ], $code);
    }

    /**
     * $re1 and $re differ by a digit, and the places of their reads are
     * numbers: no distance between the two reads makes one variable of
     * the two. The comment between them grows a byte at a time, over
     * more than a hundred bytes.
     */
    #[Test]
    public function test_two_variables_named_alike_are_read_apart_whatever_the_distance_between_their_reads(): void
    {
        for ($gap = 0; $gap <= 120; $gap++) {
            $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$re1 = '/one/';\n    \$found = preg_match(\$re1, \$s);\n    // ".str_repeat('.', $gap)."\n    \$re = '/other/';\n\n    return \$found + preg_match(\$re, \$s);\n}\n";

            $this->assertSame(['/one/', '/other/'], $this->patternsReadByBoth($code), \sprintf('With a comment of %d bytes between the two reads.', $gap));
        }

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re1', 'at' => '$re1, $s)'],
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }
}
