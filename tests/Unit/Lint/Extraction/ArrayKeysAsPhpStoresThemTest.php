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
 * An array literal is read as PHP stores it. An item with no key takes
 * the index after the highest one written or given so far; a string of
 * digits is that integer; a float is its integer part. A key written
 * twice, by its text or by the index PHP gives an item with no key,
 * holds its last value. After a negative index alone, the index of the
 * next item with no key depends on the version of PHP: it is not told,
 * and that item is kept beside the others.
 *
 * PHP 8.4 (php -r):
 *   [5 => 'a', 'b', 6 => 'c']          is [5 => 'a', 6 => 'c']
 *   [5 => 'a', 'b', 0 => 'c']          is [5 => 'a', 6 => 'b', 0 => 'c']
 *   [0 => 'a', 'b', 1 => 'c']          is [0 => 'a', 1 => 'c']
 *   ['a', 5 => 'b', 'c', 6 => 'd']     is [0 => 'a', 5 => 'b', 6 => 'd']
 *   ['a', -5 => 'b', 'c', 1 => 'd']    is [0 => 'a', -5 => 'b', 1 => 'd']
 *   ['5' => 'a', 'b', 6 => 'c']        is [5 => 'a', 6 => 'c']
 *   [-5 => 'a', 'b', 0 => 'c']         is [-5 => 'a', -4 => 'b', 0 => 'c'],
 *                                      and [-5 => 'a', 0 => 'c'] before 8.3
 *   ['k' => 'a', 'b'] and ['-k' => 'a', 'b'] hold 'b' at 0
 *   ['7' => 'a', 'b']                  holds 'b' at 8
 *   [1.5 => 'a', 0 => 'b']             holds 'a' at 1
 *   [-9.2233720368547758E+18 => 'a']   holds 'a' at PHP_INT_MIN
 *   array_keys(['a' => 1, 2])          is ['a', 0]
 *
 * Every case runs through both strategies.
 */
final class ArrayKeysAsPhpStoresThemTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{array: string, patterns: list<string>}>
     */
    public static function provideListsHoldingAnIndexTwice(): iterable
    {
        yield 'an item with no key takes the index after the one written, which is written again' => [
            'array' => "[5 => '/a/', '/b/', 6 => '/c/']",
            'patterns' => ['/a/', '/c/'],
        ];

        yield 'an item with no key takes the index after the one written, not the first one free' => [
            'array' => "[5 => '/a/', '/b/', 0 => '/c/']",
            'patterns' => ['/a/', '/b/', '/c/'],
        ];

        yield 'an item with no key follows the index zero' => [
            'array' => "[0 => '/a/', '/b/', 1 => '/c/']",
            'patterns' => ['/a/', '/c/'],
        ];

        yield 'an item with no key follows the highest index, written after an item with no key' => [
            'array' => "['/a/', 5 => '/b/', '/c/', 6 => '/d/']",
            'patterns' => ['/a/', '/b/', '/d/'],
        ];

        yield 'a negative index after an item with no key leaves the count where it was' => [
            'array' => "['/a/', -5 => '/b/', '/c/', 1 => '/d/']",
            'patterns' => ['/a/', '/b/', '/d/'],
        ];

        yield 'a string of digits is the index it writes' => [
            'array' => "['5' => '/a/', '/b/', 6 => '/c/']",
            'patterns' => ['/a/', '/c/'],
        ];

        // -4 since PHP 8.3 and 0 before: the item is kept beside the one
        // written at 0.
        yield 'after a negative index alone, the next index is not told' => [
            'array' => "[-5 => '/a/', '/b/', 0 => '/c/']",
            'patterns' => ['/a/', '/b/', '/c/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideListsHoldingAnIndexTwice')]
    public function test_a_list_of_patterns_holds_each_index_once_with_its_last_value(string $array, array $patterns): void
    {
        $code = "<?php\n\nfinal class Cleaner\n{\n    private const PATTERNS = ".$array.";\n\n    public function run(string \$s): ?string\n    {\n        return preg_replace(self::PATTERNS, '', \$s);\n    }\n}\n";

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertSame(array_fill(0, \count($patterns), 'constant'), $this->statesReadByBoth($code));
    }

    /**
     * @return iterable<string, array{array: string, key: string, pattern: string}>
     */
    public static function provideItemsNamedByTheirIndex(): iterable
    {
        yield 'the index zero, written' => ['array' => "[0 => 'a', 'b']", 'key' => '0', 'pattern' => '/^a$/'];
        yield 'the index after zero' => ['array' => "[0 => 'a', 'b']", 'key' => '1', 'pattern' => '/^b$/'];
        yield 'the index after a negative one written later' => ['array' => "['a', -5 => 'b', 'c']", 'key' => '1', 'pattern' => '/^c$/'];
        yield 'the first index after a string key' => ['array' => "['k' => 'a', 'b']", 'key' => '0', 'pattern' => '/^b$/'];
        yield 'the first index after a string key that starts with a minus sign' => ['array' => "['-k' => 'a', 'b']", 'key' => '0', 'pattern' => '/^b$/'];
        yield 'the index after a string of digits' => ['array' => "['7' => 'a', 'b']", 'key' => '8', 'pattern' => '/^b$/'];
        yield 'the integer part of a float' => ['array' => "[1.5 => 'a', 0 => 'b']", 'key' => '1', 'pattern' => '/^a$/'];
        yield 'an index beside the lowest integer written as a float' => ['array' => "[-9.2233720368547758E+18 => 'a', 0 => 'b']", 'key' => '0', 'pattern' => '/^b$/'];
    }

    #[Test]
    #[DataProvider('provideItemsNamedByTheirIndex')]
    public function test_an_item_is_found_under_the_index_php_gives_it(string $array, string $key, string $pattern): void
    {
        $argument = "'/^' . self::MAP[".$key."] . '$/'";
        $code = self::matcher($array, $argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An array one of whose keys is not told names no item the file
     * proves: the item is a part known at run time only.
     *
     * @return iterable<string, array{array: string, key: string}>
     */
    public static function provideArraysHoldingAKeyThatIsNotTold(): iterable
    {
        // 'b' is at -4 since PHP 8.3, and at 0 before.
        yield 'an item with no key after a negative index alone' => ['array' => "[-5 => 'a', 'b']", 'key' => '0'];
        yield 'a float one past the highest integer' => ['array' => "[9.2233720368547758E+18 => 'a', 0 => 'b']", 'key' => '0'];
        yield 'a float above every integer' => ['array' => "[1.0E+30 => 'a', 0 => 'b']", 'key' => '0'];
        yield 'a float below every integer' => ['array' => "[-1.0E+30 => 'a', 0 => 'b']", 'key' => '0'];
    }

    #[Test]
    #[DataProvider('provideArraysHoldingAKeyThatIsNotTold')]
    public function test_an_array_holding_a_key_that_is_not_told_names_no_item(string $array, string $key): void
    {
        $argument = "'/^' . self::MAP[".$key."] . '$/'";
        $code = self::matcher($array, $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The key target of a foreach holds the keys as PHP stores them: the
     * key written, and an integer for an item with no key.
     *
     * @return iterable<string, array{array: string, patterns: list<string>}>
     */
    public static function provideArraysIteratedForTheirKeys(): iterable
    {
        yield 'a list' => ['array' => "['x', 'y']", 'patterns' => ['/^0$/', '/^1$/']];
        yield 'an item with no key after a string key' => ['array' => "['a' => 1, 2]", 'patterns' => ['/^0$/', '/^a$/']];
        yield 'an item with no key after a string of digits' => ['array' => "['7' => 1, 2]", 'patterns' => ['/^7$/', '/^8$/']];
    }

    /**
     * @param list<string> $patterns in the order of their bytes: all stand where the argument starts
     */
    #[Test]
    #[DataProvider('provideArraysIteratedForTheirKeys')]
    public function test_the_key_target_of_a_foreach_holds_the_keys_php_stores(string $array, array $patterns): void
    {
        $code = "<?php\n\nfunction run(string \$s): void\n{\n    foreach (".$array." as \$key => \$value) {\n        preg_match('/^' . \$key . '$/', \$s);\n    }\n}\n";

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$key . '$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * After a key the file does not prove, the index of an item with no
     * key is not told either.
     */
    #[Test]
    public function test_the_key_of_an_item_after_a_key_the_file_does_not_prove_is_not_read(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $name): void
            {
                foreach ([$name => 1, 2] as $key => $value) {
                    preg_match('/^' . $key . '$/', $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$key . '$/'"],
        ], $code);
    }

    private static function matcher(string $array, string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const MAP = ".$array.";\n\n    public function run(string \$s): int\n    {\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
