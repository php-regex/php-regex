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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A variable at file level is a global, and PHP runs code where the file
 * writes no call: a magic method, an ArrayAccess or IteratorAggregate
 * method, a destructor, a class loader, an output callback, a tick
 * function. Each is code of another file, which may write the global.
 * The statements that may run one have no end, so the variable is read
 * only when everything written between the end of the assignment that
 * reaches the read and the read is on a closed list:
 *
 * - whitespace and comments;
 * - plain assignments to a plain variable, "$v = value;", whose value is
 *   made only of strings without interpolation, integers, floats, true,
 *   false, arrays of such values under such keys, parentheses,
 *   concatenations, and variables the file proves the same way;
 * - an if, with its elseif and else, whose conditions compare such
 *   values and whose bodies hold such assignments;
 * - in a condition, a plain variable the file does not prove, where it
 *   runs no code whatever it holds: under "===" and "!==" against
 *   anything of the list, under "==", "!=", "<", ">", "<=" and ">="
 *   against an integer, a float or a boolean, under "!", as an operand
 *   of "&&", "||", "and", "or" and "xor", and alone;
 * - the beginning of the statement that holds the read: if, elseif,
 *   else, while, return, braces, parentheses, "!", the target of a plain
 *   assignment, comparisons and "&&", "||", "and", "or", "xor" over
 *   such values, and the name of each function whose call holds the
 *   read, with the arguments written before it being such values.
 *
 * Anything else between leaves the variable unread, whether it may run
 * code or not: such a variable joined to a string, interpolated, sent
 * out, indexed, dereferenced, or compared with a string otherwise than
 * by identity, among the rest. A file compiled with ticks reads none of
 * them.
 *
 * Every case is a project of two files: page.php, the file read, and
 * bootstrap.php, which declares what a statement may run and writes the
 * global through $GLOBALS; part.php is there for the cases that include
 * it. Each file is read alone: nothing of bootstrap.php shows in
 * page.php.
 *
 * PHP 8.4, a script requiring bootstrap.php then page.php, with
 * $re = '/file-level/' in page.php: where code runs, preg_match() is
 * given "/(" followed by the name of what ran and "/", as "/(__get/";
 * where nothing runs it is given "/file-level/", and the variable is
 * left unread all the same when the statement is not on the list.
 *
 * Every case runs through both strategies.
 */
final class FileLevelImplicitCodeTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The other file of every case: an object whose every method writes
     * the global $re, and a class loader, an output callback and a tick
     * function that do the same. $object, $class, $callable and $code
     * are globals page.php finds set, with $flag and $sapi, and SUFFIX a
     * constant it finds defined.
     */
    private const BOOTSTRAP = <<<'PHP'
        <?php

        final class Rewriter implements ArrayAccess, IteratorAggregate
        {
            public function __get(string $name): mixed
            {
                return self::rewrite(__FUNCTION__);
            }

            public function __set(string $name, mixed $value): void
            {
                self::rewrite(__FUNCTION__);
            }

            public function __isset(string $name): bool
            {
                return '' === self::rewrite(__FUNCTION__);
            }

            public function __unset(string $name): void
            {
                self::rewrite(__FUNCTION__);
            }

            public function __toString(): string
            {
                return self::rewrite(__FUNCTION__);
            }

            public function __clone()
            {
                self::rewrite(__FUNCTION__);
            }

            public function __destruct()
            {
                self::rewrite(__FUNCTION__);
            }

            public function offsetExists(mixed $offset): bool
            {
                return '' === self::rewrite(__FUNCTION__);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return self::rewrite(__FUNCTION__);
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                self::rewrite(__FUNCTION__);
            }

            public function offsetUnset(mixed $offset): void
            {
                self::rewrite(__FUNCTION__);
            }

            public function getIterator(): Iterator
            {
                self::rewrite(__FUNCTION__);

                return new ArrayIterator([]);
            }

            public function info(string $message): void
            {
                self::rewrite(__FUNCTION__);
            }

            public static function rewrite(string $by): string
            {
                $GLOBALS['re'] = '/('.$by.'/';

                return '';
            }
        }

        spl_autoload_register(static function (string $class): void {
            Rewriter::rewrite('autoload '.$class);
            $at = strrpos($class, '\\');
            $namespace = false === $at ? '' : 'namespace '.substr($class, 0, $at).'; ';
            $name = false === $at ? $class : substr($class, $at + 1);
            eval($namespace.'class '.$name.' { public const MODE = "m"; public static int $count = 0; public static function match(string $pattern, string $subject): int { return \preg_match($pattern, $subject); } }');
        });

        register_tick_function(static function (): void {
            if (isset($GLOBALS['re'])) {
                Rewriter::rewrite('tick');
            }
        });

        ob_start(static fn (string $buffer): string => Rewriter::rewrite('output callback'), 1);

        define('SUFFIX', new Rewriter());

        $object = new Rewriter();
        $class = 'Lazy';
        $callable = 'Lazy::match';
        $flag = true;
        $sapi = 'cli';
        $code = '$re = "/(eval/";';

        PHP;

    /**
     * A property of an object PHP does not know the class of is read
     * through __get(), written through __set(), tested through __isset()
     * and removed through __unset(), in a string as anywhere else.
     *
     * PHP 8.4: "/(__get/" after the reads, empty() and "??" included,
     * "/(__set/" after the writes, the increment included, "/(__isset/"
     * after isset(), "/(__unset/" after unset().
     *
     * @return iterable<string, array{between: string}>
     */
    public static function providePropertyAccesses(): iterable
    {
        yield 'a property read' => ['between' => '$value = $object->name;'];
        yield 'a property written' => ['between' => "\$object->name = 'x';"];
        yield 'a property read through the nullsafe operator' => ['between' => '$value = $object?->name;'];
        yield 'a property under isset()' => ['between' => '$known = isset($object->name);'];
        yield 'a property under empty()' => ['between' => '$none = empty($object->name);'];
        yield 'a property unset' => ['between' => 'unset($object->name);'];
        yield 'a property incremented' => ['between' => '$object->count++;'];
        yield 'a property named by a variable' => ['between' => '$value = $object->$field;'];
        yield 'a property of an expression' => ['between' => '$value = ($object ?? $fallback)->name;'];
        yield 'a property with a default' => ['between' => "\$value = \$object->name ?? 'none';"];
        yield 'a property in a condition' => ['between' => "if (\$object->debug) {\n    \$mode = 'debug';\n}"];
        yield 'a property in an interpolated string' => ['between' => '$label = "x $object->name";'];
        yield 'a property in the braces of an interpolated string' => ['between' => '$label = "x {$object->name}";'];
        yield 'a property in the braces of a heredoc' => ['between' => "\$label = <<<TXT\n    x {\$object->name} y\n    TXT;"];
        // $flag && $object->name: the first operand is converted to a
        // boolean, the second is read.
        yield 'a property beside a variable converted to a boolean' => ['between' => "if (\$flag && \$object->name) {\n    \$mode = 'debug';\n}"];
    }

    /**
     * An item of a value is read through ArrayAccess when the value is
     * an object, and a destructuring assignment reads its items the same
     * way.
     *
     * PHP 8.4: "/(offsetGet/" after the reads, empty(), "??" and the
     * destructuring assignments of $object included, "/(offsetSet/"
     * after the writes, "/(offsetExists/" after isset(),
     * "/(offsetUnset/" after unset(). Nothing runs for an array the file
     * wrote: "/file-level/".
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideArrayAccesses(): iterable
    {
        yield 'an item read' => ['between' => "\$value = \$object['key'];"];
        yield 'an item written' => ['between' => "\$object['key'] = 1;"];
        yield 'an item appended' => ['between' => '$object[] = 1;'];
        yield 'an item under isset()' => ['between' => "\$known = isset(\$object['key']);"];
        yield 'an item under empty()' => ['between' => "\$none = empty(\$object['key']);"];
        yield 'an item unset' => ['between' => "unset(\$object['key']);"];
        yield 'an item with a default' => ['between' => "\$value = \$object['key'] ?? 'none';"];
        yield 'an item in a condition' => ['between' => "if (\$object['debug']) {\n    \$mode = 'debug';\n}"];
        yield 'an item in an interpolated string' => ['between' => '$label = "x $object[key]";'];
        yield 'an item in the braces of an interpolated string' => ['between' => "\$label = \"x {\$object['key']}\";"];
        yield 'a destructuring assignment' => ['between' => '[$first, $second] = $object;'];
        yield 'a destructuring assignment under keys' => ['between' => "['a' => \$first, 'b' => \$second] = \$object;"];
        yield 'list()' => ['between' => 'list($first, $second) = $object;'];
        yield 'an item beside a variable converted to a boolean' => ['between' => "if (\$flag && \$object['debug']) {\n    \$mode = 'debug';\n}"];
        // The key is a string that interpolates in its turn: its quotes
        // and its braces stand inside those of the first string.
        yield 'an item under an interpolated key, in an interpolated string' => ['between' => "\$label = \"{\$object[\"x{\$object['key']}y\"]}\";"];
        yield 'an item under an interpolated key, the string in an array in parentheses' => ['between' => "\$label = ([\"{\$object[\"x{\$object['key']}y\"]}\"]);"];
        // Nothing runs here: left unread because the list is closed.
        yield 'an item of an array the file wrote' => ['between' => "\$options = ['debug' => true];\n\$debug = \$options['debug'];"];
        yield 'an item written in an array the file wrote' => ['between' => "\$seen = [];\n\$seen['key'] = 1;"];
        yield 'a destructuring assignment of an array of literals' => ['between' => "[\$first, \$second] = ['a', 'b'];"];
    }

    /**
     * A string made of a value that is no string runs __toString() when
     * the value is an object; a comparison with a string does the same,
     * "==" and "!=" as "<" and the other orderings, and so does a switch.
     *
     * PHP 8.4: "/(__toString/" after each of them, the constant included,
     * which holds an object another file defined it with. Nothing runs
     * where the variable is one the file proves, in a heredoc of text
     * alone and in a nowdoc: "/file-level/".
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideStringsMadeOfAVariable(): iterable
    {
        yield 'an interpolated string' => ['between' => '$label = "x$object";'];
        yield 'the braces of an interpolated string' => ['between' => '$label = "x{$object}";'];
        yield 'the dollar and braces of an interpolated string' => ['between' => '$label = "x${object}";'];
        yield 'a heredoc' => ['between' => "\$label = <<<TXT\n    x \$object\n    TXT;"];
        yield 'a string cast' => ['between' => '$label = (string) $object;'];
        yield 'a concatenation, the variable first' => ['between' => "\$label = \$object . 'x';"];
        yield 'a concatenation, the variable last' => ['between' => "\$label = 'x' . \$object;"];
        yield 'a concatenating assignment' => ['between' => "\$label = 'x';\n\$label .= \$object;"];
        yield 'a concatenation with a constant another file defines' => ['between' => "\$label = 'x' . SUFFIX;"];
        yield 'a comparison with a string' => ['between' => "\$same = \$object == 'x';"];
        yield 'a comparison with a string in a condition' => ['between' => "if ('x' != \$object) {\n    \$label = 'a';\n}"];
        yield 'an equality with a string in a condition' => ['between' => "if (\$object == 'x') {\n    \$label = 'a';\n}"];
        yield 'an ordering with a string in a condition' => ['between' => "if (\$object < 'x') {\n    \$label = 'a';\n}"];
        yield 'a switch' => ['between' => "switch (\$object) {\n    case 'x':\n        \$label = 'a';\n        break;\n}"];
        // $flag is converted to a boolean, which asks it nothing; the
        // other operand is made a string all the same.
        yield 'a comparison with a string beside a variable converted to a boolean' => ['between' => "if (\$flag && \$object == 'x') {\n    \$label = 'a';\n}"];
        yield 'a concatenation beside a variable converted to a boolean' => ['between' => "if (\$flag && \$object . 'x') {\n    \$label = 'a';\n}"];
        yield 'a comparison with a string under xor' => ['between' => "if (\$flag xor \$object == 'x') {\n    \$label = 'a';\n}"];
        // Nothing runs here: left unread because the list is closed.
        yield 'an interpolation of a variable the file proves' => ['between' => "\$label = 'x';\n\$title = \"{\$label}y\";"];
        yield 'an interpolation of the variable itself' => ['between' => '$title = "{$re}y";'];
        yield 'a heredoc of text alone' => ['between' => "\$label = <<<TXT\n    text\n    TXT;"];
        yield 'a nowdoc naming a variable' => ['between' => "\$label = <<<'TXT'\n    x \$object {\$object->name}\n    TXT;"];
    }

    /**
     * What is sent out goes through the callback of ob_start() when the
     * buffer is given a chunk size, and through __toString() first when
     * it is an object.
     *
     * PHP 8.4: "/(__toString/" where the object is sent out, alone or
     * after a literal; "/(output callback/" after each of the others, a
     * literal included.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideOutput(): iterable
    {
        yield 'echo' => ['between' => 'echo $object;'];
        yield 'print' => ['between' => 'print $object;'];
        yield 'the echo tag of a template' => ['between' => "?>\n<?= \$object ?>\n<?php"];
        yield 'echo, after a literal' => ['between' => "echo 'a', \$object;"];
        yield 'echo of a literal' => ['between' => "echo 'literal';"];
        yield 'echo of two literals' => ['between' => "echo 'a', \"b\\n\";"];
        yield 'echo of a variable the file proves' => ['between' => "\$label = 'x';\necho \$label;"];
        yield 'echo of the variable itself' => ['between' => 'echo $re;'];
        yield 'print of a literal' => ['between' => "print 'literal';"];
        yield 'the text of a template' => ['between' => "?>\n<p>text</p>\n<?php"];
    }

    /**
     * A copy runs __clone(); a foreach over an object runs getIterator();
     * the last name of an object given up runs its destructor.
     *
     * PHP 8.4: "/(__clone/" after clone, "/(getIterator/" after each
     * foreach over the object, "/(__destruct/" after unset() and after
     * the assignment of null. Nothing runs in the other loops and in the
     * try: "/file-level/".
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideCopiesLoopsAndDestructions(): iterable
    {
        yield 'clone' => ['between' => '$copy = clone $object;'];
        yield 'a foreach over a variable' => ['between' => "foreach (\$object as \$value) {\n}"];
        yield 'a foreach over a variable, with its keys' => ['between' => "foreach (\$object as \$key => \$value) {\n}"];
        yield 'a foreach over a variable, alternative syntax' => ['between' => "foreach (\$object as \$value):\nendforeach;"];
        yield 'a foreach over an expression' => ['between' => "foreach (\$object ?? [] as \$value) {\n}"];
        yield 'unset()' => ['between' => 'unset($object);'];
        yield 'unset() of two variables' => ['between' => 'unset($first, $object);'];
        yield 'an assignment of null' => ['between' => '$object = null;'];
        // Nothing runs here: left unread because the list is closed.
        yield 'a foreach over an array written in its head' => ['between' => "foreach (['a', 'b'] as \$value) {\n}"];
        yield 'a foreach over array() written in its head' => ['between' => "foreach (array('a', 'b') as \$key => \$value) {\n}"];
        yield 'a for' => ['between' => "for (\$i = 0; \$i < 3; ++\$i) {\n}"];
        yield 'a do-while' => ['between' => "do {\n    \$label = 'x';\n} while (false);"];
        yield 'a try' => ['between' => "try {\n    \$label = 'x';\n} catch (\\Throwable \$error) {\n}"];
    }

    /**
     * A constant or a static property of a class that is not loaded
     * loads it: the functions registered with spl_autoload_register()
     * run. So does the declaration of a class whose parent is not loaded.
     *
     * PHP 8.4: "/(autoload Lazy/" after each of them. Nothing runs for
     * the name of a class, nor for a member of a class the file declares
     * with literal values: "/file-level/".
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideClassMembers(): iterable
    {
        yield 'a class constant' => ['between' => '$mode = Lazy::MODE;'];
        yield 'a class constant in a condition' => ['between' => "if (Lazy::MODE) {\n    \$mode = 'm';\n}"];
        yield 'a class constant in the value of a constant' => ['between' => 'const MODE = Lazy::MODE;'];
        yield 'a constant of a class named by a variable' => ['between' => '$mode = $class::MODE;'];
        yield 'a static property read' => ['between' => '$count = Lazy::$count;'];
        yield 'a static property written' => ['between' => 'Lazy::$count = 1;'];
        yield 'a class declared with a parent' => ['between' => "class Child extends Lazy\n{\n}"];
        // Nothing runs here: left unread because the list is closed.
        yield 'the name of a class' => ['between' => '$name = Lazy::class;'];
        yield 'a constant of a class of the file' => ['between' => "final class Local\n{\n    public const MODE = 'm';\n}\n\n\$mode = Local::MODE;"];
        yield 'a static property of a class of the file' => ['between' => "final class Local\n{\n    public static int \$count = 0;\n}\n\n\$count = Local::\$count;"];
    }

    /**
     * A declaration runs nothing where it stands, whatever its body and
     * its default values hold; neither does a closure that is not
     * called. The list holds none of them.
     *
     * PHP 8.4: "/file-level/" after each of them.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideDeclarations(): iterable
    {
        yield 'a function declared' => ['between' => "function helper(Rewriter \$object, string \$mode = Lazy::MODE): string\n{\n    echo \$object;\n    unset(\$object['key']);\n\n    return \$object->name . \$object['key'] . \$mode . clone \$object;\n}"];
        yield 'a class declared' => ['between' => "final class Helper\n{\n    public const MODE = Lazy::MODE;\n\n    public function run(Rewriter \$object): string\n    {\n        foreach (\$object as \$value) {\n            \$this->seen = \"x{\$value}\";\n        }\n\n        return \$this->seen . self::MODE . Lazy::\$count;\n    }\n}"];
        yield 'an interface declared' => ['between' => "interface Shape\n{\n}"];
        yield 'a trait declared' => ['between' => "trait Shared\n{\n}"];
        yield 'an enum declared' => ['between' => "enum Suit\n{\n    case Hearts;\n}"];
        yield 'a function declared under an attribute' => ['between' => "#[Marker(Lazy::MODE)]\nfunction marked(): void\n{\n}"];
        yield 'a closure declared and not called' => ['between' => "\$format = static function (Rewriter \$object): string {\n    return \"x{\$object->name}\" . \$object['key'];\n};"];
        yield 'an arrow function declared and not called' => ['between' => '$format = static fn (Rewriter $object): string => (string) $object;'];
        yield 'a function returning a reference declared' => ['between' => "function &helper(array &\$items): array\n{\n    return \$items;\n}"];
        yield 'a function declared with an attribute on a parameter' => ['between' => "function helper(#[\\SensitiveParameter] string \$secret, string \$mode = Lazy::MODE): void\n{\n    echo \$secret;\n}"];
    }

    /**
     * A statement that is no plain assignment to a plain variable, and a
     * plain assignment whose value is made of anything the list does not
     * hold.
     *
     * PHP 8.4: "/file-level/" after each of them: nothing runs here, and
     * the variable is left unread because the list is closed.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideOtherStatementsOffTheList(): iterable
    {
        yield 'a concatenating assignment of a literal' => ['between' => "\$label = 'x';\n\$label .= 'y';"];
        yield 'an adding assignment' => ['between' => "\$count = 1;\n\$count += 1;"];
        yield 'a null coalescing assignment' => ['between' => "\$label ??= 'x';"];
        yield 'an increment' => ['between' => "\$count = 1;\n\$count++;"];
        yield 'a decrement' => ['between' => "\$count = 1;\n--\$count;"];
        yield 'a static variable' => ['between' => 'static $count = 0;'];
        yield 'isset() of a variable' => ['between' => '$known = isset($label);'];
        yield 'empty() of a variable' => ['between' => '$none = empty($label);'];
        yield 'a variable the file does not prove, assigned' => ['between' => '$copy = $object;'];
        yield 'a constant of PHP in a value' => ['between' => "\$label = 'x' . \\PHP_EOL;"];
        yield 'a ternary in a value' => ['between' => "\$label = \$argc > 1 ? 'a' : 'b';"];
        yield 'a sum in a value' => ['between' => '$total = 1 + 2;'];
        yield 'a cast of a literal' => ['between' => "\$count = (int) '5';"];
        yield 'goto' => ['between' => "goto next;\nnext:"];
        yield 'exit in a branch' => ['between' => "\$mode = 'cli';\nif ('web' === \$mode) {\n    exit;\n}"];
        // The closing tag ends the statement as ";" does, and the line
        // break after it is sent nowhere.
        yield 'an assignment ended by a closing tag' => ['between' => "\$label = 'x' ?>\n<?php"];
    }

    /**
     * What may only begin the statement that holds the read, written as
     * a statement of its own: a comparison or a negation, assigned or
     * alone, a value alone, a return, and an if whose condition assigns.
     *
     * PHP 8.4: "/(__destruct/" after the if, whose condition gives up
     * the object for a string; "/file-level/" after the comparisons, the
     * negation and the string, where nothing runs; the read is not
     * reached after the return. The variable is left unread in each
     * case because the list is closed.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideWholeStatementsMadeOfTheBeginningOfOne(): iterable
    {
        yield 'a comparison assigned' => ['between' => '$same = $object === 1;'];
        yield 'a negation assigned' => ['between' => '$none = !$object;'];
        yield 'a comparison alone' => ['between' => '$object === 1;'];
        yield 'a string alone' => ['between' => "'x';"];
        yield 'a return' => ['between' => 'return 1;'];
        yield 'an if whose condition assigns' => ['between' => "if (\$object = 'x') {\n    \$label = 'a';\n}"];
    }

    /**
     * An array where a string is made, a comparison where an item is
     * written, and array() where no value starts.
     *
     * PHP 8.4: "/(__toString/" after the comparison of the object with a
     * string; "/file-level/" after the others, the array joined to a
     * string raising a warning: nothing runs there, and the variable is
     * left unread because the list is closed.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideArraysOutOfPlace(): iterable
    {
        yield 'an array joined to a string' => ['between' => "\$label = ['a'] . 'x';"];
        yield 'array() joined to a string' => ['between' => "\$label = array('a') . 'x';"];
        yield 'a string joined to array()' => ['between' => "\$label = 'x' . array('a');"];
        yield 'a comparison of literals in an array' => ['between' => "\$options = ['a' === 'b'];"];
        yield 'a negation in an array' => ['between' => '$options = [!$object];'];
        yield 'a comparison with a string in an array' => ['between' => "\$options = [\$object == 'x'];"];
        yield 'array() alone' => ['between' => "array('a', 'b');"];
    }

    /**
     * An if written otherwise than the list holds it: in the alternative
     * syntax, as the body of a loop, or over a variable the file does
     * not prove, compared with what is no integer, float or boolean
     * written as it is.
     *
     * PHP 8.4: "/file-level/" after each of them, $object > -1,
     * $object == (1) and $object == [1] included, which run no method of
     * the object: nothing runs, and the variable is left unread because
     * the list is closed.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideIfsTheListDoesNotHold(): iterable
    {
        yield 'an if, alternative syntax' => ['between' => "if (\$object > 1):\n    \$label = 'a';\nendif;"];
        yield 'an if and its else, alternative syntax' => ['between' => "if (\$object > 1):\nelse:\n    \$label = 'b';\nendif;"];
        yield 'an if and its elseif, alternative syntax' => ['between' => "if (\$object > 5):\nelseif (\$object > 1):\n    \$label = 'b';\nendif;"];
        yield 'an if as the body of a while' => ['between' => "while (\$object > 5) if (\$object > 1) \$label = 'a';"];
        yield 'an if over an object compared with a negative number' => ['between' => "if (\$object > -1) {\n    \$label = 'a';\n}"];
        yield 'an if over an object compared with a number in parentheses' => ['between' => "if (\$object == (1)) {\n    \$label = 'a';\n}"];
        yield 'an if over an object compared with an array' => ['between' => "if (\$object == [1]) {\n    \$label = 'a';\n}"];
    }

    /**
     * What is a call already, or a file that runs in the place of the
     * statement, kept beside the statements that show no call.
     *
     * PHP 8.4: "/(info/" after the method call, "/(part.php/" after the
     * file included or required, "/(eval/" after eval().
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideWrittenCalls(): iterable
    {
        yield 'a method call' => ['between' => "\$object->info('start');"];
        yield 'an included file' => ['between' => "include __DIR__ . '/part.php';"];
        yield 'a required file' => ['between' => "require_once __DIR__ . '/part.php';"];
        yield 'eval' => ['between' => 'eval($code);'];
    }

    #[Test]
    #[DataProvider('providePropertyAccesses')]
    #[DataProvider('provideArrayAccesses')]
    #[DataProvider('provideStringsMadeOfAVariable')]
    #[DataProvider('provideOutput')]
    #[DataProvider('provideCopiesLoopsAndDestructions')]
    #[DataProvider('provideClassMembers')]
    #[DataProvider('provideDeclarations')]
    #[DataProvider('provideOtherStatementsOffTheList')]
    #[DataProvider('provideWholeStatementsMadeOfTheBeginningOfOne')]
    #[DataProvider('provideArraysOutOfPlace')]
    #[DataProvider('provideIfsTheListDoesNotHold')]
    #[DataProvider('provideAssignmentsOfABodyReadAfterIt')]
    #[DataProvider('provideWrittenCalls')]
    public function test_a_statement_off_the_list_between_the_assignment_and_the_read_leaves_the_variable_unread(string $between): void
    {
        $project = self::project(self::whole($between));

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * The variable is an operand written after a literal: the literal is
     * kept, the operand is a part that is not read, and no pattern is
     * composed.
     *
     * PHP 8.4, $re = 'file-level' in page.php: '/^' . $re . '$/' is
     * "/^/(__get/$/" after $value = $object->name, and so on with the
     * name of what ran.
     */
    #[Test]
    #[DataProvider('providePropertyAccesses')]
    #[DataProvider('provideArrayAccesses')]
    #[DataProvider('provideStringsMadeOfAVariable')]
    #[DataProvider('provideOutput')]
    #[DataProvider('provideCopiesLoopsAndDestructions')]
    #[DataProvider('provideClassMembers')]
    #[DataProvider('provideDeclarations')]
    #[DataProvider('provideOtherStatementsOffTheList')]
    #[DataProvider('provideWholeStatementsMadeOfTheBeginningOfOne')]
    #[DataProvider('provideArraysOutOfPlace')]
    #[DataProvider('provideIfsTheListDoesNotHold')]
    #[DataProvider('provideAssignmentsOfABodyReadAfterIt')]
    #[DataProvider('provideWrittenCalls')]
    public function test_a_statement_off_the_list_between_the_assignment_and_the_read_leaves_the_operand_a_raw_part(string $between): void
    {
        $project = self::project(self::operand($between));

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$re . '$/'", 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * What the list holds between the assignment and the read: comments,
     * plain assignments of literals, of arrays and concatenations of
     * them, of variables the file proves the same way, and an if whose
     * conditions and bodies hold nothing else, or whose condition holds
     * a variable the file does not prove where it runs no code.
     *
     * PHP 8.4: "/file-level/" after each of them, the object, the class
     * loader and the output callback of bootstrap.php being there:
     * $object > 1, $object <= 1.5, $object == 1, $object == true,
     * $object === 'x', 'x' !== $object, !$object and if ($object) run
     * no method of the object. Compared with a number it raises a
     * notice, which a handler of another file may answer: that is not
     * followed.
     *
     * "&&", "||", "and", "or" and "xor" convert their operands to
     * booleans, as "!" and the if do: $object && true, $object || false,
     * $object and true, $object or false, $object xor true and
     * ($object) && true run no method of the object, nor do they beside
     * a comparison of the list, as in $object && $sapi === 'cli'.
     *
     * A body written without braces runs what the same body in braces
     * runs: nothing, for an assignment of the list.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideStatementsOnTheList(): iterable
    {
        yield 'a string assigned' => ['between' => "\$label = 'a';"];
        yield 'a double-quoted string without a variable assigned' => ['between' => '$label = "a\n";'];
        yield 'numbers and booleans assigned' => ['between' => "\$limit = 10;\n\$ratio = 1.5;\n\$enabled = true;\n\$strict = false;"];
        yield 'a concatenation of literals assigned' => ['between' => "\$label = 'a ' . 'label';"];
        yield 'a concatenation in parentheses assigned' => ['between' => "\$label = ('a' . 'b') . 'c';"];
        yield 'an array of literals assigned' => ['between' => "\$options = ['a', 'b'];"];
        yield 'an array of literals under keys assigned' => ['between' => "\$options = ['debug' => true, 'levels' => [1, 2.5], 3 => 'c'];"];
        yield 'array() of literals assigned' => ['between' => "\$options = array('a', 'b');"];
        // $a = 'x'; $b = $a . 'y'; '/' . $a . '/' is "/x/".
        yield 'a concatenation with a variable the file proves' => ['between' => "\$label = 'x';\n\$title = \$label . 'y';"];
        yield 'a concatenation of the variable itself' => ['between' => "\$title = \$re . 'y';"];
        yield 'a string variable assigned again' => ['between' => "\$label = 'a';\n\$label = 'b';"];
        // A single-quoted string interpolates nothing: the names are words.
        yield 'a single-quoted string naming a variable' => ['between' => "\$label = 'x \$object {\$object->name} \$object[key]';"];
        yield 'comments' => ['between' => "// echo \$object;\n/* \$value = \$object->name; */\n# clone \$object\n/** @var string \$re */"];
        yield 'an if whose condition and body hold such values' => ['between' => "\$mode = 'cli';\nif ('cli' === \$mode) {\n    \$label = 'a';\n}"];
        yield 'an if and its else whose condition and bodies hold such values' => ['between' => "\$mode = 'cli';\nif ('cli' === \$mode) {\n    \$label = 'a';\n} else {\n    \$label = 'b';\n}"];
        yield 'an if over a variable the file does not prove, compared with a number' => ['between' => "if (\$argc > 1) {\n    \$label = 'a';\n}"];
        yield 'an if over an object greater than an integer' => ['between' => "if (\$object > 1) {\n    \$label = 'a';\n}"];
        yield 'an if over an object not greater than a float' => ['between' => "if (\$object <= 1.5) {\n    \$label = 'a';\n}"];
        yield 'an if over an object equal to an integer' => ['between' => "if (\$object == 1) {\n    \$label = 'a';\n}"];
        yield 'an if over an object equal to a boolean' => ['between' => "if (\$object == true) {\n    \$label = 'a';\n}"];
        yield 'an if over an object identical to a string' => ['between' => "if (\$object === 'x') {\n    \$label = 'a';\n}"];
        yield 'an if over a string not identical to an object' => ['between' => "if ('x' !== \$object) {\n    \$label = 'a';\n}"];
        yield 'an if over an object negated' => ['between' => "if (!\$object) {\n    \$label = 'a';\n}"];
        yield 'an if over an object alone' => ['between' => "if (\$object) {\n    \$label = 'a';\n}"];
        yield 'an if over an object and another variable the file does not prove' => ['between' => "if (\$object && \$flag) {\n    \$label = 'a';\n}"];
        yield 'an if over a variable the file does not prove or the negation of an object' => ['between' => "if (\$flag || !\$object) {\n    \$label = 'a';\n}"];
        yield 'an if over the negation of a variable or an object' => ['between' => "if (!\$flag || \$object) {\n    \$label = 'a';\n}"];
        yield 'an if over two variables the file does not prove, under and' => ['between' => "if (\$object and \$flag) {\n    \$label = 'a';\n}"];
        yield 'an if over two variables the file does not prove, under or' => ['between' => "if (!\$flag or \$object) {\n    \$label = 'a';\n}"];
        yield 'an if over two variables the file does not prove, under xor' => ['between' => "if (\$object xor \$flag) {\n    \$label = 'a';\n}"];
        yield 'an if over an object in parentheses and another variable' => ['between' => "if ((\$object) && \$flag) {\n    \$label = 'a';\n}"];
        yield 'an if over three variables the file does not prove' => ['between' => "if (\$object && \$flag && \$sapi) {\n    \$label = 'a';\n}"];
        yield 'an if over an object and a comparison of the list' => ['between' => "if (\$object && \$sapi === 'cli') {\n    \$label = 'a';\n}"];
        yield 'an if whose body is written without braces' => ['between' => "if (\$object > 1) \$label = 'a';"];
        yield 'an if and its else whose bodies are written without braces' => ['between' => "if (\$object > 1) \$label = 'a'; else \$label = 'b';"];
        yield 'an if and its else if' => ['between' => "if (\$object > 1) {\n    \$label = 'a';\n} else if (\$object > 2) {\n    \$label = 'b';\n}"];
    }

    /**
     * An assignment written in the body of an if proves nothing once
     * the body is left, with braces or without: the body may not have
     * run, and the variable then holds what it held before. Joined to a
     * string after the if, it is off the list.
     *
     * PHP 8.4, $sapi being "cli": the body does not run, $object is
     * still the object, and $title = $object . 'y' gives
     * "/(__toString/". Where both bodies assign a string nothing runs,
     * "/file-level/", and the variable is left unread because an
     * assignment proves its variable only in the block it is written
     * in.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideAssignmentsOfABodyReadAfterIt(): iterable
    {
        yield 'assigned in a body without braces, joined after it' => ['between' => "if (\$sapi === 'web') \$object = 'x';\n\$title = \$object . 'y';"];
        yield 'assigned in a body in braces, joined after it' => ['between' => "if (\$sapi === 'web') {\n    \$object = 'x';\n}\n\$title = \$object . 'y';"];
        yield 'assigned in the bodies of an if and its else without braces, joined after them' => ['between' => "if (\$sapi === 'web') \$label = 'x'; else \$label = 'z';\n\$title = \$label . 'y';"];
    }

    #[Test]
    #[DataProvider('provideStatementsOnTheList')]
    public function test_a_statement_on_the_list_between_the_assignment_and_the_read_leaves_the_variable_read(string $between): void
    {
        $project = self::project(self::whole($between));

        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * PHP 8.4, $re = 'file-level' in page.php: '/^' . $re . '$/' is
     * "/^file-level$/" after each of them.
     */
    #[Test]
    #[DataProvider('provideStatementsOnTheList')]
    public function test_a_statement_on_the_list_between_the_assignment_and_the_read_leaves_the_operand_read(string $between): void
    {
        $project = self::project(self::operand($between));

        $this->assertExtractedByBoth([
            ['pattern' => '/^file-level$/', 'at' => "'/^' . \$re . '$/'", 'source' => 'preg_match()', 'location' => null],
        ], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . \$re . '$/'", 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * A file compiled with ticks runs the functions registered with
     * register_tick_function() after each of its statements, the
     * assignment included: nothing stands between the assignment and the
     * read, and the variable is written all the same. A declare of ticks
     * written anywhere before the read leaves the variable unread.
     *
     * PHP 8.4: "/(tick/" in each case, the declare written in the body
     * of a function included, which holds for the rest of the file;
     * "/^/(tick/$/" for the operand. "/file-level/" after the block that
     * is closed before the assignment: nothing runs there, and the
     * variable is left unread because the rule asks nothing more of a
     * declare of ticks than to stand before the read.
     *
     * @return iterable<string, array{code: string, argument: string, state: string}>
     */
    public static function provideFilesCompiledWithTicks(): iterable
    {
        yield 'declare(ticks=1) at the top of the file' => [
            'code' => "<?php\n\ndeclare(ticks=1);\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'ticks declared with strict types' => [
            'code' => "<?php\n\ndeclare(strict_types=1, ticks=1);\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'ticks declared in upper case' => [
            'code' => "<?php\n\nDECLARE(TICKS=1);\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'a tick every two statements' => [
            'code' => "<?php\n\ndeclare(ticks=2);\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'the block of a declare(ticks=1)' => [
            'code' => "<?php\n\ndeclare(ticks=1) {\n    \$re = '/file-level/';\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'the block of a declare(ticks=1), alternative syntax' => [
            'code' => "<?php\n\ndeclare(ticks=1):\n    \$re = '/file-level/';\n    preg_match(\$re, \$subject);\nenddeclare;\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'declare(ticks=1) between the assignment and the read' => [
            'code' => "<?php\n\n\$re = '/file-level/';\ndeclare(ticks=1);\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'an operand, in a file compiled with ticks' => [
            'code' => "<?php\n\ndeclare(ticks=1);\n\n\$re = 'file-level';\npreg_match('/^' . \$re . '$/', \$subject);\n",
            'argument' => "'/^' . \$re . '$/'",
            'state' => 'raw_shell',
        ];

        yield 'the block of a declare(ticks=1) closed before the assignment' => [
            'code' => "<?php\n\ndeclare(ticks=1) {\n    \$limit = 10;\n}\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'declare(ticks=1) in a function declared before the assignment' => [
            'code' => "<?php\n\nfunction ticking(): void\n{\n    declare(ticks=1);\n}\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];
    }

    #[Test]
    #[DataProvider('provideFilesCompiledWithTicks')]
    public function test_a_file_declaring_ticks_before_the_read_reads_no_variable_of_its_file_level(string $code, string $argument, string $state): void
    {
        $project = self::project($code);

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * In a loop the assignment is judged within the turn, whether a
     * statement of the body makes it or the head of a foreach: a
     * statement off the list between it and the read, in the turn,
     * leaves the variable unread.
     *
     * PHP 8.4: "/(__toString/", "/(__get/" and "/(offsetGet/" at each
     * turn.
     *
     * @return iterable<string, array{loop: string}>
     */
    public static function provideTurnsRunningCodeBeforeTheRead(): iterable
    {
        yield 'a string made of an object after an assignment of the turn' => ['loop' => "foreach ([1, 2] as \$turn) {\n    \$re = '/in-the-turn/';\n    \$label = \"x{\$object}\";\n    preg_match(\$re, \$subject);\n}"];
        yield 'a property read after the head of a foreach' => ['loop' => "foreach (['/target-a/', '/target-b/'] as \$re) {\n    \$value = \$object->name;\n    preg_match(\$re, \$subject);\n}"];
        yield 'an item read after the head of a foreach, the key target read' => ['loop' => "foreach (['/target-a/' => 1, '/target-b/' => 2] as \$re => \$weight) {\n    \$value = \$object['key'];\n    preg_match(\$re, \$subject);\n}"];
    }

    #[Test]
    #[DataProvider('provideTurnsRunningCodeBeforeTheRead')]
    public function test_a_statement_off_the_list_between_the_assignment_of_a_turn_and_the_read_leaves_the_variable_unread(string $loop): void
    {
        $project = self::project("<?php\n\n".$loop."\n");

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * The statement that holds the read begins with something the list
     * does not hold, or a value between names a constant of the file.
     *
     * PHP 8.4: "/(__toString/" where the object is compared before the
     * call, and where the constant is joined, since the constant another
     * file defined first keeps its value; "/file-level/" in the echo,
     * which sends the result out after the call: nothing runs before the
     * read there, and the variable is left unread because the list is
     * closed.
     *
     * A variable converted to a boolean before the call is asked
     * nothing, and what stands beside it runs what it runs alone:
     * "/(__toString/" for $object . 'x' && ..., for
     * $flag && $object != 'x' && ... and for
     * $flag xor $object . 'x' xor ....
     *
     * An argument written before the call is a value of the list or
     * nothing: "/(__destruct/" where it assigns a string to the variable
     * that held the object; "/file-level/" where it compares or negates,
     * in an array or out of one, and where it is named, a keyword naming
     * it or not: nothing runs before the read there.
     *
     * What a variable, a string or an array names is found before its
     * arguments are evaluated, and a static method loads its class
     * then: "/(autoload Lazy/" for $callable(...), ($callable)(...),
     * 'Lazy::match'(...) and [$class, 'match'](...).
     *
     * A keyword that names an argument, or that imports a function in
     * a grouped use, declares nothing: what is written after it is read
     * as after any other statement, "/(__toString/" for the echo.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesLeftUnread(): iterable
    {
        yield 'a comparison of a variable the file does not prove, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nif (\$object != 'x' && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n"];
        yield 'a constant of the file in a value between' => ['code' => "<?php\n\nconst SUFFIX = 'y';\n\n\$re = '/file-level/';\n\$label = 'x' . SUFFIX;\npreg_match(\$re, \$subject);\n"];
        yield 'echo of the result of the call' => ['code' => "<?php\n\n\$re = '/file-level/';\necho preg_match(\$re, \$subject);\n"];

        yield 'a string made of a variable the file does not prove, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nif (\$object . 'x' && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n"];
        yield 'a comparison with a string after a variable converted to a boolean, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nif (\$flag && \$object != 'x' && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n"];
        yield 'a string made of a variable under xor, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nif (\$flag xor \$object . 'x' xor preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n"];

        yield 'an argument that compares, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nvar_dump(\$object === 1, preg_match(\$re, \$subject));\n"];
        yield 'an argument that negates, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nvar_dump(!\$object, preg_match(\$re, \$subject));\n"];
        yield 'an argument that assigns, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nvar_dump(\$object = 'x', preg_match(\$re, \$subject));\n"];
        yield 'an argument named by a keyword, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nrender(class: 'btn', found: preg_match(\$re, \$subject));\n"];
        yield 'an argument holding a negation in an array, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nvar_dump([!\$object], preg_match(\$re, \$subject));\n"];
        yield 'an argument holding a comparison in an array, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nvar_dump(['a' === 'b'], preg_match(\$re, \$subject));\n"];
        yield 'an argument named, before the call' => ['code' => "<?php\n\n\$re = '/file-level/';\nrender(label: 'btn', found: preg_match(\$re, \$subject));\n"];

        yield 'the call in the arguments of what a variable names' => ['code' => "<?php\n\n\$re = '/file-level/';\n\$callable(preg_match(\$re, \$subject), \$text);\n"];
        yield 'the call in the arguments of what a variable in parentheses names' => ['code' => "<?php\n\n\$re = '/file-level/';\n(\$callable)(preg_match(\$re, \$subject), \$text);\n"];
        yield 'the call in the arguments of what a string names' => ['code' => "<?php\n\n\$re = '/file-level/';\n'Lazy::match'(preg_match(\$re, \$subject), \$text);\n"];
        yield 'the call in the arguments of what an array names' => ['code' => "<?php\n\n\$re = '/file-level/';\n[\$class, 'match'](preg_match(\$re, \$subject), \$text);\n"];

        yield 'echo after a call naming an argument by a keyword' => ['code' => "<?php\n\nrender(class: 'btn', function: 'on');\n\$re = '/file-level/';\necho \$object;\npreg_match(\$re, \$subject);\n"];
        yield 'echo after a function imported in a grouped use' => ['code' => "<?php\n\nuse App\\{function helper, Kernel};\n\n\$re = '/file-level/';\necho \$object;\npreg_match(\$re, \$subject);\n"];
    }

    #[Test]
    #[DataProvider('provideFilesLeftUnread')]
    public function test_a_read_under_something_off_the_list_is_left_unread(string $code): void
    {
        $project = self::project($code);

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * A static call loads its class before it evaluates its arguments:
     * the class loader runs between the assignment and the read, though
     * the call is the one that holds the read.
     *
     * PHP 8.4, the class not loaded: Preg::match($re, $subject) is given
     * "/(autoload Composer\Pcre\Preg/", and '/^' . $re . '$/' is
     * "/^/(autoload Composer\Pcre\Preg/$/".
     */
    #[Test]
    public function test_a_static_call_holding_the_read_leaves_the_variable_unread(): void
    {
        $registry = PatternFunctionRegistry::create(['composer-pcre']);
        $whole = self::project("<?php\n\nuse Composer\\Pcre\\Preg;\n\n\$re = '/file-level/';\nPreg::match(\$re, \$subject);\n");

        $this->assertExtractedByBoth([], $whole, $registry);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $whole, $registry);

        $operand = self::project("<?php\n\nuse Composer\\Pcre\\Preg;\n\n\$re = 'file-level';\nPreg::match('/^' . \$re . '$/', \$subject);\n");

        $this->assertExtractedByBoth([], $operand, $registry);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$re . '$/'"],
        ], $operand, $registry);
    }

    /**
     * Each row gives the file, the argument, and the pattern read with
     * the text it is written at and the name it is read through.
     *
     * The statements off the list run before the assignment, or after
     * the read and out of any loop: whatever they write, the assignment
     * writes after them, or the read is made before them.
     *
     * The other rows are the shapes scripts are written in, and the
     * beginnings the list holds for the statement of the read, a
     * variable the file does not prove included where it runs no code:
     * negated, under an identity, and converted to a boolean by "&&",
     * "||", "and", "or" or "xor", alone or in parentheses.
     *
     * PHP 8.4: the pattern of the row in each case. With the object of
     * bootstrap.php in the place of $flag and of $debug, the call is
     * given the pattern the file assigned after $object && ...,
     * !$object || ..., $object and ..., !$object or ...,
     * $object xor ..., $object && $flag && ..., ($object) && ... and
     * $found = $object && ...: none of them runs a method of the
     * object.
     *
     * @return iterable<string, array{code: string, argument: string, pattern: array{pattern: string, at: string, location: string|null}}>
     */
    public static function provideFilesStillRead(): iterable
    {
        yield 'the statements before the assignment' => [
            'code' => "<?php\n\necho \$object;\n\$value = \$object->name;\n\$item = \$object['key'];\n\$copy = clone \$object;\n\$mode = Lazy::MODE;\nforeach (\$object as \$each) {\n}\nunset(\$object);\n\$re = '/after-them/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-them/', 'at' => "'/after-them/'", 'location' => 'via $re'],
        ];

        yield 'the statements after the read' => [
            'code' => "<?php\n\n\$re = '/before-them/';\npreg_match(\$re, \$subject);\necho \$object;\n\$value = \$object->name;\n\$item = \$object['key'];\n\$copy = clone \$object;\n\$mode = Lazy::MODE;\nforeach (\$object as \$each) {\n}\nunset(\$object);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/before-them/', 'at' => "'/before-them/'", 'location' => 'via $re'],
        ];

        // The next turn assigns again before it reads.
        yield 'assigned in a loop, the statements after the read' => [
            'code' => "<?php\n\nforeach ([1, 2] as \$turn) {\n    \$re = '/before-them-in-the-turn/';\n    preg_match(\$re, \$subject);\n    echo \$object;\n    \$value = \$object->name;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/before-them-in-the-turn/', 'at' => "'/before-them-in-the-turn/'", 'location' => 'via $re'],
        ];

        yield 'literals assigned one after the other, then the call' => [
            'code' => "<?php\n\n\$subject = 'a subject';\n\$pattern = '/one-after-the-other/';\n\$limit = 10;\n\$flags = 0;\npreg_match(\$pattern, \$subject, \$matches, \$flags);\n",
            'argument' => '$pattern',
            'pattern' => ['pattern' => '/one-after-the-other/', 'at' => "'/one-after-the-other/'", 'location' => 'via $pattern'],
        ];

        // The words stand in comments: they are no code.
        yield 'an assignment, a comment, the call' => [
            'code' => "<?php\n\n\$pattern = '/under-a-comment/';\n// echo \$object, \$object->name, \$object['key'] and clone \$object are only words here\n/* so are Lazy::MODE, unset(\$object) and declare(ticks=1) */\n# and \"x{\$object}\"\npreg_match(\$pattern, \$subject);\n",
            'argument' => '$pattern',
            'pattern' => ['pattern' => '/under-a-comment/', 'at' => "'/under-a-comment/'", 'location' => 'via $pattern'],
        ];

        yield 'an assignment among other literal assignments of one list' => [
            'code' => "<?php\n\n\$name = 'a name';\n\$enabled = true;\n\$pattern = '/among-the-others/';\n\$replacement = '';\n\$limit = 10;\n\$options = ['a', 'b'];\n\$clean = preg_replace(\$pattern, \$replacement, \$subject, \$limit);\n",
            'argument' => '$pattern',
            'pattern' => ['pattern' => '/among-the-others/', 'at' => "'/among-the-others/'", 'location' => 'via $pattern'],
        ];

        yield 'an assignment, then the call in a condition' => [
            'code' => "<?php\n\n\$pattern = '/in-a-condition/';\nif (preg_match(\$pattern, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$pattern',
            'pattern' => ['pattern' => '/in-a-condition/', 'at' => "'/in-a-condition/'", 'location' => 'via $pattern'],
        ];

        yield 'the result of the call assigned' => [
            'code' => "<?php\n\n\$re = '/result-assigned/';\n\$found = preg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/result-assigned/', 'at' => "'/result-assigned/'", 'location' => 'via $re'],
        ];

        yield 'the call negated in a condition' => [
            'code' => "<?php\n\n\$re = '/negated/';\nif (!preg_match(\$re, \$subject)) {\n    \$found = false;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/negated/', 'at' => "'/negated/'", 'location' => 'via $re'],
        ];

        yield 'the call compared with a number in a condition' => [
            'code' => "<?php\n\n\$re = '/compared/';\nif (1 === preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/compared/', 'at' => "'/compared/'", 'location' => 'via $re'],
        ];

        yield 'the result of the call returned' => [
            'code' => "<?php\n\n\$re = '/returned/';\n\nreturn preg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/returned/', 'at' => "'/returned/'", 'location' => 'via $re'],
        ];

        yield 'the call in the argument of a function' => [
            'code' => "<?php\n\n\$re = '/in-a-call/';\nvar_dump(preg_match(\$re, \$subject));\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/in-a-call/', 'at' => "'/in-a-call/'", 'location' => 'via $re'],
        ];

        yield 'the call in the second argument of a function, after a literal' => [
            'code' => "<?php\n\n\$re = '/after-a-literal/';\nvar_dump('found', preg_match(\$re, \$subject));\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-literal/', 'at' => "'/after-a-literal/'", 'location' => 'via $re'],
        ];

        yield 'preg_replace(), its result assigned' => [
            'code' => "<?php\n\n\$re = '/replaced/';\n\$clean = preg_replace(\$re, 'x', \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/replaced/', 'at' => "'/replaced/'", 'location' => 'via $re'],
        ];

        yield 'preg_match_all(), with its matches' => [
            'code' => "<?php\n\n\$re = '/all-of-them/';\npreg_match_all(\$re, \$subject, \$matches);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/all-of-them/', 'at' => "'/all-of-them/'", 'location' => 'via $re'],
        ];

        yield 'the call in a branch whose condition the file proves' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/in-a-proved-branch/';\nif ('cli' === \$mode) {\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/in-a-proved-branch/', 'at' => "'/in-a-proved-branch/'", 'location' => 'via $re'],
        ];

        yield 'the call after a comparison the file proves, in one condition' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/after-a-comparison/';\nif ('cli' === \$mode && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-comparison/', 'at' => "'/after-a-comparison/'", 'location' => 'via $re'],
        ];

        yield 'the call in a branch whose condition is a variable the file does not prove' => [
            'code' => "<?php\n\n\$re = '/under-a-flag/';\nif (\$flag) {\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/under-a-flag/', 'at' => "'/under-a-flag/'", 'location' => 'via $re'],
        ];

        yield 'the call after the negation of a variable the file does not prove' => [
            'code' => "<?php\n\n\$re = '/after-a-negation/';\nif (!\$debug && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-negation/', 'at' => "'/after-a-negation/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, identical to a string' => [
            'code' => "<?php\n\n\$re = '/after-an-identity/';\nif (\$sapi === 'cli' && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-an-identity/', 'at' => "'/after-an-identity/'", 'location' => 'via $re'],
        ];

        yield 'the call in the condition of an elseif' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/in-an-elseif/';\nif ('web' === \$mode) {\n    \$label = 'w';\n} elseif (preg_match(\$re, \$subject)) {\n    \$label = 'c';\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/in-an-elseif/', 'at' => "'/in-an-elseif/'", 'location' => 'via $re'],
        ];

        yield 'the call in an else' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/in-an-else/';\nif ('web' === \$mode) {\n    \$label = 'w';\n} else {\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/in-an-else/', 'at' => "'/in-an-else/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, under &&' => [
            'code' => "<?php\n\n\$re = '/after-a-flag/';\nif (\$flag && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-flag/', 'at' => "'/after-a-flag/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, under ||' => [
            'code' => "<?php\n\n\$re = '/unless-a-flag/';\nif (\$debug || preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/unless-a-flag/', 'at' => "'/unless-a-flag/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, under and' => [
            'code' => "<?php\n\n\$re = '/after-a-flag-and/';\nif (\$flag and preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-flag-and/', 'at' => "'/after-a-flag-and/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, under or' => [
            'code' => "<?php\n\n\$re = '/unless-a-flag-or/';\nif (\$debug or preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/unless-a-flag-or/', 'at' => "'/unless-a-flag-or/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, under xor' => [
            'code' => "<?php\n\n\$re = '/either-a-flag/';\nif (\$flag xor preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/either-a-flag/', 'at' => "'/either-a-flag/'", 'location' => 'via $re'],
        ];

        yield 'the call after two variables the file does not prove' => [
            'code' => "<?php\n\n\$re = '/after-two-flags/';\nif (\$flag && \$debug && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-two-flags/', 'at' => "'/after-two-flags/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, in parentheses' => [
            'code' => "<?php\n\n\$re = '/after-a-flag-in-parentheses/';\nif ((\$flag) && preg_match(\$re, \$subject)) {\n    \$found = true;\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-flag-in-parentheses/', 'at' => "'/after-a-flag-in-parentheses/'", 'location' => 'via $re'],
        ];

        yield 'the call after a variable the file does not prove, their result assigned' => [
            'code' => "<?php\n\n\$re = '/flag-and-result-assigned/';\n\$found = \$flag && preg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/flag-and-result-assigned/', 'at' => "'/flag-and-result-assigned/'", 'location' => 'via $re'],
        ];

        yield 'the call as the body of an if written without braces' => [
            'code' => "<?php\n\n\$re = '/body-without-braces/';\nif (\$flag) preg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/body-without-braces/', 'at' => "'/body-without-braces/'", 'location' => 'via $re'],
        ];

        yield 'the call as the body of an else written without braces' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/else-without-braces/';\nif ('web' === \$mode) \$label = 'w'; else preg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/else-without-braces/', 'at' => "'/else-without-braces/'", 'location' => 'via $re'],
        ];

        yield 'the call in the condition of an else if' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/in-an-else-if/';\nif ('web' === \$mode) {\n    \$label = 'w';\n} else if (preg_match(\$re, \$subject)) {\n    \$label = 'c';\n}\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/in-an-else-if/', 'at' => "'/in-an-else-if/'", 'location' => 'via $re'],
        ];

        // The arguments are evaluated in the order they are written: the
        // named one comes after the read.
        yield 'the call before an argument named by a keyword' => [
            'code' => "<?php\n\n\$re = '/before-a-named-argument/';\nrender(preg_match(\$re, \$subject), class: 'btn');\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/before-a-named-argument/', 'at' => "'/before-a-named-argument/'", 'location' => 'via $re'],
        ];

        // "class" and "function" name arguments here, and "function" an
        // import: none declares anything, and each stands before the
        // assignment.
        yield 'a call naming its arguments by keywords, before the assignment' => [
            'code' => "<?php\n\nrender(class: 'btn', function: 'on');\n\$re = '/after-named-arguments/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-named-arguments/', 'at' => "'/after-named-arguments/'", 'location' => 'via $re'],
        ];

        // The declaration is a statement of its own, "&" or not: the
        // assignment after it proves its variable a string, which the
        // value joined between is made of.
        yield 'a string assigned after a function returning a reference, joined between' => [
            'code' => "<?php\n\nfunction &helper(array &\$items): array\n{\n    return \$items;\n}\n\$label = 'a';\n\$re = '/after-a-reference-function/';\n\$title = \$label . 'y';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-reference-function/', 'at' => "'/after-a-reference-function/'", 'location' => 'via $re'],
        ];

        yield 'a function imported in a grouped use, before the assignment' => [
            'code' => "<?php\n\nuse App\\{function helper, Kernel};\n\n\$re = '/after-a-grouped-use/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'pattern' => ['pattern' => '/after-a-grouped-use/', 'at' => "'/after-a-grouped-use/'", 'location' => 'via $re'],
        ];
    }

    /**
     * @param array{pattern: string, at: string, location: string|null} $pattern
     */
    #[Test]
    #[DataProvider('provideFilesStillRead')]
    public function test_a_file_that_holds_nothing_off_the_list_between_the_assignment_and_the_read_is_still_read(string $code, string $argument, array $pattern): void
    {
        $project = self::project($code);

        $this->assertExtractedByBoth([$pattern], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'at' => $argument.', '],
        ], $project);
    }

    /**
     * A variable a pattern is read through, joined to a string in a
     * loop written after its assignment: a later turn of the loop
     * stands between the two, so the file does not prove it there. The
     * loop stands before the assignment of $re, and nothing stands
     * between that assignment and its read.
     *
     * PHP 8.4: the calls are given "/after-the-loop/" then "/^abc$/".
     * The second is left unread: a loop and a call stand between the
     * assignment of $part and it.
     *
     * @return iterable<string, array{loop: string}>
     */
    public static function provideLoopsJoiningAVariableBeforeTheAssignment(): iterable
    {
        yield 'a foreach' => ['loop' => "foreach (\$argv as \$line) {\n    \$label = \$part . 'y';\n}"];
        yield 'a while' => ['loop' => "while (\$argc > 5) {\n    \$label = \$part . 'y';\n}"];
        yield 'a loop in a loop' => ['loop' => "foreach (\$argv as \$first) {\n    foreach (\$argv as \$line) {\n        \$label = \$part . 'y';\n    }\n}"];
    }

    #[Test]
    #[DataProvider('provideLoopsJoiningAVariableBeforeTheAssignment')]
    public function test_a_variable_joined_in_a_loop_before_the_assignment_leaves_the_read_after_it_read(string $loop): void
    {
        $project = self::project("<?php\n\n\$part = 'abc';\n".$loop."\n\$re = '/after-the-loop/';\npreg_match(\$re, \$subject);\npreg_match('/^' . \$part . '\$/', \$other);\n");

        $this->assertExtractedByBoth([
            ['pattern' => '/after-the-loop/', 'at' => "'/after-the-loop/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '\$/'", 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * The assignment stands in a loop and the read after it: the loop
     * may not turn at all, and the variable then holds what it held
     * before the loop, which the file did not write. The end of a loop
     * is not on the list, whatever its body holds: a string whose
     * braces and quotes stand inside those of another string closes no
     * block.
     *
     * PHP 8.4, the loop given an empty array: preg_match() is given
     * what $re held before the loop.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideBodiesOfALoopAssigningTheVariable(): iterable
    {
        yield 'the assignment alone' => ['body' => "\$re = '/in-the-turn/';"];
        yield 'a string of the list, then the assignment' => ['body' => "\$label = 'a {b} c';\n        \$re = '/in-the-turn/';"];
        yield 'an interpolated string, then the assignment' => ['body' => "\$label = \"x{\$object['key']}y\";\n        \$re = '/in-the-turn/';"];
        yield 'a string interpolating under an interpolated key, then the assignment' => ['body' => "\$label = \"{\$object[\"x{\$object['key']}y\"]}\";\n        \$re = '/in-the-turn/';"];
        yield 'a string interpolating a property named in braces, then the assignment' => ['body' => "\$label = \"{\$object->{'a'}[\"x{\$object['key']}y\"]}\";\n        \$re = '/in-the-turn/';"];
    }

    #[Test]
    #[DataProvider('provideBodiesOfALoopAssigningTheVariable')]
    public function test_the_end_of_a_loop_between_the_assignment_and_the_read_leaves_the_variable_unread(string $body): void
    {
        $project = self::project("<?php\n\nif (\$argc === 1) {\n    foreach (\$argv as \$line) {\n        ".$body."\n    }\n    preg_match(\$re, \$subject);\n}\n");

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * The assignment and the read are two arguments of one call, and a
     * property of the object is read in an argument written between
     * them. The call is written in the braces of an interpolated
     * string, in those of a heredoc, or after an argument a keyword
     * names: what stands between the assignment and the read is judged
     * there as anywhere else.
     *
     * PHP 8.4: the arguments are evaluated in the order they are
     * written, __get() runs after the assignment, and preg_match() is
     * given "/(__get/".
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideAssignmentsAndReadsWrittenInOneCall(): iterable
    {
        yield 'a call in the braces of an interpolated string' => ['code' => "<?php\n\n\$label = \"x {\$object->info(\$re = '/file-level/', \$object->name, preg_match(\$re, \$subject))}\";\n"];
        yield 'a call in the braces of a heredoc' => ['code' => "<?php\n\n\$label = <<<TXT\n    {\$object->info(\$re = '/file-level/', \$object->name, preg_match(\$re, \$subject))}\n    TXT;\n"];
        yield 'a call whose first argument is named by a keyword' => ['code' => "<?php\n\nrender(class: \$re = '/file-level/', other: \$object->name, found: preg_match(\$re, \$subject));\n"];
        yield 'a call whose arguments are named' => ['code' => "<?php\n\nrender(label: \$re = '/file-level/', other: \$object->name, found: preg_match(\$re, \$subject));\n"];
        yield 'a call' => ['code' => "<?php\n\nrender(\$re = '/file-level/', \$object->name, preg_match(\$re, \$subject));\n"];
    }

    #[Test]
    #[DataProvider('provideAssignmentsAndReadsWrittenInOneCall')]
    public function test_a_property_read_between_an_assignment_and_a_read_of_one_call_leaves_the_variable_unread(string $code): void
    {
        $project = self::project($code);

        $this->assertExtractedByBoth([], $project);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $project);
    }

    /**
     * page.php, where the variable is the whole argument.
     */
    private static function whole(string $between): string
    {
        return "<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n";
    }

    /**
     * page.php, where the variable is an operand written after a literal.
     */
    private static function operand(string $between): string
    {
        return "<?php\n\n\$re = 'file-level';\n".$between."\npreg_match('/^' . \$re . '$/', \$subject);\n";
    }

    /**
     * The files of a case, part.php being the one page.php includes in
     * some; the positions are read in page.php.
     *
     * @return array<string, string>
     */
    private static function project(string $page): array
    {
        return ['page.php' => $page, 'bootstrap.php' => self::BOOTSTRAP, 'part.php' => "<?php\n\n\$re = '/(part.php/';\n"];
    }
}
