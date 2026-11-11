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
 * An interpolated string, a command in backticks, a heredoc and the
 * declaration of a function or of a class are off the list
 * FileLevelImplicitCodeTest holds, and are stepped over as a whole: the
 * quotes, the braces, the brackets and the semicolons they hold are
 * theirs, and end no statement and no block of the file. What is
 * written after them is read as after any other statement: a variable
 * assigned there is read, and the body they are written in ends where
 * its own brace closes.
 *
 * PHP 8.4, with the bootstrap.php of FileLevelImplicitCodeTest required
 * before the file: whatever the string or the declaration runs, it runs
 * before the assignment, and preg_match() is given "/file-level/".
 *
 * Every case runs through both strategies.
 */
final class FileLevelSteppedOverCodeTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row is a statement written before the assignment of $re.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideTextsThatInterpolate(): iterable
    {
        yield 'a string naming a variable' => ['before' => '$label = "a$object";'];
        yield 'a string naming a variable in braces' => ['before' => '$label = "a{$object}b";'];
        yield 'a string naming a variable in a dollar and braces' => ['before' => '$label = "a${object}b";'];
        yield 'a string reading an item under a quoted key' => ['before' => '$label = "a{$object["key"]}b";'];
        yield 'a string reading an item under an interpolated key' => ['before' => "\$label = \"{\$object[\"a{\$object['key']}b\"]}\";"];
        yield 'a string reading a property named in braces' => ['before' => "\$label = \"{\$object->{'name'}}\";"];
        yield 'a string calling a method with a closure' => ['before' => "\$label = \"{\$object->info(function () { return ';'; })}\";"];
        yield 'a string reading an item under a match' => ['before' => "\$label = \"{\$object[match (1) { 1 => 'a' }]}\";"];
        yield 'two strings joined' => ['before' => '$label = "a$object" . "b$flag";'];
        yield 'a string naming a variable, then a string of the list' => ['before' => "\$label = \"a\$object\";\n\$other = 'b';"];

        yield 'a command naming a variable' => ['before' => '$listing = `ls $object`;'];
        yield 'a command naming a variable in braces' => ['before' => '$listing = `ls {$object}`;'];
        yield 'a command in the key of an item, in a string' => ['before' => '$label = "{$object[`ls`]}";'];
        yield 'a string in the key of an item, in a command' => ['before' => '$listing = `ls {$object["a$flag"]}`;'];
        yield 'a command holding one double quote' => ['before' => "\$listing = `echo '\"' \$flag`;"];

        yield 'a heredoc naming a variable' => ['before' => "\$label = <<<TXT\n    a \$object\n    TXT;"];
        yield 'a heredoc naming a variable in braces' => ['before' => "\$label = <<<TXT\n    a {\$object} b\n    TXT;"];
        yield 'a heredoc holding quotes and backticks' => ['before' => "\$label = <<<TXT\n    a \"\$object\" `b`\n    TXT;"];
        yield 'a heredoc in the key of an item, in a string' => ['before' => "\$label = \"{\$object[<<<TXT\n    a\n    TXT]}\";"];
        yield 'a string in the key of an item, in a heredoc' => ['before' => "\$label = <<<TXT\n    {\$object[\"a\$flag\"]}\n    TXT;"];
        yield 'a heredoc in the key of an item, in a heredoc' => ['before' => "\$label = <<<TXT\n    {\$object[<<<KEY\n    a \$flag\n    KEY]}\n    TXT;"];
    }

    /**
     * Each row is a declaration, or a statement that holds one, written
     * before the assignment of $re.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideDeclarations(): iterable
    {
        yield 'a function whose parameters hold arrays and parentheses' => ['before' => "function helper(array \$items = [1, [2]], \$limit = (1))\n{\n    if (\$items) {\n        return \"{\$limit}\";\n    }\n}"];
        yield 'a function interpolating in a dollar and braces' => ['before' => "function helper(\$name)\n{\n    return \"\${name}\";\n}"];
        yield 'a function holding braces in strings' => ['before' => "function helper()\n{\n    return '}' . \"}\";\n}"];
        yield 'a function holding a heredoc with a brace' => ['before' => "function helper(\$name)\n{\n    return <<<TXT\n    {\$name} }\n    TXT;\n}"];
        yield 'a function under attributes, with attributes on its parameters' => ['before' => "#[Marker([1])]\nfunction helper(#[First] \$one, #[Second(1)] \$two)\n{\n}"];
        yield 'a function returning a reference' => ['before' => "function &helper()\n{\n    static \$items = [];\n\n    return \$items;\n}"];
        yield 'a function declared in the body of an if' => ['before' => "if (\$flag) {\n    function helper()\n    {\n    }\n}"];
        yield 'an abstract class whose method has no body' => ['before' => "abstract class Shape\n{\n    abstract public function area(): float;\n}"];
        yield 'an interface whose method takes an array' => ['before' => "interface Shape\n{\n    public function scale(array \$by = []): void;\n}"];
        yield 'a trait interpolating a property' => ['before' => "trait Named\n{\n    public function name(): string\n    {\n        return \"{\$this->name}\";\n    }\n}"];
        yield 'an enum backed by strings' => ['before' => "enum Suit: string\n{\n    case Hearts = 'h';\n}"];
        yield 'a class with a parent and an array property' => ['before' => "final class Circle extends Shape\n{\n    public \$points = [1, [2]];\n}"];
        yield 'a class under an attribute holding an array' => ['before' => "#[Marker([1])]\nfinal class Circle\n{\n}"];
        yield 'a class whose methods are named after directives' => ['before' => "final class Directives\n{\n    public function ticks(): int\n    {\n        \$ticks = 1;\n\n        return \$ticks;\n    }\n}"];

        yield 'a closure using a variable' => ['before' => "\$format = function (\$value) use (\$flag) {\n    return \$value;\n};"];
        yield 'a closure returning a reference' => ['before' => "\$format = function &(\$value) {\n    return \$value;\n};"];
        yield 'a static closure' => ['before' => "\$format = static function () {\n};"];
        yield 'a closure given to a function' => ['before' => "\$names = array_map(function (\$value) {\n    return \$value;\n}, \$items);"];
        yield 'a closure written against its parentheses, given to a function' => ['before' => "\$names = array_map(function(\$value) {\n    return \$value;\n}, \$items);"];
        yield 'an anonymous class with arguments and a parent' => ['before' => "\$shape = new class(1) extends Shape {\n    public function area()\n    {\n    }\n};"];
        yield 'an anonymous class written against its brace' => ['before' => "\$shape = new class{\n    public function area()\n    {\n    }\n};"];

        // The keyword declares nothing here.
        yield 'a function imported' => ['before' => 'use function App\helper;'];
        yield 'the name of a class' => ['before' => '$name = Circle::class;'];
        yield 'the name of a class, the last arm of a match' => ['before' => "\$name = match (true) {\n    default => Circle::class\n};"];
        yield 'a property named class' => ['before' => '$name = $object->class;'];
        yield 'a property named function, through the nullsafe operator' => ['before' => '$name = $object?->function;'];
        yield 'a property named enum' => ['before' => '$name = $object->enum;'];
    }

    #[Test]
    #[DataProvider('provideTextsThatInterpolate')]
    #[DataProvider('provideDeclarations')]
    public function test_a_variable_assigned_after_what_is_stepped_over_is_read(string $before): void
    {
        $this->assertRead("<?php\n\n".$before."\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n");
    }

    /**
     * Each row is a statement written in the body of an if, before the
     * assignment of $re; the read stands after the body. The braces the
     * statement holds are those of an expression, of a text or of a
     * declaration: the body ends at its own brace, which is on the
     * list, and not before.
     *
     * PHP 8.4, $flag being true: "/file-level/".
     *
     * @return iterable<string, array{statement: string}>
     */
    public static function provideStatementsHoldingBraces(): iterable
    {
        yield 'a command naming a variable in braces' => ['statement' => '$listing = `ls {$object}`;'];
        yield 'a heredoc naming a variable in braces' => ['statement' => "\$label = <<<TXT\n    ls {\$object}\n    TXT;"];
        yield 'a string naming a variable in braces' => ['statement' => '$label = "ls {$object}";'];
        yield 'a match' => ['statement' => "\$label = match (\$sapi) {\n        'cli' => 1,\n        default => 2,\n    };"];
        yield 'a property named in braces' => ['statement' => "\$label = \$object->{'name'};"];
        yield 'a closure' => ['statement' => "\$format = function () {\n        return 1;\n    };"];
        yield 'an anonymous class written against its brace' => ['statement' => "\$shape = new class{\n        public function area()\n        {\n        }\n    };"];
        yield 'an if and its body' => ['statement' => "if (\$sapi) {\n        \$label = 'a';\n    }"];
    }

    #[Test]
    #[DataProvider('provideStatementsHoldingBraces')]
    public function test_the_braces_a_statement_holds_end_no_body_before_its_own_brace(string $statement): void
    {
        $this->assertRead("<?php\n\nif (\$flag) {\n    ".$statement."\n    \$re = '/file-level/';\n}\npreg_match(\$re, \$subject);\n");
    }

    private function assertRead(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }
}
