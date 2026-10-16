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
 * What a file proves about its names, as the syntax tree shows it: the
 * scopes a variable lives in, the ways a variable, a property or a constant
 * is written, and the shapes that prove nothing.
 *
 * Every case runs through both strategies: the tokenizer must read the
 * same patterns as nikic/php-parser.
 */
final class AstValueCollectorTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * A function no rule of the case touches: the resolution runs, and only
     * the case at hand is left out.
     */
    private const CONTROL = <<<'PHP'

        function control_resolution(string $s): int
        {
            $control = '/control/';

            return preg_match($control, $s);
        }

        PHP;

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideUnprovenVariables(): iterable
    {
        // static keeps the value of the last call: the default is no
        // assignment, and the variable may hold it at the call.
        yield 'a static variable' => ['code' => <<<'PHP'
            function run(string $s): int
            {
                static $re = '/static-default/';
                $result = preg_match($re, $s);
                $re = '/assigned/';

                return $result;
            }
            PHP];

        yield 'one assignment is a call' => ['code' => <<<'PHP'
            function run(string $s, bool $load): int
            {
                $re = '/maybe/';
                if ($load) {
                    $re = load_pattern();
                }

                return preg_match($re, $s);
            }
            PHP];

        yield 'one assignment is a variable variable' => ['code' => <<<'PHP'
            function run(string $name, string $s): int
            {
                $re = $$name;

                return preg_match($re, $s);
            }
            PHP];

        // A closure's use() copies the variable of the body around it: its
        // value comes from there.
        yield 'a variable a closure imports' => ['code' => <<<'PHP'
            function run(string $s): int
            {
                $re = '/outer/';
                $match = function (string $s) use ($re): int {
                    if ('' === $s) {
                        $re = '/inner/';
                    }

                    return preg_match($re, $s);
                };

                return $match($s);
            }
            PHP];

        // In a namespace, extract() is an unqualified name: PHP's own
        // function, unless the namespace declares one.
        yield 'extract() in the body' => ['code' => <<<'PHP'
            namespace App;

            function run(array $data, string $s): int
            {
                $re = '/before-extract/';
                extract($data);

                return preg_match($re, $s);
            }
            PHP];

        // self::{$name} is PHP 8.3's dynamic class constant fetch.
        yield 'a dynamic class constant' => ['code' => <<<'PHP'
            final class Matcher
            {
                private const RE = '/dynamic-fetch/';

                public function run(string $name, string $s): int
                {
                    return preg_match(self::{$name}, $s);
                }
            }
            PHP];

        yield 'a call that is no define()' => ['code' => <<<'PHP'
            register('OTHER_RE', '/not-a-define/');
            preg_match(OTHER_RE, $s);
            PHP];
    }

    #[Test]
    #[DataProvider('provideUnprovenVariables')]
    public function test_a_name_the_file_does_not_prove_is_not_read(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'", 'location' => 'via $control'],
        ], "<?php\n\n".$code."\n".self::CONTROL);
    }

    /**
     * An anonymous class closes before the variable: the variable still
     * belongs to the function body around it.
     */
    #[Test]
    public function test_a_variable_after_an_anonymous_class_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/after-anonymous-class/', 'at' => "'/after-anonymous-class/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php

            function run(string $s): int
            {
                $handler = new class {};
                $re = '/after-anonymous-class/';

                return preg_match($re, $s);
            }
            PHP);
    }

    /**
     * An array literal is passed by value: the call cannot write the
     * variables it holds.
     */
    #[Test]
    public function test_a_variable_passed_inside_an_array_literal_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/passed-in-array/', 'at' => "'/passed-in-array/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php

            function run(string $s): int
            {
                $re = '/passed-in-array/';
                log_all([$re]);

                return preg_match($re, $s);
            }
            PHP);
    }

    /**
     * Writing into what a call returns writes none of the variables the
     * call was given by value.
     */
    #[Test]
    public function test_a_write_into_a_call_result_leaves_its_arguments_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/into-call-result/', 'at' => "'/into-call-result/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php

            function run(string $s): int
            {
                $re = '/into-call-result/';
                first([$re])[0] = 1;

                return preg_match($re, $s);
            }
            PHP);
    }

    #[Test]
    public function test_a_fully_qualified_define_declares_its_constant(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/fully-qualified-define/', 'at' => "'/fully-qualified-define/'", 'location' => 'via ROOT_RE'],
        ], <<<'PHP'
            <?php

            \define('ROOT_RE', '/fully-qualified-define/');
            preg_match(ROOT_RE, $s);
            PHP);
    }

    /**
     * A foreach at the file's top level writes variables of no function
     * body: the file is read all the same.
     */
    #[Test]
    public function test_a_foreach_at_the_top_level_leaves_the_constants_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/top-level/', 'at' => "'/top-level/'", 'location' => 'via RE'],
        ], <<<'PHP'
            <?php

            const RE = '/top-level/';
            foreach ($rows as $key => $row) {
            }
            preg_match(RE, $s);
            PHP);
    }

    /**
     * A property written under a computed name in one class leaves the
     * properties of another class of the file read.
     */
    #[Test]
    public function test_a_computed_property_write_leaves_the_other_classes_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/reader/', 'at' => "'/reader/'", 'location' => 'via $this->re'],
        ], <<<'PHP'
            <?php

            final class Writer
            {
                private string $re = '/writer/';

                public function set(string $name): void
                {
                    $this->$name = 'x';
                }

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }

            final class Reader
            {
                private string $re = '/reader/';

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }
            PHP);
    }
}
