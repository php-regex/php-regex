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
 * A local variable as an operand of a concatenation reads as the value
 * its assignment gives it, when it passes the rules a variable read alone
 * passes, every write a plain assignment, and when one assignment alone
 * reaches the concatenation. Two assignments cannot be told alternatives
 * from successive ones without following the branches: nothing is known of
 * the argument then, and no pattern is read (ComposedReadTest holds that
 * rule). A variable assigned another variable, and one holding an integer,
 * read the same way.
 *
 * The one assignment may give several values, a ternary or a match: one
 * operand of several values, standing once in the concatenation, gives as
 * many patterns, each one the code builds. Two operands of several values,
 * or the same one twice, would give combinations the code never builds:
 * nothing is known of the argument, and no pattern is read. Past 32
 * patterns nothing is known either. A variable that does not pass the
 * rules is a part built at run time, and the argument a raw shell.
 *
 * A pattern composed at the call is reported where the argument starts; a
 * variable assigned such a concatenation is read where the concatenation
 * is written, as one built with a constant is.
 *
 * Every expected pattern is what PHP 8.4 builds from the same statements
 * (php -r '$part = "abc"; var_dump("/" . $part . "+/");' is "/abc+/", and
 * so on, see each row).
 *
 * Every case runs through both strategies.
 */
