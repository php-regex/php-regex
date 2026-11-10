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
 * A pattern reached through several variables: the argument names one,
 * whose assignment names another, and so on. Every variable on the way is
 * followed as the one the argument names is: its values are read, and a
 * write that proves nothing of it, however far from the call, is seen.
 *
 * The tokenizer follows only the variables it has a reason to: the ones an
 * argument spells, then the ones spelled in what a followed variable is
 * assigned, until no name is added. Each case here needs that closure,
 * and runs through both strategies, which must agree.
 *
 * PHP 8.4: $word = 'abc'; $body = '^' . $word . '$'; $re = '/' . $body .
 * '/'; leaves "/^abc$/" in $re.
 */
final class TransitiveWatchingTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The argument spells $body only; $word is spelled in what $body is
     * assigned.
     */
    #[Test]
    public function test_a_pattern_is_read_through_two_variables(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $word = 'abc';
                $body = '^' . $word . '$';

                return preg_match('/' . $body . '/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/' . \$body . '/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The argument spells $re; $body is spelled in what $re is assigned,
     * $word in what $body is, $letter in what $word is.
     *
     * PHP 8.4: $letter = 'a'; $word = $letter . 'bc'; $body = '^' . $word
     * . '$'; $re = '/' . $body . '/i'; leaves "/^abc$/i" in $re.
     */
    #[Test]
    public function test_a_pattern_is_read_through_three_and_four_variables(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $letter = 'a';
                $word = $letter . 'bc';
                $body = '^' . $word . '$';
                $re = '/' . $body . '/i';

                return preg_match($re, $s) + preg_match('#' . $body . '#', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/i', 'at' => "'/' . \$body . '/i'", 'location' => 'via $re'],
            ['pattern' => '#^abc$#', 'at' => "'#' . \$body . '#'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'constant', 'expression' => "'#' . \$body . '#'"],
        ], $code);
    }

    /**
     * The variables on the way may be spelled in an interpolated string
     * or in the arguments of sprintf() and implode() as well as in a
     * concatenation.
     *
     * PHP 8.4: $word = 'abc'; $body = "^{$word}$"; $re = sprintf('/%s/',
     * $body); leaves "/^abc$/" in $re; $list = implode('|', [$word,
     * 'def']); '/^(' . $list . ')$/' is "/^(abc|def)$/".
     */
    #[Test]
    public function test_a_pattern_is_read_through_variables_spelled_in_a_string_or_a_call(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $word = 'abc';
                $body = "^{$word}$";
                $re = sprintf('/%s/', $body);
                $list = implode('|', [$word, 'def']);

                return preg_match($re, $s) + preg_match('/^(' . $list . ')$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "sprintf('/%s/', \$body)", 'location' => 'via $re'],
            ['pattern' => '/^(abc|def)$/', 'at' => "'/^(' . \$list . ')$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'constant', 'expression' => "'/^(' . \$list . ')$/'"],
        ], $code);
    }

    /**
     * A variable assigned another variable is one more on the way: the
     * argument spells $third only, and a write of the first of them is
     * seen from there.
     *
     * PHP 8.4: $first = '/alias/'; $second = $first; $third = $second;
     * leaves "/alias/" in $third.
     *
     * @return iterable<string, array{write: string}>
     */
    public static function provideWritesOfAnAliasedVariable(): iterable
    {
        yield 'passed to a call' => ['write' => 'normalize_part($far);'];
        yield 'a concatenating assignment' => ['write' => "\$far .= 'i';"];
        yield 'assigned a parameter in a branch' => ['write' => "if ('' !== \$given) {\n        \$far = \$given;\n    }"];
        yield 'a reference taken of it' => ['write' => '$alias = &$far;'];
    }

    #[Test]
    #[DataProvider('provideWritesOfAnAliasedVariable')]
    public function test_a_pattern_is_read_through_variables_assigned_one_another_and_a_write_of_the_first_is_seen(string $write): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given): int\n{\n"
            ."    \$first = '/alias/';\n    \$second = \$first;\n    \$third = \$second;\n\n"
            ."    \$far = '/written/';\n    ".$write."\n    \$near = \$far;\n    \$lost = \$near;\n\n"
            ."    return preg_match(\$third, \$s) + preg_match(\$lost, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/alias/', 'at' => "'/alias/'", 'location' => 'via $third'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$third', 'at' => '$third, $s)'],
            ['state' => 'opaque', 'expression' => '$lost', 'at' => '$lost, $s)'],
        ], $code);
    }

    /**
     * Each statement writes $far in a way that proves nothing of it; $far
     * is two variables away from the call.
     *
     * @return iterable<string, array{write: string}>
     */
    public static function provideWritesTwoVariablesAway(): iterable
    {
        yield 'passed to a call, which may take it by reference' => ['write' => 'normalize_part($far);'];
        yield 'passed to a method' => ['write' => '$items->normalize($far);'];
        yield 'written as an output argument' => ['write' => 'parse_str($given, $far);'];
        // $far = 'abc'; $far .= 'd'; leaves "abcd" in PHP.
        yield 'a concatenating assignment' => ['write' => "\$far .= 'd';"];
        yield 'a null coalescing assignment' => ['write' => "\$far ??= 'd';"];
        yield 'a reference taken of it' => ['write' => '$alias = &$far;'];
        yield 'assigned by reference' => ['write' => '$far = &$given;'];
        yield 'captured by reference in a closure' => ['write' => "\$write = function () use (&\$far): void {\n    };"];
        yield 'a reference in an array literal' => ['write' => '$references = [&$far];'];
        yield 'assigned a parameter in a branch' => ['write' => "if ('' !== \$given) {\n        \$far = \$given;\n    }"];
        yield 'assigned a call in a branch' => ['write' => "if ('' !== \$given) {\n        \$far = strtolower(\$given);\n    }"];
        yield 'the value of a foreach over an unknown array' => ['write' => "foreach (\$items->all() as \$far) {\n    }"];
        yield 'the value of a foreach by reference' => ['write' => "foreach (\$items->all() as &\$far) {\n    }"];
        yield 'a destructuring target' => ['write' => '[$far] = $items->all();'];
        yield 'a list() target' => ['write' => 'list(, $far) = $items->all();'];
        yield 'incremented' => ['write' => '$far++;'];
        yield 'unset' => ['write' => 'unset($far);'];
        yield 'declared global' => ['write' => 'global $far;'];
        yield 'declared static' => ['write' => 'static $far;'];
        yield 'a catch variable' => ['write' => "try {\n        \$items->all();\n    } catch (\\Exception \$far) {\n    }"];
    }

    /**
     * The chain beside it is the same without the write: it is read, and
     * the written one is a pattern around a part built at run time.
     */
    #[Test]
    #[DataProvider('provideWritesTwoVariablesAway')]
    public function test_a_write_two_variables_away_makes_the_argument_a_raw_shell(string $write): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given, object \$items): int\n{\n"
            ."    \$word = 'abc';\n    \$body = '^' . \$word . '$';\n    \$re = '/' . \$body . '/';\n\n"
            ."    \$far = 'abc';\n    ".$write."\n    \$near = '^' . \$far . '$';\n    \$written = '#' . \$near . '#';\n\n"
            ."    return preg_match(\$re, \$s) + preg_match(\$written, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
            ['state' => 'raw_shell', 'expression' => '$written', 'at' => '$written, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A parameter two variables away is a part built at run time, as one
     * written in the argument is.
     */
    #[Test]
    public function test_a_parameter_two_variables_away_makes_the_argument_a_raw_shell(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given): int
            {
                $word = 'abc';
                $body = '^' . $word . '$';

                $near = '^' . $given . '$';
                $written = '#' . $near . '#';

                return preg_match('/' . $body . '/', $s) + preg_match($written, $s) + preg_match("#{$near}#i", $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/' . \$body . '/'"],
            ['state' => 'raw_shell', 'expression' => '$written', 'at' => '$written, $s)'],
            ['state' => 'raw_shell', 'expression' => '"#{$near}#i"'],
        ], $code);
    }

    /**
     * The written variable is the one that would give the delimiter:
     * nothing static is left of the argument.
     *
     * @return iterable<string, array{write: string}>
     */
    public static function provideWritesOfTheOpeningTwoVariablesAway(): iterable
    {
        yield 'passed to a call' => ['write' => 'normalize_part($far);'];
        yield 'a concatenating assignment' => ['write' => "\$far .= '^';"];
        yield 'assigned a parameter in a branch' => ['write' => "if ('' !== \$given) {\n        \$far = \$given;\n    }"];
    }

    #[Test]
    #[DataProvider('provideWritesOfTheOpeningTwoVariablesAway')]
    public function test_a_write_two_variables_away_of_the_opening_leaves_the_argument_opaque(string $write): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given): int\n{\n"
            ."    \$open = '/';\n    \$start = \$open . '^abc';\n    \$re = \$start . '$/';\n\n"
            ."    \$far = '#';\n    ".$write."\n    \$near = \$far . '^abc';\n    \$written = \$near . '$#';\n\n"
            ."    return preg_match(\$re, \$s) + preg_match(\$written, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "\$start . '$/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'opaque', 'expression' => '$written', 'at' => '$written, $s)'],
        ], $code);
    }

    /**
     * Three variables away, and one variable away: the write is seen at
     * any distance.
     */
    #[Test]
    public function test_a_write_is_seen_at_any_distance_from_the_call(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $letter = 'a';
                $word = $letter . 'bc';
                $body = '^' . $word . '$';
                $re = '/' . $body . '/';

                $farthest = 'a';
                normalize_part($farthest);
                $far = $farthest . 'bc';
                $near = '^' . $far . '$';
                $three = '#' . $near . '#';

                $close = 'abc';
                $close .= 'd';
                $one = '~^' . $close . '$~';

                return preg_match($re, $s) + preg_match($three, $s) + preg_match($one, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'raw_shell', 'expression' => '$three', 'at' => '$three, $s)'],
            ['state' => 'raw_shell', 'expression' => '$one', 'at' => '$one, $s)'],
        ], $code);
    }

    /**
     * The write stands after the call in the source, in a loop holding
     * both, or anywhere in the body for a call that may take a reference:
     * it is seen all the same.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideWritesAfterTheCall(): iterable
    {
        yield 'passed to a call after the read' => ['body' => "\$far = 'abc';\n    \$near = '^' . \$far . '$';\n    \$found = preg_match('#' . \$near . '#', \$s);\n    normalize_part(\$far);"];
        yield 'a concatenating assignment after the read' => ['body' => "\$far = 'abc';\n    \$near = '^' . \$far . '$';\n    \$found = preg_match('#' . \$near . '#', \$s);\n    \$far .= 'd';"];
    }

    #[Test]
    #[DataProvider('provideWritesAfterTheCall')]
    public function test_a_write_two_variables_away_after_the_call_is_seen(string $body): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$word = 'abc';\n    \$body = '^' . \$word . '$';\n    ".$body."\n\n    return \$found + preg_match('/' . \$body . '/', \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'#' . \$near . '#'"],
            ['state' => 'constant', 'expression' => "'/' . \$body . '/'"],
        ], $code);
    }

    /**
     * preg_quote(), sprintf(), implode() and join() take their arguments
     * by value: a variable two variables away passed to one of them keeps
     * the value the file proves, and the pattern is read.
     *
     * @return iterable<string, array{statement: string}>
     */
    public static function provideByValueCallsTwoVariablesAway(): iterable
    {
        yield 'preg_quote()' => ['statement' => "\$quoted = preg_quote(\$word, '/');"];
        yield 'sprintf()' => ['statement' => "\$text = sprintf('The word is %s.', \$word);"];
        yield 'implode()' => ['statement' => "\$text = implode(\$word, ['a', 'b']);"];
        yield 'join()' => ['statement' => "\$text = \\join(\$word, ['a', 'b']);"];
    }

    #[Test]
    #[DataProvider('provideByValueCallsTwoVariablesAway')]
    public function test_a_by_value_call_two_variables_away_writes_nothing(string $statement): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$word = 'abc';\n    ".$statement."\n    \$body = '^' . \$word . '$';\n    \$re = '/' . \$body . '/';\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A variable of the same name in another function is another
     * variable: a write there changes nothing here.
     */
    #[Test]
    public function test_a_write_in_another_function_is_not_one_of_this_variable(): void
    {
        $code = <<<'PHP'
            <?php

            function other(string $given): string
            {
                $word = 'abc';
                normalize_part($word);
                $body = $given;
                $body .= $word;

                return $body;
            }

            function run(string $s): int
            {
                $word = 'abc';
                $body = '^' . $word . '$';
                $re = '/' . $body . '/';

                return preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/' . \$body . '/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }
}
