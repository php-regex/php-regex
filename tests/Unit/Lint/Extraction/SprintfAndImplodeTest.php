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
 * sprintf() and implode(), or join(), read as the string PHP builds when
 * the file proves what they are given.
 *
 * sprintf(): the format is one constant string holding only %s, %d and
 * %%. Each argument fills its place, a %d place taking an integer and
 * being a part built at run time for anything else; an argument the file
 * does not prove is such a part in its place, a preg_quote() call a
 * quoted one; an argument past the places is ignored, as PHP ignores it.
 * Any other directive, a spread or a named argument, or fewer arguments
 * than places, and nothing is read of the call.
 *
 * implode() and join(): the separator and every item are proved, and the
 * call is the items joined, with nothing between them when the array is
 * given alone; otherwise nothing is read of it.
 *
 * The names are compared without case, and the function is the one of PHP
 * unless the file declares one of that name in the namespace of the call,
 * or imports one with "use function". The pattern is composed at the
 * call: it is reported where the argument starts.
 *
 * Every expected pattern is what PHP 8.4 gives for the same call (php -r
 * 'var_dump(sprintf("/^%s$/", "abc"));' is "/^abc$/", and so on, see each
 * row).
 *
 * Every case runs through both strategies.
 */
final class SprintfAndImplodeTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideSprintfCalls(): iterable
    {
        // sprintf('/^%s$/', 'abc') is "/^abc$/".
        yield 'a string place' => ['argument' => "sprintf('/^%s$/', 'abc')", 'pattern' => '/^abc$/'];
        // sprintf('/^a{%d}$/', 3) is "/^a{3}$/".
        yield 'an integer place' => ['argument' => "sprintf('/^a{%d}$/', 3)", 'pattern' => '/^a{3}$/'];
        // sprintf('/^%d$/', -3) is "/^-3$/".
        yield 'an integer place taking a negative integer' => ['argument' => "sprintf('/^%d$/', -3)", 'pattern' => '/^-3$/'];
        // sprintf('/^%d%%$/', 50) is "/^50%$/".
        yield 'a percent sign' => ['argument' => "sprintf('/^%d%%$/', 50)", 'pattern' => '/^50%$/'];
        // sprintf('/100%%/') is "/100%/".
        yield 'a percent sign and no place' => ['argument' => "sprintf('/100%%/')", 'pattern' => '/100%/'];
        // sprintf('/abc/') is "/abc/".
        yield 'no place at all' => ['argument' => "sprintf('/abc/')", 'pattern' => '/abc/'];
        // sprintf('/^%s|%s$/', 'a', 'b') is "/^a|b$/".
        yield 'two string places' => ['argument' => "sprintf('/^%s|%s$/', 'a', 'b')", 'pattern' => '/^a|b$/'];
        // sprintf('/^%s{%d,%d}$/', 'a', 2, 5) is "/^a{2,5}$/".
        yield 'a string place and two integer places' => ['argument' => "sprintf('/^%s{%d,%d}$/', 'a', 2, 5)", 'pattern' => '/^a{2,5}$/'];
        // sprintf('/^%s$/u', 'né') is "/^né$/u".
        yield 'a multi-byte argument' => ['argument' => "sprintf('/^%s$/u', 'né')", 'pattern' => '/^né$/u'];
        // sprintf('%s', 3) is "3": an integer reads as PHP writes it.
        yield 'a string place taking an integer' => ['argument' => "sprintf('/^a{%s}$/', 3)", 'pattern' => '/^a{3}$/'];
        yield 'a format in a constant' => ['argument' => "sprintf(self::FORMAT, 'abc')", 'pattern' => '/^abc$/'];
        yield 'a format built of a constant' => ['argument' => "sprintf('/^' . self::PLACE . '$/', 'abc')", 'pattern' => '/^abc$/'];
        yield 'an argument in a constant' => ['argument' => "sprintf('/^%s$/', self::WORD)", 'pattern' => '/^word$/'];
        yield 'an integer argument in a constant' => ['argument' => "sprintf('/^a{%d}$/', self::MAX)", 'pattern' => '/^a{3}$/'];
        yield 'an argument built of literals' => ['argument' => "sprintf('/^%s$/', 'a' . 'bc')", 'pattern' => '/^abc$/'];
        // $part = 'abc': sprintf('/^%s$/', $part) is "/^abc$/".
        yield 'an argument in a local variable' => ['argument' => "sprintf('/^%s$/', \$part)", 'pattern' => '/^abc$/'];
        yield 'fully qualified' => ['argument' => "\\sprintf('/^%s$/', 'abc')", 'pattern' => '/^abc$/'];
        // PHP compares function names without case: SPRINTF('/^%s$/',
        // 'abc') is "/^abc$/".
        yield 'spelled in upper case' => ['argument' => "SPRINTF('/^%s$/', 'abc')", 'pattern' => '/^abc$/'];
        yield 'spelled in mixed case' => ['argument' => "Sprintf('/^%s$/', 'abc')", 'pattern' => '/^abc$/'];
        // '/' . sprintf('%s|%s', 'a', 'b') . '/' is "/a|b/".
        yield 'an operand of a concatenation' => ['argument' => "'/' . sprintf('%s|%s', 'a', 'b') . '/'", 'pattern' => '/a|b/'];
        // sprintf('/^%s$/', sprintf('%s+', 'a')) is "/^a+$/".
        yield 'an argument of another sprintf()' => ['argument' => "sprintf('/^%s$/', sprintf('%s+', 'a'))", 'pattern' => '/^a+$/'];
        // sprintf('/^(%s)$/', implode('|', ['a', 'b'])) is "/^(a|b)$/".
        yield 'around implode()' => ['argument' => "sprintf('/^(%s)$/', implode('|', ['a', 'b']))", 'pattern' => '/^(a|b)$/'];
        yield 'with a trailing comma' => ['argument' => "sprintf(\n            '/^%s$/',\n            'abc',\n        )", 'pattern' => '/^abc$/'];
        // sprintf('/^%s$/', 'a', 'extra') is "/^a$/": PHP ignores an
        // argument past the places, whatever it is.
        yield 'an argument past the places' => ['argument' => "sprintf('/^%s$/', 'a', 'extra')", 'pattern' => '/^a$/'];
        yield 'an argument past the places the file does not prove' => ['argument' => "sprintf('/^%s$/', 'a', \$given)", 'pattern' => '/^a$/'];
        yield 'an argument and no place' => ['argument' => "sprintf('/abc/', \$given)", 'pattern' => '/abc/'];
        // $format = '/^%s$/': sprintf($format, 'abc') is "/^abc$/".
        yield 'a format in a local variable' => ['argument' => "sprintf(\$format, 'abc')", 'pattern' => '/^abc$/'];
        // $max = 3: sprintf('/^a{%d}$/', $max) is "/^a{3}$/".
        yield 'an integer argument in a local variable' => ['argument' => "sprintf('/^a{%d}$/', \$max)", 'pattern' => '/^a{3}$/'];
        yield 'an integer argument in a local variable, in a string place' => ['argument' => "sprintf('/^a{%s}$/', \$max)", 'pattern' => '/^a{3}$/'];
    }

    #[Test]
    #[DataProvider('provideSprintfCalls')]
    public function test_sprintf_of_what_the_file_proves_reads_as_php_formats_it(string $argument, string $pattern): void
    {
        $code = self::matcher($argument);

        // Composed at the call: reported where the argument starts, with
        // no name to go through.
        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An argument the file does not prove is a hole in its place, of its
     * own kind: what the format writes around it is kept.
     *
     * @return iterable<string, array{argument: string, state: string}>
     */
    public static function provideSprintfArgumentsTheFileDoesNotProve(): iterable
    {
        yield 'a parameter' => ['argument' => "sprintf('/^%s$/', \$given)", 'state' => 'raw_shell'];
        yield 'a call' => ['argument' => "sprintf('/^%s$/', strtolower(\$given))", 'state' => 'raw_shell'];
        yield 'a parameter in an integer place' => ['argument' => "sprintf('/^a{%d}$/', \$count)", 'state' => 'raw_shell'];
        yield 'a parameter beside a proved argument' => ['argument' => "sprintf('/^%s-%s$/', 'abc', \$given)", 'state' => 'raw_shell'];
        yield 'preg_quote() with its delimiter' => ['argument' => "sprintf('/^%s$/', preg_quote(\$given, '/'))", 'state' => 'quoted_shell'];
        yield 'preg_quote() without a delimiter' => ['argument' => "sprintf('/^%s$/', preg_quote(\$given))", 'state' => 'quoted_shell'];
        yield 'two preg_quote() calls' => ['argument' => "sprintf('/^%s-%s$/', preg_quote(\$given, '/'), preg_quote(\$other, '/'))", 'state' => 'quoted_shell'];
        yield 'preg_quote() and a parameter' => ['argument' => "sprintf('/^%s-%s$/', preg_quote(\$given, '/'), \$other)", 'state' => 'raw_shell'];
        yield 'preg_quote() beside a proved argument' => ['argument' => "sprintf('/^%s{%d}$/', preg_quote(\$given, '/'), 3)", 'state' => 'quoted_shell'];
        // A float, a boolean and null are not read, as operands of a
        // concatenation are not: sprintf('%s', 1.5) is "1.5", true gives
        // "1", null "".
        yield 'a float' => ['argument' => "sprintf('/^a{%s}$/', 1.5)", 'state' => 'raw_shell'];
        yield 'a boolean' => ['argument' => "sprintf('/^a{%s}$/', true)", 'state' => 'raw_shell'];
        yield 'null' => ['argument' => "sprintf('/^a{%s}$/', null)", 'state' => 'raw_shell'];
        // The delimiter is not known: the hole comes first.
        yield 'a parameter in the first place' => ['argument' => "sprintf('%s/abc/', \$given)", 'state' => 'opaque'];
        yield 'preg_quote() in the first place' => ['argument' => "sprintf('%s/abc/', preg_quote(\$given))", 'state' => 'opaque'];
        yield 'a parameter as the whole format' => ['argument' => "sprintf('%s', \$given)", 'state' => 'opaque'];
    }

    #[Test]
    #[DataProvider('provideSprintfArgumentsTheFileDoesNotProve')]
    public function test_a_sprintf_argument_the_file_does_not_prove_is_a_hole_of_its_own_kind(string $argument, string $state): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Any directive but %s, %d and %%, and the whole call is a part built
     * at run time: alone it leaves nothing known of the argument, between
     * two literals it leaves a raw shell.
     *
     * PHP 8.4, with 3: %05d is "00003", %+d "+3", %5s "    3", %-5s
     * "3    ", %x "3", %b "11", %f "3.000000", %e "3.000000e+0", %c the
     * byte 3, %1$s "3"; %S, %D and %z are a ValueError ("Unknown format
     * specifier").
     *
     * @return iterable<string, array{directive: string}>
     */
    public static function provideOtherDirectives(): iterable
    {
        yield 'a padded integer' => ['directive' => '%05d'];
        // Written in a single-quoted format: sprintf('%\'010d', 3) is
        // "0000000003".
        yield 'an integer padded with a custom character' => ['directive' => "%\\'010d"];
        yield 'a signed integer' => ['directive' => '%+d'];
        yield 'a string of a given width' => ['directive' => '%5s'];
        yield 'a left-justified string' => ['directive' => '%-5s'];
        yield 'a string of a given precision' => ['directive' => '%.2s'];
        yield 'a numbered argument' => ['directive' => '%1$s'];
        yield 'a numbered integer' => ['directive' => '%1$d'];
        yield 'hexadecimal' => ['directive' => '%x'];
        yield 'upper case hexadecimal' => ['directive' => '%X'];
        yield 'octal' => ['directive' => '%o'];
        yield 'binary' => ['directive' => '%b'];
        yield 'a character' => ['directive' => '%c'];
        yield 'unsigned' => ['directive' => '%u'];
        yield 'a float' => ['directive' => '%f'];
        yield 'a locale-independent float' => ['directive' => '%F'];
        yield 'scientific notation' => ['directive' => '%e'];
        yield 'the shorter of two notations' => ['directive' => '%g'];
        yield 'an upper case S, which PHP refuses' => ['directive' => '%S'];
        yield 'an upper case D, which PHP refuses' => ['directive' => '%D'];
        yield 'a letter that is no directive' => ['directive' => '%z'];
    }

    #[Test]
    #[DataProvider('provideOtherDirectives')]
    public function test_any_other_directive_makes_the_whole_call_a_raw_hole(string $directive): void
    {
        $call = "sprintf('/^".$directive."-%s$/', 3, 'abc')";
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    return preg_match(".$call.", \$s)\n        + preg_match('/^' . ".$call." . '$/', \$s)\n        + preg_match(sprintf('/^%s-%d$/', 'abc', 3), \$s);\n}\n";

        // The call beside them holds the two directives that are read:
        // sprintf('/^%s-%d$/', 'abc', 3) is "/^abc-3$/".
        $this->assertExtractedByBoth([
            ['pattern' => '/^abc-3$/', 'at' => "sprintf('/^%s-%d$/', 'abc', 3)", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $call, 'at' => $call.', $s)'],
            ['state' => 'raw_shell', 'expression' => "'/^' . ".$call." . '$/'"],
            ['state' => 'constant', 'expression' => "sprintf('/^%s-%d$/', 'abc', 3)"],
        ], $code);
    }

    /**
     * A call PHP itself refuses or the file does not prove whole: fewer
     * arguments than places (sprintf('/^%s%s$/', 'a') is an
     * ArgumentCountError, "3 arguments are required, 2 given"), a "%" that
     * ends the format (sprintf('/50%/') is a ValueError), a format the
     * file does not prove, a spread or a named argument.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideSprintfCallsNothingIsReadOf(): iterable
    {
        yield 'an argument short' => ['call' => "sprintf('/^%s%s$/', 'a')"];
        yield 'no argument for one place' => ['call' => "sprintf('/^%s$/')"];
        yield 'no argument for an integer place' => ['call' => "sprintf('/^a{%d}$/')"];
        yield 'a percent sign ending the format' => ['call' => "sprintf('/50%')"];
        yield 'a percent sign before the closing delimiter' => ['call' => "sprintf('/50%/')"];
        yield 'a format in a parameter' => ['call' => "sprintf(\$given, 'abc')"];
        yield 'a format built around a parameter' => ['call' => "sprintf('/^%s' . \$given . '$/', 'abc')"];
        yield 'a format returned by a call' => ['call' => "sprintf(load_format(), 'abc')"];
        yield 'no format at all' => ['call' => 'sprintf()'];
        // sprintf('/^%s$/', ...['abc']) is "/^abc$/" in PHP: what a
        // spread gives is not followed.
        yield 'a spread argument' => ['call' => "sprintf('/^%s$/', ...\$args)"];
        yield 'a spread literal array' => ['call' => "sprintf('/^%s$/', ...['abc'])"];
        yield 'a spread argument after a proved one' => ['call' => "sprintf('/^%s-%s$/', 'abc', ...\$args)"];
        yield 'the format spread' => ['call' => 'sprintf(...$args)'];
        // sprintf(format: '/abc/') is "/abc/" in PHP; with values: it is
        // an ArgumentCountError ("does not accept unknown named
        // parameters").
        yield 'the format passed by name' => ['call' => "sprintf(format: '/abc/')"];
        yield 'an argument passed by name' => ['call' => "sprintf('/^%s$/', values: 'abc')"];
        yield 'a method named sprintf' => ['call' => "\$this->sprintf('/^%s$/', 'abc')"];
        yield 'a static method named sprintf' => ['call' => "Text::sprintf('/^%s$/', 'abc')"];
        yield 'a function of another namespace named sprintf' => ['call' => "\\Vendor\\Text\\sprintf('/^%s$/', 'abc')"];
        // vsprintf('/^%s$/', ['abc']) is "/^abc$/" in PHP: not one of the
        // functions that are read.
        yield 'vsprintf()' => ['call' => "vsprintf('/^%s$/', ['abc'])"];
        yield 'number_format()' => ['call' => 'number_format(3)'];
    }

    #[Test]
    #[DataProvider('provideSprintfCallsNothingIsReadOf')]
    public function test_a_sprintf_call_the_file_does_not_prove_whole_is_a_raw_hole(string $call): void
    {
        $code = "<?php\n\nfinal class Matcher\n{\n    public function run(string \$s, string \$given, array \$args): int\n    {\n        return preg_match(".$call.", \$s)\n            + preg_match('/^' . ".$call." . '$/', \$s)\n            + preg_match(sprintf('/^%s-%d$/', 'abc', 3), \$s);\n    }\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc-3$/', 'at' => "sprintf('/^%s-%d$/', 'abc', 3)", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $call, 'at' => $call.', $s)'],
            ['state' => 'raw_shell', 'expression' => "'/^' . ".$call." . '$/'"],
            ['state' => 'constant', 'expression' => "sprintf('/^%s-%d$/', 'abc', 3)"],
        ], $code);
    }

    /**
     * A %d place takes an integer. PHP casts whatever else it is given:
     * sprintf('/^a{%d}$/', '3x') is "/^a{3}$/", '12' gives "12", 'abc'
     * "0", 1.5 "1", true "1". None of these casts is read: the place is a
     * part built at run time, and what the format writes around it is
     * kept.
     *
     * @return iterable<string, array{value: string}>
     */
    public static function provideValuesAnIntegerPlaceCasts(): iterable
    {
        yield 'a string starting with digits' => ['value' => "'3x'"];
        yield 'a string of digits' => ['value' => "'12'"];
        yield 'a string with no digit' => ['value' => "'abc'"];
        yield 'a float' => ['value' => '1.5'];
        yield 'a boolean' => ['value' => 'true'];
        yield 'null' => ['value' => 'null'];
        yield 'a constant holding a string' => ['value' => 'self::WORD'];
        yield 'a local variable holding a string' => ['value' => '$part'];
    }

    #[Test]
    #[DataProvider('provideValuesAnIntegerPlaceCasts')]
    public function test_an_integer_place_given_no_integer_is_a_raw_hole_in_that_place(string $value): void
    {
        $argument = "sprintf('/^a{%d}$/', ".$value.')';
        $first = "sprintf('%d/abc/', ".$value.')';
        $code = "<?php\n\nfinal class Matcher\n{\n    private const WORD = 'word';\n\n    public function run(string \$s): int\n    {\n        \$part = 'abc';\n\n"
            .'        return preg_match('.$argument.', $s) + preg_match('.$first.", \$s) + preg_match(sprintf('/^b{%d}$/', 3), \$s);\n    }\n}\n";

        // The integer beside them is read: "/^b{3}$/".
        $this->assertExtractedByBoth([
            ['pattern' => '/^b{3}$/', 'at' => "sprintf('/^b{%d}$/', 3)", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
            // The delimiter is not known: the hole comes first.
            ['state' => 'opaque', 'expression' => $first, 'source' => 'preg_match()'],
            ['state' => 'constant', 'expression' => "sprintf('/^b{%d}$/', 3)"],
        ], $code);
    }

    /**
     * One argument or one item of several values, standing once, gives
     * as many patterns, as one operand of a concatenation does. Two of
     * them, or the same one twice, would give strings the code never
     * builds: nothing is known of the argument.
     *
     * PHP 8.4: $kind = 'cat' gives "/^cats?$/" and "/^(bird|cat)$/",
     * $kind = 'dog' gives "/^dogs?$/" and "/^(bird|dog)$/";
     * sprintf('/^%s|%s$/', $kind, $kind) is "/^cat|cat$/" or
     * "/^dog|dog$/", never "/^cat|dog$/".
     */
    #[Test]
    public function test_one_argument_of_several_values_gives_as_many_patterns_and_two_give_none(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                if ($flag) {
                    $kind = 'cat';
                    $size = 'big';
                } else {
                    $kind = 'dog';
                    $size = 'small';
                }

                return preg_match(sprintf('/^%ss?$/', $kind), $s)
                    + preg_match('/^(' . implode('|', ['bird', $kind]) . ')$/', $s)
                    + preg_match(sprintf('/^%s|%s$/', $kind, $kind), $s)
                    + preg_match(sprintf('/^%s-%s$/', $size, $kind), $s)
                    + preg_match('/^(' . implode('|', [$size, $kind]) . ')$/', $s)
                    + preg_match(sprintf('/^%s' . $size . '$/', $kind), $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => "sprintf('/^%ss?$/', \$kind)", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dogs?$/', 'at' => "sprintf('/^%ss?$/', \$kind)", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^(bird|cat)$/', 'at' => "'/^(' . implode('|', ['bird', \$kind]) . ')$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^(bird|dog)$/', 'at' => "'/^(' . implode('|', ['bird', \$kind]) . ')$/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "sprintf('/^%ss?$/', \$kind)"],
            ['state' => 'finite_set', 'expression' => "'/^(' . implode('|', ['bird', \$kind]) . ')$/'"],
            ['state' => 'opaque', 'expression' => "sprintf('/^%s|%s$/', \$kind, \$kind)"],
            ['state' => 'opaque', 'expression' => "sprintf('/^%s-%s$/', \$size, \$kind)"],
            ['state' => 'opaque', 'expression' => "'/^(' . implode('|', [\$size, \$kind]) . ')$/'"],
            ['state' => 'opaque', 'expression' => "sprintf('/^%s' . \$size . '$/', \$kind)"],
        ], $code);
    }

    /**
     * A format held in a local variable is read by the rules of a
     * variable: one written in a way that proves nothing of it leaves
     * nothing read of the call.
     *
     * PHP 8.4: $format = '/^%s-%d$/'; sprintf($format, 'abc', 3) is
     * "/^abc-3$/".
     */
    #[Test]
    public function test_a_format_in_a_variable_is_read_by_the_rules_of_a_variable(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given): int
            {
                $format = '/^%s-%d$/';
                $alias = $format;
                $written = '/^%s-%d$/';
                normalize_part($written);
                $unknown = $given;

                return preg_match(sprintf($format, 'abc', 3), $s)
                    + preg_match(sprintf($alias, 'def', 4), $s)
                    + preg_match(sprintf($written, 'abc', 3), $s)
                    + preg_match(sprintf($unknown, 'abc', 3), $s)
                    + preg_match('#' . sprintf($written, 'abc', 3) . '#', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc-3$/', 'at' => "sprintf(\$format, 'abc', 3)", 'location' => null],
            ['pattern' => '/^def-4$/', 'at' => "sprintf(\$alias, 'def', 4)", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "sprintf(\$format, 'abc', 3)"],
            ['state' => 'constant', 'expression' => "sprintf(\$alias, 'def', 4)"],
            ['state' => 'opaque', 'expression' => "sprintf(\$written, 'abc', 3)", 'at' => "sprintf(\$written, 'abc', 3), \$s)"],
            ['state' => 'opaque', 'expression' => "sprintf(\$unknown, 'abc', 3)"],
            ['state' => 'raw_shell', 'expression' => "'#' . sprintf(\$written, 'abc', 3) . '#'"],
        ], $code);
    }

    /**
     * A variable assigned sprintf() or implode() is read where the call
     * is written, through the name the call reads, as one assigned a
     * concatenation is.
     */
    #[Test]
    public function test_a_variable_assigned_a_call_is_read_at_the_call(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given): int
            {
                $formatted = sprintf('/^%s{%d}$/', 'a', 3);
                $joined = implode('|', ['/cat', 'dog/']);
                $shell = sprintf('/^%s$/', preg_quote($given, '/'));

                return preg_match($formatted, $s) + preg_match($joined, $s) + preg_match($shell, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^a{3}$/', 'at' => "sprintf('/^%s{%d}$/', 'a', 3)", 'location' => 'via $formatted'],
            ['pattern' => '/cat|dog/', 'at' => "implode('|', ['/cat', 'dog/'])", 'location' => 'via $joined'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$formatted', 'at' => '$formatted, $s)'],
            ['state' => 'constant', 'expression' => '$joined', 'at' => '$joined, $s)'],
            ['state' => 'quoted_shell', 'expression' => '$shell', 'at' => '$shell, $s)'],
        ], $code);
    }

    /**
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideImplodeCalls(): iterable
    {
        // implode('|', ['/a', 'b/']) is "/a|b/".
        yield 'implode() of a literal array' => ['argument' => "implode('|', ['/a', 'b/'])", 'pattern' => '/a|b/'];
        // join('|', ['/a', 'b/']) is "/a|b/".
        yield 'join() of a literal array' => ['argument' => "join('|', ['/a', 'b/'])", 'pattern' => '/a|b/'];
        // '/^(' . implode('|', ['a', 'b', 'c']) . ')$/' is "/^(a|b|c)$/".
        yield 'an operand of a concatenation' => ['argument' => "'/^(' . implode('|', ['a', 'b', 'c']) . ')$/'", 'pattern' => '/^(a|b|c)$/'];
        // WORDS is ['cat', 'dog']: "/^(cat|dog)$/".
        yield 'of a constant array' => ['argument' => "'/^(' . implode('|', self::WORDS) . ')$/'", 'pattern' => '/^(cat|dog)$/'];
        yield 'of an array of constants' => ['argument' => "'/^(' . implode('|', [self::WORD, 'other']) . ')$/'", 'pattern' => '/^(word|other)$/'];
        yield 'with a separator in a constant' => ['argument' => "'/^(' . implode(self::BAR, ['a', 'b']) . ')$/'", 'pattern' => '/^(a|b)$/'];
        // implode('', ['/a', 'b', '/']) is "/ab/".
        yield 'with an empty separator' => ['argument' => "implode('', ['/a', 'b', '/'])", 'pattern' => '/ab/'];
        // implode(', ', ['/a', 'b/']) is "/a, b/".
        yield 'with a separator of two characters' => ['argument' => "implode(', ', ['/a', 'b/'])", 'pattern' => '/a, b/'];
        // implode('|', ['/only/']) is "/only/".
        yield 'of one item' => ['argument' => "implode('|', ['/only/'])", 'pattern' => '/only/'];
        // '/^(' . implode('|', []) . ')$/' is "/^()$/".
        yield 'of no item' => ['argument' => "'/^(' . implode('|', []) . ')$/'", 'pattern' => '/^()$/'];
        // implode('|', ['k' => '/a', 'j' => 'b/']) is "/a|b/": the keys
        // are not joined.
        yield 'of a keyed array' => ['argument' => "implode('|', ['k' => '/a', 'j' => 'b/'])", 'pattern' => '/a|b/'];
        // '/^(' . implode('|', [1, 2]) . ')$/' is "/^(1|2)$/": an integer
        // reads as PHP writes it.
        yield 'of integers' => ['argument' => "'/^(' . implode('|', [1, 2]) . ')$/'", 'pattern' => '/^(1|2)$/'];
        // implode('|', ['a', 3, -2]) is "a|3|-2".
        yield 'of strings and integers' => ['argument' => "'/^(' . implode('|', ['a', 3, -2]) . ')$/'", 'pattern' => '/^(a|3|-2)$/'];
        yield 'of items built of literals' => ['argument' => "'/^(' . implode('|', ['a' . 'b', 'c']) . ')$/'", 'pattern' => '/^(ab|c)$/'];
        yield 'with a trailing comma in the array' => ['argument' => "implode('|', [\n            '/a',\n            'b/',\n        ])", 'pattern' => '/a|b/'];
        yield 'of an array() literal' => ['argument' => "implode('|', array('/a', 'b/'))", 'pattern' => '/a|b/'];
        yield 'fully qualified' => ['argument' => "\\implode('|', ['/a', 'b/'])", 'pattern' => '/a|b/'];
        // PHP compares function names without case: IMPLODE('|', ['a'])
        // . Join('|', ['b']) is "ab".
        yield 'implode() spelled in upper case' => ['argument' => "IMPLODE('|', ['/a', 'b/'])", 'pattern' => '/a|b/'];
        yield 'join() spelled in mixed case' => ['argument' => "Join('|', ['/a', 'b/'])", 'pattern' => '/a|b/'];
        yield 'join(), fully qualified' => ['argument' => "\\join('|', ['/a', 'b/'])", 'pattern' => '/a|b/'];
        // implode(['/a', 'b/']) is "/ab/": given the array alone, PHP
        // joins with nothing.
        yield 'implode() of the array alone' => ['argument' => "implode(['/a', 'b/'])", 'pattern' => '/ab/'];
        yield 'join() of the array alone' => ['argument' => "join(['/a', 'b/'])", 'pattern' => '/ab/'];
        yield 'implode() of a constant array alone' => ['argument' => "'/' . implode(self::WORDS) . '/'", 'pattern' => '/catdog/'];
    }

    #[Test]
    #[DataProvider('provideImplodeCalls')]
    public function test_implode_of_what_the_file_proves_reads_as_php_joins_it(string $argument, string $pattern): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A separator or an item the file does not prove, and the whole call
     * is a part built at run time, whatever the other items are: a
     * preg_quote() item makes no quoted hole of the call.
     *
     * PHP 8.4: implode('|', [1.5, true, null, false]) is "1.5|1||": a
     * float, a boolean and null are not read, as operands are not.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideImplodeCallsNothingIsReadOf(): iterable
    {
        yield 'an unknown array' => ['call' => "implode('|', \$words)"];
        yield 'an array returned by a call' => ['call' => "implode('|', array_keys(\$words))"];
        yield 'an item that is a parameter' => ['call' => "implode('|', ['a', \$given])"];
        yield 'an item that is a call' => ['call' => "implode('|', ['a', strtolower(\$given)])"];
        yield 'an item that is a preg_quote() call' => ['call' => "implode('|', ['a', preg_quote(\$given, '/')])"];
        yield 'an array of quoted texts' => ['call' => "implode('|', array_map('preg_quote', \$words))"];
        yield 'an item that is a float' => ['call' => "implode('|', ['a', 1.5])"];
        yield 'an item that is a boolean' => ['call' => "implode('|', ['a', true])"];
        yield 'an item that is null' => ['call' => "implode('|', ['a', null])"];
        yield 'an item that is an array' => ['call' => "implode('|', ['a', ['b']])"];
        yield 'a spread item' => ['call' => "implode('|', ['a', ...\$words])"];
        yield 'a separator in a parameter' => ['call' => "implode(\$given, ['a', 'b'])"];
        yield 'a separator returned by a call' => ['call' => "implode(separator(), ['a', 'b'])"];
        yield 'join() of an unknown array' => ['call' => "join('|', \$words)"];
        yield 'a method named implode' => ['call' => "\$this->implode('|', ['a', 'b'])"];
        yield 'a static method named join' => ['call' => "Text::join('|', ['a', 'b'])"];
        yield 'a function of another namespace named implode' => ['call' => "\\Vendor\\Text\\implode('|', ['a', 'b'])"];
        // implode(separator: '|', array: ['a', 'b']) is "a|b" in PHP: a
        // named argument is not followed.
        yield 'arguments passed by name' => ['call' => "implode(separator: '|', array: ['a', 'b'])"];
        yield 'the array passed by name' => ['call' => "implode('|', array: ['a', 'b'])"];
        yield 'an unknown array alone' => ['call' => 'implode($words)'];
        yield 'no argument at all' => ['call' => 'implode()'];
    }

    #[Test]
    #[DataProvider('provideImplodeCallsNothingIsReadOf')]
    public function test_an_implode_call_the_file_does_not_prove_whole_is_a_raw_hole(string $call): void
    {
        $code = "<?php\n\nfinal class Matcher\n{\n    public function run(string \$s, string \$given, array \$words): int\n    {\n        return preg_match(".$call.", \$s)\n            + preg_match('/^(' . ".$call." . ')$/', \$s)\n            + preg_match('/^(' . implode('|', ['kept', 'read']) . ')$/', \$s);\n    }\n}\n";

        // The call beside them is proved whole: "/^(kept|read)$/".
        $this->assertExtractedByBoth([
            ['pattern' => '/^(kept|read)$/', 'at' => "'/^(' . implode('|', ['kept', 'read']) . ')$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $call, 'at' => $call.', $s)'],
            ['state' => 'raw_shell', 'expression' => "'/^(' . ".$call." . ')$/'"],
            ['state' => 'constant', 'expression' => "'/^(' . implode('|', ['kept', 'read']) . ')$/'"],
        ], $code);
    }

    /**
     * A later unit reads this: the items held in a local variable
     * assigned an array literal ($local = ['a', 'b']; implode('|',
     * $local) is "a|b" in PHP). Until the arrays are read, the call is a
     * part built at run time as with any array the file does not prove;
     * each row moves with that reading.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideImplodeCallsALaterUnitReads(): iterable
    {
        yield 'implode() of a local array' => ['call' => "implode('|', \$local)"];
        yield 'join() of a local array' => ['call' => "join('|', \$local)"];
        yield 'implode() of a local array alone' => ['call' => 'implode($local)'];
    }

    #[Test]
    #[DataProvider('provideImplodeCallsALaterUnitReads')]
    public function test_implode_of_a_local_array_is_a_raw_hole_until_the_arrays_are_read(string $call): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$local = ['a', 'b'];\n\n    return preg_match(".$call.", \$s)\n        + preg_match('/^(' . ".$call." . ')$/', \$s)\n        + preg_match('/^(' . implode('|', ['kept', 'read']) . ')$/', \$s);\n}\n";

        // The call beside them is proved whole: "/^(kept|read)$/".
        $this->assertExtractedByBoth([
            ['pattern' => '/^(kept|read)$/', 'at' => "'/^(' . implode('|', ['kept', 'read']) . ')$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $call, 'at' => $call.', $s)'],
            ['state' => 'raw_shell', 'expression' => "'/^(' . ".$call." . ')$/'"],
            ['state' => 'constant', 'expression' => "'/^(' . implode('|', ['kept', 'read']) . ')$/'"],
        ], $code);
    }

    /**
     * The function is the one of PHP unless the file declares one of that
     * name in the namespace of the call, or imports one with "use
     * function": an unqualified call reaches that one first, and what it
     * returns is not known. A fully qualified call still reaches PHP's.
     *
     * @return iterable<string, array{header: string, footer: string}>
     */
    public static function provideFilesThatShadowTheFunctions(): iterable
    {
        yield 'functions declared in the namespace of the call' => [
            'header' => "namespace App\\Text;\n",
            'footer' => "function sprintf(string \$format, string ...\$values): string\n{\n    return \$format;\n}\n\nfunction implode(string \$separator, array \$items): string\n{\n    return \$separator;\n}\n\nfunction join(string \$separator, array \$items): string\n{\n    return \$separator;\n}\n",
        ];

        yield 'functions declared in upper case' => [
            'header' => "namespace App\\Text;\n",
            'footer' => "function SPRINTF(string \$format, string ...\$values): string\n{\n    return \$format;\n}\n\nfunction IMPLODE(string \$separator, array \$items): string\n{\n    return \$separator;\n}\n\nfunction JOIN(string \$separator, array \$items): string\n{\n    return \$separator;\n}\n",
        ];

        yield 'functions imported with use function' => [
            'header' => "namespace App\\Text;\n\nuse function Vendor\\Text\\sprintf;\nuse function Vendor\\Text\\implode;\nuse function Vendor\\Text\\join;\n",
            'footer' => '',
        ];

        yield 'functions imported under an alias' => [
            'header' => "namespace App\\Text;\n\nuse function Vendor\\Text\\format as sprintf;\nuse function Vendor\\Text\\glue as implode;\nuse function Vendor\\Text\\glue as join;\n",
            'footer' => '',
        ];

        yield 'functions imported in a group' => [
            'header' => "namespace App\\Text;\n\nuse function Vendor\\Text\\{sprintf, implode, join};\n",
            'footer' => '',
        ];

        yield 'functions imported at the top of a file with no namespace' => [
            'header' => "use function Vendor\\Text\\sprintf;\nuse function Vendor\\Text\\implode;\nuse function Vendor\\Text\\join;\n",
            'footer' => '',
        ];
    }

    #[Test]
    #[DataProvider('provideFilesThatShadowTheFunctions')]
    public function test_a_function_the_file_declares_or_imports_is_not_the_one_of_php(string $header, string $footer): void
    {
        $code = "<?php\n\n".$header."\nfunction run(string \$s): int\n{\n"
            ."    return preg_match(sprintf('/^%s$/', 'shadowed'), \$s)\n"
            ."        + preg_match(implode('|', ['/shadowed', 'implode/']), \$s)\n"
            ."        + preg_match(join('|', ['/shadowed', 'join/']), \$s)\n"
            ."        + preg_match('/^' . sprintf('%s', 'operand') . '$/', \$s)\n"
            ."        + preg_match(\\sprintf('/^%s$/', 'qualified'), \$s)\n"
            ."        + preg_match(\\implode('|', ['/qualified', 'implode/']), \$s)\n"
            ."        + preg_match(\\join('|', ['/qualified', 'join/']), \$s);\n}\n\n".$footer;

        $this->assertExtractedByBoth([
            ['pattern' => '/^qualified$/', 'at' => "\\sprintf('/^%s$/', 'qualified')", 'location' => null],
            ['pattern' => '/qualified|implode/', 'at' => "\\implode('|', ['/qualified', 'implode/'])", 'location' => null],
            ['pattern' => '/qualified|join/', 'at' => "\\join('|', ['/qualified', 'join/'])", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "sprintf('/^%s$/', 'shadowed')", 'at' => "sprintf('/^%s$/', 'shadowed'), \$s)"],
            ['state' => 'opaque', 'expression' => "implode('|', ['/shadowed', 'implode/'])"],
            ['state' => 'opaque', 'expression' => "join('|', ['/shadowed', 'join/'])"],
            ['state' => 'raw_shell', 'expression' => "'/^' . sprintf('%s', 'operand') . '$/'"],
            ['state' => 'constant', 'expression' => "\\sprintf('/^%s$/', 'qualified')"],
            ['state' => 'constant', 'expression' => "\\implode('|', ['/qualified', 'implode/'])"],
            ['state' => 'constant', 'expression' => "\\join('|', ['/qualified', 'join/'])"],
        ], $code);
    }

    /**
     * Without such a declaration, an unqualified call in a namespace
     * falls back to the function of PHP; a function of that name declared
     * in another namespace of the file, or a method of that name, hides
     * nothing.
     */
    #[Test]
    public function test_an_unqualified_call_in_a_namespace_reaches_the_function_of_php(): void
    {
        $code = <<<'PHP'
            <?php

            namespace Vendor\Text {
                function sprintf(string $format): string
                {
                    return $format;
                }

                function implode(string $separator): string
                {
                    return $separator;
                }
            }

            namespace App\Search {
                final class Formatter
                {
                    public function sprintf(string $format): string
                    {
                        return $format;
                    }

                    public function join(string $separator): string
                    {
                        return $separator;
                    }

                    public function run(string $s): int
                    {
                        return preg_match(sprintf('/^%s$/', 'fallback'), $s)
                            + preg_match(implode('|', ['/fallback', 'implode/']), $s)
                            + preg_match(join('|', ['/fallback', 'join/']), $s);
                    }
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^fallback$/', 'at' => "sprintf('/^%s$/', 'fallback')", 'location' => null],
            ['pattern' => '/fallback|implode/', 'at' => "implode('|', ['/fallback', 'implode/'])", 'location' => null],
            ['pattern' => '/fallback|join/', 'at' => "join('|', ['/fallback', 'join/'])", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "sprintf('/^%s$/', 'fallback')"],
            ['state' => 'constant', 'expression' => "implode('|', ['/fallback', 'implode/'])"],
            ['state' => 'constant', 'expression' => "join('|', ['/fallback', 'join/'])"],
        ], $code);
    }

    /**
     * A final class holding the constants a format, an argument or an
     * array may name, whose method run() assigns $part, $format and $max
     * and reads the argument in preg_match().
     */
    private static function matcher(string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const FORMAT = '/^%s$/';\n    private const PLACE = '%s';\n    private const WORD = 'word';\n    private const MAX = 3;\n"
            ."    private const BAR = '|';\n    private const WORDS = ['cat', 'dog'];\n\n"
            ."    public function run(string \$s, string \$given, string \$other, int \$count): int\n    {\n        \$part = 'abc';\n        \$format = '/^%s$/';\n        \$max = 3;\n\n"
            .'        return preg_match('.$argument.", \$s);\n    }\n}\n";
    }
}
