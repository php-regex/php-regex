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
 * A closure written as the callback of a function of PHP that calls it
 * and lets it go keeps the class it is written in: self:: names that
 * class there. It is that callback however it is spaced, whether it
 * returns by reference or not, and with a comma after the last argument,
 * which adds no argument. It is not when the argument is more than the
 * closure, when a spread follows it, whose items take the last places,
 * or when it is itself spread.
 *
 * PHP 8.4: array_udiff([1, 2], [2], fn ($x, $y) => $x <=> $y,) is [1];
 * array_udiff_uassoc([1, 2], fn ($a, $b) => $a <=> $b, ...['strcmp'])
 * calls the closure as its value callback, and with ...[[2], 'strcmp']
 * refuses it as an array; array_map(...fn ($x) => $x) throws "Only arrays
 * and Traversables can be unpacked".
 *
 * Every case runs through both strategies.
 */
final class CallbackClosureSpellingTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const READ = 'preg_match(self::RE, $x)';

    /**
     * @return iterable<string, array{call: string}>
     */
    public static function provideCallbacksPhpCalls(): iterable
    {
        yield 'no space after function' => ['call' => 'array_map(function($x) { return '.self::READ.'; }, $xs)'];
        yield 'no space before the body' => ['call' => 'array_map(function ($x){ return '.self::READ.'; }, $xs)'];
        yield 'no space between the use list and the body' => ['call' => 'array_map(function ($x) use ($s){ return '.self::READ.'; }, $xs)'];
        yield 'no space between the return type and the body' => ['call' => 'array_map(function ($x): int{ return (int) '.self::READ.'; }, $xs)'];
        yield 'no space around the return type' => ['call' => 'array_map(function ($x):int{ return (int) '.self::READ.'; }, $xs)'];
        yield 'a closure returning by reference' => ['call' => 'array_map(function &($x) { $found = '.self::READ.'; return $found; }, $xs)'];
        yield 'a static arrow function with no space before its parameters' => ['call' => 'array_map(static fn($x) => '.self::READ.', $xs)'];
        yield 'a comma after the last callback' => ['call' => 'array_udiff($xs, $ys, fn ($x, $y) => '.self::READ.',)'];
        yield 'a comma after the last of two callbacks' => ['call' => 'array_udiff_uassoc($xs, $ys, fn ($x, $y) => '.self::READ.", 'strcmp',)"];
        yield 'a comma after the array of array_map()' => ['call' => 'array_map(fn ($x) => '.self::READ.', $xs,)'];
    }

    #[Test]
    #[DataProvider('provideCallbacksPhpCalls')]
    public function test_a_callback_php_calls_keeps_its_class(string $call): void
    {
        $code = self::method($call);

        $this->assertExtractedByBoth([
            ['pattern' => '/const/', 'at' => "'/const/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $x)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{call: string}>
     */
    public static function provideClosuresNoCallIsKnownToKeepUnbound(): iterable
    {
        // The items of $ys take the last places: the closure is the value
        // callback when $ys holds one item, an array when it holds two.
        yield 'a closure followed by a spread' => ['call' => 'array_udiff_uassoc($xs, fn ($x, $y) => '.self::READ.', ...$ys)'];
        yield 'an arrow function that is spread' => ['call' => 'array_map(...fn ($x) => '.self::READ.')'];
        // The closure when it is not null, which it never is: more than
        // the closure is written there all the same.
        yield 'a closure joined to a fallback' => ['call' => 'array_map(function ($x) { return '.self::READ."; } ?? 'trim', \$xs)"];
    }

    #[Test]
    #[DataProvider('provideClosuresNoCallIsKnownToKeepUnbound')]
    public function test_a_closure_that_is_not_the_callback_alone_names_no_class(string $call): void
    {
        $code = self::method($call);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $x)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The closure holds the declaration of the class, not the code of
     * its methods: binding it gives the methods no other class.
     */
    #[Test]
    public function test_a_class_declared_in_a_closure_reads_its_own_constants(): void
    {
        $code = <<<'PHP'
            <?php

            $declare = function (): void {
                final class Matcher
                {
                    private const RE = '/const/';

                    public function all(array $xs): array
                    {
                        return array_map(fn (string $x): int => (int) preg_match(self::RE, $x), $xs);
                    }
                }
            };
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/const/', 'at' => "'/const/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $x)', 'source' => 'preg_match()'],
        ], $code);
    }

    private static function method(string $call): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const RE = '/const/';\n\n    public function run(string \$s, array \$xs, array \$ys): mixed\n    {\n        return ".$call.";\n    }\n}\n";
    }
}
