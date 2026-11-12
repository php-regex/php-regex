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
 * A variable of the file level joined to a string runs __toString() when
 * it holds an object, and raises a warning when it holds an array: between
 * the assignment of another variable and its read, the join is on the list
 * FileLevelImplicitCodeTest holds only when the file proves the variable a
 * string. What proves it:
 *
 * - a plain assignment of a value of the list that is a string: a string
 *   alone, in parentheses or not, or a concatenation, whatever it joins,
 *   since a number or a boolean joined to a string is a string;
 * - written as a statement of the file, or of a body in braces the join
 *   is still in: once the body is left it may not have run, and the
 *   variable then holds what it held before; so does the body of an if
 *   or of an else written without braces, which proves nothing;
 * - with nothing off the list since.
 *
 * A number, a boolean or an array assigned alone proves no string, and
 * takes the proof an earlier assignment gave. A variable a pattern is
 * read through is proved by what the file assigns it where it is read,
 * whatever the value is made of.
 *
 * PHP 8.4, with the bootstrap.php of FileLevelImplicitCodeTest required
 * before the file, which gives $object an object whose __toString()
 * writes the global $re: where the variable joined is a string,
 * preg_match() is given "/file-level/"; where it may still hold the
 * object, "/(__toString/". An integer, a float or a boolean joined to a
 * string runs nothing, "/file-level/", and an array joined to one raises
 * "Array to string conversion": both leave the variable unread, because
 * the file proves no string there. An assignment gives up what its
 * variable held, which the list does not follow: the variables these
 * files assign are unset before the files run, or hold the object where
 * a row says so.
 *
 * Every case runs through both strategies.
 */
