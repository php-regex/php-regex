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
 * The head of a loop runs at times of its own. What a foreach iterates
 * is read once, before the first turn; its targets are assigned at each
 * turn, a plain variable holding a value or a key of what is iterated,
 * whatever the other target is and however the head is spaced. A target
 * that is a property or an item writes the variable it starts with, which
 * holds no value of the loop. The first part of the head of a for runs
 * once and its second part before each turn, both before the body; its
 * third part runs after the body, so a variable it alone assigns is not
 * assigned yet at the first turn.
 *
 * PHP 8.4: "$re = '/a/'; foreach (preg_split($re, 'bab') as $p) { $re =
 * '/b/'; }" splits once, on "/a/"; "foreach (['/x/', '/y/'] as
 * $o->pattern) {}" leaves $o the object it was; in "for ($i = 0; $i < 3;
 * $part = 'x') { ... $i++; }" the body sees $part unset, then "x", "x".
 *
 * Every case runs through both strategies.
 */
final class LoopHeadTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row gives the head of a foreach whose target $re takes the
     * patterns "/a/" and "/b/".
     *
     * @return iterable<string, array{head: string}>
     */
    public static function provideHeadsWhoseTargetIsAVariable(): iterable
    {
        yield 'no space after foreach' => ['head' => "foreach(['/a/', '/b/'] as \$re)"];
        yield 'no space after as' => ['head' => "foreach (['/a/', '/b/'] as\$re)"];
        yield 'the key, with no space after as' => ['head' => "foreach (['/a/' => 1, '/b/' => 2] as\$re => \$n)"];
        yield 'the value, with no space after the arrow' => ['head' => "foreach ([1 => '/a/', 2 => '/b/'] as \$n =>\$re)"];
        yield 'the value, the key being an item' => ['head' => "foreach (['/a/', '/b/'] as \$seen[\$k] => \$re)"];
        yield 'the value, the key being a property' => ['head' => "foreach (['/a/', '/b/'] as \$o->position => \$re)"];
    }

    #[Test]
    #[DataProvider('provideHeadsWhoseTargetIsAVariable')]
    public function test_a_variable_target_holds_what_the_loop_iterates(string $head): void
    {
        $code = self::inFunction($head." {\n    preg_match(\$re, \$s);\n}");

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => 'via $re'],
            ['pattern' => '/b/', 'at' => "'/b/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{loop: string}>
     */
    public static function provideLoopsThatLeaveTheVariableUnread(): iterable
    {
        // $re is the object, or the array, the loop writes into.
        yield 'a target that is a property of the variable' => ['loop' => "foreach (['/x/', '/y/'] as \$re->pattern) {\n    preg_match(\$re, \$s);\n}"];
        yield 'a target that is an item of the variable' => ['loop' => "foreach (['/x/', '/y/'] as \$re[0]) {\n    preg_match(\$re, \$s);\n}"];
        yield 'a target that is a static property of the variable' => ['loop' => "foreach (['/x/', '/y/'] as \$re::\$pattern) {\n    preg_match(\$re, \$s);\n}"];
        // Where the loop ends is not looked for past a control structure.
        yield 'a body that is an if' => ['loop' => "foreach (['/x/', '/y/'] as \$re)\n    if (\$flag)\n        preg_match(\$re, \$s);"];
        yield 'a key, in a loop whose body is an if' => ['loop' => "foreach (['/x/' => 1] as \$re => \$n)\n    if (\$flag)\n        preg_match(\$re, \$s);"];
    }

    #[Test]
    #[DataProvider('provideLoopsThatLeaveTheVariableUnread')]
    public function test_a_loop_that_writes_the_variable_unseen_leaves_it_unread(string $loop): void
    {
        $code = self::inFunction($loop);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The read stands before "as": the assignment in the body, which a
     * later turn runs, comes after it all the same.
     */
    #[Test]
    public function test_what_a_foreach_iterates_is_read_before_its_body_runs(): void
    {
        $code = self::inFunction("\$re = '/a/';\nforeach (preg_split(\$re, \$s) as \$part) {\n    \$re = '/b/';\n}");

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_split()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_split()'],
        ], $code);
    }

    /**
     * Each row is a loop whose body joins $part, assigned before the read
     * at every turn, to the literals around it: "/^x$/".
     *
     * @return iterable<string, array{loop: string}>
     */
    public static function provideLoopsThatAssignBeforeTheRead(): iterable
    {
        yield 'the first part of the head of a for' => ['loop' => "for (\$part = 'x'; \$k < 3; \$k++) {\n    preg_match('/^' . \$part . '\$/', \$s);\n}"];
        yield 'the second part of the head of a for' => ['loop' => "for (\$i = 0; \$part = 'x'; \$i++) {\n    preg_match('/^' . \$part . '\$/', \$s);\n    break;\n}"];
        yield 'the second part of the head of a for with no first part' => ['loop' => "for (; \$part = 'x';) {\n    preg_match('/^' . \$part . '\$/', \$s);\n    break;\n}"];
        yield 'the third statement of the body of a do while' => ['loop' => "do {\n    \$k++;\n    \$n = 1;\n    \$part = 'x';\n    preg_match('/^' . \$part . '\$/', \$s);\n} while (\$k < 3);"];
        yield 'the third statement of the body of a while' => ['loop' => "while (\$k < 3) {\n    \$k++;\n    \$n = 1;\n    \$part = 'x';\n    preg_match('/^' . \$part . '\$/', \$s);\n}"];
    }

    #[Test]
    #[DataProvider('provideLoopsThatAssignBeforeTheRead')]
    public function test_a_part_assigned_before_the_read_at_every_turn_is_joined(string $loop): void
    {
        $code = self::inFunction($loop);

        $this->assertExtractedByBoth([
            ['pattern' => '/^x$/', 'at' => "'/^' . \$part . '\$/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . \$part . '\$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Each row is a for whose third part alone assigns $part: the body
     * runs once before it, and PHP joins nothing there.
     *
     * @return iterable<string, array{head: string}>
     */
    public static function provideHeadsWhoseThirdPartAssigns(): iterable
    {
        yield 'three parts' => ['head' => "for (\$i = 0; \$i < 3; \$part = 'x')"];
        yield 'no space after for' => ['head' => "for(\$i = 0; \$i < 3; \$part = 'x')"];
        yield 'no first part' => ['head' => "for (; \$k < 3; \$part = 'x')"];
        yield 'no second part' => ['head' => "for (\$i = 0;; \$part = 'x')"];
    }

    #[Test]
    #[DataProvider('provideHeadsWhoseThirdPartAssigns')]
    public function test_a_part_the_third_part_of_a_for_assigns_is_not_joined(string $head): void
    {
        $code = self::inFunction($head." {\n    preg_match('/^' . \$part . '\$/', \$s);\n    if (++\$k > 3) {\n        break;\n    }\n}");

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/^' . \$part . '\$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    private static function inFunction(string $body): string
    {
        return "<?php\n\nfunction run(string \$s, bool \$flag, int \$k, object \$o): void\n{\n    ".str_replace("\n", "\n    ", $body)."\n}\n";
    }
}
