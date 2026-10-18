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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The optimizer's options are one typed value. As an array they are keyed in
 * snake_case, as Regex::create() and the Symfony and Laravel configs are: a
 * key it does not know or a value of the wrong type is refused instead of
 * being ignored or cast.
 *
 * @phpstan-import-type OptimizerOptionsArray from OptimizerOptions
 */
final class OptimizerOptionsTest extends TestCase
{
    use PhpUnitForwardCompat;

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
        $this->expectExceptionMessageIsOrContains($message);

        OptimizerOptions::fromArray($options);
    }

    /**
     * @return iterable<string, array{options: array<array-key, mixed>, message: string}>
     */
    public static function provideRefusedOptions(): iterable
    {
        yield 'unknown key' => ['options' => ['digit' => true], 'message' => 'Unknown optimizer option "digit"'];
        yield '1.x key for possessive' => ['options' => ['autoPossessify' => true], 'message' => 'Unknown optimizer option "autoPossessify"; the options are:'];
        yield '1.x key for factorize' => ['options' => ['allowAlternationFactorization' => true], 'message' => 'Unknown optimizer option "allowAlternationFactorization"; the options are:'];
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
     * A 1.x name is refused without a word about what replaced it: the
     * message names the key and lists the options, and stops there.
     */
    #[Test]
    #[DataProvider('provideRenamedKeys')]
    public function test_from_array_names_no_replacement_for_a_1_x_key(string $key): void
    {
        try {
            OptimizerOptions::fromArray([$key => true]);
            $this->fail(\sprintf('"%s" was accepted.', $key));
        } catch (InvalidRegexOptionException $e) {
            $this->assertStringContainsString(\sprintf('Unknown optimizer option "%s"', $key), $e->getMessage());
            $this->assertStringNotContainsString('since 2.0', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/it is "/', $e->getMessage());
        }
    }

    /**
     * The camelCase reader (regex.json, the PHPStan parameters) refuses a
     * 1.x name the same generic way.
     */
    #[Test]
    #[DataProvider('provideRenamedKeys')]
    public function test_from_camel_case_array_names_no_replacement_for_a_1_x_key(string $key): void
    {
        try {
            OptimizerOptions::fromCamelCaseArray([$key => true]);
            $this->fail(\sprintf('"%s" was accepted.', $key));
        } catch (InvalidRegexOptionException $e) {
            $this->assertStringContainsString(\sprintf('Unknown optimizer option "%s"', $key), $e->getMessage());
            $this->assertStringNotContainsString('since 2.0', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/it is "/', $e->getMessage());
        }
    }

    /**
     * The refusal lists the option names, not the constructor parameters
     * the reader maps them to: an old name sees the same list a typo does.
     */
    #[Test]
    public function test_the_refusal_lists_the_option_names(): void
    {
        try {
            OptimizerOptions::fromArray(['autoPossessify' => true]);
            $this->fail('The option was accepted.');
        } catch (InvalidRegexOptionException $e) {
            $this->assertMatchesRegularExpression('/the options are: .*\\bmin_quantifier_count\\b/', $e->getMessage());
            $this->assertStringNotContainsString('minQuantifierCount', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{key: string}>
     */
    public static function provideRenamedKeys(): iterable
    {
        yield '1.x possessive' => ['key' => 'autoPossessify'];
        yield '1.x factorize' => ['key' => 'allowAlternationFactorization'];
    }

    /**
     * @param OptimizerOptionsArray $options
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
     * @return iterable<string, array{pattern: string, options: OptimizerOptionsArray, optimized: string}>
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