final class FileLevelProvedStringTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * $label holds a string where it is joined, whatever runs.
     *
     * PHP 8.4: "/file-level/" in each case, $flag being true or false.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideAssignmentsThatProveAString(): iterable
    {
        yield 'a number joined to a string' => ['between' => "\$label = 1 . 'a';\n\$title = \$label . 'y';"];
        yield 'a string joined to a number' => ['between' => "\$label = 'a' . 1;\n\$title = \$label . 'y';"];
        yield 'a float joined to a string' => ['between' => "\$label = 1.5 . 'a';\n\$title = \$label . 'y';"];
        yield 'a boolean joined to a string' => ['between' => "\$label = true . 'a';\n\$title = \$label . 'y';"];
        yield 'a number joined to two strings' => ['between' => "\$label = 1 . 'a' . 'b';\n\$title = \$label . 'y';"];
        yield 'a number joined between two strings' => ['between' => "\$label = 'a' . 1 . 'b';\n\$title = \$label . 'y';"];
        yield 'a boolean joined to two strings' => ['between' => "\$label = false . 'a' . 'b';\n\$title = \$label . 'y';"];
        yield 'a string in parentheses' => ['between' => "\$label = ('a');\n\$title = \$label . 'y';"];
        yield 'a string in parentheses joined to a string' => ['between' => "\$label = ('a') . 'b';\n\$title = \$label . 'y';"];
        // The numbers stand in the array of another variable.
        yield 'a string, then an array of numbers assigned to another variable' => ['between' => "\$label = 'k';\n\$weights = [1, true];\n\$title = \$label . 'y';"];
        yield 'its own value joined to a string' => ['between' => "\$label = 'a';\n\$label = \$label . 'b';\n\$title = \$label . 'y';"];
        yield 'assigned and joined in one body' => ['between' => "if (\$flag) {\n    \$label = 'a';\n    \$title = \$label . 'y';\n}"];
        yield 'assigned before a body, joined in it' => ['between' => "\$label = 'a';\nif (\$flag) {\n    \$title = \$label . 'y';\n}"];
        yield 'assigned in a body, joined in a body it holds' => ['between' => "if (\$flag) {\n    \$label = 'a';\n    if (\$sapi) {\n        \$title = \$label . 'y';\n    }\n}"];
        yield 'assigned in a body, joined after a body it holds' => ['between' => "if (\$flag) {\n    \$label = 'a';\n    if (\$sapi) {\n        \$other = 'b';\n    }\n    \$title = \$label . 'y';\n}"];
        // A string before the if, a string in its body: a string after it.
        yield 'assigned, assigned again in a body, joined after it' => ['between' => "\$label = 'a';\nif (\$flag) {\n    \$label = 'b';\n}\n\$title = \$label . 'y';"];
        yield 'assigned, assigned again in a body without braces, joined after it' => ['between' => "\$label = 'a';\nif (\$flag) \$label = 'b';\n\$title = \$label . 'y';"];
        yield 'assigned before an if without braces, joined in its else' => ['between' => "\$label = 'a';\nif (\$flag) \$other = 'b'; else \$title = \$label . 'y';"];
        yield 'assigned after the body of an if' => ['between' => "if (\$flag) {\n    \$other = 'b';\n}\n\$label = 'a';\n\$title = \$label . 'y';"];
        yield 'assigned after the body of an else' => ['between' => "if (\$flag) {\n    \$other = 'b';\n} else {\n    \$other = 'c';\n}\n\$label = 'a';\n\$title = \$label . 'y';"];
        yield 'assigned after an if and its else without braces' => ['between' => "if (\$flag) \$other = 'b'; else \$other = 'c';\n\$label = 'a';\n\$title = \$label . 'y';"];
    }

    #[Test]
    #[DataProvider('provideAssignmentsThatProveAString')]
    public function test_a_variable_proved_a_string_joined_between_the_assignment_and_the_read_leaves_the_variable_read(string $between): void
    {
        $this->assertRead(self::whole($between));
    }

    /**
     * $label is joined where the file does not prove it a string: it
     * was assigned a number, a boolean or an array, alone or after a
     * string, or the assignment that would prove it stands in a body the
     * join has left.
     *
     * PHP 8.4: "/file-level/" after the numbers and the booleans, which
     * run nothing, and where both bodies of an if assign a string; "Array
     * to string conversion" is raised where an array is joined, which a
     * handler of another file may answer; "/(__toString/" where the body
     * that assigns $label does not run, $label holding the object before
     * the file runs: $flag false for the body that holds another body
     * and for the if joined in its else, true for the else without
     * braces, and $sapi empty for the body of a body. The variable is
     * left unread in each case.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideAssignmentsThatProveNoString(): iterable
    {
        yield 'an integer' => ['between' => "\$label = 1;\n\$title = \$label . 'y';"];
        yield 'a float' => ['between' => "\$label = 1.5;\n\$title = \$label . 'y';"];
        yield 'true' => ['between' => "\$label = true;\n\$title = \$label . 'y';"];
        yield 'false, in upper case' => ['between' => "\$label = FALSE;\n\$title = \$label . 'y';"];
        yield 'an integer in parentheses' => ['between' => "\$label = (1);\n\$title = \$label . 'y';"];
        yield 'a string, then an integer' => ['between' => "\$label = 'a';\n\$label = 1;\n\$title = \$label . 'y';"];
        yield 'a string, then a boolean' => ['between' => "\$label = 'a';\n\$label = true;\n\$title = \$label . 'y';"];
        yield 'an array' => ['between' => "\$label = ['a'];\n\$title = \$label . 'y';"];
        yield 'array()' => ['between' => "\$label = array('a');\n\$title = \$label . 'y';"];
        yield 'a string, then an array' => ['between' => "\$label = 'a';\n\$label = ['b'];\n\$title = \$label . 'y';"];
        yield 'a string, then array()' => ['between' => "\$label = 'a';\n\$label = array('b');\n\$title = \$label . 'y';"];
        yield 'a string, then an array of a number' => ['between' => "\$label = 'a';\n\$label = [1];\n\$title = \$label . 'y';"];
        // The concatenation is an item: the value is an array all the same.
        yield 'a string, then an array of a concatenation' => ['between' => "\$label = 'a';\n\$label = ['b' . 'c'];\n\$title = \$label . 'y';"];
        yield 'a string, then array() of a concatenation under a number' => ['between' => "\$label = 'a';\n\$label = array(1 => 'b' . 'c');\n\$title = \$label . 'y';"];
        yield 'a string, then an array in a body, joined after it' => ['between' => "\$label = 'a';\nif (\$flag) {\n    \$label = ['b'];\n}\n\$title = \$label . 'y';"];
        yield 'a string, then an array in a body without braces, joined after it' => ['between' => "\$label = 'a';\nif (\$flag) \$label = ['b'];\n\$title = \$label . 'y';"];
        yield 'assigned in a body that holds another body, joined after it' => ['between' => "if (\$flag) {\n    \$label = 'a';\n    if (\$sapi) {\n        \$other = 'b';\n    }\n}\n\$title = \$label . 'y';"];
        yield 'assigned in a body of a body, joined in the outer one' => ['between' => "if (\$flag) {\n    if (\$sapi) {\n        \$label = 'a';\n    }\n    \$title = \$label . 'y';\n}"];
        yield 'assigned in the bodies of an if and its else, joined after them' => ['between' => "if (\$flag) {\n    \$label = 'a';\n} else {\n    \$label = 'b';\n}\n\$title = \$label . 'y';"];
        yield 'assigned in the body of an if, joined in its else' => ['between' => "if (\$flag) {\n    \$label = 'a';\n} else {\n    \$title = \$label . 'y';\n}"];
        yield 'assigned in an else without braces, joined after it' => ['between' => "if (\$flag) \$other = 'b'; else \$label = 'a';\n\$title = \$label . 'y';"];
    }

    #[Test]
    #[DataProvider('provideAssignmentsThatProveNoString')]
    public function test_a_variable_not_proved_a_string_joined_between_the_assignment_and_the_read_leaves_the_variable_unread(string $between): void
    {
        $this->assertUnread(self::whole($between));
    }

    /**
     * The first statement of a file proves its variable as any other
     * does, whether the file opens with the PHP tag or with the text of
     * a template.
     *
     * PHP 8.4: "/file-level/".
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFirstStatementsOfAFile(): iterable
    {
        yield 'after the opening tag' => ['code' => "<?php \$label = 'a';\n\$re = '/file-level/';\n\$title = \$label . 'y';\npreg_match(\$re, \$subject);\n"];
        yield 'after the text of a template' => ['code' => "<p>text</p>\n<?php \$label = 'a';\n\$re = '/file-level/';\n\$title = \$label . 'y';\npreg_match(\$re, \$subject);\n"];
    }

    #[Test]
    #[DataProvider('provideFirstStatementsOfAFile')]
    public function test_the_first_statement_of_a_file_proves_its_variable(string $code): void
    {
        $this->assertRead($code);
    }

    /**
     * The first assignment the file makes of a string stands in a body,
     * and the join after that body: nothing was proved before, and the
     * body proves nothing once it is left.
     *
     * PHP 8.4, $sapi being "cli", $flag true and $label holding the
     * object before the file runs: "/(__toString/".
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFirstAssignmentsWrittenInABody(): iterable
    {
        yield 'in the body of an if' => ['code' => "<?php\n\nif (\$sapi === 'web') {\n    \$label = 'x';\n}\n\$re = '/file-level/';\n\$title = \$label . 'y';\npreg_match(\$re, \$subject);\n"];
        yield 'in a body of a body, the join in the outer one' => ['code' => "<?php\n\nif (\$flag) {\n    if (\$sapi === 'web') {\n        \$label = 'x';\n    }\n    \$re = '/file-level/';\n    \$title = \$label . 'y';\n    preg_match(\$re, \$subject);\n}\n"];
    }

    #[Test]
    #[DataProvider('provideFirstAssignmentsWrittenInABody')]
    public function test_the_first_assignment_of_a_file_written_in_a_body_proves_nothing_after_it(string $code): void
    {
        $this->assertUnread($code);
    }

    /**
     * A variable assigned one the file does not prove holds what that
     * one held: the copy is made before the assignment of $re, and the
     * join of the copy after it.
     *
     * PHP 8.4: "/(__toString/".
     */
    #[Test]
    public function test_a_copy_of_a_variable_the_file_does_not_prove_is_not_proved_either(): void
    {
        $this->assertUnread("<?php\n\n\$copy = \$object;\n\$re = '/file-level/';\n\$title = \$copy . 'y';\npreg_match(\$re, \$subject);\n");
    }

    /**
     * A call may write any variable of the file level: one written in
     * the condition of an if takes the proof an earlier assignment
     * gave, in the body of that if as anywhere after it. The call
     * stands before the assignment of $re, and nothing but the join
     * between that assignment and the read.
     *
     * PHP 8.4, helper() being a function of another file that gives
     * $label the object: "/(__toString/".
     */
    #[Test]
    public function test_a_call_in_the_condition_of_the_body_that_joins_takes_the_proof_of_an_earlier_assignment(): void
    {
        $this->assertUnread("<?php\n\n\$label = 'a';\nif (helper()) {\n    \$re = '/file-level/';\n    \$title = \$label . 'y';\n    preg_match(\$re, \$subject);\n}\n");
    }

    /**
     * The same file without the call: the string assigned before the
     * if is still proved in its body.
     *
     * PHP 8.4: "/file-level/".
     */
    #[Test]
    public function test_a_condition_of_the_list_over_the_body_that_joins_keeps_the_proof_of_an_earlier_assignment(): void
    {
        $this->assertRead("<?php\n\n\$label = 'a';\nif (\$flag) {\n    \$re = '/file-level/';\n    \$title = \$label . 'y';\n    preg_match(\$re, \$subject);\n}\n");
    }

    /**
     * $part is assigned a ternary of two strings, which no value of the
     * list is made of, and a pattern is read through it further down:
     * the file proves it to hold strings where it is joined, each time
     * it is. The read of $part itself comes after a call, which may
     * have written it.
     *
     * PHP 8.4: "/file-level/", then "/^a$/" or "/^b$/".
     */
    #[Test]
    public function test_a_variable_a_pattern_is_read_through_is_proved_by_what_the_file_assigns_it(): void
    {
        $code = "<?php\n\n\$part = \$argc > 1 ? 'a' : 'b';\n\$re = '/file-level/';\n\$title = \$part . 'y';\n\$other = \$part . 'z';\npreg_match(\$re, \$subject);\npreg_match('/^' . \$part . '\$/', \$subject);\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '\$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The same ternary, assigned to a variable no pattern is read
     * through: the file does not follow it, and the join is off the
     * list.
     *
     * PHP 8.4: "/file-level/"; nothing runs, and the variable is left
     * unread because the list is closed.
     */
    #[Test]
    public function test_a_variable_no_pattern_is_read_through_is_not_proved_by_a_ternary(): void
    {
        $this->assertUnread("<?php\n\n\$part = \$argc > 1 ? 'a' : 'b';\n\$re = '/file-level/';\n\$title = \$part . 'y';\npreg_match(\$re, \$subject);\n");
    }

    /**
     * A file where the variable is the whole argument, and something is
     * written between its assignment and the read.
     */
    private static function whole(string $between): string
    {
        return "<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n";
    }

    private function assertRead(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    private function assertUnread(string $code): void
    {
        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }
}
