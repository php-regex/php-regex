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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two declarations of one function may mark the same position under two
 * parameter names (two copies of a helper, one renamed): a named argument
 * is read under either, whatever the order the declarations were read in.
 */
final class DeclaredParameterNamesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{specs: list<string>}>
     */
    public static function provideDeclarationOrders(): iterable
    {
        yield 'expression first' => ['specs' => ['App\grep#1$expression', 'App\grep#1$pattern']];
        yield 'pattern first' => ['specs' => ['App\grep#1$pattern', 'App\grep#1$expression']];
    }

    /**
     * @param list<string> $specs
     */
    #[Test]
    #[DataProvider('provideDeclarationOrders')]
    public function test_a_named_argument_is_read_under_either_declared_name(array $specs): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/by-expression/', 'at' => "'/by-expression/'"],
            ['pattern' => '/by-pattern/', 'at' => "'/by-pattern/'"],
            ['pattern' => '/positional/', 'at' => "'/positional/'"],
        ], <<<'PHP'
            <?php

            namespace App;

            grep(lines: [], expression: '/by-expression/');
            grep(lines: [], pattern: '/by-pattern/');
            grep([], '/positional/');
            grep(lines: [], regex: '/no-such-parameter/');
            PHP, PatternFunctionRegistry::native()->withDeclaredFunctions($specs));
    }

    #[Test]
    public function test_the_declaration_order_gives_the_same_function(): void
    {
        $this->assertEquals(
            PatternFunctionRegistry::native()->withDeclaredFunctions(['App\grep#1$expression', 'App\grep#1$pattern'])->lookupFunction('App\grep'),
            PatternFunctionRegistry::native()->withDeclaredFunctions(['App\grep#1$pattern', 'App\grep#1$expression'])->lookupFunction('App\grep'),
        );
    }

    /**
     * The names of a position are a list, sorted, each once.
     */
    #[Test]
    public function test_the_names_of_a_position_are_sorted_and_unique(): void
    {
        $function = PatternFunctionRegistry::native()
            ->withDeclaredFunctions(['App\grep#1$pattern', 'App\grep#1$expression', 'App\grep#1$pattern', 'App\grep#2$subject'])
            ->lookupFunction('App\grep');

        $this->assertInstanceOf(PatternFunction::class, $function);
        $this->assertSame(['expression', 'pattern'], $function->parameterNames);
        $this->assertSame([['subject']], $function->moreParameterNames);
    }
}
