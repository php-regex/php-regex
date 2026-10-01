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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Cache\NullCache;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every kind of condition, written back out.
 *
 * A condition is the one place where a node decides its own parentheses, and
 * twice now a kind has been written back with a set too many — "(?(R)" and
 * then "(?(VERSION>=10.4)". One case per kind, so a third cannot happen
 * quietly.
 */
final class ConditionalRoundTripTest extends TestCase
{
    #[Test]
    #[DataProvider('provideConditionals')]
    public function test_a_conditional_is_written_back_as_it_was_read(string $pattern): void
    {
        $recompiled = Regex::create(['cache' => new NullCache()])
            ->parse($pattern)
            ->accept(new PatternPrinter());

        $this->assertSame($pattern, $recompiled);
    }

    #[Test]
    #[DataProvider('provideConditionals')]
    public function test_what_is_written_back_still_compiles(string $pattern): void
    {
        $recompiled = Regex::create(['cache' => new NullCache()])
            ->parse($pattern)
            ->accept(new PatternPrinter());

        set_error_handler(static fn (): bool => true);
        $compiles = false !== @preg_match($recompiled, '');
        restore_error_handler();

        $this->assertTrue($compiles, \sprintf('PCRE rejects "%s".', $recompiled));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideConditionals(): iterable
    {
        yield 'a numbered group' => ['pattern' => '/(a)(?(1)y|n)/'];
        yield 'a named group' => ['pattern' => '/(?<n>a)(?(n)y|n)/'];
        yield 'a recursion' => ['pattern' => '/(?(R)y|n)/'];
        yield 'a numbered recursion' => ['pattern' => '/(a)(?(R1)y|n)/'];
        yield 'a version, at least' => ['pattern' => '/(?(VERSION>=10.4)y|n)/'];
        yield 'a version, exactly' => ['pattern' => '/(?(VERSION=10.4)y|n)/'];
        yield 'a lookahead' => ['pattern' => '/(?(?=a)y|n)/'];
        yield 'a negative lookahead' => ['pattern' => '/(?(?!a)y|n)/'];
        yield 'a lookbehind' => ['pattern' => '/(?(?<=a)y|n)/'];
        yield 'a negative lookbehind' => ['pattern' => '/(?(?<!a)y|n)/'];
        yield 'a define' => ['pattern' => '/(?(DEFINE)(?<x>a))(?&x)/'];
        yield 'no else branch' => ['pattern' => '/(a)(?(1)y)/'];
    }
}
