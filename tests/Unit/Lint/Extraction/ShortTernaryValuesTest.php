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
 * "A ?: B" and "A ?? B" over an A of several values: PHP keeps each value
 * of A it does not pass over, and takes B for each one it does. "?:"
 * passes over '' and '0', wherever they stand among the values of A, and
 * "??" over none of a constant, which is never null. Every value kept is
 * read, and every value of B when one of A is passed over; B is not read
 * when PHP never takes it.
 *
 * PHP 8.4 (php -r): '' ?: 'B' and '0' ?: 'B' are "B", '/x/' ?: 'B' is
 * "/x/", and '' ?? 'B' is "".
 *
 * Every case runs through both strategies.
 */
final class ShortTernaryValuesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The constants of each case: one of two patterns, one that is ''
     * first or last, one that is '0', and their counterparts as parts of
     * a pattern.
     */
    private const CONSTANTS = <<<'PHP'
            private const PAIR = \PHP_INT_SIZE === 8 ? '/wide/' : '/narrow/';
            private const BLANK_FIRST = \PHP_INT_SIZE === 8 ? '' : '/after-blank/';
            private const BLANK_LAST = \PHP_INT_SIZE === 8 ? '/before-blank/' : '';
            private const ZERO_FIRST = \PHP_INT_SIZE === 8 ? '0' : '/after-zero/';
            private const PART = \PHP_INT_SIZE === 8 ? 'wide' : 'narrow';
            private const NO_PART = \PHP_INT_SIZE === 8 ? '' : 'given';
            private const EITHER_PART = self::NO_PART ?: self::PART;
        PHP;

    /**
     * @return iterable<string, array{argument: string, patterns: list<string>}>
     */
    public static function provideFallbacksOverSeveralValues(): iterable
    {
        yield 'no value passed over: the fallback is not read' => [
            'argument' => "self::PAIR ?: '/fallback/'",
            'patterns' => ['/wide/', '/narrow/'],
        ];

        yield 'an empty string before a pattern' => [
            'argument' => "self::BLANK_FIRST ?: '/fallback/'",
            'patterns' => ['/after-blank/', '/fallback/'],
        ];

        yield 'an empty string after a pattern' => [
            'argument' => "self::BLANK_LAST ?: '/fallback/'",
            'patterns' => ['/before-blank/', '/fallback/'],
        ];

        yield 'a zero before a pattern' => [
            'argument' => "self::ZERO_FIRST ?: '/fallback/'",
            'patterns' => ['/after-zero/', '/fallback/'],
        ];

        yield 'a constant under "??" is never null' => [
            'argument' => "self::PAIR ?? '/fallback/'",
            'patterns' => ['/wide/', '/narrow/'],
        ];

        yield 'a ternary written in the argument' => [
            'argument' => "(\$flag ? '' : '/chosen/') ?: '/fallback/'",
            'patterns' => ['/chosen/', '/fallback/'],
        ];

        yield 'a ternary of two patterns written in the argument' => [
            'argument' => "(\$flag ? '/one/' : '/other/') ?: '/fallback/'",
            'patterns' => ['/one/', '/other/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideFallbacksOverSeveralValues')]
    public function test_each_value_php_keeps_is_read_and_the_fallback_for_one_passed_over(string $argument, array $patterns): void
    {
        $code = self::matcher('return preg_match('.$argument.', $s);');

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The same through a variable: it holds each value PHP keeps.
     *
     * @return iterable<string, array{value: string, patterns: list<string>}>
     */
    public static function provideAssignedFallbacksOverSeveralValues(): iterable
    {
        yield 'a zero before a pattern' => [
            'value' => "(\$flag ? '0' : '/chosen/') ?: '/fallback/'",
            'patterns' => ['/chosen/', '/fallback/'],
        ];

        yield 'no value passed over' => [
            'value' => "(\$flag ? '/one/' : '/other/') ?: '/fallback/'",
            'patterns' => ['/one/', '/other/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideAssignedFallbacksOverSeveralValues')]
    public function test_a_variable_assigned_a_fallback_holds_each_value_php_keeps(string $value, array $patterns): void
    {
        $code = self::matcher('$re = '.$value.";\n\n        return preg_match(\$re, \$s);");

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Joined to other texts, the fallback gives a pattern for each value
     * PHP keeps: '/^' . ('' ?: 'wide') . '$/' is "/^wide$/".
     *
     * @return iterable<string, array{argument: string, patterns: list<string>}>
     */
    public static function provideComposedFallbacks(): iterable
    {
        yield 'no value passed over' => [
            'argument' => "'/^' . (self::PART ?: 'fallback') . '$/'",
            'patterns' => ['/^narrow$/', '/^wide$/'],
        ];

        yield 'an empty string before a part' => [
            'argument' => "'/^' . (self::NO_PART ?: 'fallback') . '$/'",
            'patterns' => ['/^fallback$/', '/^given$/'],
        ];

        yield 'a fallback of two values' => [
            'argument' => "'/^' . (self::NO_PART ?: self::PART) . '$/'",
            'patterns' => ['/^given$/', '/^narrow$/', '/^wide$/'],
        ];

        yield 'a fallback of two values, in a constant' => [
            'argument' => "'/^' . self::EITHER_PART . '$/'",
            'patterns' => ['/^given$/', '/^narrow$/', '/^wide$/'],
        ];
    }

    /**
     * @param list<string> $patterns in the order of their bytes: all stand where the argument starts
     */
    #[Test]
    #[DataProvider('provideComposedFallbacks')]
    public function test_a_fallback_joined_to_other_texts_gives_a_pattern_for_each_value_kept(string $argument, array $patterns): void
    {
        $code = self::matcher('return preg_match('.$argument.', $s);');

        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    private static function matcher(string $body): string
    {
        return "<?php\n\nfinal class Matcher\n{\n".self::CONSTANTS."\n\n    public function run(string \$s, bool \$flag): int\n    {\n        ".$body."\n    }\n}\n";
    }
}
