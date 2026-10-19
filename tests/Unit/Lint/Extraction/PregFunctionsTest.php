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

use PHPRegex\Linter\Extraction\PregFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The preg functions taking a pattern, with their parameters as PHP
 * declares them: PHP binds a named argument by these names.
 */
final class PregFunctionsTest extends TestCase
{
    #[Test]
    public function test_the_parameters_are_the_ones_php_declares(): void
    {
        foreach (PregFunctions::PARAMETERS as $function => $parameters) {
            $declared = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new \ReflectionFunction($function))->getParameters(),
            );

            $this->assertSame($declared, $parameters, $function);
        }
    }

    #[Test]
    public function test_every_preg_function_taking_a_pattern_is_listed(): void
    {
        $functions = array_keys(PregFunctions::PARAMETERS);
        sort($functions);

        $this->assertSame(
            ['preg_filter', 'preg_grep', 'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_replace_callback_array', 'preg_split'],
            $functions,
        );
    }

    /**
     * preg_replace_callback_array() takes an array too, its keys the
     * patterns: no list of patterns.
     */
    #[Test]
    public function test_the_pattern_lists_are_the_functions_php_lets_take_an_array_of_patterns(): void
    {
        foreach (array_keys(PregFunctions::PARAMETERS) as $function) {
            if ('preg_replace_callback_array' === $function) {
                continue;
            }

            $type = (string) (new \ReflectionFunction($function))->getParameters()[0]->getType();

            $this->assertSame(str_contains($type, 'array'), \in_array($function, PregFunctions::PATTERN_LISTS, true), $function);
        }
    }

    /**
     * @param array{int, string}|null $expected
     */
    #[Test]
    #[DataProvider('provideSubjects')]
    public function test_subject_names_the_position_and_name_of_the_subject(string $function, ?array $expected): void
    {
        $this->assertSame($expected, PregFunctions::subject($function));
    }

    /**
     * @return iterable<string, array{function: string, expected: array{int, string}|null}>
     */
    public static function provideSubjects(): iterable
    {
        yield 'preg_match' => ['function' => 'preg_match', 'expected' => [1, 'subject']];
        yield 'preg_match_all' => ['function' => 'preg_match_all', 'expected' => [1, 'subject']];
        yield 'preg_replace' => ['function' => 'preg_replace', 'expected' => [2, 'subject']];
        yield 'preg_replace_callback' => ['function' => 'preg_replace_callback', 'expected' => [2, 'subject']];
        yield 'preg_replace_callback_array' => ['function' => 'preg_replace_callback_array', 'expected' => [1, 'subject']];
        yield 'preg_split' => ['function' => 'preg_split', 'expected' => [1, 'subject']];
        yield 'preg_grep reads an array' => ['function' => 'preg_grep', 'expected' => [1, 'array']];
        yield 'preg_filter' => ['function' => 'preg_filter', 'expected' => [2, 'subject']];
        yield 'preg_quote takes no pattern' => ['function' => 'preg_quote', 'expected' => null];
        yield 'another function' => ['function' => 'strlen', 'expected' => null];
    }
}
