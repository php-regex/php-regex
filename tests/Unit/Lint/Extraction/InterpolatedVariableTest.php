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
 * What a string interpolates is read as PHP reads it there: "$list[-1]"
 * is the item under -1, "$map[007]" the one under the string "007", a
 * number not written the canonical way being a string key; "$map[$key]"
 * the item the variable names. "${name[0]}" is an item of the variable,
 * not the variable, and "$o?->part" a property of another object: parts
 * known at run time only. "${expression}" names a variable no token
 * spells: any variable of the body may be the one, and none is read.
 *
 * PHP 8.4: with $items = [-1 => '/neg/', 1 => '/pos/'], "$items[-1]" is
 * "/neg/"; with [7 => '/seven/', '007' => '/bond/'], "$items[007]" is
 * "/bond/" and "$items[7]" is "/seven/"; with [1 => '/one/', '0x1' =>
 * '/hex/'], "$items[0x1]" is "/hex/"; with $parts = 'abc', "/^${parts[0]}$/"
 * is "/^a$/"; with $re = '/x/' and $n = 're', "${$n}" and "${'r' . 'e'}"
 * are "/x/".
 *
 * Every case runs through both strategies.
 */
final class InterpolatedVariableTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{setup: string, argument: string, pattern: string}>
     */
    public static function provideItemsNamedInAString(): iterable
    {
        yield 'a negative index' => ['setup' => "\$items = [-1 => '/neg/', 1 => '/pos/'];", 'argument' => '"$items[-1]"', 'pattern' => '/neg/'];
        yield 'an index' => ['setup' => "\$items = [7 => '/seven/', '007' => '/bond/'];", 'argument' => '"$items[7]"', 'pattern' => '/seven/'];
        yield 'a number with leading zeros' => ['setup' => "\$items = [7 => '/seven/', '007' => '/bond/'];", 'argument' => '"$items[007]"', 'pattern' => '/bond/'];
        yield 'a hexadecimal number' => ['setup' => "\$items = [1 => '/one/', '0x1' => '/hex/'];", 'argument' => '"$items[0x1]"', 'pattern' => '/hex/'];
        yield 'a bare word' => ['setup' => "\$items = ['a' => '/a/', 'b' => '/b/'];", 'argument' => '"$items[a]"', 'pattern' => '/a/'];
        yield 'a variable' => ['setup' => "\$key = 'a';\n    \$items = ['a' => '/a/', 'b' => '/b/'];", 'argument' => '"$items[$key]"', 'pattern' => '/a/'];
    }

    #[Test]
    #[DataProvider('provideItemsNamedInAString')]
    public function test_an_item_named_in_a_string_is_the_one_php_reads(string $setup, string $argument, string $pattern): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{setup: string, argument: string}>
     */
    public static function providePartsKnownAtRunTime(): iterable
    {
        // The first byte of $parts, not $parts: "/^a$/".
        yield 'an item of a variable named in ${}' => ['setup' => "\$parts = 'abc';", 'argument' => '"/^${parts[0]}$/"'];
        yield 'a property read through a nullsafe arrow' => ['setup' => '', 'argument' => '"/^$o?->part$/"'];
    }

    #[Test]
    #[DataProvider('providePartsKnownAtRunTime')]
    public function test_a_part_the_string_does_not_spell_is_known_at_run_time(string $setup, string $argument): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideVariablesNamedByAnExpression(): iterable
    {
        yield 'a concatenation' => ['statement' => "\$name = \"\${'r' . 'e'}\";"];
        yield 'a literal' => ['statement' => "\$name = \"\${'re'}\";"];
        yield 'a variable' => ['statement' => "\$n = 're';\n    \$name = \"\${\$n}\";"];
    }

    #[Test]
    #[DataProvider('provideVariablesNamedByAnExpression')]
    public function test_a_variable_named_by_an_expression_in_a_string_leaves_the_body_unread(string $statement): void
    {
        $code = self::matcher("\$re = '/x/';\n    ".$statement, '$re');

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * "${re}" spells its variable: it reads $re, and writes none.
     */
    #[Test]
    public function test_a_variable_spelled_in_a_string_leaves_the_body_read(): void
    {
        $code = self::matcher("\$re = '/x/';\n    \$name = \"\${re}\";", '$re');

        $this->assertExtractedByBoth([
            ['pattern' => '/x/', 'at' => "'/x/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * "${GLOBALS['mode']}" names the variables of the file level from a
     * function body, as $GLOBALS does out of a string: none of them is
     * read. "${mode}" names a variable of the body, and nothing else.
     */
    #[Test]
    public function test_globals_named_in_a_string_leave_the_file_level_unread(): void
    {
        $file = static fn (string $function): string => "<?php\n\n".$function."\n\n\$re = '/x/';\npreg_match(\$re, \$s);\n";
        $naming = $file("function show(): string\n{\n    return \"\${GLOBALS['mode']}\";\n}");
        $local = $file("function show(string \$mode): string\n{\n    return \"\${mode}\";\n}");

        $this->assertExtractedByBoth([], $naming);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $naming);

        $this->assertExtractedByBoth([
            ['pattern' => '/x/', 'at' => "'/x/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $local);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $local);
    }

    private static function matcher(string $setup, string $argument): string
    {
        return "<?php\n\nfunction run(string \$s, object \$o): int\n{\n    ".$setup."\n\n    return preg_match(".$argument.", \$s);\n}\n";
    }
}
