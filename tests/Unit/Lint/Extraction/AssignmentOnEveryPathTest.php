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
 * $map['a'] ?? '/fallback/' never builds its fallback when the array holds
 * the key and every path to the read assigns the array: the assignment is
 * a statement of the function body itself, or of the file, in no branch,
 * no loop and no larger expression. Anywhere else a path may skip it, PHP
 * may find no array, and the fallback is read beside the item.
 *
 * A block that is a statement of its own, the braces of a namespace and
 * what follows a label run with the statements around them. The body of
 * an if, of a loop, of a switch or of a try may not run, whether braces
 * close it or the keyword of the alternative syntax does; what follows
 * it runs again.
 *
 * PHP 8.4, $flag false: "if ($flag) { $map = ['a' => '/a/']; }", the same
 * with "if ($flag): ... endif;", "while ($flag): ... endwhile;", "$flag &&
 * $map = ['a' => '/a/'];" and "switch ($flag) { case true: $map = ... }"
 * all leave $map['a'] ?? '/fallback/' as "/fallback/"; "$flag ? PHP_EOL :
 * $map = ['a' => '/a/'];" does with $flag true, and a try whose body
 * throws before the assignment. "if ($flag): $y = 1; endif; $map = ['a'
 * => '/a/'];", "$y = 1;{ $map = ['a' => '/a/']; }" and "retry: $map = ['a'
 * => '/a/'];" give "/a/" whatever $flag is.
 *
 * Every case runs through both strategies.
 */
final class AssignmentOnEveryPathTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const ASSIGNMENT = "\$map = ['a' => '/a/'];";

    private const READ = "preg_match(\$map['a'] ?? '/fallback/', \$s);";

    /**
     * Each row is the body of a function: the assignment, and the read
     * after it.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideBodiesWhoseEveryPathAssigns(): iterable
    {
        yield 'a statement of the body' => ['body' => self::ASSIGNMENT."\n".self::READ];
        yield 'right after the semicolon of the statement before' => ['body' => '$y = 1;'.self::ASSIGNMENT."\n".self::READ];
        yield 'after an if' => ['body' => "if (\$flag) {\n    \$y = 1;\n}\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after a for' => ['body' => "for (\$i = 0; \$i < 2; \$i++) {\n}\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after a for with no body' => ['body' => "for (\$i = 0; \$i < 2; \$i++);\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after a do while' => ['body' => "do {\n    \$y = 1;\n} while (\$flag);\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after a closure whose body branches' => ['body' => "\$f = function () {\n    if (true) {\n        \$z = 1;\n    }\n};\n".self::ASSIGNMENT."\n".self::READ];
        yield 'in a block of its own' => ['body' => "\$y = 1;\n{\n    ".self::ASSIGNMENT."\n}\n".self::READ];
        yield 'in a block of its own right after a semicolon' => ['body' => "\$y = 1;{\n    ".self::ASSIGNMENT."\n}\n".self::READ];
        yield 'after a label' => ['body' => 'retry: '.self::ASSIGNMENT."\n".self::READ];
        yield 'after a label right after a semicolon' => ['body' => '$y = 1;retry: '.self::ASSIGNMENT."\n".self::READ];
        yield 'closed by a closing tag' => ['body' => "\$map = ['a' => '/a/'] ?><?php\n".self::READ];
        yield 'after an endif' => ['body' => "if (\$flag):\n    \$y = 1;\nendif;\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after an endwhile' => ['body' => "while (\$flag):\n    \$y = 1;\nendwhile;\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after an endfor' => ['body' => "for (\$i = 0; \$i < 2; \$i++):\n    \$y = 1;\nendfor;\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after an endforeach' => ['body' => "foreach ([1, 2] as \$n):\n    \$y = 1;\nendforeach;\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after an endswitch' => ['body' => "switch (\$k):\n    case 1:\n    \$y = 1;\nendswitch;\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after an enddeclare' => ['body' => "declare(ticks=1):\n    \$y = 1;\nenddeclare;\n".self::ASSIGNMENT."\n".self::READ];
        yield 'after two endif, one in the other' => ['body' => "if (\$flag):\n    if (\$k):\n        \$y = 1;\n    endif;\nendif;\n".self::ASSIGNMENT."\n".self::READ];
    }

    #[Test]
    #[DataProvider('provideBodiesWhoseEveryPathAssigns')]
    public function test_an_array_every_path_assigns_always_holds_its_key(string $body): void
    {
        $code = self::inFunction($body);

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "\$map['a'] ?? '/fallback/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The same, at the level of the file: a variable there is read across
     * assignments of literals and ifs over them only, so the rows hold
     * nothing else between the assignment and the read.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesWhoseEveryPathAssigns(): iterable
    {
        yield 'a statement of the file' => ['code' => "<?php\n\n".self::ASSIGNMENT."\n".self::READ."\n"];
        yield 'right after the semicolon of the statement before' => ['code' => "<?php\n\n\$y = 1;".self::ASSIGNMENT."\n".self::READ."\n"];
        yield 'after an if' => ['code' => "<?php\n\nif (\$flag) {\n    \$y = 1;\n}\n".self::ASSIGNMENT."\n".self::READ."\n"];
        yield 'after an endif' => ['code' => "<?php\n\nif (\$flag):\n    \$y = 1;\nendif;\n".self::ASSIGNMENT."\n".self::READ."\n"];
        yield 'after a for' => ['code' => "<?php\n\nfor (\$i = 0; \$i < 2; \$i++) {\n}\n".self::ASSIGNMENT."\n".self::READ."\n"];
        yield 'in the braces of a namespace' => ['code' => "<?php\n\nnamespace App\\Script {\n    ".self::ASSIGNMENT."\n    ".self::READ."\n}\n"];
        yield 'in the braces of a namespace written with no space' => ['code' => "<?php\n\nnamespace App\\Script{\n    ".self::ASSIGNMENT."\n    ".self::READ."\n}\n"];
        yield 'in the braces of the global namespace' => ['code' => "<?php\n\nnamespace {\n    ".self::ASSIGNMENT."\n    ".self::READ."\n}\n"];
    }

    #[Test]
    #[DataProvider('provideFilesWhoseEveryPathAssigns')]
    public function test_an_array_every_path_of_the_file_assigns_always_holds_its_key(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "\$map['a'] ?? '/fallback/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Each row is the body of a function in which a path reaches the read
     * without the assignment, or in which the assignment is written in a
     * larger expression, which is not followed: "$other = $map = [...]"
     * and "$map = [...] and $y = 2" always assign, and so does the
     * condition of a for before its body runs; the fallback is kept there
     * all the same, one pattern more than PHP builds and none fewer.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideBodiesAPathOfWhichMaySkipTheAssignment(): iterable
    {
        yield 'in an if' => ['body' => "if (\$flag) {\n    ".self::ASSIGNMENT."\n}\n".self::READ];
        yield 'in an if, after an if of its own' => ['body' => "if (\$flag) {\n    if (\$k) {\n        \$y = 1;\n    }\n    ".self::ASSIGNMENT."\n}\n".self::READ];
        yield 'in a try' => ['body' => "try {\n    ".self::ASSIGNMENT."\n} finally {\n}\n".self::READ];
        yield 'in a switch' => ['body' => "switch (\$k) {\n    case PHP_INT_SIZE:\n        ".self::ASSIGNMENT."\n}\n".self::READ];
        yield 'before an endif' => ['body' => "if (\$flag):\n    \$y = 1;\n    ".self::ASSIGNMENT."\nendif;\n".self::READ];
        yield 'before an endwhile' => ['body' => "while (\$flag):\n    \$y = 1;\n    ".self::ASSIGNMENT."\nendwhile;\n".self::READ];
        yield 'before an endfor' => ['body' => "for (\$i = 0; \$i < 2; \$i++):\n    \$y = 1;\n    ".self::ASSIGNMENT."\nendfor;\n".self::READ];
        yield 'before an endforeach' => ['body' => "foreach ([1, 2] as \$n):\n    \$y = 1;\n    ".self::ASSIGNMENT."\nendforeach;\n".self::READ];
        yield 'before an endswitch' => ['body' => "switch (\$k):\n    case 1:\n    \$y = 1;\n    ".self::ASSIGNMENT."\nendswitch;\n".self::READ];
        yield 'before an enddeclare' => ['body' => "declare(ticks=1):\n    \$y = 1;\n    ".self::ASSIGNMENT."\nenddeclare;\n".self::READ];
        yield 'between two endif' => ['body' => "if (\$flag):\n    if (\$k):\n        \$y = 1;\n    endif;\n    ".self::ASSIGNMENT."\nendif;\n".self::READ];
        yield 'in the else arm of a ternary whose other arm is a constant' => ['body' => "\$flag ? PHP_EOL : \$map = ['a' => '/a/'];\n".self::READ];
        yield 'after a logical and' => ['body' => "\$flag && \$map = ['a' => '/a/'];\n".self::READ];
        yield 'assigned to another variable in the same statement' => ['body' => "\$other = \$map = ['a' => '/a/'];\n".self::READ];
        yield 'followed by a logical and' => ['body' => "\$map = ['a' => '/a/'] and \$y = 2;\n".self::READ];
        yield 'as the condition of a for' => ['body' => "for (\$i = 0; \$map = ['a' => '/a/']; \$i++) {\n    ".self::READ."\n    break;\n}"];
        yield 'as the condition of a for written with no space' => ['body' => "for(\$i = 0; \$map = ['a' => '/a/']; \$i++) {\n    ".self::READ."\n    break;\n}"];
    }

    #[Test]
    #[DataProvider('provideBodiesAPathOfWhichMaySkipTheAssignment')]
    public function test_an_array_a_path_may_not_assign_keeps_the_fallback_of_its_item(string $body): void
    {
        $this->assertTheFallbackIsKept(self::inFunction($body));
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesAPathOfWhichMaySkipTheAssignment(): iterable
    {
        yield 'in an if' => ['code' => "<?php\n\nif (\$flag) {\n    ".self::ASSIGNMENT."\n}\n".self::READ."\n"];
        yield 'in an if, after an if of its own' => ['code' => "<?php\n\nif (\$flag) {\n    if (\$k) {\n        \$y = 1;\n    }\n    ".self::ASSIGNMENT."\n}\n".self::READ."\n"];
    }

    #[Test]
    #[DataProvider('provideFilesAPathOfWhichMaySkipTheAssignment')]
    public function test_an_array_a_path_of_the_file_may_not_assign_keeps_the_fallback_of_its_item(string $code): void
    {
        $this->assertTheFallbackIsKept($code);
    }

    /**
     * A method body is a body like any other, and the class around it
     * holds no statement.
     */
    #[Test]
    public function test_a_method_body_is_read_as_a_function_body(): void
    {
        $always = "<?php\n\nfinal class Matcher\n{\n    public function run(string \$s, bool \$flag): void\n    {\n        ".self::ASSIGNMENT."\n        ".self::READ."\n    }\n}\n";
        $sometimes = "<?php\n\nfinal class Matcher\n{\n    public function run(string \$s, bool \$flag): void\n    {\n        if (\$flag) {\n            ".self::ASSIGNMENT."\n        }\n        ".self::READ."\n    }\n}\n";

        $this->assertSame(['constant'], $this->statesReadByBoth($always));
        $this->assertSame(['/a/'], $this->patternsReadByBoth($always));
        $this->assertTheFallbackIsKept($sometimes);
    }

    private function assertTheFallbackIsKept(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/fallback/', 'at' => "'/fallback/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "\$map['a'] ?? '/fallback/'", 'source' => 'preg_match()'],
        ], $code);
    }

    private static function inFunction(string $body): string
    {
        return "<?php\n\nfunction run(string \$s, bool \$flag, int \$k): void\n{\n    ".str_replace("\n", "\n    ", $body)."\n}\n";
    }
}
