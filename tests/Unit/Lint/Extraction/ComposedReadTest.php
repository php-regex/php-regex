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
 * A variable enters a composition, a concatenation, an interpolated
 * string, an argument of sprintf() or an item of implode(), only when one
 * assignment reaches that read. The branches are not followed: two
 * assignments written before a read cannot be told alternatives, one in
 * each branch of an "if", from successive ones, the second replacing the
 * first. Joining each value to the literals around would report a pattern
 * the code never builds.
 *
 * Each composed read takes the assignments that reach its own place: the
 * ones written before it, and the ones written after it in a loop that
 * holds both. One of them, written before the read: the variable is
 * read, and its value may be several, the arms of a ternary or of a
 * match. Several, or none written before the read: nothing is known of
 * the argument, and no pattern is read.
 *
 * A variable that is the whole argument keeps the rule it has: each
 * literal it is assigned is a pattern the file writes, read where it is
 * written.
 *
 * PHP 8.4 (php -r): $part = 'a'; '/' . $part . '/' is "/a/"; then $part =
 * '('; '#' . $part . '#' is "#(#". The first call never builds "/(/", nor
 * the second "#a#".
 *
 * Every case runs through both strategies.
 */
final class ComposedReadTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The first call is composed before the second assignment is
     * written: one assignment reaches it, and it is "/a/" alone. Two are
     * written before the second call: whether the second replaces the
     * first is not followed, and nothing is read there.
     */
    #[Test]
    public function test_a_composed_read_takes_the_assignments_written_before_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $t): int
            {
                $part = 'a';
                $first = preg_match('/' . $part . '/', $s);
                $part = '(';

                return $first + preg_match('#' . $part . '#', $t);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/' . \$part . '/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/' . \$part . '/'", 'source' => 'preg_match()'],
            ['state' => 'opaque', 'expression' => "'#' . \$part . '#'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An assignment written after the read reaches it when a loop holds
     * both: the second turn of the loop composes with what the first
     * turn assigned.
     *
     * PHP 8.4: $part = 'a'; foreach (['x', 'y'] as $line) { $built[] =
     * '/' . $part . '/'; $part = '('; } builds "/a/" then "/(/".
     *
     * @return iterable<string, array{loop: string}>
     */
    public static function provideLoopsHoldingTheReadAndALaterAssignment(): iterable
    {
        yield 'a foreach' => ['loop' => "foreach (\$lines as \$line) {\n        \$found += preg_match('/' . \$part . '/', \$line);\n        \$part = '(';\n    }"];
        yield 'a while' => ['loop' => "while (\$found < 3) {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        \$part = '(';\n    }"];
        yield 'a for' => ['loop' => "for (\$i = 0; \$i < 3; \$i++) {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        \$part = '(';\n    }"];
        yield 'a do-while' => ['loop' => "do {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        \$part = '(';\n    } while (\$found < 3);"];
        yield 'the assignment in a branch of the loop' => ['loop' => "foreach (\$lines as \$line) {\n        \$found += preg_match('/' . \$part . '/', \$line);\n        if ('' === \$line) {\n            \$part = '(';\n        }\n    }"];
        yield 'the read in a loop inside the loop holding the assignment' => ['loop' => "foreach (\$lines as \$line) {\n        foreach (\$lines as \$other) {\n            \$found += preg_match('/' . \$part . '/', \$other);\n        }\n        \$part = '(';\n    }"];
    }

    #[Test]
    #[DataProvider('provideLoopsHoldingTheReadAndALaterAssignment')]
    public function test_an_assignment_after_the_read_reaches_it_in_a_loop_that_holds_both(string $loop): void
    {
        $code = "<?php\n\nfunction run(string \$s, array \$lines): int\n{\n    \$found = 0;\n    \$part = 'a';\n    ".$loop."\n\n    return \$found;\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/' . \$part . '/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * One assignment reaches the read, wherever it is written: in a
     * branch, in the loop that holds the read, or before a later one the
     * read does not see.
     *
     * The path that leaves the variable undefined is not followed. PHP
     * 8.4 (php -r): with $flag false, "if ($flag) { $part = 'a'; }" then
     * '/' . $part . '/' raises "Warning: Undefined variable $part" and
     * is "//"; with $flag true it is "/a/", the pattern read. A read
     * the same condition guards never meets the first path, and the
     * file does not tell the two apart.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideBodiesWhereOneAssignmentReaches(): iterable
    {
        yield 'an assignment in a branch' => ['body' => "if (\$flag) {\n        \$part = 'a';\n    }\n\n    return preg_match('/' . \$part . '/', \$s);"];
        yield 'an assignment in a loop, before the read' => ['body' => "\$found = 0;\n    foreach (\$lines as \$line) {\n        \$part = 'a';\n        \$found += preg_match('/' . \$part . '/', \$line);\n    }\n\n    return \$found;"];
        yield 'a later assignment out of any loop' => ['body' => "\$part = 'a';\n    \$found = preg_match('/' . \$part . '/', \$s);\n    \$part = '(';\n\n    return \$found;"];
        yield 'a later assignment in a loop that does not hold the read' => ['body' => "\$part = 'a';\n    \$found = preg_match('/' . \$part . '/', \$s);\n    foreach (\$lines as \$line) {\n        \$part = '(';\n    }\n\n    return \$found;"];
        yield 'a later assignment after the loop that holds the read' => ['body' => "\$part = 'a';\n    \$found = 0;\n    foreach (\$lines as \$line) {\n        \$found += preg_match('/' . \$part . '/', \$line);\n    }\n    \$part = '(';\n\n    return \$found;"];
        yield 'the same name assigned in another function' => ['body' => "\$part = 'a';\n\n    return preg_match('/' . \$part . '/', \$s) + other(\$s);\n}\n\nfunction other(string \$s): int\n{\n    \$part = '(';\n\n    return \\strlen(\$part . \$s);"];
    }

    #[Test]
    #[DataProvider('provideBodiesWhereOneAssignmentReaches')]
    public function test_one_assignment_reaching_the_read_is_read(string $body): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag, array \$lines): int\n{\n    ".$body."\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/' . \$part . '/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/' . \$part . '/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Assignments in the two branches of an "if" are two assignments
     * written before the read, as successive ones are: nothing is read,
     * whatever each of them gives.
     *
     * @return iterable<string, array{setup: string}>
     */
    public static function provideSeveralAssignmentsReachingTheRead(): iterable
    {
        yield 'one in each branch of an if' => ['setup' => "if (\$flag) {\n        \$kind = 'cat';\n    } else {\n        \$kind = 'dog';\n    }"];
        yield 'one outright and one in a branch' => ['setup' => "\$kind = 'cat';\n    if (\$flag) {\n        \$kind = 'dog';\n    }"];
        yield 'two in a row' => ['setup' => "\$kind = 'cat';\n    \$kind = 'dog';"];
        yield 'three, in the arms of a switch' => ['setup' => "switch (\$given) {\n        case 'a':\n            \$kind = 'cat';\n            break;\n        case 'b':\n            \$kind = 'dog';\n            break;\n        default:\n            \$kind = 'bird';\n    }"];
        // Two assignments of one text are two assignments all the same.
        yield 'two of the same text' => ['setup' => "\$kind = 'cat';\n    if (\$flag) {\n        \$kind = 'c' . 'at';\n    }"];
        yield 'one of them a ternary' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';\n    if ('' === \$given) {\n        \$kind = 'bird';\n    }"];
        // The shell around the variable is not kept either: nothing says
        // which assignment the call composes with.
        yield 'one of them a parameter' => ['setup' => "\$kind = 'cat';\n    if (\$flag) {\n        \$kind = \$given;\n    }"];
        yield 'one of them a quoted text' => ['setup' => "\$kind = 'cat';\n    if (\$flag) {\n        \$kind = preg_quote(\$given, '/');\n    }"];
    }

    #[Test]
    #[DataProvider('provideSeveralAssignmentsReachingTheRead')]
    public function test_several_assignments_reaching_a_composed_read_leave_the_argument_opaque(string $setup): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given, bool \$flag): int\n{\n    ".$setup."\n\n    return preg_match('/^' . \$kind . 's?$/', \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/^' . \$kind . 's?$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Every way of composing is under the rule, and so is a variable
     * assigned what is composed, or another variable, before it is read.
     *
     * @return iterable<string, array{before: string, argument: string}>
     */
    public static function provideCompositionsOfAVariableTwoAssignmentsReach(): iterable
    {
        yield 'a concatenation' => ['before' => '', 'argument' => "'/^' . \$kind . 's?$/'"];
        yield 'the first operand' => ['before' => '', 'argument' => "\$kind . '/i'"];
        yield 'the last operand' => ['before' => '', 'argument' => "'/^pet-' . \$kind"];
        yield 'an interpolated string' => ['before' => '', 'argument' => '"/^{$kind}s?$/"'];
        yield 'an interpolated string, the variable bare' => ['before' => '', 'argument' => '"/^$kind+/"'];
        yield 'a heredoc' => ['before' => '', 'argument' => "<<<RE\n        /^{\$kind}s?$/\n        RE"];
        yield 'an argument of sprintf()' => ['before' => '', 'argument' => "sprintf('/^%ss?$/', \$kind)"];
        yield 'the format of sprintf()' => ['before' => '', 'argument' => "sprintf(\$kind, 'x')"];
        yield 'an item of implode()' => ['before' => '', 'argument' => "'/^(' . implode('|', ['bird', \$kind]) . ')$/'"];
        yield 'the separator of implode()' => ['before' => '', 'argument' => "implode(\$kind, ['/a', 'b/'])"];
        yield 'an arm of a ternary composed of it' => ['before' => '', 'argument' => "\$flag ? '/whole/' : '/^' . \$kind . '$/'"];
        yield 'a variable assigned the concatenation' => ['before' => "\$re = '/^' . \$kind . 's?$/';", 'argument' => '$re'];
        yield 'a variable assigned it, then composed' => ['before' => '$alias = $kind;', 'argument' => "'/^' . \$alias . 's?$/'"];
        yield 'a variable built of it, then composed' => ['before' => "\$group = '(' . \$kind . ')';", 'argument' => "'/^' . \$group . '+$/'"];
    }

    #[Test]
    #[DataProvider('provideCompositionsOfAVariableTwoAssignmentsReach')]
    public function test_every_composition_of_a_variable_two_assignments_reach_is_opaque(string $before, string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): int\n{\n    \$kind = 'cat';\n    if (\$flag) {\n        \$kind = 'dog';\n    }\n    ".$before."\n\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * One assignment may give several values, exactly: the arms of a
     * ternary or of a match are the values the variable holds at the
     * read, and each gives a pattern the code builds.
     *
     * PHP 8.4: $kind = $flag ? 'cat' : 'dog'; '/^' . $kind . 's?$/' is
     * "/^cats?$/" or "/^dogs?$/".
     *
     * @return iterable<string, array{setup: string, argument: string, patterns: list<string>}>
     */
    public static function provideOneAssignmentOfSeveralValues(): iterable
    {
        yield 'a ternary, in a concatenation' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';", 'argument' => "'/^' . \$kind . 's?$/'", 'patterns' => ['/^cats?$/', '/^dogs?$/']];
        yield 'a ternary, in an interpolated string' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';", 'argument' => '"/^{$kind}s?$/"', 'patterns' => ['/^cats?$/', '/^dogs?$/']];
        yield 'a ternary, in sprintf()' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';", 'argument' => "sprintf('/^%ss?$/', \$kind)", 'patterns' => ['/^cats?$/', '/^dogs?$/']];
        yield 'a ternary, in implode()' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';", 'argument' => "'/^(' . implode('|', ['bird', \$kind]) . ')$/'", 'patterns' => ['/^(bird|cat)$/', '/^(bird|dog)$/']];
        yield 'a match' => ['setup' => "\$kind = match (\$given) {\n        'a' => 'cat',\n        'b' => 'dog',\n        default => 'bird',\n    };", 'argument' => "'/^' . \$kind . 's?$/'", 'patterns' => ['/^birds?$/', '/^cats?$/', '/^dogs?$/']];
        yield 'a ternary assigned in a branch' => ['setup' => "if ('' !== \$given) {\n        \$kind = \$flag ? 'cat' : 'dog';\n    }", 'argument' => "'/^' . \$kind . 's?$/'", 'patterns' => ['/^cats?$/', '/^dogs?$/']];
        yield 'a ternary, through a variable built of it' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';\n    \$group = '(' . \$kind . ')';", 'argument' => "'/^' . \$group . '+$/'", 'patterns' => ['/^(cat)+$/', '/^(dog)+$/']];
        yield 'a ternary, through a variable assigned it' => ['setup' => "\$kind = \$flag ? 'cat' : 'dog';\n    \$alias = \$kind;", 'argument' => "'/^' . \$alias . 's?$/'", 'patterns' => ['/^cats?$/', '/^dogs?$/']];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideOneAssignmentOfSeveralValues')]
    public function test_one_assignment_of_several_values_gives_each_of_them(string $setup, string $argument, array $patterns): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given, bool \$flag): int\n{\n    ".$setup."\n\n    return preg_match(".$argument.", \$s);\n}\n";

        $expected = [];
        foreach ($patterns as $pattern) {
            $expected[] = ['pattern' => $pattern, 'at' => $argument.', $s)', 'source' => 'preg_match()', 'location' => null];
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The only assignment of the variable is written after the read, and
     * no loop holds both: no assignment reaches the read. None is not
     * one: nothing is read, and the value assigned later is not taken.
     *
     * PHP 8.4: '/' . $part . '/' before $part = 'a' is "//", with the
     * warning "Undefined variable $part"; it is never "/a/".
     *
     * @return iterable<string, array{read: string, argument: string}>
     */
    public static function provideReadsNoAssignmentReaches(): iterable
    {
        yield 'a concatenation' => ['read' => "\$found = preg_match('/' . \$part . '/', \$s);", 'argument' => "'/' . \$part . '/'"];
        yield 'an interpolated string' => ['read' => '$found = preg_match("/^{$part}$/", $s);', 'argument' => '"/^{$part}$/"'];
        yield 'an interpolated string, the variable bare' => ['read' => '$found = preg_match("/^$part+/", $s);', 'argument' => '"/^$part+/"'];
        yield 'an argument of sprintf()' => ['read' => "\$found = preg_match(sprintf('/^%s$/', \$part), \$s);", 'argument' => "sprintf('/^%s$/', \$part)"];
        yield 'an item of implode()' => ['read' => "\$found = preg_match('/^(' . implode('|', ['b', \$part]) . ')$/', \$s);", 'argument' => "'/^(' . implode('|', ['b', \$part]) . ')$/'"];
        yield 'a variable assigned the concatenation before it' => ['read' => "\$re = '/' . \$part . '/';\n    \$found = preg_match(\$re, \$s);", 'argument' => '$re'];
        yield 'a read in a branch before it' => ['read' => "\$found = 0;\n    if ('' !== \$s) {\n        \$found = preg_match('/' . \$part . '/', \$s);\n    }", 'argument' => "'/' . \$part . '/'"];
        yield 'a read in a loop that ends before it' => ['read' => "\$found = 0;\n    foreach (\$lines as \$line) {\n        \$found += preg_match('/' . \$part . '/', \$s);\n    }", 'argument' => "'/' . \$part . '/'"];
    }

    #[Test]
    #[DataProvider('provideReadsNoAssignmentReaches')]
    public function test_no_assignment_reaching_a_composed_read_leaves_the_argument_opaque(string $read, string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, array \$lines): int\n{\n    ".$read."\n    \$part = 'a';\n\n    return \$found + \strlen(\$part);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The only assignment is written after the read, in a loop that
     * holds both: it reaches the read from the second turn on, and the
     * first turn builds something else. Nothing is read.
     *
     * PHP 8.4: foreach (['x', 'y'] as $line) { $built[] = '/' . $part .
     * '/'; $part = 'a'; } builds "//", with the warning "Undefined
     * variable $part", then "/a/".
     *
     * @return iterable<string, array{loop: string, argument: string}>
     */
    public static function provideLoopsWhoseOnlyAssignmentFollowsTheRead(): iterable
    {
        yield 'a concatenation in a foreach' => ['loop' => "foreach (\$lines as \$line) {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        \$part = 'a';\n    }", 'argument' => "'/' . \$part . '/'"];
        yield 'an interpolated string in a foreach' => ['loop' => "foreach (\$lines as \$line) {\n        \$found += preg_match(\"/^{\$part}$/\", \$s);\n        \$part = 'a';\n    }", 'argument' => '"/^{$part}$/"'];
        yield 'an argument of sprintf() in a foreach' => ['loop' => "foreach (\$lines as \$line) {\n        \$found += preg_match(sprintf('/^%s$/', \$part), \$s);\n        \$part = 'a';\n    }", 'argument' => "sprintf('/^%s$/', \$part)"];
        yield 'a concatenation in a while' => ['loop' => "while (\$found < 3) {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        \$part = 'a';\n    }", 'argument' => "'/' . \$part . '/'"];
        yield 'an interpolated string in a for' => ['loop' => "for (\$i = 0; \$i < 3; \$i++) {\n        \$found += preg_match(\"/^\$part+/\", \$s);\n        \$part = 'a';\n    }", 'argument' => '"/^$part+/"'];
        yield 'a concatenation in a do-while' => ['loop' => "do {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        \$part = 'a';\n    } while (\$found < 3);", 'argument' => "'/' . \$part . '/'"];
        yield 'the assignment in a branch of the loop' => ['loop' => "foreach (\$lines as \$line) {\n        \$found += preg_match('/' . \$part . '/', \$s);\n        if ('' === \$line) {\n            \$part = 'a';\n        }\n    }", 'argument' => "'/' . \$part . '/'"];
        yield 'the read in a loop inside the loop holding the assignment' => ['loop' => "foreach (\$lines as \$line) {\n        foreach (\$lines as \$other) {\n            \$found += preg_match('/' . \$part . '/', \$s);\n        }\n        \$part = 'a';\n    }", 'argument' => "'/' . \$part . '/'"];
        // PHP 8.4: for ($i = 0; $i < 2; $i++, $part = 'a') { $built[] = '/' . $part . '/'; } builds "//" then "/a/".
        yield 'the assignment in the step of a for' => ['loop' => "for (\$i = 0; \$i < 3; \$i++, \$part = 'a') {\n        \$found += preg_match('/' . \$part . '/', \$s);\n    }", 'argument' => "'/' . \$part . '/'"];
    }

    #[Test]
    #[DataProvider('provideLoopsWhoseOnlyAssignmentFollowsTheRead')]
    public function test_the_only_assignment_written_after_the_read_in_a_loop_leaves_the_argument_opaque(string $loop, string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, array \$lines): int\n{\n    \$found = 0;\n    ".$loop."\n\n    return \$found;\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The same variable, two assignments reaching both reads: alone, it
     * is one of the two patterns the file writes, each read where it is
     * written; joined to a flag, nothing is read.
     */
    #[Test]
    public function test_a_variable_read_alone_keeps_each_literal_it_is_assigned(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $re = '/alone-a/';
                if ($flag) {
                    $re = '/alone-b/';
                }

                return preg_match($re, $s) + preg_match($re . 'i', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/alone-a/', 'at' => "'/alone-a/'", 'source' => 'preg_match()', 'location' => 'via $re'],
            ['pattern' => '/alone-b/', 'at' => "'/alone-b/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'opaque', 'expression' => "\$re . 'i'"],
        ], $code);
    }

    /**
     * A variable built of one that two assignments reach holds nothing
     * the file proves: read alone or joined to literals, nothing is read
     * through it.
     */
    #[Test]
    public function test_a_variable_built_of_one_two_assignments_reach_brings_nothing_through(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $x = 'a';
                if ($flag) {
                    $x = 'b';
                }
                $pair = '(' . $x . ')';
                $re = '/^' . $pair . '$/';

                return preg_match('/' . $pair . '+/', $s) + preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/' . \$pair . '+/'"],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A variable assigned a composition that is not built holds nothing
     * the file proves, and is still assigned: what stands after "??" or
     * "?:" is not what the call is given. PHP 8.4: with $v = '/' . $p .
     * $q . '/', $v ?? '/d/' and $v ?: '/d/' are $v.
     */
    #[Test]
    public function test_a_fallback_after_a_composition_that_is_not_built_is_not_read_for_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $f, bool $g): int
            {
                $p = $f ? 'a' : 'b';
                $q = $g ? 'c' : 'd';
                $v = '/' . $p . $q . '/';

                return preg_match($v ?? '/d/', $s) + preg_match($v ?: '/e/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "\$v ?? '/d/'"],
            ['state' => 'opaque', 'expression' => "\$v ?: '/e/'"],
        ], $code);
    }
}
