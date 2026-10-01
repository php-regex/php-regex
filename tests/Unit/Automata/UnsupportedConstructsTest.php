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

namespace PhpRegex\Tests\Unit\Automata;

use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\LanguageSolver;
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
        yield 'lookahead' => ['/(?=a)a/'];
        yield 'lookbehind' => ['/(?<=a)b/'];
        yield 'backreference' => ['/(a)\\1/'];
        yield 'recursion' => ['/(?R)/'];
        yield 'subroutine' => ['/(a)(?1)/'];
    }
}
