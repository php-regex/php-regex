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
 * An assignment may be written inside the value of another assignment
 * of the same variable, as an argument of the call that value is. PHP
 * runs it where it is written, before the arguments after it: a read
 * written after it in that value holds what it assigned. The assignment
 * around them is not made yet, and gives the read nothing.
 *
 * PHP 8.4 (php -r): function wrap($a, $b) { return $a . $b; } $part =
 * wrap($part = 'x', preg_match('/^' . $part . '$/', 'x')); leaves "x1" in
 * $part: the pattern was "/^x$/", and it matched.
 *
 * Every case runs through both strategies.
 */
final class AssignmentInsideAValueTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_read_after_the_inner_assignment_holds_what_it_assigned(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): string
            {
                $part = wrap($part = 'x', preg_match('/^' . $part . '$/', $s));

                return $part;
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^x$/', 'at' => "'/^' . \$part . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . \$part . '$/'"],
        ], $code);
    }

    /**
     * The read stands before the inner assignment: nothing is assigned
     * yet, and PHP joins nothing there.
     */
    #[Test]
    public function test_a_read_before_the_inner_assignment_holds_nothing(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): string
            {
                $part = wrap(preg_match('/^' . $part . '$/', $s), $part = 'x');

                return $part;
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/^' . \$part . '$/'"],
        ], $code);
    }
}
