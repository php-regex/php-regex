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
 * A closure or an arrow function may be given another class: self:: and
 * static:: in it name no class the file can know, unless nothing but PHP
 * ever holds the closure. That is the case of a closure written directly
 * as an argument of one of the functions of PHP that call it and let it
 * go: array_map(), array_filter(), usort(), preg_replace_callback(),
 * call_user_func() and the others listed below. There, self::X reads the
 * class around it, and static::X too where the file already proves it, in
 * a final class or for a final constant. Every closure between the read
 * and the class must stand so.
 *
 * The function is the one of PHP: a name the file declares in the
 * namespace of the call, or imports with "use function", is another
 * function, which may bind what it is given.
 *
 * PHP 8.4 (php -r), final class A { const RE = '/a/'; } and final class B
 * { const RE = '/(/'; }: a closure of A reading self::RE gives "/(/" after
 * Closure::bind($f, new B, B::class), after bindTo(null, B::class) on a
 * static closure, for an arrow function bound the same way, and through
 * $f->call(new B); bound to an object alone, bindTo(new B), it still
 * gives "/a/". Written in array_map(), array_filter(), \array_map(),
 * ARRAY_MAP(), call_user_func() or preg_replace_callback(), it gives
 * "/a/". Handed to a function of the file, to a method, or assigned
 * first, it gives "/(/" once that code binds it; so does a closure
 * written in array_map() inside a closure handed away, and so does one
 * written in a function named array_map that the namespace declares.
 *
 * Every case runs through both strategies.
 */
final class ClosurePassedToPhpFunctionTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The functions whose closure keeps its class, each in a call PHP
     * accepts, "%s" standing for the closure.
     */
    private const CALLS = [
        'array_map' => 'array_map(%s, $xs)',
        'array_filter' => 'array_filter($xs, %s)',
        'array_reduce' => 'array_reduce($xs, %s, 0)',
        'array_walk' => 'array_walk($xs, %s)',
        'array_walk_recursive' => 'array_walk_recursive($xs, %s)',
        'array_find' => 'array_find($xs, %s)',
        'array_find_key' => 'array_find_key($xs, %s)',
        'array_any' => 'array_any($xs, %s)',
        'array_all' => 'array_all($xs, %s)',
        'usort' => 'usort($xs, %s)',
        'uasort' => 'uasort($xs, %s)',
        'uksort' => 'uksort($xs, %s)',
        'array_udiff' => 'array_udiff($xs, $ys, %s)',
        'array_udiff_assoc' => 'array_udiff_assoc($xs, $ys, %s)',
        'array_udiff_uassoc' => "array_udiff_uassoc(\$xs, \$ys, %s, 'strcmp')",
        'array_uintersect' => 'array_uintersect($xs, $ys, %s)',
        'array_uintersect_assoc' => 'array_uintersect_assoc($xs, $ys, %s)',
        'array_uintersect_uassoc' => "array_uintersect_uassoc(\$xs, \$ys, %s, 'strcmp')",
        'array_diff_ukey' => 'array_diff_ukey($xs, $ys, %s)',
        'array_diff_uassoc' => 'array_diff_uassoc($xs, $ys, %s)',
        'array_intersect_ukey' => 'array_intersect_ukey($xs, $ys, %s)',
        'array_intersect_uassoc' => 'array_intersect_uassoc($xs, $ys, %s)',
        'call_user_func' => 'call_user_func(%s, $s)',
        'call_user_func_array' => 'call_user_func_array(%s, [$s])',
        'iterator_apply' => 'iterator_apply($rows, %s, [$s])',
    ];

    /**
     * @return iterable<string, array{read: string}>
     */
    public static function provideClosuresWrittenInTheCall(): iterable
    {
        yield 'a closure in array_filter()' => ['read' => 'return array_filter($xs, function ($x) { return preg_match(self::RE, $x); });'];
        yield 'a static closure in array_filter()' => ['read' => 'return array_filter($xs, static function ($x) { return preg_match(self::RE, $x); });'];
        yield 'an arrow function in array_map()' => ['read' => 'return array_map(fn ($x) => preg_match(self::RE, $x), $xs);'];
        yield 'a static arrow function in array_map()' => ['read' => 'return array_map(static fn ($x): int => (int) preg_match(self::RE, $x), $xs);'];
        yield 'a fully qualified array_map()' => ['read' => 'return \\array_map(fn ($x) => preg_match(self::RE, $x), $xs);'];
        // PHP compares function names without case.
        yield 'array_map() spelled in upper case' => ['read' => 'return ARRAY_MAP(fn ($x) => preg_match(self::RE, $x), $xs);'];
        yield 'array_filter() spelled in mixed case' => ['read' => 'return Array_Filter($xs, fn ($x) => preg_match(self::RE, $x));'];
        yield 'a closure capturing a variable' => ['read' => 'return array_map(function ($x) use ($s) { return preg_match(self::RE, $x . $s); }, $xs);'];
        yield 'a closure passed by name' => ['read' => 'return array_map(callback: fn ($x) => preg_match(self::RE, $x), array: $xs);'];
        yield 'a call inside another call' => ['read' => 'return \\count(array_filter($xs, static fn (string $x): bool => 1 === preg_match(self::RE, $x)));'];
        yield 'a call over several lines' => ['read' => "return array_map(\n            static function (string \$x): int {\n                return (int) preg_match(self::RE, \$x);\n            },\n            \$xs,\n        );"];
        yield 'an arrow function in an arrow function, both written in a call' => ['read' => 'return array_map(fn ($x) => array_filter($x, fn ($y) => preg_match(self::RE, $y)), $xs);'];
        yield 'a closure in a closure, both written in a call' => ['read' => 'return array_map(function ($x) { return array_filter($x, function ($y) { return preg_match(self::RE, $y); }); }, $xs);'];
        yield 'three closures, all written in a call' => ['read' => 'return array_map(fn ($x) => array_map(fn ($y) => array_filter($y, fn ($z) => preg_match(self::RE, $z)), $x), $xs);'];
    }

    #[Test]
    #[DataProvider('provideClosuresWrittenInTheCall')]
    public function test_self_in_a_closure_written_in_a_call_of_a_php_function_reads_the_class(string $read): void
    {
        $code = self::matcher($read);

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, ', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{call: string}>
     */
    public static function provideFunctionsThatKeepTheClass(): iterable
    {
        foreach (self::CALLS as $function => $call) {
            yield $function.'()' => ['call' => $call];
        }
    }

    #[Test]
    #[DataProvider('provideFunctionsThatKeepTheClass')]
    public function test_each_function_of_the_list_keeps_the_class_of_its_closure(string $call): void
    {
        $code = self::matcher('return '.\sprintf($call, 'static fn (mixed ...$args): int => (int) preg_match(self::RE, (string) $args[0])').';');

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, ', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * preg_replace_callback() is one of them: the pattern it is given and
     * the one its closure reads are two arguments.
     */
    #[Test]
    public function test_preg_replace_callback_keeps_the_class_of_its_closure(): void
    {
        $code = self::matcher("return preg_replace_callback('/outer/', static fn (array \$m): string => (string) preg_match(self::RE, \$m[0]), \$s);");

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
            ['pattern' => '/outer/', 'at' => "'/outer/'", 'source' => 'preg_replace_callback()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/outer/'", 'source' => 'preg_replace_callback()'],
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, ', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * static:: is read where the file proves it out of a closure: in a
     * final class, or for a final constant. self:: names the class it is
     * written in, final or not. static:: on a constant a child class may
     * replace stays unread.
     *
     * PHP 8.4: in a final class, array_map(fn ($x) => static::RE, $xs)
     * gives the constant of the class.
     */
    #[Test]
    public function test_static_in_such_a_closure_is_read_where_the_file_proves_it(): void
    {
        $code = <<<'PHP'
            <?php

            final class Closed
            {
                const RE = '/closed/';

                public function run(array $xs): array
                {
                    return array_map(fn ($x) => preg_match(static::RE, $x), $xs);
                }
            }

            class Open
            {
                const RE = '/open/';
                final public const FIXED = '/fixed/';
                const REPLACED = '/a-child-may-replace-it/';

                public function run(array $xs): array
                {
                    return [
                        array_map(fn ($a) => preg_match(self::RE, $a), $xs),
                        array_map(fn ($b) => preg_match(static::FIXED, $b), $xs),
                        array_map(fn ($c) => preg_match(static::REPLACED, $c), $xs),
                    ];
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/closed/', 'at' => "'/closed/'", 'location' => 'via static::RE'],
            ['pattern' => '/open/', 'at' => "'/open/'", 'location' => 'via self::RE'],
            ['pattern' => '/fixed/', 'at' => "'/fixed/'", 'location' => 'via static::FIXED'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'static::RE', 'at' => 'static::RE, $x)'],
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $a)'],
            ['state' => 'constant', 'expression' => 'static::FIXED', 'at' => 'static::FIXED, $b)'],
            ['state' => 'opaque', 'expression' => 'static::REPLACED', 'at' => 'static::REPLACED, $c)'],
        ], $code);
    }

    /**
     * A closure some other code holds may be bound there: assigned
     * first, handed to a method, to a function of the file or of another
     * namespace, to a constructor, or to a function of PHP that keeps it
     * to call it later. One closure so held between the read and the
     * class is enough, whatever the others are. $this-> is not read in a
     * closure at all.
     *
     * @return iterable<string, array{read: string, expression?: string}>
     */
    public static function provideClosuresThatMayBeBound(): iterable
    {
        yield 'a closure assigned, then passed to array_map()' => ['read' => "\$check = fn (\$x) => preg_match(self::RE, \$x);\n\n        return array_map(\$check, \$xs);"];
        yield 'a closure passed to a method' => ['read' => 'return $this->each($xs, fn ($x) => preg_match(self::RE, $x));'];
        yield 'a closure passed to a method named array_map' => ['read' => 'return $this->array_map(fn ($x) => preg_match(self::RE, $x), $xs);'];
        yield 'a closure passed to a static method' => ['read' => 'return Collection::map($xs, fn ($x) => preg_match(self::RE, $x));'];
        yield 'a closure passed to a function of the project' => ['read' => 'return apply(fn ($x) => preg_match(self::RE, $x), $xs);'];
        yield 'a closure passed to a function of another namespace' => ['read' => 'return \\Vendor\\Arrays\\array_map(fn ($x) => preg_match(self::RE, $x), $xs);'];
        yield 'a closure passed to a constructor' => ['read' => 'return new Runner(fn ($x) => preg_match(self::RE, $x));'];
        yield 'a closure passed to register_shutdown_function()' => ['read' => 'return register_shutdown_function(fn () => preg_match(self::RE, $s));'];
        yield 'a closure passed to spl_autoload_register()' => ['read' => 'return spl_autoload_register(fn ($x) => preg_match(self::RE, $x));'];
        yield 'a closure passed to set_error_handler()' => ['read' => 'return set_error_handler(fn ($no, $x) => (bool) preg_match(self::RE, $x));'];
        yield 'a closure passed to ob_start()' => ['read' => 'return ob_start(fn ($x) => (string) preg_match(self::RE, $x));'];
        yield 'a closure passed to Closure::fromCallable()' => ['read' => 'return \\Closure::fromCallable(fn ($x) => preg_match(self::RE, $x));'];
        yield 'a closure returned, holding one written in a call' => ['read' => 'return fn (array $ys) => array_map(fn ($x) => preg_match(self::RE, $x), $ys);'];
        yield 'a closure passed to a function of the project, holding one written in a call' => ['read' => 'return apply(fn () => array_map(fn ($x) => preg_match(self::RE, $x), $xs));'];
        yield 'a closure assigned, holding one written in a call' => ['read' => "\$outer = function () use (\$xs) {\n            return array_filter(\$xs, fn (\$x) => preg_match(self::RE, \$x));\n        };\n\n        return \$outer();"];
        yield 'a closure written in a call, holding one that is assigned' => ['read' => "return array_map(function (\$x) {\n            \$inner = fn () => preg_match(self::RE, \$x);\n\n            return \$inner();\n        }, \$xs);"];
        yield 'a closure written in a call, holding one passed to a function of the project' => ['read' => 'return array_map(fn ($x) => apply(fn () => preg_match(self::RE, $x)), $xs);'];
        yield '$this-> in a closure written in a call' => ['read' => 'return array_map(fn ($x) => preg_match($this->re, $x), $xs);', 'expression' => '$this->re'];
    }

    #[Test]
    #[DataProvider('provideClosuresThatMayBeBound')]
    public function test_a_closure_some_other_code_holds_names_no_class(string $read, string $expression = 'self::RE'): void
    {
        $code = self::matcher($read);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $expression, 'at' => $expression.', ', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The function is the one of PHP unless the file declares one of that
     * name in the namespace of the call, or imports one with "use
     * function": an unqualified call reaches that one first, and it may
     * bind the closure. A fully qualified call still reaches PHP's.
     *
     * PHP 8.4: with function array_map(callable $f, array $xs) { return
     * [\Closure::bind($f, null, \B::class)('x')]; } declared in the
     * namespace, array_map(fn ($x) => self::RE, ['x']) gives ["/(/"] and
     * \array_map(...) gives ["/a/"].
     *
     * @return iterable<string, array{header: string, footer: string}>
     */
    public static function provideFilesThatShadowTheFunction(): iterable
    {
        yield 'a function declared in the namespace of the call' => [
            'header' => '',
            'footer' => "function array_map(callable \$callback, array \$items): array\n{\n    return [\\Closure::bind(\$callback, null, Other::class)];\n}\n",
        ];

        yield 'a function declared in upper case' => [
            'header' => '',
            'footer' => "function ARRAY_MAP(callable \$callback, array \$items): array\n{\n    return [\\Closure::bind(\$callback, null, Other::class)];\n}\n",
        ];

        yield 'a function imported with use function' => [
            'header' => "use function Vendor\\Arrays\\array_map;\n\n",
            'footer' => '',
        ];

        yield 'a function imported under an alias' => [
            'header' => "use function Vendor\\Arrays\\map as array_map;\n\n",
            'footer' => '',
        ];

        yield 'a function imported in a group' => [
            'header' => "use function Vendor\\Arrays\\{array_map, array_filter};\n\n",
            'footer' => '',
        ];
    }

    #[Test]
    #[DataProvider('provideFilesThatShadowTheFunction')]
    public function test_a_function_the_file_declares_or_imports_is_not_the_one_of_php(string $header, string $footer): void
    {
        $code = "<?php\n\nnamespace App\\Text;\n\n".$header."final class A\n{\n    const RE = '/a/';\n\n    public function f(array \$xs): array\n    {\n        return [\n"
            ."            array_map(fn (\$x) => preg_match(self::RE, \$x), \$xs),\n"
            ."            \\array_map(fn (\$y) => preg_match(self::RE, \$y), \$xs),\n"
            ."        ];\n    }\n}\n\n".$footer;

        // The qualified call alone reads the constant.
        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $x)'],
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $y)'],
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

            namespace Vendor\Arrays {
                function array_map(callable $callback, array $items): array
                {
                    return $items;
                }
            }

            namespace App\Search {
                final class A
                {
                    const RE = '/a/';

                    public function array_filter(array $items): array
                    {
                        return $items;
                    }

                    public function f(array $xs): array
                    {
                        return [
                            array_map(fn ($x) => preg_match(self::RE, $x), $xs),
                            array_filter($xs, fn ($y) => preg_match(self::RE, $y)),
                        ];
                    }
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $x)'],
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $y)'],
        ], $code);
    }

    /**
     * Out of any class, and in a trait, self:: names no class the file
     * knows, in such a closure as anywhere.
     */
    #[Test]
    public function test_self_out_of_a_class_names_none_in_such_a_closure_either(): void
    {
        $code = <<<'PHP'
            <?php

            const RE = '/global/';

            trait Reads
            {
                public function run(array $xs): array
                {
                    return array_map(fn ($a) => preg_match(self::RE, $a), $xs);
                }
            }

            function run(array $xs): array
            {
                return array_map(fn ($b) => preg_match(self::RE, $b), $xs);
            }

            array_map(fn ($c) => preg_match(self::RE, $c), $argv);
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $a)'],
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $b)'],
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $c)'],
        ], $code);
    }

    /**
     * A final class holding RE and the property $re, whose method f()
     * holds the statements.
     */
    private static function matcher(string $read): string
    {
        return "<?php\n\nfinal class A\n{\n    const RE = '/a/';\n    private \$re = '/b/';\n\n    public function f(array \$xs, array \$ys = [], string \$s = '', ?\\Iterator \$rows = null)\n    {\n        ".$read."\n    }\n}\n";
    }
}
