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

use PHPRegex\Linter\Extraction\PatternFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternFunctionTest extends TestCase
{
    /**
     * @return iterable<string, array{function: PatternFunction, source: string}>
     */
    public static function provideSources(): iterable
    {
        yield 'function' => ['function' => new PatternFunction('preg_match'), 'source' => 'preg_match()'];
        yield 'static method' => ['function' => new PatternFunction('Preg::match'), 'source' => 'Preg::match()'];
        yield 'constructor' => ['function' => new PatternFunction('RegexIterator', 1, kind: PatternFunction::KIND_CONSTRUCTOR), 'source' => 'new RegexIterator()'];
        yield 'attribute' => ['function' => new PatternFunction('Regex', kind: PatternFunction::KIND_ATTRIBUTE), 'source' => '#[Regex]'];
    }

    #[Test]
    #[DataProvider('provideSources')]
    public function test_source_names_the_call_as_the_code_writes_it(PatternFunction $function, string $source): void
    {
        $this->assertSame($source, $function->source());
    }

    /**
     * Each function eachArgument() gives reads one argument: split again,
     * it is itself.
     */
    #[Test]
    public function test_each_argument_gives_functions_reading_one_argument_each(): void
    {
        $function = new PatternFunction('between', 1, moreArgumentIndexes: [2, 3], parameterNames: ['from'], moreParameterNames: [['to']]);

        $split = $function->eachArgument();

        $this->assertSame(
            [[1, ['from'], [], []], [2, ['to'], [], []], [3, ['pattern'], [], []]],
            array_map(static fn (PatternFunction $each): array => [$each->argumentIndex, $each->parameterNames, $each->moreArgumentIndexes, $each->moreParameterNames], $split),
        );
        foreach ($split as $each) {
            $this->assertSame([$each], $each->eachArgument());
        }
    }
}
