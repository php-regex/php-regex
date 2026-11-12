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
 * A variable the file does not prove stands, in the condition of an if
 * written between the assignment of a variable of the file level and its
 * read, where PHP asks it nothing whatever it holds, or where the list
 * FileLevelImplicitCodeTest holds does not take it:
 *
 * - converted to a boolean, in parentheses of its own or not: alone as
 *   the condition, under "!", as an operand of "&&", "||", "and", "or"
 *   or "xor";
 * - compared with an integer, a float or a boolean written as it is,
 *   which is the whole of the other operand, the variable written first
 *   or second, with spaces around the operator or without;
 * - anywhere else, it is off the list: in parentheses under a
 *   comparison, compared with a string, with a concatenation or with a
 *   comparison, and joined to anything.
 *
 * PHP 8.4, with the bootstrap.php of FileLevelImplicitCodeTest required
 * before the file, which gives $object an object whose __toString()
 * writes the global $re: preg_match() is given "/file-level/" where no
 * method of the object runs, and "/(__toString/" where one does.
 *
 * Every case runs through both strategies.
 */
final class FileLevelUnprovedOperandTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * PHP 8.4: "/file-level/" in each case. Compared with a number, the
     * object raises a notice, which is not followed.
     *
     * @return iterable<string, array{condition: string}>
     */
    public static function provideConditionsThatRunNoCode(): iterable
    {
        yield 'the variable in parentheses of its own' => ['condition' => '($object)'];
        yield 'the variable in two pairs of parentheses' => ['condition' => '(($object))'];
        yield 'the variable in parentheses, negated' => ['condition' => '!($object)'];
        yield 'two variables in parentheses under &&' => ['condition' => '($object) && ($flag)'];
        yield 'a variable in parentheses after another, under or' => ['condition' => '$flag or ($object)'];
        yield 'an integer equal to the variable' => ['condition' => '1 == $object'];
        yield 'a boolean not equal to the variable' => ['condition' => 'false != $object'];
        yield 'a boolean in upper case equal to the variable' => ['condition' => 'TRUE == $object'];
        yield 'a float smaller than the variable' => ['condition' => '1.5 < $object'];
        yield 'an integer not greater than the variable' => ['condition' => '1 <= $object'];
        yield 'an integer equal to the variable, after &&' => ['condition' => 'true && 1 == $object'];
        yield 'an integer equal to the variable, before &&' => ['condition' => '1 == $object && true'];
        yield 'an integer equal to the variable, after another variable' => ['condition' => '$flag && 1 == $object'];
        yield 'the variable equal to an integer, after another variable' => ['condition' => '$flag && $object == 1'];
        yield 'the variable equal to an integer, before another variable' => ['condition' => '$object == 1 || $flag'];
        yield 'a comparison with an integer, in parentheses' => ['condition' => '($object == 1)'];
        yield 'an integer compared with the variable, in parentheses' => ['condition' => '(1 == $object) && $flag'];
        yield 'the variable greater than an integer, without spaces' => ['condition' => '$object>1'];
        yield 'an integer smaller than the variable, without spaces' => ['condition' => '1<$object'];
    }

    #[Test]
    #[DataProvider('provideConditionsThatRunNoCode')]
    public function test_a_condition_asking_nothing_of_a_variable_the_file_does_not_prove_leaves_the_variable_read(string $condition): void
    {
        $code = self::whole($condition);

        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * PHP 8.4: "/(__toString/" where the object is joined to a string or
     * compared with one, a concatenation included; "/file-level/" in the
     * rows of the parentheses and of the comparison compared, where
     * nothing runs: the variable is left unread there because the list
     * is closed.
     *
     * @return iterable<string, array{condition: string}>
     */
    public static function provideConditionsOffTheList(): iterable
    {
        yield 'the variable in parentheses, identical to a string' => ['condition' => "(\$object) === 'x'"];
        yield 'a string identical to the variable in parentheses' => ['condition' => "'x' === (\$object)"];
        yield 'the variable in parentheses, equal to an integer' => ['condition' => '($object) == 1'];
        yield 'an integer equal to the variable in parentheses' => ['condition' => '1 == ($object)'];
        yield 'the variable in two pairs of parentheses, equal to an integer' => ['condition' => '(($object)) == 1'];
        yield 'a comparison equal to the variable' => ['condition' => '(2 == 1) == $object'];
        yield 'an integer equal to the variable joined to a string' => ['condition' => "1 == \$object . 'x'"];
        yield 'the variable joined to a string, equal to an integer' => ['condition' => "\$object . 'x' == 1"];
        yield 'a string joined to an integer, equal to the variable' => ['condition' => "'a' . 1 == \$object"];
        yield 'the variable equal to an integer joined to a string' => ['condition' => "\$object == 1 . 'a'"];
        yield 'an integer joined to the variable' => ['condition' => '1 . $object'];
        yield 'a string joined to the variable, before another variable' => ['condition' => "'x' . \$object && \$flag"];
        yield 'the variable joined to a string, before another variable' => ['condition' => "\$object . 'x' && \$flag"];
        yield 'a string equal to the variable, before another variable' => ['condition' => "'x' == \$object && \$flag"];
        yield 'a string equal to the variable, after another variable' => ['condition' => "\$flag && 'x' == \$object"];
        yield 'a string joined to the variable, after another variable' => ['condition' => "\$flag && 'x' . \$object"];
    }

    #[Test]
    #[DataProvider('provideConditionsOffTheList')]
    public function test_a_condition_off_the_list_over_a_variable_the_file_does_not_prove_leaves_the_variable_unread(string $condition): void
    {
        $code = self::whole($condition);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A file where the variable is the whole argument, and an if over
     * the condition is written between its assignment and the read.
     */
    private static function whole(string $condition): string
    {
        return "<?php\n\n\$re = '/file-level/';\nif (".$condition.") {\n    \$label = 'a';\n}\npreg_match(\$re, \$subject);\n";
    }
}
