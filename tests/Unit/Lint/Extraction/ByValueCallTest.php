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
 * Any call may take a variable by reference, and the file does not say
 * which: a variable passed to one is written off. Four functions of PHP
 * are known to take every argument by value, preg_quote(), sprintf(),
 * implode() and join(): a variable passed to one of them keeps the value
 * the file proves.
 *
 * PHP 8.4, in one function body: $re = "/read/"; preg_quote($re, "/");
 * sprintf("%s", $re); implode($re, ["a"]); join($re, ["a"]); leaves $re
 * holding "/read/".
 *
 * Every case runs through both strategies.
 */
final class ByValueCallTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideByValueCalls(): iterable
    {
        yield 'preg_quote()' => ['statement' => "\$quoted = preg_quote(\$re, '/');"];
        yield 'preg_quote(), fully qualified' => ['statement' => '$quoted = \\preg_quote($re);'];
        yield 'preg_quote(), its argument passed by name' => ['statement' => "\$quoted = preg_quote(delimiter: '/', str: \$re);"];
        yield 'sprintf(), as an argument' => ['statement' => "\$text = sprintf('The pattern is %s.', \$re);"];
        yield 'sprintf(), as the format' => ['statement' => '$text = \\sprintf($re, 1);'];
        // PHP compares function names without case: Sprintf('%s', 'x') is "x".
        yield 'sprintf(), spelled in mixed case' => ['statement' => "\$text = Sprintf('%s', \$re);"];
        yield 'implode()' => ['statement' => "\$text = implode(\$re, ['a', 'b']);"];
        yield 'join()' => ['statement' => "\$text = join(\$re, ['a', 'b']);"];
        yield 'a by-value call inside another call' => ['statement' => "\$text = strtoupper(sprintf('%s', \$re));"];
        yield 'twice' => ['statement' => "\$text = sprintf('%s%s', preg_quote(\$re), \$re);"];
        yield 'in a condition' => ['statement' => "if ('' === preg_quote(\$re)) {\n        return 0;\n    }"];
    }

    #[Test]
    #[DataProvider('provideByValueCalls')]
    public function test_a_variable_passed_to_a_by_value_function_is_still_read(string $statement): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$re = '/read/';\n    ".$statement."\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/read/', 'at' => "'/read/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * In a namespace an unqualified preg_quote() is PHP's when the
     * namespace declares none.
     */
    #[Test]
    public function test_an_unqualified_by_value_function_in_a_namespace_is_the_one_of_php(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App\Search;

            function run(string $s): int
            {
                $re = '/namespaced/';
                $quoted = preg_quote($re, '/');

                return preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/namespaced/', 'at' => "'/namespaced/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * Only the function of PHP is known: a method or a function of another
     * namespace of the same name, and every other function, by value or
     * not, still writes the variable off. vsprintf() takes its arguments
     * by value too, and is not one of the four.
     *
     * @return iterable<string, array{statement: string}>
     */
    public static function provideOtherCalls(): iterable
    {
        yield 'another function' => ['statement' => 'normalize_pattern($re);'];
        yield 'a function of PHP outside the four' => ['statement' => '$text = vsprintf($re, []);'];
        yield 'a method named sprintf' => ['statement' => '$text = $formatter->sprintf($re);'];
        yield 'a static method named preg_quote' => ['statement' => '$text = Text::preg_quote($re);'];
        yield 'a function of another namespace named implode' => ['statement' => '$text = \\Vendor\\Text\\implode($re, []);'];
        yield 'a function held in a variable' => ['statement' => "\$call = 'sprintf';\n    \$text = \$call(\$re);"];
        yield 'a by-value call and another call' => ['statement' => "\$text = sprintf('%s', \$re);\n    normalize_pattern(\$re);"];
    }

    #[Test]
    #[DataProvider('provideOtherCalls')]
    public function test_a_variable_passed_to_any_other_call_is_still_written_off(string $statement): void
    {
        $code = "<?php\n\nfunction run(string \$s, object \$formatter): int\n{\n    \$re = '/written-off/';\n    ".$statement."\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }
}
