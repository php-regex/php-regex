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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnsupportedConstructsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNonRegularPatterns')]
    public function test_non_regular_patterns_throw_complexity_exception(string $pattern): void
    {
        $solver = new LanguageSolver();

        $this->expectException(ComplexityException::class);

        $solver->intersection($pattern, '/a/');
    }

    public static function provideNonRegularPatterns(): \Generator
    {
        yield 'lookahead inside a lookahead' => ['/(?=a(?=b))a/'];
        yield 'non-atomic lookahead' => ['/(*napla:a)a/'];
        yield 'backreference' => ['/(a)\\1/'];
        yield 'recursion' => ['/(?R)/'];
        yield 'subroutine' => ['/(a)(?1)/'];
    }
}
