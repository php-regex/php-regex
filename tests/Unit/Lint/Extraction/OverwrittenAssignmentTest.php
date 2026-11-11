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
 * A value a variable holds only to be joined into the next one is no
 * pattern of the call: "$re = '/a'; $re = $re . 'b/';" gives the call
 * "/ab/", and "/a" alone is given to none. The assignment that takes it
 * stands as a statement of the body, so every path runs it, and names
 * the variable in its own value; nothing reads the variable between the
 * two. A value that is replaced without being joined, or that a call or
 * another variable reads first, is still one of the values of the
 * variable.
 *
 * PHP 8.4 (php -r): "$re = '/a'; $re = $re . 'b/'; echo $re;" prints
 * "/ab/".
 *
 * Every case runs through both strategies.
 */
final class OverwrittenAssignmentTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{body: string, patterns: list<string>, states: list<string>}>
     */
    public static function provideBodies(): iterable
    {
        // The opening piece alone has no closing delimiter: it is never a pattern.
        yield 'a pattern built in two steps' => [
            'body' => "\$re = '/a';\n    \$re = \$re . 'b/';\n    preg_match(\$re, \$s);",
            'patterns' => ['/ab/'],
            'states' => ['constant'],
        ];
        // Two assignments stand before the last join: which one it takes is not read, and nothing is.
        yield 'a pattern built in three steps' => [
            'body' => "\$re = '/^';\n    \$re = \$re . 'a+';\n    \$re = \$re . '$/';\n    preg_match(\$re, \$s);",
            'patterns' => [],
            'states' => ['opaque'],
        ];
        // Replaced, and joined into nothing: each literal stays a value of the variable.
        yield 'a literal assigned over another before any read' => [
            'body' => "\$re = '/first/';\n    \$re = '/second/';\n    preg_match(\$re, \$s);",
            'patterns' => ['/first/', '/second/'],
            'states' => ['finite_set'],
        ];
        yield 'an interpolation of the value it replaces' => [
            'body' => "\$re = '/a';\n    \$re = \"{\$re}b/\";\n    preg_match(\$re, \$s);",
            'patterns' => ['/ab/'],
            'states' => ['constant'],
        ];
        // A call reads the first value before the second is assigned: both are patterns.
        yield 'a read between the two assignments' => [
            'body' => "\$re = '/first/';\n    preg_match(\$re, \$s);\n    \$re = '/second/';\n    preg_match(\$re, \$s);",
            'patterns' => ['/first/', '/second/'],
            'states' => ['finite_set', 'finite_set'],
        ];
        // Another variable takes the first value before it is replaced, and a call reads that variable.
        yield 'the first value kept by another variable' => [
            'body' => "\$re = '/first/';\n    \$kept = \$re;\n    \$re = '/second/';\n    preg_match(\$kept, \$s);\n    preg_match(\$re, \$s);",
            'patterns' => ['/first/', '/second/'],
            'states' => ['finite_set', 'finite_set'],
        ];
        // The second assignment runs on some paths only: the first value reaches the read on the others.
        yield 'a second assignment in a branch' => [
            'body' => "\$re = '/first/';\n    if ('' === \$s) {\n        \$re = '/second/';\n    }\n    preg_match(\$re, \$s);",
            'patterns' => ['/first/', '/second/'],
            'states' => ['finite_set'],
        ];
        yield 'a second assignment in a loop that holds the read' => [
            'body' => "\$re = '/first/';\n    foreach ([1, 2] as \$turn) {\n        preg_match(\$re, \$s);\n        \$re = '/second/';\n    }",
            'patterns' => ['/first/', '/second/'],
            'states' => ['finite_set'],
        ];
    }

    /**
     * @param list<string> $patterns the patterns read, in the order of their literals
     * @param list<string> $states   the state of each argument, in the order of the calls
     */
    #[Test]
    #[DataProvider('provideBodies')]
    public function test_a_value_joined_into_the_next_one_is_no_pattern_of_the_call(string $body, array $patterns, array $states): void
    {
        $code = "<?php\n\nfunction run(string \$s): void\n{\n    ".$body."\n}\n";

        $read = $this->patternsReadByBoth($code);
        sort($read, \SORT_STRING);
        $this->assertSame($patterns, $read);
        $this->assertSame($states, $this->statesReadByBoth($code));
    }

    /**
     * The same at the file level, where the two assignments and the call
     * follow each other.
     */
    #[Test]
    public function test_a_value_joined_into_the_next_one_at_file_level_is_no_pattern_of_the_call(): void
    {
        $code = "<?php\n\n\$re = '/a';\n\$re = \$re . 'b/';\npreg_match(\$re, \$subject);\n";

        $this->assertSame(['/ab/'], $this->patternsReadByBoth($code));
        $this->assertSame(['constant'], $this->statesReadByBoth($code));
    }
}
