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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A class written in a closure, an anonymous one or one the closure
 * declares when it runs, has a body of its own: once it is closed, the
 * code is in the closure again, and after the closure in the method
 * around it. In the closure, self:: names no class the file knows, for
 * the closure may be bound to another class; in the method, and in a
 * closure a function of PHP only calls, it names the class around.
 *
 * PHP 8.4 (php -r): with "function run(): \Closure { return function ():
 * string { $o = new class {}; return self::RE; }; }" in a class Holder,
 * the closure returns Holder::RE, and Other::RE once it is bound with
 * \Closure::bind($closure, null, Other::class). "function after():
 * string { $f = function (): object { return new class {}; }; return
 * self::RE; }" returns Holder::RE. After "$boot = function (): void {
 * final class Inner { const RE = '/inner/'; public function run(array
 * $xs): array { return array_map(fn ($x) => self::RE, $xs); } } };
 * $boot();", (new Inner)->run([1]) is ['/inner/'].
 *
 * Both strategies read the same.
 */
final class ClassWrittenInAClosureTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_closure_that_holds_an_anonymous_class_may_still_be_bound(): void
    {
        $code = <<<'PHP'
            <?php

            final class Holder
            {
                const RE = '/holder/';

                public function run(): \Closure
                {
                    return function (string $s): int {
                        $before = preg_match(self::RE, 'before');
                        $helper = new class {
                            public function name(): string
                            {
                                return 'helper';
                            }
                        };

                        return $before + preg_match(self::RE, $s);
                    };
                }
            }
            PHP;

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => "self::RE, 'before')"],
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $s)'],
        ], $code);
    }

    #[Test]
    public function test_the_method_around_a_closure_that_holds_an_anonymous_class_reads_its_class(): void
    {
        $code = <<<'PHP'
            <?php

            final class Holder
            {
                const RE = '/holder/';

                public function run(string $s): int
                {
                    $make = function (): object {
                        return new class {};
                    };

                    return preg_match(self::RE, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/holder/', 'at' => "'/holder/'", 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $s)'],
        ], $code);
    }

    /**
     * The class is declared when the closure runs; its method is no part
     * of the closure, and the arrow function array_map() calls there
     * reads the class of the method.
     */
    #[Test]
    public function test_a_class_declared_in_a_closure_reads_its_own_constant(): void
    {
        $code = <<<'PHP'
            <?php

            $boot = function (): void {
                final class Inner
                {
                    const RE = '/inner/';

                    public function run(array $xs): array
                    {
                        return array_map(fn ($x) => preg_match(self::RE, $x), $xs);
                    }
                }
            };
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/inner/', 'at' => "'/inner/'", 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $x)'],
        ], $code);
    }
}