final class LocalVariableOperandTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{setup: string, argument: string, pattern: string}>
     */
    public static function provideOperandsAVariableGives(): iterable
    {
        // $part = 'abc'; '/' . $part . '+/' is "/abc+/".
        yield 'a variable between two literals' => ['setup' => "\$part = 'abc';", 'argument' => "'/' . \$part . '+/'", 'pattern' => '/abc+/'];
        // $open = '/^'; $open . 'abc$/' is "/^abc$/".
        yield 'a variable first' => ['setup' => "\$open = '/^';", 'argument' => "\$open . 'abc$/'", 'pattern' => '/^abc$/'];
        // $flags = 'i'; '/abc/' . $flags is "/abc/i".
        yield 'a variable last' => ['setup' => "\$flags = 'i';", 'argument' => "'/abc/' . \$flags", 'pattern' => '/abc/i'];
        // $a = 'abc'; $b = 'def'; '/' . $a . '|' . $b . '/' is "/abc|def/".
        yield 'two variables' => ['setup' => "\$a = 'abc';\n        \$b = 'def';", 'argument' => "'/' . \$a . '|' . \$b . '/'", 'pattern' => '/abc|def/'];
        // '/' . $a . $b . '/' is "/abcdef/".
        yield 'two variables side by side' => ['setup' => "\$a = 'abc';\n        \$b = 'def';", 'argument' => "'/' . \$a . \$b . '/'", 'pattern' => '/abcdef/'];
        // $open = '/'; $body = 'abc'; $open . $body . $open is "/abc/".
        yield 'variables only' => ['setup' => "\$open = '/';\n        \$body = 'abc';", 'argument' => '$open . $body . $open', 'pattern' => '/abc/'];
        // $part = 'a' . 'bc'; gives the same "/abc+/".
        yield 'a variable assigned a concatenation of literals' => ['setup' => "\$part = 'a' . 'bc';", 'argument' => "'/' . \$part . '+/'", 'pattern' => '/abc+/'];
        // WORD is 'word': '/' . $part . '+/' is "/word+/".
        yield 'a variable assigned a class constant' => ['setup' => '$part = self::WORD;', 'argument' => "'/' . \$part . '+/'", 'pattern' => '/word+/'];
        yield 'a variable in parentheses' => ['setup' => "\$part = 'abc';", 'argument' => "'/' . (\$part) . '+/'", 'pattern' => '/abc+/'];
        yield 'a variable in a concatenation in parentheses' => ['setup' => "\$part = 'abc';", 'argument' => "'/' . (\$part . '+') . '/'", 'pattern' => '/abc+/'];
        // $part = ''; '/a' . $part . 'b/' is "/ab/".
        yield 'a variable holding an empty string' => ['setup' => "\$part = '';", 'argument' => "'/a' . \$part . 'b/'", 'pattern' => '/ab/'];
        // $part = 'né'; '/^é' . $part . '$/u' is "/^éné$/u", 10 bytes.
        yield 'a variable beside a multi-byte literal' => ['setup' => "\$part = 'né';", 'argument' => "'/^é' . \$part . '$/u'", 'pattern' => '/^éné$/u'];
        yield 'a variable between bracket delimiters' => ['setup' => "\$part = 'abc';", 'argument' => "'{^' . \$part . '$}i'", 'pattern' => '{^abc$}i'];
        // $part = 'a|b'; '/^(' . $part . ')$/' is "/^(a|b)$/".
        yield 'a variable in a group it fills' => ['setup' => "\$part = 'a|b';", 'argument' => "'/^(' . \$part . ')$/'", 'pattern' => '/^(a|b)$/'];
        // $part = 'abc'; $alias = $part; '/' . $alias . '+/' is "/abc+/".
        yield 'a variable assigned another variable' => ['setup' => "\$part = 'abc';\n        \$alias = \$part;", 'argument' => "'/' . \$alias . '+/'", 'pattern' => '/abc+/'];
        // A variable of one value may stand twice: $part . $part is
        // "abcabc".
        yield 'a variable of one value, twice' => ['setup' => "\$part = 'abc';", 'argument' => "'/' . \$part . '-' . \$part . '/'", 'pattern' => '/abc-abc/'];
        // $count = 3; '/a{' . $count . '}/' is "/a{3}/": an integer reads
        // as PHP writes it.
        yield 'a variable holding an integer' => ['setup' => '$count = 3;', 'argument' => "'/a{' . \$count . '}/'", 'pattern' => '/a{3}/'];
        // $count = -3; '/a{' . $count . '}/' is "/a{-3}/".
        yield 'a variable holding a negative integer' => ['setup' => '$count = -3;', 'argument' => "'/a{' . \$count . '}/'", 'pattern' => '/a{-3}/'];
        // MAX is 3: "/a{3}/".
        yield 'a variable assigned an integer constant' => ['setup' => '$count = self::MAX;', 'argument' => "'/a{' . \$count . '}/'", 'pattern' => '/a{3}/'];
        // $low = 2; $high = 5; '/a{' . $low . ',' . $high . '}/' is
        // "/a{2,5}/".
        yield 'two variables holding integers' => ['setup' => "\$low = 2;\n        \$high = 5;", 'argument' => "'/a{' . \$low . ',' . \$high . '}/'", 'pattern' => '/a{2,5}/'];
    }

    #[Test]
    #[DataProvider('provideOperandsAVariableGives')]
    public function test_a_variable_operand_reads_as_the_value_assigned_to_it(string $setup, string $argument, string $pattern): void
    {
        $code = self::matcher($setup, $argument);

        // Composed at the call: reported where the argument starts, with
        // no name to go through.
        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument.', $s)', 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A variable assigned a concatenation around another variable is read
     * where that concatenation is written, through the name the call
     * reads; joined to something more at the call, it is composed there.
     *
     * $part = 'abc'; $re = '/' . $part . '+/'; gives "/abc+/", and $re .
     * 'i' is "/abc+/i".
     */
    #[Test]
    public function test_a_variable_assigned_a_concatenation_with_a_variable_is_read_at_its_concatenation(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $part = 'abc';
                $re = '/' . $part . '+/';

                return preg_match($re, $s) + preg_match($re . 'i', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/abc+/', 'at' => "'/' . \$part . '+/'", 'location' => 'via $re'],
            ['pattern' => '/abc+/i', 'at' => "\$re . 'i'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'constant', 'expression' => "\$re . 'i'"],
        ], $code);
    }

    /**
     * One assignment gives the operand one of two values, the arms of a
     * ternary: the argument is one of two patterns, both composed at the
     * call.
     *
     * $kind = true ? 'cat' : 'dog' gives "/^cats?$/", with false
     * "/^dogs?$/".
     */
    #[Test]
    public function test_an_operand_of_two_values_gives_two_patterns(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $kind = $flag ? 'cat' : 'dog';

                return preg_match('/^' . $kind . 's?$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => "'/^' . \$kind . 's?$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dogs?$/', 'at' => "'/^' . \$kind . 's?$/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$kind . 's?$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Two operands of several values are not multiplied: the code builds
     * only some of the combinations, and the file does not say which.
     *
     * PHP 8.4: after $x = $flag ? 'a' : 'b'; $y = $flag ? 'c' : 'd';, '/'
     * . $x . $y . '/' is "/ac/" or "/bd/", never "/ad/" nor "/bc/".
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideCompositionsOfTwoOperandsOfSeveralValues(): iterable
    {
        yield 'two variables of two values' => ['argument' => "'/' . \$x . \$y . '/'"];
        yield 'two variables of two values, apart' => ['argument' => "'/^' . \$x . '-' . \$y . '$/'"];
        // $x is 'a' or 'b': '/' . $x . $x . '/' is "/aa/" or "/bb/",
        // never "/ab/".
        yield 'the same variable of two values, twice' => ['argument' => "'/' . \$x . \$x . '/'"];
        yield 'the same variable of two values, twice and apart' => ['argument' => "'/^' . \$x . '|' . \$x . '$/'"];
        // $pair holds $x: joined to $x again, the variable stands twice.
        yield 'a variable of two values and a variable built of it' => ['argument' => "'/' . \$pair . \$x . '/'"];
        yield 'a variable of two values and its alias' => ['argument' => "'/' . \$x . \$alias . '/'"];
        yield 'variables of several values only' => ['argument' => '$open . $x'];
    }

    #[Test]
    #[DataProvider('provideCompositionsOfTwoOperandsOfSeveralValues')]
    public function test_two_operands_of_several_values_leave_the_argument_opaque(string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): int\n{\n"
            ."    \$x = \$flag ? 'a' : 'b';\n    \$y = \$flag ? 'c' : 'd';\n    \$open = \$flag ? '/' : '#';\n"
            ."    \$pair = '(' . \$x . ')';\n    \$alias = \$x;\n\n"
            .'    return preg_match('.$argument.", \$s) + preg_match('/^' . \$x . '$/', \$s);\n}\n";

        // One of them alone, once, is still read: "/^a$/" and "/^b$/".
        $this->assertExtractedByBoth([
            ['pattern' => '/^a$/', 'at' => "'/^' . \$x . '$/'", 'location' => null],
            ['pattern' => '/^b$/', 'at' => "'/^' . \$x . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s) +', 'source' => 'preg_match()'],
            ['state' => 'finite_set', 'expression' => "'/^' . \$x . '$/'"],
        ], $code);
    }

    /**
     * A variable built of one of several values holds as many: read
     * alone or joined to literals, it gives each of them.
     *
     * PHP 8.4: $x = $flag ? 'a' : 'b'; $pair = '(' . $x . ')'; '/' .
     * $pair . '+/' is "/(a)+/" or "/(b)+/".
     */
    #[Test]
    public function test_a_variable_built_of_one_of_several_values_brings_them_through(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $x = $flag ? 'a' : 'b';
                $pair = '(' . $x . ')';
                $re = '/^' . $pair . '$/';

                return preg_match('/' . $pair . '+/', $s) + preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^(a)$/', 'at' => "'/^' . \$pair . '$/'", 'location' => 'via $re'],
            ['pattern' => '/^(b)$/', 'at' => "'/^' . \$pair . '$/'", 'location' => 'via $re'],
            ['pattern' => '/(a)+/', 'at' => "'/' . \$pair . '+/'", 'location' => null],
            ['pattern' => '/(b)+/', 'at' => "'/' . \$pair . '+/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/' . \$pair . '+/'"],
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * Two arms of the same text are one value: one pattern, read once
     * where the argument starts.
     */
    #[Test]
    public function test_an_operand_whose_two_arms_are_the_same_text_gives_one_pattern(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $kind = $flag ? 'cat' : 'c' . 'at';

                return preg_match('/^' . $kind . 's?$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => "'/^' . \$kind . 's?$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . \$kind . 's?$/'"],
        ], $code);
    }

    /**
     * A variable that does not pass the rules a variable read alone
     * passes is a part built at run time, whatever it was assigned: the
     * argument is a raw shell, and no pattern is read.
     *
     * @return iterable<string, array{setup: string, argument: string}>
     */
    public static function provideVariablesThatDoNotPass(): iterable
    {
        $operand = "'/' . \$part . '+/'";

        yield 'a parameter' => ['setup' => '', 'argument' => "'/' . \$given . '+/'"];
        yield 'a parameter assigned a literal in the body' => ['setup' => "\$given = 'abc';", 'argument' => "'/' . \$given . '+/'"];
        yield 'a variable the body never assigns' => ['setup' => '', 'argument' => "'/' . \$missing . '+/'"];
        yield 'a variable assigned a parameter' => ['setup' => '$part = $given;', 'argument' => $operand];
        yield 'a variable assigned a call' => ['setup' => '$part = strtolower($given);', 'argument' => $operand];
        yield 'a variable passed to a call' => ['setup' => "\$part = 'abc';\n        normalize_part(\$part);", 'argument' => $operand];
        yield 'a variable passed to a method' => ['setup' => "\$part = 'abc';\n        \$this->normalize(\$part);", 'argument' => $operand];
        // $part = 'ab'; $part .= 'c'; gives "abc" in PHP: a compound
        // assignment is not followed.
        yield 'a variable written with a concatenating assignment' => ['setup' => "\$part = 'ab';\n        \$part .= 'c';", 'argument' => $operand];
        yield 'a variable written with a null coalescing assignment' => ['setup' => "\$part = 'abc';\n        \$part ??= 'def';", 'argument' => $operand];
        yield 'a variable a reference is taken of' => ['setup' => "\$part = 'abc';\n        \$alias = &\$part;", 'argument' => $operand];
        yield 'a variable captured by reference in a closure' => ['setup' => "\$part = 'abc';\n        \$write = function () use (&\$part): void {\n        };", 'argument' => $operand];
        yield 'a global variable' => ['setup' => "global \$part;\n        \$part = 'abc';", 'argument' => $operand];
        yield 'a static variable' => ['setup' => "static \$part = 'abc';", 'argument' => $operand];
        yield 'the value of a foreach over an unknown array' => ['setup' => "\$part = 'abc';\n        foreach (\$this->all() as \$part) {\n        }", 'argument' => $operand];
        yield 'a destructuring target' => ['setup' => "\$part = 'abc';\n        [\$part] = \$this->all();", 'argument' => $operand];
        yield 'an incremented variable' => ['setup' => "\$part = 'abc';\n        \$part++;", 'argument' => $operand];
        yield 'an unset variable' => ['setup' => "\$part = 'abc';\n        unset(\$part);", 'argument' => $operand];
        yield 'a variable in a body calling extract()' => ['setup' => "\$part = 'abc';\n        extract(\$this->all());", 'argument' => $operand];
        yield 'a variable in a body writing a variable variable' => ['setup' => "\$part = 'abc';\n        \$\$given = 'x';", 'argument' => $operand];
    }

    #[Test]
    #[DataProvider('provideVariablesThatDoNotPass')]
    public function test_a_variable_that_does_not_pass_the_rules_is_a_raw_hole(string $setup, string $argument): void
    {
        // Beside a variable that passes, read in the same body: the
        // refusal is that of the one variable.
        $code = self::matcher("\$kept = 'kept';\n        ".$setup, $argument, "preg_match('/' . \$kept . '/', \$s)");

        $expected = [
            ['state' => 'raw_shell', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ];

        // extract() and a variable variable may write any variable of the
        // body: the one beside is written off with it.
        if (str_contains($setup, 'extract(') || str_contains($setup, '$$')) {
            $this->assertExtractedByBoth([], $code);
            $expected[] = ['state' => 'raw_shell', 'expression' => "'/' . \$kept . '/'"];
        } else {
            $this->assertExtractedByBoth([
                ['pattern' => '/kept/', 'at' => "'/' . \$kept . '/'", 'location' => null],
            ], $code);
            $expected[] = ['state' => 'constant', 'expression' => "'/' . \$kept . '/'"];
        }

        $this->assertArgumentsReadByBoth($expected, $code);
    }

    /**
     * One of the arms of the assignment proves nothing: the operand may
     * be a part built at run time, and the pattern the other arm gives is
     * not read.
     */
    #[Test]
    public function test_an_operand_one_of_whose_values_is_not_proved_gives_no_pattern(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given, bool $flag): int
            {
                $kind = $flag ? 'cat' : $given;

                return preg_match('/^' . $kind . 's?$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$kind . 's?$/'"],
        ], $code);
    }

    /**
     * The delimiter opens the first part: a variable that is not proved
     * there leaves nothing static, as a parameter written there does.
     *
     * @return iterable<string, array{setup: string, argument: string}>
     */
    public static function provideUnprovedVariablesBeforeAnyDelimiter(): iterable
    {
        yield 'a variable assigned a parameter, first' => ['setup' => '$open = $given;', 'argument' => "\$open . 'abc/'"];
        yield 'a variable passed to a call, first' => ['setup' => "\$open = '/';\n        normalize_part(\$open);", 'argument' => "\$open . 'abc/'"];
        yield 'a variable assigned an empty string, before a parameter' => ['setup' => "\$open = '';", 'argument' => "\$open . \$given . '/abc/'"];
    }

    #[Test]
    #[DataProvider('provideUnprovedVariablesBeforeAnyDelimiter')]
    public function test_an_unproved_variable_before_any_delimiter_leaves_the_argument_opaque(string $setup, string $argument): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)'],
        ], $code);
    }

    /**
     * A variable assigned another variable holds what that one holds: a
     * pattern is reported where its literal is written, through the name
     * the call reads, as one read through a constant is.
     *
     * PHP 8.4: $first = '/alias/'; $second = $first; $third = $second;
     * leaves "/alias/" in $third.
     */
    #[Test]
    public function test_a_variable_assigned_another_variable_is_read_through_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given, bool $flag): int
            {
                $first = '/alias/';
                $second = $first;
                $third = $second;

                $branches = '/branch-a/';
                if ($flag) {
                    $branches = '/branch-b/';
                }
                $either = $branches;

                $unknown = $given;
                $written = '/written/';
                normalize_part($written);
                $lost = $written;

                return preg_match($second, $s) + preg_match($third, $s) + preg_match($either, $s)
                    + preg_match($unknown, $s) + preg_match($lost, $s);
            }
            PHP;

        // Read by two calls through two names, the literal is reported
        // once; which of the two names it goes by is only compared
        // between the strategies.
        $this->assertExtractedByBoth([
            ['pattern' => '/alias/', 'at' => "'/alias/'"],
            ['pattern' => '/branch-a/', 'at' => "'/branch-a/'", 'location' => 'via $either'],
            ['pattern' => '/branch-b/', 'at' => "'/branch-b/'", 'location' => 'via $either'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$second', 'at' => '$second, $s)'],
            ['state' => 'constant', 'expression' => '$third', 'at' => '$third, $s)'],
            ['state' => 'finite_set', 'expression' => '$either', 'at' => '$either, $s)'],
            ['state' => 'opaque', 'expression' => '$unknown', 'at' => '$unknown, $s)'],
            ['state' => 'opaque', 'expression' => '$lost', 'at' => '$lost, $s)'],
        ], $code);
    }

    /**
     * A variable holding a float, a boolean or null is not read as one
     * holding an integer is: '/a{' . 1.5 . '}/' is "/a{1.5}/" in PHP, and
     * a part built at run time here, as the literal is.
     *
     * @return iterable<string, array{setup: string}>
     */
    public static function provideVariablesHoldingAScalarThatIsNoInteger(): iterable
    {
        yield 'a float' => ['setup' => '$count = 1.5;'];
        yield 'a boolean' => ['setup' => '$count = true;'];
        yield 'null' => ['setup' => '$count = null;'];
        yield 'an integer parameter' => ['setup' => '$count = $limit;'];
        yield 'a sum of integers' => ['setup' => '$count = 1 + 2;'];
    }

    #[Test]
    #[DataProvider('provideVariablesHoldingAScalarThatIsNoInteger')]
    public function test_a_variable_holding_a_scalar_that_is_no_integer_is_a_raw_hole(string $setup): void
    {
        $code = "<?php\n\nfunction run(string \$s, int \$limit): int\n{\n    \$max = 3;\n    ".$setup."\n\n    return preg_match('/a{' . \$count . '}/', \$s) + preg_match('/b{' . \$max . '}/', \$s);\n}\n";

        // The integer beside it is read: '/b{' . 3 . '}/' is "/b{3}/".
        $this->assertExtractedByBoth([
            ['pattern' => '/b{3}/', 'at' => "'/b{' . \$max . '}/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/a{' . \$count . '}/'"],
            ['state' => 'constant', 'expression' => "'/b{' . \$max . '}/'"],
        ], $code);
    }

    /**
     * A variable assigned preg_quote() holds a quoted text: as an operand
     * it is a quoted hole, with or without its delimiter, and the argument
     * a quoted shell. Beside a part that is not quoted, the shell is raw.
     *
     * @return iterable<string, array{setup: string, argument: string, state: string}>
     */
    public static function provideVariablesHoldingAQuotedText(): iterable
    {
        yield 'quoted with the delimiter' => ['setup' => "\$quoted = preg_quote(\$given, '/');", 'argument' => "'/^' . \$quoted . '$/'", 'state' => 'quoted_shell'];
        yield 'quoted without a delimiter' => ['setup' => '$quoted = preg_quote($given);', 'argument' => "'/^' . \$quoted . '$/'", 'state' => 'quoted_shell'];
        yield 'quoted, in a variable assigned a concatenation' => ['setup' => "\$quoted = '^' . preg_quote(\$given, '/') . '$';", 'argument' => "'/' . \$quoted . '/'", 'state' => 'quoted_shell'];
        yield 'quoted, beside a parameter' => ['setup' => "\$quoted = preg_quote(\$given, '/');", 'argument' => "'/^' . \$quoted . \$given . '$/'", 'state' => 'raw_shell'];
        yield 'quoted, first' => ['setup' => "\$quoted = preg_quote(\$given, '/');", 'argument' => "\$quoted . '/i'", 'state' => 'opaque'];
    }

    #[Test]
    #[DataProvider('provideVariablesHoldingAQuotedText')]
    public function test_a_variable_holding_a_quoted_text_is_a_quoted_hole(string $setup, string $argument, string $state): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * 32 patterns are the most a composition gives: one operand given 32
     * values by one assignment, joined to literals and to an operand of
     * one value, gives 32, each read where the argument starts.
     */
    #[Test]
    public function test_a_composition_of_thirty_two_patterns_is_read(): void
    {
        $kinds = self::names('k', 32);
        $code = self::product(['s1'], $kinds, "'/^' . \$size . '-' . \$kind . '$/'");

        $expected = [];
        foreach ($kinds as $kind) {
            $expected[] = '/^s1-'.$kind.'$/';
        }

        $patterns = $this->patternsReadByBoth($code);
        $this->assertCount(32, $patterns);
        $this->assertSame($expected, $patterns);

        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$size . '-' . \$kind . '$/'"],
        ], $code);
    }

    /**
     * @return iterable<string, array{sizes: int, kinds: int, argument: string, expression: string}>
     */
    public static function provideCompositionsThatAreNotBuilt(): iterable
    {
        $direct = "'/^' . \$size . '-' . \$kind . '$/'";

        // One operand of several values, past the cap.
        yield '1 value by 33, one past the cap' => ['sizes' => 1, 'kinds' => 33, 'argument' => $direct, 'expression' => $direct];
        yield '33 values in one operand' => ['sizes' => 1, 'kinds' => 33, 'argument' => "'/^' . \$kind . '$/'", 'expression' => "'/^' . \$kind . '$/'"];
        yield '100 values in one operand' => ['sizes' => 1, 'kinds' => 100, 'argument' => "'/^' . \$kind . '$/'", 'expression' => "'/^' . \$kind . '$/'"];
        // Two operands of several values, whatever their product.
        yield '2 values by 2' => ['sizes' => 2, 'kinds' => 2, 'argument' => $direct, 'expression' => $direct];
        yield '4 values by 8, a product of 32' => ['sizes' => 4, 'kinds' => 8, 'argument' => $direct, 'expression' => $direct];
        yield '3 values by 11' => ['sizes' => 3, 'kinds' => 11, 'argument' => $direct, 'expression' => $direct];
        yield '6 values by 6' => ['sizes' => 6, 'kinds' => 6, 'argument' => $direct, 'expression' => $direct];
    }

    /**
     * Past 32 patterns, or with two operands of several values, the
     * composition is not built: nothing is known of the argument, and
     * none of the patterns is read.
     */
    #[Test]
    #[DataProvider('provideCompositionsThatAreNotBuilt')]
    public function test_a_composition_that_is_not_built_leaves_the_argument_opaque(int $sizes, int $kinds, string $argument, string $expression): void
    {
        $code = self::product(self::names('s', $sizes), self::names('k', $kinds), $argument);

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $expression],
        ], $code);
    }

    /**
     * The cap bounds what a composition gives, not what a variable
     * holds: 33 patterns assigned to a variable read alone, each by an
     * assignment of its own, are a finite set, each read where it is
     * written; 33 values one assignment gives, joined to a literal, are a
     * composition, past the cap. A variable assigned a composition that
     * is not built proves nothing.
     */
    #[Test]
    public function test_the_cap_bounds_a_composition_and_not_a_variable_read_alone(): void
    {
        $values = self::names('/whole-', 33, '/');
        $alone = self::product(['s1'], $values, '$kind', '', true);

        $patterns = $this->patternsReadByBoth($alone);
        $this->assertCount(33, $patterns);
        $this->assertSame($values, $patterns);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$kind', 'at' => '$kind, $s)'],
        ], $alone);

        $joined = self::product(['s1'], $values, "\$kind . 'i'");
        $this->assertSame([], $this->patternsReadByBoth($joined));
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "\$kind . 'i'"],
        ], $joined);

        $assigned = self::product(self::names('s', 6), self::names('k', 6), '$re', "\$re = '/^' . \$size . '-' . \$kind . '$/';");
        $this->assertSame([], $this->patternsReadByBoth($assigned));
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $assigned);
    }

    /**
     * A variable assigned a concatenation naming itself, or two variables
     * naming each other in a loop: the reading ends, and both strategies
     * end on the same state for the argument. PHP 8.4: $re = '/a'; $re =
     * $re . '/'; leaves "/a/" in $re.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideVariableCycles(): iterable
    {
        yield 'a variable joined to itself' => ['body' => "\$re = '/a';\n    \$re = \$re . '/';"];
        yield 'a variable assigned itself' => ['body' => "\$re = '/a/';\n    \$re = \$re;"];
        yield 'two variables assigned each other' => ['body' => "\$re = '/a/';\n    \$other = \$re;\n    \$re = \$other;"];
        yield 'two variables naming each other in a loop' => ['body' => "\$re = '/a/';\n    \$other = 'b';\n    foreach (str_split(\$s) as \$char) {\n        \$re = \$other . '/';\n        \$other = \$re . 'c';\n    }"];
    }

    #[Test]
    #[DataProvider('provideVariableCycles')]
    public function test_a_variable_assigned_from_itself_ends(string $body): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    ".$body."\n\n    return preg_match(\$re, \$s) + preg_match('/control/', \$s);\n}\n";

        $arguments = $this->argumentsReadByBoth($code);

        $this->assertCount(2, $arguments);
        $this->assertSame('$re', $arguments[0]['expression']);
        $this->assertSame(['state' => 'constant', 'expression' => "'/control/'"], ['state' => $arguments[1]['state'], 'expression' => $arguments[1]['expression']]);
        $this->assertContains('/control/', $this->patternsReadByBoth($code));
    }

    /**
     * A final class holding WORD and MAX, whose method run() runs the statements,
     * then reads the argument in preg_match().
     */
    private static function matcher(string $setup, string $argument, ?string $beside = null): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const WORD = 'word';\n    private const MAX = 3;\n\n"
            ."    public function run(string \$s, string \$given): int\n    {\n        ".$setup."\n\n"
            .'        return preg_match('.$argument.', $s)'.(null === $beside ? '' : ' + '.$beside).";\n    }\n}\n";
    }

    /**
     * A function giving $size and $kind each one of the values, then
     * reading the argument in preg_match(). Each is given its values by
     * one assignment, a match with an arm per value, the one assignment
     * of a variable of one value being that value; or, when asked, by an
     * assignment per value, the first outright and each other under a
     * condition of its own.
     *
     * @param list<string> $sizes
     * @param list<string> $kinds
     */
    private static function product(array $sizes, array $kinds, string $argument, string $before = '', bool $assignments = false): string
    {
        return "<?php\n\nfunction run(string \$s, int \$a, int \$b): int\n{\n"
            .($assignments ? self::branches('size', 'a', $sizes) : self::arms('size', 'a', $sizes))
            .($assignments ? self::branches('kind', 'b', $kinds) : self::arms('kind', 'b', $kinds))
            .('' === $before ? '' : '    '.$before."\n")
            ."\n    return preg_match(".$argument.", \$s);\n}\n";
    }

    /**
     * One assignment per value.
     *
     * @param list<string> $values
     */
    private static function branches(string $variable, string $selector, array $values): string
    {
        $code = '';
        foreach ($values as $index => $value) {
            $code .= 0 === $index
                ? \sprintf("    \$%s = '%s';\n", $variable, $value)
                : \sprintf("    if (%d === \$%s) {\n        \$%s = '%s';\n    }\n", $index, $selector, $variable, $value);
        }

        return $code;
    }

    /**
     * One assignment of a match giving each value in an arm, the last as
     * its default arm.
     *
     * @param list<string> $values
     */
    private static function arms(string $variable, string $selector, array $values): string
    {
        if (1 === \count($values)) {
            return \sprintf("    \$%s = '%s';\n", $variable, $values[0]);
        }

        $code = \sprintf("    \$%s = match (\$%s) {\n", $variable, $selector);
        foreach ($values as $index => $value) {
            $code .= \sprintf("        %s => '%s',\n", $index === \count($values) - 1 ? 'default' : (string) ($index + 1), $value);
        }

        return $code."    };\n";
    }

    /**
     * @return list<string>
     */
    private static function names(string $prefix, int $count, string $suffix = ''): array
    {
        $names = [];
        for ($i = 1; $i <= $count; $i++) {
            $names[] = \sprintf('%s%02d%s', $prefix, $i, $suffix);
        }

        return $names;
    }
}
