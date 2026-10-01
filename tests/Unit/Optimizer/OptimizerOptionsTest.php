<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Optimizer;

use PhpRegex\Optimizer\OptimizerOptions;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The optimizer's options are one typed value. As an array they are keyed in
 * snake_case, as Regex::create() and the Symfony and Laravel configs are: a
 * key it does not know or a value of the wrong type is refused instead of
 * being ignored or cast.
 */
final class OptimizerOptionsTest extends TestCase
{
    #[Test]
    public function test_defaults(): void
    {
        $options = new OptimizerOptions();

        $this->assertTrue($options->digits);
        $this->assertTrue($options->word);
        $this->assertTrue($options->ranges);
        $this->assertTrue($options->canonicalizeCharClasses);
        $this->assertFalse($options->possessive);
        $this->assertFalse($options->factorize);
        $this->assertSame(4, $options->minQuantifierCount);
        $this->assertFalse($options->verifyWithAutomata);
        $this->assertEquals($options, OptimizerOptions::fromArray([]));
    }

    #[Test]
    public function test_from_array_reads_every_key(): void
    {
        $options = OptimizerOptions::fromArray([
            'digits' => false,
            'word' => false,
            'ranges' => false,
            'canonicalize_char_classes' => false,
            'possessive' => true,
            'factorize' => true,
            'min_quantifier_count' => 2,
            'verify_with_automata' => true,
        ]);

        $this->assertEquals(new OptimizerOptions(false, false, false, false, true, true, 2, true), $options);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    #[Test]
    #[DataProvider('provideRefusedOptions')]
    public function test_from_array_refuses_what_it_cannot_read(array $options, string $message): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessage($message);

        OptimizerOptions::fromArray($options);
    }

    /**
     * @return iterable<string, array{options: array<array-key, mixed>, message: string}>
     */
    public static function provideRefusedOptions(): iterable
    {
        yield 'unknown key' => ['options' => ['digit' => true], 'message' => 'Unknown optimizer option "digit"'];
        yield '1.x key for possessive' => ['options' => ['autoPossessify' => true], 'message' => 'Unknown optimizer option "autoPossessify": it is "possessive" since 2.0'];
        yield '1.x key for factorize' => ['options' => ['allowAlternationFactorization' => true], 'message' => 'Unknown optimizer option "allowAlternationFactorization": it is "factorize" since 2.0'];
        yield 'camelCase key' => ['options' => ['minQuantifierCount' => 3], 'message' => 'Unknown optimizer option "minQuantifierCount"'];
        yield 'list key' => ['options' => [true], 'message' => 'Unknown optimizer option "0"'];
        yield 'string for a flag' => ['options' => ['digits' => 'yes'], 'message' => '"digits" must be a bool'];
        yield 'integer for a flag' => ['options' => ['possessive' => 1], 'message' => '"possessive" must be a bool'];
        yield 'string count' => ['options' => ['min_quantifier_count' => '4'], 'message' => '"min_quantifier_count" must be an integer of at least 2'];
        yield 'count below two' => ['options' => ['min_quantifier_count' => 1], 'message' => '"min_quantifier_count" must be an integer of at least 2'];
    }

    #[Test]
    public function test_constructor_refuses_a_count_below_two(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        new OptimizerOptions(minQuantifierCount: 1);
    }

    /**
     * @param array<string, bool|int> $options
     */
    #[Test]
    #[DataProvider('provideOptimizations')]
    public function test_optimize_takes_the_options_as_an_array_or_a_value(string $pattern, array $options, string $optimized): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame($optimized, $regex->optimize($pattern, $options)->optimized);
        $this->assertSame($optimized, $regex->optimize($pattern, OptimizerOptions::fromArray($options))->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, options: array<string, bool|int>, optimized: string}>
     */
    public static function provideOptimizations(): iterable
    {
        yield 'defaults' => ['pattern' => '/[0-9]/', 'options' => [], 'optimized' => '/\\d/'];
        yield 'digits off' => ['pattern' => '/[0-9]/', 'options' => ['digits' => false], 'optimized' => '/[0-9]/'];
        yield 'possessive' => ['pattern' => '/a+b/', 'options' => ['possessive' => true], 'optimized' => '/a++b/'];
        yield 'factorize' => ['pattern' => '/abc|abd/', 'options' => ['factorize' => true], 'optimized' => '/ab(?:c|d)/'];
    }

    #[Test]
    public function test_optimize_refuses_an_unknown_key(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        Regex::create(['cache' => null])->optimize('/a/', ['autoPossessify' => true]);
    }
}
