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
 * "." binds tighter than a comparison, a bitwise operator, "&&" and "||":
 * with one of them in the argument, the concatenation is an operand of
 * theirs, and the argument is a boolean or a number, not the text the
 * concatenation writes. An assignment, a compound one and "print" take
 * all that follows them, up to "and", "or" and "xor", which bind looser
 * still: what an operator joins after them is no part of the argument
 * either. An arrow function is a closure, whatever its body returns.
 *
 * None of these arguments is a pattern the file writes: each is left
 * unread, with no shell around a part it does not have.
 *
 * PHP 8.4, $given = 'x': '/^' . $given == '/^x' is true, '/^' . $given
 * <=> '/^x' is 0, '/^' . $given | '/^x' is "/^x" and $given === '/^' .
 * 'x$/' is false; ('/a/' . $x = 'b/' or $flag) is true; with $g = '/x',
 * ($g .= $flag ? '/i' : '/u') is "/x/i"; (print '') is 1.
 *
 * Every case runs through both strategies.
 */
final class LooserOperatorTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const OPERATORS = ['==', '===', '!=', '!==', '<', '>', '<=', '>=', '<=>', '&&', '||', '|', '^', '&'];

    /**
     * @return iterable<string, array{argument: string}>
     */
    public static function provideConcatenationsUnderALooserOperator(): iterable
    {
        foreach (self::OPERATORS as $operator) {
            yield 'a concatenation, then '.$operator => ['argument' => "'/^' . \$given ".$operator." '/^x'"];
            yield $operator.', then a concatenation' => ['argument' => '$given '.$operator." '/^' . 'x\$/'"];
        }
    }

    #[Test]
    #[DataProvider('provideConcatenationsUnderALooserOperator')]
    public function test_a_concatenation_under_a_looser_operator_is_not_the_argument(string $argument): void
    {
        $this->assertTheArgumentIsLeftUnread($argument);
    }

    /**
     * @return iterable<string, array{argument: string}>
     */
    public static function provideOperatorsThatTakeTheRest(): iterable
    {
        // $x = ($flag ? '/a/' : '/b/'): the arms are those of the value
        // assigned, which an argument is not read through.
        yield 'an assignment of a ternary' => ['argument' => "\$x = \$flag ? '/a/' : '/b/'"];
        // $given . ($flag ? '/i' : '/u'): neither arm is the argument.
        yield 'a concatenating assignment of a ternary' => ['argument' => "\$given .= \$flag ? '/i' : '/u'"];
        yield 'print of a ternary' => ['argument' => "print \$flag ? '/a/' : '/b/'"];
        // ('/a/' . ($x = 'b/')) or $flag: a boolean.
        yield 'an assignment in a concatenation, then or' => ['argument' => "'/a/' . \$x = 'b/' or \$flag"];
    }

    #[Test]
    #[DataProvider('provideOperatorsThatTakeTheRest')]
    public function test_what_follows_an_operator_that_takes_the_rest_is_not_the_argument(string $argument): void
    {
        $this->assertTheArgumentIsLeftUnread($argument);
    }

    /**
     * An arrow function runs to the end of the expression it is written
     * in: the ternary and the concatenation of its body are its own.
     */
    #[Test]
    public function test_an_arrow_function_is_not_what_its_body_returns(): void
    {
        $this->assertTheArgumentIsLeftUnread("fn (bool \$x): string => \$x ? '/a/' : '/b/'");
        $this->assertTheArgumentIsLeftUnread("fn (string \$x): string => '/^' . \$x . '\$/'");
    }

    /**
     * $map[$given] is one of the values of the array, and one of them is
     * a closure: the patterns of its body are not values of the array.
     */
    #[Test]
    public function test_an_array_holding_an_arrow_function_holds_no_pattern_of_its_body(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given): int
            {
                $map = ['a' => '/a/', 'pick' => fn (bool $x): string => $x ? '/b/' : '/c/'];

                return preg_match($map[$given], $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$map[$given]', 'source' => 'preg_match()'],
        ], $code);
    }

    private function assertTheArgumentIsLeftUnread(string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given, bool \$flag): int\n{\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }
}
