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
 * array_udiff() and its kin take any number of arrays, then their
 * callback: the last argument, when every argument is written at its
 * position. The first argument is an array whatever follows it, and
 * behind a spread no position is known. A closure PHP calls keeps the
 * class it is written in, and self::RE is read in it; one written where
 * an array stands is called by no one the file knows.
 *
 * PHP 8.4 (php -r): array_udiff([1, 2, 3], fn ($a, $b) => $a <=> $b) is
 * [1, 2, 3]; array_udiff(fn ($a, $b) => 0) throws "expects at least 2
 * arguments, 1 given"; array_udiff([1], fn ($a, $b) => 0, ...[[1], fn
 * ($a, $b) => 0]) throws "Argument #2 must be of type array, Closure
 * given".
 *
 * Every case runs through both strategies.
 */
final class TrailingCallbackPositionTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const CLOSURE = 'static fn (string $a, string $b): int => preg_match(self::RE, $a . $b)';

    #[Test]
    public function test_the_last_of_two_arguments_is_the_callback(): void
    {
        $code = self::matcher('array_udiff($xs, '.self::CLOSURE.')');

        $this->assertExtractedByBoth([
            ['pattern' => '/in-a-closure/', 'at' => "'/in-a-closure/'", 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $a . $b)'],
        ], $code);
    }

    /**
     * @return iterable<string, array{call: string}>
     */
    public static function provideClosuresWrittenWhereNoCallbackIsKnown(): iterable
    {
        yield 'the only argument, where the first array stands' => ['call' => 'array_udiff('.self::CLOSURE.')'];
        yield 'the first of two arguments, where the first array stands' => ['call' => 'array_udiff_uassoc('.self::CLOSURE.", 'strcmp')"];
        yield 'the second argument, before a spread' => ['call' => 'array_udiff($xs, '.self::CLOSURE.', ...$more)'];
    }

    #[Test]
    #[DataProvider('provideClosuresWrittenWhereNoCallbackIsKnown')]
    public function test_a_closure_written_where_no_callback_is_known_names_no_class(string $call): void
    {
        $code = self::matcher($call);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $a . $b)'],
        ], $code);
    }

    private static function matcher(string $call): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const RE = '/in-a-closure/';\n\n    public function run(array \$xs, array \$more): array\n    {\n        return ".$call.";\n    }\n}\n";
    }
}
