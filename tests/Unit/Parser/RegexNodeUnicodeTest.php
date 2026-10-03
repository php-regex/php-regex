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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern reads its subject as UTF-8 under /u, or when it opens with
 * (*UTF), or (*UTF8), which the 8-bit PCRE2 library takes as the same
 * option. Each verdict below is the engine's: "^.$" matches the two bytes
 * of "é" only in UTF mode.
 */
final class RegexNodeUnicodeTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_utf_mode_is_read_as_the_engine_reads_it(string $pattern, bool $unicode): void
    {
        $dot = preg_replace('/a(?=[\/#]\w*$)/', '^.$', $pattern);
        $this->assertIsString($dot);
        $this->assertSame($unicode ? 1 : 0, preg_match($dot, 'é'), $dot);

        $this->assertSame($unicode, RegexParser::create()->parse($pattern)->isUnicode());
    }

    #[Test]
    public function test_the_length_of_a_literal_counts_code_points_after_utf8(): void
    {
        $this->assertSame([1, 1], RegexParser::create()->parse('/(*UTF8)é/')->accept(new LengthRangeCalculator()));
    }

    #[Test]
    public function test_the_solver_reads_utf8_as_the_u_flag(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->equivalent('/(*UTF8)./', '/./u')->isEquivalent);
        $this->assertFalse($solver->equivalent('/(*UTF8)./', '/./')->isEquivalent);
    }

    /**
     * @return iterable<string, array{pattern: string, unicode: bool}>
     */
    public static function providePatterns(): iterable
    {
        yield 'u flag' => ['pattern' => '/a/u', 'unicode' => true];
        yield 'no flag' => ['pattern' => '/a/', 'unicode' => false];
        yield 'utf' => ['pattern' => '/(*UTF)a/', 'unicode' => true];
        yield 'utf8' => ['pattern' => '/(*UTF8)a/', 'unicode' => true];
        yield 'after another option' => ['pattern' => '/(*UCP)(*UTF)a/', 'unicode' => true];
        yield 'after a limit' => ['pattern' => '/(*LIMIT_MATCH=10)(*UTF8)a/', 'unicode' => true];
        yield 'other options only' => ['pattern' => '/(*UCP)a/', 'unicode' => false];
        yield 'other delimiter' => ['pattern' => '#(*UTF)a#', 'unicode' => true];
    }
}
