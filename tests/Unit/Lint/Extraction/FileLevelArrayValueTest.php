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
 * The list FileLevelImplicitCodeTest holds takes, between the assignment
 * of a variable of the file level and its read, a plain assignment of an
 * array of strings, numbers and booleans under such keys, written with
 * brackets or with array(), in parentheses or not. The square brackets
 * are an array only where a value starts, and "=>" the key of an item
 * only in an array and after what is no array: an item read from a
 * value, an array used as a key and a string joined to an array are off
 * the list, whether they run code or not.
 *
 * PHP 8.4: nothing runs for the arrays of the list, and preg_match() is
 * given "/file-level/"; what the other rows do is said with each.
 *
 * Every case runs through both strategies.
 */
final class FileLevelArrayValueTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * PHP 8.4: "/file-level/" after each of them.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideArraysOfTheList(): iterable
    {
        yield 'an array under a string key' => ['between' => "\$options = ['k' => 'v'];"];
        yield 'array() under a string key' => ['between' => "\$options = array('k' => 'v');"];
        yield 'array() under keys, holding array()' => ['between' => "\$options = array('k' => 'v', 3 => array('a', 1.5));"];
        yield 'an array holding array() under a key' => ['between' => "\$options = ['k' => array('a'), 'l' => ['b']];"];
        yield 'an array in parentheses' => ['between' => "\$options = (['a', 'b']);"];
        yield 'array() in parentheses, in an array' => ['between' => "\$options = [(array('a', 'b')), 'c'];"];
        yield 'a boolean in upper case' => ['between' => '$enabled = TRUE;'];
    }

    #[Test]
    #[DataProvider('provideArraysOfTheList')]
    public function test_an_array_of_the_list_between_the_assignment_and_the_read_leaves_the_variable_read(string $between): void
    {
        $code = self::whole($between);

        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * PHP 8.4: an array used as a key is refused when the file is
     * compiled, "Illegal offset type", and the file does not run; the
     * item read from a string and the comparisons run nothing,
     * "/file-level/"; an array joined to a string, written first or
     * second, raises "Array to string conversion", which a handler of
     * another file may answer.
     * The variable is left unread in each case because the list is
     * closed.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideBracketsAndArrowsOutOfPlace(): iterable
    {
        yield 'an array as the key of an item' => ['between' => "\$options = [['a'] => 'v'];"];
        yield 'array() as the key of an item' => ['between' => "\$options = [array('a') => 'v'];"];
        yield 'an array as a key, in array()' => ['between' => "\$options = array(['a'] => 'v');"];
        yield 'an item read from a string' => ['between' => "\$letter = 'abc'[0];"];
        yield 'an item read from a string in parentheses' => ['between' => "\$letter = ('abc')[0];"];
        yield 'a string joined to an array' => ['between' => "\$label = 'x' . ['a'];"];
        yield 'an array joined to a string, in an array' => ['between' => "\$options = [['a'] . 'x'];"];
        yield 'an ordering of strings in an array' => ['between' => "\$options = ['a' < 'b'];"];
        yield 'an inequality of strings in an array' => ['between' => "\$options = ['a' != 'b'];"];
        yield 'an ordering or equality of strings in an array' => ['between' => "\$options = ['a' >= 'b'];"];
    }

    #[Test]
    #[DataProvider('provideBracketsAndArrowsOutOfPlace')]
    public function test_brackets_and_arrows_out_of_place_between_the_assignment_and_the_read_leave_the_variable_unread(string $between): void
    {
        $code = self::whole($between);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A file where the variable is the whole argument, and something is
     * written between its assignment and the read.
     */
    private static function whole(string $between): string
    {
        return "<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n";
    }
}
