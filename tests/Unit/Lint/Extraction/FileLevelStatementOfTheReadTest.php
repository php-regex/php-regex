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
 * The statement that holds the read of a variable of the file level
 * begins before the read, and what it begins with stands between the
 * assignment and the read as any statement does. The list
 * FileLevelImplicitCodeTest holds takes, there: if, elseif, else,
 * return, braces, parentheses, the target of a plain assignment where a
 * value may start, comparisons, "!" and the operators that convert to a
 * boolean, over values of the list or a variable where it runs no code,
 * and the name of each function whose call holds the read, qualified or
 * not, the arguments written before the read being values of the list,
 * each alone.
 *
 * Anything else the statement begins with leaves the variable unread:
 * a constant, a concatenation, an array or an item that holds the call,
 * an argument that compares or assigns, a target assigned where no
 * value may start.
 *
 * PHP 8.4, with the bootstrap.php of FileLevelImplicitCodeTest required
 * before the file: preg_match() is given "/file-level/" in every row
 * read. What the rows left unread run is said with each.
 *
 * Every case runs through both strategies.
 */
final class FileLevelStatementOfTheReadTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row is the statement that holds the read, written after the
     * assignment of $re.
     *
     * PHP 8.4: "/file-level/" wherever the call is reached, $flag
     * holding the object in the rows that name it: converted to a
     * boolean or compared, it runs no method.
     *
     * @return iterable<string, array{statement: string}>
     */
    public static function provideBeginningsOfTheList(): iterable
    {
        yield 'the result assigned in a condition' => ['statement' => "if (\$found = preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'the result assigned in a condition, negated' => ['statement' => "if (!\$found = preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'the result assigned after a variable converted to a boolean' => ['statement' => "if (\$flag && \$found = preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'the result assigned in parentheses' => ['statement' => '$found = ($count = preg_match($re, $subject));'];
        yield 'the result assigned to two variables' => ['statement' => '$found = $count = preg_match($re, $subject);'];
        yield 'the result assigned in the argument of a function' => ['statement' => 'var_dump($found = preg_match($re, $subject));'];
        yield 'the result assigned in the second argument of a function' => ['statement' => "var_dump('found', \$found = preg_match(\$re, \$subject));"];
        yield 'the result assigned and returned' => ['statement' => 'return $found = preg_match($re, $subject);'];

        yield 'the argument of a qualified function' => ['statement' => 'App\check(preg_match($re, $subject));'];
        yield 'the argument of a fully qualified function' => ['statement' => '\var_dump(preg_match($re, $subject));'];
        yield 'the argument of a function in the argument of another' => ['statement' => 'var_dump(intval(preg_match($re, $subject)));'];
        yield 'the third argument of a function, after two literals' => ['statement' => "var_dump('a', 'b', preg_match(\$re, \$subject));"];
        yield 'the fourth argument of a function, after three literals' => ['statement' => "var_dump('a', 1, true, preg_match(\$re, \$subject));"];
        yield 'the second argument of a function, after an array' => ['statement' => "var_dump(['a', 'b'], preg_match(\$re, \$subject));"];
        yield 'the second argument of a function, after a string in parentheses' => ['statement' => "var_dump(('a'), preg_match(\$re, \$subject));"];

        yield 'compared with a number written first, under an ordering' => ['statement' => '$found = 0 < preg_match($re, $subject);'];
        yield 'compared with a number written first, under an inequality' => ['statement' => '$found = 0 != preg_match($re, $subject);'];
        yield 'compared with a number written first, under an ordering or equality' => ['statement' => '$found = 1 >= preg_match($re, $subject);'];
        yield 'in parentheses' => ['statement' => '$found = (preg_match($re, $subject));'];

        yield 'after a variable in parentheses' => ['statement' => '$found = ($flag) && preg_match($re, $subject);'];
        yield 'after a variable in two pairs of parentheses' => ['statement' => '$found = (($flag)) && preg_match($re, $subject);'];
        yield 'after a variable in parentheses that starts the statement' => ['statement' => '($flag) && preg_match($re, $subject);'];
        yield 'after a variable that starts the statement, under &&' => ['statement' => '$flag && preg_match($re, $subject);'];
        yield 'after a variable that starts the statement, under or' => ['statement' => '$flag or preg_match($re, $subject);'];
        yield 'after a variable identical to a string, which starts the statement' => ['statement' => "\$flag === 'x' && preg_match(\$re, \$subject);"];
        yield 'after a string identical to a variable, which starts the statement' => ['statement' => "'x' === \$flag && preg_match(\$re, \$subject);"];
        yield 'after a variable equal to a number, which starts the statement' => ['statement' => '$flag == 1 && preg_match($re, $subject);'];
        yield 'after a number equal to a variable, which starts the statement' => ['statement' => '1 == $flag && preg_match($re, $subject);'];
        yield 'after a number equal to a variable, in a condition' => ['statement' => "if (1 == \$flag && preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'after a boolean equal to a variable, in a condition' => ['statement' => "if (true == \$flag && preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'after a number equal to a variable, after another variable' => ['statement' => "if (\$sapi && 1 == \$flag && preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'after a number equal to a variable, returned' => ['statement' => 'return 1 == $flag && preg_match($re, $subject);'];
        yield 'after a number equal to a variable, assigned' => ['statement' => '$found = 1 == $flag && preg_match($re, $subject);'];

        yield 'in the braces of a block' => ['statement' => "{\n    preg_match(\$re, \$subject);\n}"];
        // The ";" is the whole body of the if, or of the else: the block
        // after it runs whatever the condition gives.
        yield 'in a block written after an if whose body is an empty statement' => ['statement' => "if (\$flag);\n{\n    preg_match(\$re, \$subject);\n}"];
        yield 'in a block written after an else whose body is an empty statement' => ['statement' => "if (\$flag) {\n    \$label = 'a';\n} else;\n{\n    preg_match(\$re, \$subject);\n}"];
        yield 'in the condition of an if in the body of an if' => ['statement' => "if (\$flag) {\n    if (preg_match(\$re, \$subject)) {\n        \$label = 'a';\n    }\n}"];
    }

    #[Test]
    #[DataProvider('provideBeginningsOfTheList')]
    public function test_a_statement_beginning_with_what_the_list_holds_leaves_the_variable_read(string $statement): void
    {
        $this->assertRead("<?php\n\n\$re = '/file-level/';\n".$statement."\n");
    }

    /**
     * Each row is the statement that holds the read, written after the
     * assignment of $re.
     *
     * PHP 8.4: "/file-level/" in each case but two: nothing runs before
     * the read, the array joined to the call in parentheses raising its
     * warning after it, and the variable is left unread because the
     * list is closed. "Array to string conversion" is raised before the
     * call by 'x' . array('a'), which a handler of another file may
     * answer, and "/(__toString/" is given after $flag . 'x', $flag
     * holding the object.
     *
     * @return iterable<string, array{statement: string}>
     */
    public static function provideBeginningsOffTheList(): iterable
    {
        yield 'the result assigned after a comparison' => ['statement' => "if (1 === \$found = preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'the result assigned after a concatenation' => ['statement' => "if ('a' . \$found = preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'the result assigned in an array' => ['statement' => '$found = [$count = preg_match($re, $subject)];'];

        yield 'a constant before the call, under &&' => ['statement' => "if (DEBUG && preg_match(\$re, \$subject)) {\n    \$label = 'a';\n}"];
        yield 'a constant compared with a number before the call' => ['statement' => '$found = \PHP_VERSION_ID > 80000 && preg_match($re, $subject);'];
        yield 'a string joined to the call' => ['statement' => "\$found = 'a' . preg_match(\$re, \$subject);"];
        yield 'an array joined to the call in parentheses' => ['statement' => "\$found = ['a'] . (preg_match(\$re, \$subject));"];
        yield 'a string joined to array() before the call' => ['statement' => "\$found = 'x' . array('a') && preg_match(\$re, \$subject);"];
        yield 'a variable joined to a string before the call' => ['statement' => "\$found = \$flag . 'x' && preg_match(\$re, \$subject);"];
        yield 'the call in an array' => ['statement' => '$found = [preg_match($re, $subject)];'];
        yield 'the call in array()' => ['statement' => '$found = array(preg_match($re, $subject));'];
        yield 'the call after an item of an array' => ['statement' => "\$found = ['a', preg_match(\$re, \$subject)];"];
        yield 'the call compared in the brackets of an item' => ['statement' => "\$label = ['no', 'yes'][1 === preg_match(\$re, \$subject)];"];

        yield 'an argument that compares in parentheses, before the call' => ['statement' => "var_dump(('a' === 'b'), preg_match(\$re, \$subject));"];
        yield 'an argument that compares, then a literal, before the call' => ['statement' => "var_dump('a' === 'b', 'c', preg_match(\$re, \$subject));"];
        yield 'a literal, then an argument that compares, before the call' => ['statement' => "var_dump('c', 'a' === 'b', preg_match(\$re, \$subject));"];
        yield 'an argument that assigns, then a literal, before the call' => ['statement' => "var_dump(\$found = 'a', 'c', preg_match(\$re, \$subject));"];
        yield 'an argument that compares, before the call in the argument of another function' => ['statement' => "var_dump('a' === 'b', intval('c', preg_match(\$re, \$subject)));"];
        yield 'an argument that is a variable the file does not prove, before the call' => ['statement' => 'var_dump($flag, preg_match($re, $subject));'];
    }

    #[Test]
    #[DataProvider('provideBeginningsOffTheList')]
    public function test_a_statement_beginning_with_what_the_list_does_not_hold_leaves_the_variable_unread(string $statement): void
    {
        $this->assertUnread("<?php\n\n\$re = '/file-level/';\n".$statement."\n");
    }

    /**
     * A comparison, a negation and a conversion to a boolean are what
     * only the beginning of the statement of the read may hold: assigned
     * as a statement of its own, between the assignment and the read,
     * each is off the list, over strings as over anything else.
     *
     * PHP 8.4: "/file-level/" in each case: nothing runs, and the
     * variable is left unread because the list is closed.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideComparisonsOfLiteralsAssigned(): iterable
    {
        yield 'an identity of strings' => ['between' => "\$same = 'a' === 'b';"];
        yield 'an inequality of strings' => ['between' => "\$other = 'a' != 'b';"];
        yield 'an ordering of strings' => ['between' => "\$less = 'a' < 'b';"];
        yield 'an ordering or equality of strings' => ['between' => "\$most = 'a' >= 'b';"];
        yield 'a negation of a string' => ['between' => "\$none = !'a';"];
        yield 'two strings under &&' => ['between' => "\$both = 'a' && 'b';"];
        yield 'two strings under xor' => ['between' => "\$either = ('a' xor 'b');"];
    }

    #[Test]
    #[DataProvider('provideComparisonsOfLiteralsAssigned')]
    public function test_a_comparison_of_literals_assigned_between_the_assignment_and_the_read_leaves_the_variable_unread(string $between): void
    {
        $this->assertUnread("<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n");
    }

    /**
     * An if whose condition assigns is the beginning of a statement and
     * no if of the list, whatever else the condition holds: written
     * whole between the assignment and the read, it leaves the variable
     * unread.
     *
     * PHP 8.4: "/(__destruct/" in each case: the condition gives up the
     * object $object held for another value.
     *
     * @return iterable<string, array{condition: string}>
     */
    public static function provideConditionsThatAssign(): iterable
    {
        yield 'a comparison assigned' => ['condition' => "\$object = 'x' === \$flag"];
        yield 'an assignment in parentheses, then &&' => ['condition' => "(\$object = 'x') && true"];
        yield 'an assignment in two pairs of parentheses, then &&' => ['condition' => "((\$object = 'x')) && true"];
        yield 'an assignment after &&' => ['condition' => "true && \$object = 'x'"];
        yield 'an assignment after a negation' => ['condition' => "!\$object = 'x'"];
    }

    #[Test]
    #[DataProvider('provideConditionsThatAssign')]
    public function test_an_if_whose_condition_assigns_between_the_assignment_and_the_read_leaves_the_variable_unread(string $condition): void
    {
        $this->assertUnread("<?php\n\n\$re = '/file-level/';\nif (".$condition.") {\n    \$label = 'a';\n}\npreg_match(\$re, \$subject);\n");
    }

    /**
     * A variable assigned in the statement of the read, before the read,
     * holds from there what the statement gave it: the string an earlier
     * statement proved is proved no more.
     *
     * PHP 8.4: "Array to string conversion" is raised before the call,
     * which a handler of another file may answer.
     */
    #[Test]
    public function test_a_variable_assigned_in_the_statement_of_the_read_is_proved_no_more(): void
    {
        $this->assertUnread("<?php\n\n\$label = 'a';\n\$re = '/file-level/';\nif ((\$label = ['b']) && \$label . 'y' && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n");
    }

    /**
     * An assignment written in the braces that name a property is no
     * statement of its own: the property is read after it, through
     * __get() when the object has one, before the call that reads $re.
     *
     * PHP 8.4: "/(__get/".
     */
    #[Test]
    public function test_an_assignment_in_the_braces_that_name_a_property_is_no_statement_of_its_own(): void
    {
        $this->assertUnread("<?php\n\n\$found = \$object->{\$re = '/file-level/'} === preg_match(\$re, \$subject);\n");
    }

    /**
     * What is written before the assignment changes nothing to what the
     * statement of the read may begin with: parentheses, an array or a
     * call closed before leave nothing open.
     *
     * PHP 8.4: "/file-level/" in each case.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideBracketsClosedBeforeTheAssignment(): iterable
    {
        yield 'a string in parentheses' => ['before' => "\$label = ('a') . 'b';"];
        yield 'a call' => ['before' => "\$label = trim(' a ');"];
        yield 'a call of what a variable names' => ['before' => "\$matched = \$callable('/x/', 'x');"];
        yield 'a condition' => ['before' => "if (\$flag) {\n    \$label = 'a';\n}"];
        yield 'an item written' => ['before' => "\$items[0] = 'a';"];
        yield 'an array, then a string in parentheses' => ['before' => "\$items = ['a', ['b']];\n\$label = ('a');"];
    }

    #[Test]
    #[DataProvider('provideBracketsClosedBeforeTheAssignment')]
    public function test_brackets_closed_before_the_assignment_leave_the_statement_of_the_read_as_it_is(string $before): void
    {
        $this->assertRead("<?php\n\n".$before."\n\$re = '/file-level/';\nif (\$found = preg_match(\$re, \$subject)) {\n    \$label = 'b';\n}\n");
    }

    /**
     * The first statement of the file opens with a parenthesis: it is
     * closed where it closes, and the statements after it are read as
     * after any other.
     *
     * PHP 8.4: "/file-level/" in each case.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesOpeningWithAParenthesis(): iterable
    {
        yield 'a closure called at once' => ['code' => "<?php\n\n(function () {\n    return 1;\n})();\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n"];
        yield 'a comparison in parentheses, or exit' => ['code' => "<?php\n(\$argc > 1) || exit(1);\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n"];
        yield 'a string in parentheses, right after the opening tag' => ['code' => "<?php ('a');\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n"];
    }

    #[Test]
    #[DataProvider('provideFilesOpeningWithAParenthesis')]
    public function test_a_file_opening_with_a_parenthesis_is_read_after_it(string $code): void
    {
        $this->assertRead($code);
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
