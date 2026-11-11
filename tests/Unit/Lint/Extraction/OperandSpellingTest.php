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
 * An operand is read as PHP evaluates it, or not at all: a number under
 * its signs and its parentheses keeps its value, a sum is no number the
 * file writes; a float key is the integer PHP stores it as, whatever its
 * sign and however it is spelled; a call through a variable, a function
 * taken as a closure and an item named by no key are no text.
 *
 * PHP 8.4: '/a{' . -(3) . '}/' is "/a{-3}/" and '/a{' . -1 + 2 . '}/' is
 * "/a{1}/", "+" binding tighter than "."; [+2.0 => '/two/'][2] is "/two/"
 * and [1_0.0 => '/ten/'][10] is "/ten/"; "preg_match($map[], $s)" is
 * refused at compile time, "Cannot use [] for reading"; '/^' .
 * preg_quote(...) . '$/' throws "Object of class Closure could not be
 * converted to string".
 *
 * Every case runs through both strategies.
 */
final class OperandSpellingTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideNumbersUnderSignsAndParentheses(): iterable
    {
        yield 'a negative number in parentheses' => ['argument' => "'/a{' . -(3) . '}/'", 'pattern' => '/a{-3}/'];
        yield 'a positive number in two pairs of parentheses' => ['argument' => "'/a{' . +((3)) . '}/'", 'pattern' => '/a{3}/'];
        yield 'two minus signs' => ['argument' => "'/a{' . - -3 . '}/'", 'pattern' => '/a{3}/'];
    }

    #[Test]
    #[DataProvider('provideNumbersUnderSignsAndParentheses')]
    public function test_a_number_under_signs_and_parentheses_keeps_its_value(string $argument, string $pattern): void
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
     * The sign is the first token of something that is no number of its
     * own: the part is known at run time only, and the literals around it
     * are its shell.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideSignedOperandsThatAreNoNumber(): iterable
    {
        // -1 + 2 is 1: "/a{1}/", which the file does not write.
        yield 'a negative number added to another' => ['argument' => "'/a{' . -1 + 2 . '}/'"];
        yield 'a negated variable' => ['argument' => "'/a{' . -\$k . '}/'"];
        yield 'a negative number in parentheses, multiplied' => ['argument' => "'/a{' . -(2) * (3) . '}/'"];
    }

    #[Test]
    #[DataProvider('provideSignedOperandsThatAreNoNumber')]
    public function test_a_signed_operand_that_is_no_number_is_a_part_known_at_run_time(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * preg_quote(...) is a closure, which PHP refuses to join to a
     * string: no quoted text, and no text of a format either.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideFirstClassCallables(): iterable
    {
        yield 'preg_quote(...)' => ['argument' => "'/^' . preg_quote(...) . '$/'"];
        yield 'sprintf(...)' => ['argument' => "'/^' . sprintf(...) . '$/'"];
        yield 'implode(...)' => ['argument' => "'/^' . implode(...) . '$/'"];
    }

    #[Test]
    #[DataProvider('provideFirstClassCallables')]
    public function test_a_first_class_callable_is_not_the_text_its_function_returns(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Each row gives the key as the array writes it and as the read names
     * it: PHP stores a float key as an integer.
     *
     * @return iterable<string, array{written: string, named: string}>
     */
    public static function provideFloatKeys(): iterable
    {
        yield 'a float under a plus sign' => ['written' => '+2.0', 'named' => '2'];
        yield 'a float under a minus sign' => ['written' => '-2.0', 'named' => '-2'];
        yield 'a float with a digit separator' => ['written' => '1_0.0', 'named' => '10'];
        yield 'an integer, named by a float under a plus sign' => ['written' => '2', 'named' => '+2.0'];
        yield 'a negative integer, named in parentheses under its sign' => ['written' => '-2', 'named' => '-(2)'];
    }

    #[Test]
    #[DataProvider('provideFloatKeys')]
    public function test_a_float_key_names_the_item_of_its_integer(string $written, string $named): void
    {
        $code = "<?php\n\nfinal class Matcher\n{\n    private const KEYED = [".$written." => '/held/', 3 => '/other/'];\n\n    public function run(string \$s): int\n    {\n        return preg_match(self::KEYED[".$named."], \$s);\n    }\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/held/', 'at' => "'/held/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::KEYED['.$named.']', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{setup: string, argument: string}>
     */
    public static function provideOperandsThatAreNoText(): iterable
    {
        // PHP refuses to compile "$map[]" as a value; a buffer being typed
        // holds it, and it names no item of the array.
        yield 'an item of a local array named by no key' => ['setup' => "\$map = ['/a/', '/b/'];", 'argument' => '$map[]'];
        yield 'an item of a constant named by no key' => ['setup' => '', 'argument' => 'self::LIST[]'];
        yield 'a call through a variable' => ['setup' => "\$build = 'strtolower';", 'argument' => "\$build('/A/')"];
    }

    #[Test]
    #[DataProvider('provideOperandsThatAreNoText')]
    public function test_an_operand_that_is_no_text_leaves_the_argument_unread(string $setup, string $argument): void
    {
        $code = "<?php\n\nfinal class Matcher\n{\n    private const LIST = ['/l-a/', '/l-b/'];\n\n    public function run(string \$s): int\n    {\n        ".$setup."\n\n        return preg_match(".$argument.", \$s);\n    }\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    private static function matcher(string $argument): string
    {
        return "<?php\n\nfunction run(string \$s, int \$k): int\n{\n    return preg_match(".$argument.", \$s);\n}\n";
    }
}
