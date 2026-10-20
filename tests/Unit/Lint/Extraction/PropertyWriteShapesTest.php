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

use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a property default stops proving a pattern: a write under a name the
 * file does not spell, a reference or a call reaching the property through
 * any receiver. And the expressions that write nothing of it.
 */
final class PropertyWriteShapesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const CONTROL = <<<'PHP'

        function control_resolution(string $s): int
        {
            $control = '/control/';

            return preg_match($control, $s);
        }

        PHP;

    /**
     * @return iterable<string, array{write: string}>
     */
    public static function provideWrites(): iterable
    {
        yield 'a foreach target named by a variable' => ['write' => "foreach (\$values as \$this->\$name) {\n        }"];
        yield 'a destructuring target under a braced name' => ['write' => '[$this->{$name}] = $values;'];
        yield 'a reference through the result of a method' => ['write' => '$alias = &$this->self()->re;'];
        yield 'passed through a static property of a qualified class' => ['write' => 'normalize_pattern(\Some\Holder::$holder->re);'];
        yield 'passed through a variable variable' => ['write' => "normalize_pattern(\${'holder'}->re);"];
        yield 'passed through a parenthesised receiver' => ['write' => 'normalize_pattern(($this)->re);'];

        // Iterating an object by reference writes each property its scope
        // sees, private ones included: PHP 8.4 gives "/(/" for $this->re.
        yield 'a by-reference foreach over $this' => ['write' => "foreach (\$this as &\$value) {\n            \$value = '/(/';\n        }"];
        yield 'a by-reference foreach over an alias of $this, with a key' => ['write' => "\$self = \$this;\n        foreach (\$self as \$key => &\$value) {\n            \$value = '/(/';\n        }"];
        yield 'a by-reference foreach over a variable' => ['write' => "foreach (\$values as &\$value) {\n            \$value = '/(/';\n        }"];
        yield 'a by-reference foreach over a computed property name' => ['write' => 'foreach ($this->$name as &$item) {}'];

        // array_walk() walks an object's properties, private ones included,
        // and the callee of any call may: $this passed is $this written.
        yield 'array_walk() over $this' => ['write' => "array_walk(\$this, static function (&\$value): void {\n            \$value = '/(/';\n        });"];
        yield '$this passed to a function' => ['write' => 'register($this);'];
        yield '$this passed by name to a static method' => ['write' => 'Registry::add(item: $this);'];
        yield '$this passed to a constructor' => ['write' => 'new Holder($this);'];
        yield '$this passed to a method' => ['write' => '$this->self()->accept($this);'];
    }

    #[Test]
    #[DataProvider('provideWrites')]
    public function test_a_property_written_this_way_is_not_read(string $write): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher($write));
    }

    /**
     * @return iterable<string, array{write: string}>
     */
    public static function provideOtherTargets(): iterable
    {
        yield 'a reference to a static property' => ['write' => '$alias = &self::$cache;'];
        yield 'a reference to the result of a method' => ['write' => '$alias = &$this->make();'];
        yield 'a reference to an offset of another property' => ['write' => '$alias = &$this->items[0];'];
        yield 'a by-reference foreach over a literal array' => ['write' => 'foreach ([1, 2] as &$value) {}'];
        yield 'a by-reference foreach over a parenthesised array() literal' => ['write' => 'foreach ((array(1, 2)) as &$value) {}'];
        // A named property iterated by reference is that property written.
        yield 'a by-reference foreach over another property' => ['write' => 'foreach ($this->items as &$item) {}'];
        yield 'a by-reference foreach over another property of another receiver' => ['write' => 'foreach ($this->self()?->items as $key => &$item) {}'];
        yield 'a foreach by value over $this' => ['write' => 'foreach ($this as $key => $value) {}'];
        yield 'another property passed to a call' => ['write' => 'count($this->items);'];
        yield 'a call on $this, $this not passed' => ['write' => '$this->self()->make();'];
    }

    #[Test]
    #[DataProvider('provideOtherTargets')]
    public function test_a_reference_to_something_else_leaves_the_property_read(string $write): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher($write));
    }

    /**
     * A foreach PHP cannot parse, which only the tokenizer reads: one with
     * nothing to iterate, or empty parentheses, may be any expression, and
     * writes every property; one with no "as" names no target and writes
     * none.
     *
     * @return iterable<string, array{write: string, expected: list<string>}>
     */
    public static function provideBrokenForeachs(): iterable
    {
        yield 'nothing to iterate' => ['write' => 'foreach ( as &$value) {}', 'expected' => ['/control/']];
        yield 'empty parentheses to iterate' => ['write' => 'foreach (() as &$value) {}', 'expected' => ['/control/']];
        yield 'no "as"' => ['write' => 'foreach ($this) {}', 'expected' => ['/property/', '/control/']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideBrokenForeachs')]
    public function test_a_foreach_php_cannot_parse_is_read_as_the_tokens_say(string $write, array $expected): void
    {
        $directory = $this->makeProject(['broken.php' => self::matcher($write)]);
        $patterns = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))->extract([$directory.'/broken.php']),
        );

        $this->assertSame($expected, $patterns);
    }

    /**
     * A closure out of any class may be bound to any of them: a write under
     * a computed name there stops every property of the file.
     */
    #[Test]
    public function test_a_computed_write_out_of_any_class_stops_every_property(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher('').<<<'PHP'

            $writer = function (string $name, string $value): void {
                $this->$name = $value;
            };

            PHP);
    }

    /**
     * array_walk() and array_walk_recursive() over an object write each of
     * its properties, private ones included, from any scope: PHP 8.4 gives
     * "/(/" for $this->re in both cases. Over anything but a literal array
     * they stop every property of the file.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideArrayWalks(): iterable
    {
        yield 'array_walk() over an alias of $this' => ['code' => "\$self = \$this;\n        array_walk(\$self, function (&\$v) { \$v = '/(/'; });"];
        yield 'array_walk() of a spread' => ['code' => '\\array_walk(...$values);'];
        yield 'array_walk() naming its callback only' => ['code' => "array_walk(callback: 'trim');"];
        yield 'array_walk_recursive() over an alias of $this' => ['code' => "\$self = \$this;\n        \\array_walk_recursive(array: \$self, callback: function (&\$v) { \$v = '/(/'; });"];
    }

    #[Test]
    #[DataProvider('provideArrayWalks')]
    public function test_array_walk_over_anything_but_a_literal_array_stops_every_property(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher($code));
    }

    #[Test]
    public function test_array_walk_out_of_any_class_stops_every_property(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher('').<<<'PHP'

            function clobber(Matcher $matcher): void
            {
                array_walk($matcher, function (&$v) { $v = '/(/'; });
            }

            PHP);
    }

    #[Test]
    public function test_array_walk_over_a_literal_array_leaves_the_properties_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher("array_walk([1, 2], function (&\$v) { \$v = 0; });\n        Other\\array_walk(\$values, 'trim');"));
    }

    /**
     * The literal array may be passed under its name, after the callback.
     */
    #[Test]
    public function test_array_walk_over_a_named_literal_array_leaves_the_properties_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher('array_walk(callback: function (&$v) { $v = 0; }, array: [1, 2]);'));
    }

    /**
     * A property iterated by reference is written, through any receiver:
     * its default stops proving a pattern, the other properties' do not.
     */
    #[Test]
    public function test_a_property_iterated_by_reference_is_written_alone(): void
    {
        $matcher = <<<'PHP'
            <?php

            final class Matcher
            {
                private string $re = '/property/';
                private array $items = ['/item/'];

                public function change(self $other): void
                {
                    %s
                }

                public function run(string $s): bool
                {
                    return 1 === preg_match($this->re, $s) && null !== preg_replace($this->items, '', $s);
                }
            }

            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ['pattern' => '/item/', 'at' => "'/item/'", 'location' => 'via $this->items'],
        ], \sprintf($matcher, ''));

        foreach (['foreach ($this->items as &$item) {}', 'foreach ($other->items as $key => &$item) {}'] as $write) {
            $this->assertExtractedByBoth([
                ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ], \sprintf($matcher, $write));
        }
    }

    /**
     * In a trait, $this is whichever class uses it: a by-reference foreach
     * over it, or $this passed out, stops every property of the file. A
     * by-reference foreach out of any class reaches public properties only,
     * which are never read.
     */
    #[Test]
    public function test_a_trait_iterating_this_by_reference_stops_every_property(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher('').<<<'PHP'

            trait Rewrites
            {
                public function rewrite(): void
                {
                    foreach ($this as &$value) {
                        $value = '/(/';
                    }
                }
            }

            PHP);

        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher('').<<<'PHP'

            trait Hands
            {
                public function handOut(): void
                {
                    register($this);
                }
            }

            PHP);

        $this->assertExtractedByBoth([
            ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], self::matcher('').<<<'PHP'

            function rewrite_all(iterable $items): void
            {
                foreach ($items as &$value) {
                    $value = '/(/';
                }
            }

            PHP);
    }

    /**
     * A class the file declares twice, one or the other at run time: which
     * default $this->re holds, the file does not say.
     */
    #[Test]
    public function test_a_property_of_a_class_declared_twice_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP_WRAP'
            <?php

            if (\PHP_OS_FAMILY === 'Windows') {
                final class Matcher
                {
                    private string $re = '/windows/';

                    public function run(string $s): bool
                    {
                        return 1 === preg_match($this->re, $s);
                    }
                }
            } else {
                final class Matcher
                {
                    private string $re = '/elsewhere/';

                    public function run(string $s): bool
                    {
                        return 1 === preg_match($this->re, $s);
                    }
                }
            }

            PHP_WRAP.self::CONTROL);
    }

    /**
     * An empty literal is no pattern.
     */
    #[Test]
    public function test_an_empty_literal_pattern_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\npreg_match('', \$subject);\n".self::CONTROL);
    }

    /**
     * A function marking none of its parameters declares nothing.
     */
    #[Test]
    public function test_the_scan_leaves_out_a_function_marking_no_parameter(): void
    {
        $this->assertSame(['App\marked#1$pattern'], PatternAttributeScanner::scan(<<<'PHP_WRAP'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function plain(string $text) {}
            function marked(string $subject, #[RegexPattern] string $pattern) {}
            PHP_WRAP));
    }

    private static function matcher(string $write): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private static string \$cache = '';\n    private string \$re = '/property/';\n    private array \$items = [];\n\n    public function change(string \$name, array \$values): void\n    {\n        ".$write."\n    }\n\n    public function self(): self\n    {\n        return \$this;\n    }\n\n    public function make(): string\n    {\n        return '';\n    }\n\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(\$this->re, \$s);\n    }\n}\n".self::CONTROL;
    }
}
