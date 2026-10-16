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
 * Closure::bind(), bindTo() and call() give a closure another class and
 * another $this: in a closure or an arrow function, self::, static:: and
 * $this-> name no class the file can know. PHP 8.4 prints "/(/" for both
 * bindings below.
 */
final class BoundClosureTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{read: string}>
     */
    public static function provideReads(): iterable
    {
        yield 'self:: in a closure' => ['read' => 'return function ($s) { return preg_match(self::RE, $s); };'];
        yield 'static:: in a static closure' => ['read' => 'return static function ($s) { return preg_match(static::RE, $s); };'];
        yield '$this-> in a closure' => ['read' => 'return function ($s) { return preg_match($this->re, $s); };'];
        yield 'self:: in an arrow function' => ['read' => 'return fn ($s) => preg_match(self::RE, $s);'];
        yield '$this-> in an arrow function in a closure' => ['read' => 'return function () { return fn ($s) => preg_match($this->re, $s); };'];
    }

    #[Test]
    #[DataProvider('provideReads')]
    public function test_a_closure_may_be_bound_to_another_class(string $read): void
    {
        $this->assertExtractedByBoth([], self::matcher($read));
    }

    #[Test]
    public function test_a_method_of_an_anonymous_class_in_a_closure_is_no_closure(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'location' => 'via A::RE'],
        ], self::matcher('return function () { return new class { public function m($s) { return preg_match(A::RE, $s); } }; };'));
    }

    #[Test]
    public function test_self_out_of_any_class_names_none(): void
    {
        $this->assertExtractedByBoth([], "<?php\npreg_match(self::RE, \$s);\n");
    }

    #[Test]
    public function test_a_method_reads_its_own_class(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/a/', 'at' => "'/a/'", 'location' => 'via self::RE'],
            ['pattern' => '/b/', 'at' => "'/b/'", 'location' => 'via $this->re'],
        ], self::matcher('return preg_match(self::RE, $s) + preg_match($this->re, $s);'));
    }

    private static function matcher(string $read): string
    {
        return "<?php\nfinal class A\n{\n    const RE = '/a/';\n    private \$re = '/b/';\n\n    public function f(\$s = '')\n    {\n        ".$read."\n    }\n}\n";
    }
}
