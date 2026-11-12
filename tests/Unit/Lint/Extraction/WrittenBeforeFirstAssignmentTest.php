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
 * A variable joined to other texts before the body assigns it holds
 * nothing the body proves: PHP joins nothing there, and the argument is
 * not read. Unless something else gave it a value first: a parameter
 * holds what the caller passed, and a call the variable is passed to may
 * have written it by reference. Then it is a part known at run time
 * only, and what is written around it is kept.
 *
 * PHP 8.4 (php -r): function run(string $s, string $word) { return '/^'
 * . $word . '$/'; $word = 'x'; } run('', 'given') is "/^given$/"; with
 * function fill(&$v) { $v = 'filled'; }, fill($part); '/^' . $part . '$/'
 * is "/^filled$/".
 *
 * Every case runs through both strategies.
 */
final class WrittenBeforeFirstAssignmentTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideVariablesWrittenBeforeTheirFirstAssignment(): iterable
    {
        yield 'a parameter' => [
            'code' => "<?php\n\nfunction run(string \$s, string \$part): void\n{\n    preg_match('/^' . \$part . '$/', \$s);\n    \$part = 'x';\n}\n",
        ];

        yield 'a variable passed to a call before the read' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    fill(\$part);\n    preg_match('/^' . \$part . '$/', \$s);\n    \$part = 'x';\n}\n",
        ];

        // The call runs before the read at the second turn.
        yield 'a variable passed to a call after the read, in a loop that holds both' => [
            'code' => "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n    while (\$flag) {\n        preg_match('/^' . \$part . '$/', \$s);\n        fill(\$part);\n    }\n\n    \$part = 'x';\n}\n",
        ];

        yield 'a variable passed to a call after the read, in the loop around the loop of the read' => [
            'code' => "<?php\n\nfunction run(string \$s, bool \$flag, array \$lines): void\n{\n    while (\$flag) {\n        foreach (\$lines as \$line) {\n            preg_match('/^' . \$part . '$/', \$s . \$line);\n        }\n\n        fill(\$part);\n    }\n\n    \$part = 'x';\n}\n",
        ];
    }

    #[Test]
    #[DataProvider('provideVariablesWrittenBeforeTheirFirstAssignment')]
    public function test_a_variable_written_before_its_first_assignment_is_a_part_not_read(string $code): void
    {
        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The call runs after the read, and no loop runs it again before it:
     * nothing gave the variable a value where it is joined.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideVariablesNothingWroteBeforeTheRead(): iterable
    {
        yield 'a variable passed to a call after the read' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    preg_match('/^' . \$part . '$/', \$s);\n    fill(\$part);\n    \$part = 'x';\n}\n",
        ];

        yield 'a variable passed to a call after the loop of the read' => [
            'code' => "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n    while (\$flag) {\n        preg_match('/^' . \$part . '$/', \$s);\n        \$flag = false;\n    }\n\n    fill(\$part);\n    \$part = 'x';\n}\n",
        ];

        // The read stands in the value of the assignment: PHP joins
        // nothing there, and the call that follows runs after it.
        yield 'a variable read in the value of its only assignment, and passed to a call after it' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    \$part = preg_match('/^' . \$part . '$/', \$s) ? 'a' : 'b';\n    fill(\$part);\n}\n",
        ];

        // The goto may run the assignment before the read: one value
        // then, and nothing at the first pass.
        yield 'a variable assigned after the read, in a body a goto runs again' => [
            'code' => "<?php\n\nfunction run(string \$s, bool \$flag): void\n{\n    again:\n    preg_match('/^' . \$part . '$/', \$s);\n    \$part = 'x';\n    if (\$flag) {\n        goto again;\n    }\n}\n",
        ];
    }

    #[Test]
    #[DataProvider('provideVariablesNothingWroteBeforeTheRead')]
    public function test_a_variable_nothing_wrote_before_the_read_leaves_the_argument_opaque(string $code): void
    {
        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/^' . \$part . '$/'", 'source' => 'preg_match()'],
        ], $code);
    }
}
