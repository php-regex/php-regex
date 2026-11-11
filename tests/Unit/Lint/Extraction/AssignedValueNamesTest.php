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
 * A pattern is read through any number of variables: each name the value
 * assigned to a variable holds is followed in its turn, up to the end of
 * the statement, past the parentheses and the calls of the value, and
 * whether a space follows the "=" or not. A property read beside a
 * constant is still a property: what the file does to it counts.
 *
 * PHP 8.4: $tail = '$/'; $re = ('/^' . 'a') . $tail; leaves "/^a$/" in
 * $re; "const FOREACH = 1;" declares a class constant, "foreach" being
 * a name there.
 *
 * Every case runs through both strategies.
 */
final class AssignedValueNamesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row gives the statements before the read of $re, the pattern
     * they leave in it and the text it is reported at.
     *
     * @return iterable<string, array{statements: string, pattern: string, at: string}>
     */
    public static function provideValuesThatNameAnotherVariable(): iterable
    {
        yield 'a variable right after the equals sign' => [
            'statements' => "\$base = '/x/';\n    \$re =\$base;",
            'pattern' => '/x/',
            'at' => "'/x/'",
        ];

        yield 'a variable after a parenthesis' => [
            'statements' => "\$tail = '\$/';\n    \$re = ('/^' . 'a') . \$tail;",
            'pattern' => '/^a$/',
            'at' => "('/^' . 'a') . \$tail",
        ];

        yield 'a variable after a parenthesis right after the equals sign' => [
            'statements' => "\$tail = '\$/';\n    \$re =('/^' . 'a') . \$tail;",
            'pattern' => '/^a$/',
            'at' => "('/^' . 'a') . \$tail",
        ];

        yield 'a variable after a call' => [
            'statements' => "\$tail = '\$/';\n    \$re = sprintf('/^%s', 'a') . \$tail;",
            'pattern' => '/^a$/',
            'at' => "sprintf('/^%s', 'a') . \$tail",
        ];
    }

    #[Test]
    #[DataProvider('provideValuesThatNameAnotherVariable')]
    public function test_a_variable_named_in_an_assigned_value_is_followed(string $statements, string $pattern, string $at): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    ".$statements."\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $at, 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{before: string}>
     */
    public static function provideConstantsNamedForeach(): iterable
    {
        yield 'declared in a class' => ['before' => "final class Mode\n{\n    public const FOREACH = 1;\n}\n\nfunction run(string \$s): int\n{"];
        yield 'read in the body' => ['before' => "function run(string \$s): int\n{\n    \$mode = Mode::FOREACH;"];
    }

    /**
     * No loop starts at a constant of that name: the assignments written
     * after it are read as those before it.
     */
    #[Test]
    #[DataProvider('provideConstantsNamedForeach')]
    public function test_a_constant_named_foreach_starts_no_loop(string $before): void
    {
        $code = "<?php\n\n".$before."\n    \$re = '/x/';\n\n    return preg_match(\$re, \$s);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/x/', 'at' => "'/x/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * $this->re . FLAGS reads a property and a constant. A call that is
     * passed the property of another object of the class may write it, by
     * reference: the default proves nothing then, and without that call
     * it is the pattern.
     */
    #[Test]
    public function test_a_property_joined_to_a_constant_is_written_by_a_call_it_is_passed_to(): void
    {
        $class = static fn (string $method): string => "<?php\n\nconst FLAGS = 'i';\n\nfinal class Matcher\n{\n    private string \$re = '/default/';\n".$method."\n    public function run(string \$s): int\n    {\n        return preg_match(\$this->re . FLAGS, \$s);\n    }\n}\n";
        $passed = $class("\n    public function reset(self \$other): void\n    {\n        normalize(\$other->re);\n    }\n");
        $kept = $class('');

        $this->assertExtractedByBoth([], $passed);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$this->re . FLAGS', 'source' => 'preg_match()'],
        ], $passed);

        $this->assertExtractedByBoth([
            ['pattern' => '/default/i', 'at' => '$this->re . FLAGS', 'source' => 'preg_match()', 'location' => null],
        ], $kept);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$this->re . FLAGS', 'source' => 'preg_match()'],
        ], $kept);
    }
}
