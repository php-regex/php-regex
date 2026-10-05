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

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quantifier becomes possessive only where what follows can never start
 * with a character it gives back. The charset analyzer reads the outer
 * flags only, so an inline flag scope after the quantifier, wherever it
 * stands, keeps it greedy: "(?i)A" takes the "a" that "a+" would give back.
 */
final class InlineFlagsPossessificationTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, possessive: string}>
     */
    public static function provideInlineFlagsAfterTheQuantifier(): iterable
    {
        yield 'inside a script run' => ['pattern' => '/a+(*sr:(?i)A)/', 'possessive' => '/a++(*sr:(?i)A)/'];
        yield 'inside an atomic script run' => ['pattern' => '/a+(*atomic_script_run:(?i)A)/', 'possessive' => '/a++(*atomic_script_run:(?i)A)/'];
        yield 'scoped inside a group' => ['pattern' => '/a+(?:(?i)A)/', 'possessive' => '/a++(?:(?i)A)/'];
        yield 'inside a DEFINE body the call runs' => ['pattern' => '/a+(?(DEFINE)(?<d>(?i)A))(?&d)/', 'possessive' => '/a++(?(DEFINE)(?<d>(?i)A))(?&d)/'];
    }

    #[Test]
    #[DataProvider('provideInlineFlagsAfterTheQuantifier')]
    public function test_a_quantifier_before_an_inline_flag_scope_stays_greedy(string $pattern, string $possessive): void
    {
        // Oracle: the possessive form gives no "a" back to "(?i)A".
        $this->assertSame(1, preg_match($pattern, 'aa', $match));
        $this->assertSame('aa', $match[0]);
        $this->assertSame(0, preg_match($possessive, 'aa'));

        $optimized = Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(autoPossessify: true))->accept(new PatternPrinter());

        $this->assertSame($pattern, $optimized);
    }

    /**
     * The walk itself reaches every container, a DEFINE body and a script
     * run included: through the optimizer the charset analyzer refuses
     * those containers first, so only the walk shows it.
     *
     * @return iterable<string, array{pattern: string, flags: bool}>
     */
    public static function provideContainers(): iterable
    {
        yield 'flag group in a DEFINE body' => ['pattern' => '/(?(DEFINE)(?<d>(?i)x))a/', 'flags' => true];
        yield 'scoped flag group in a DEFINE body' => ['pattern' => '/(?(DEFINE)(?<d>(?i:x)))a/', 'flags' => true];
        yield 'flag group in a script run' => ['pattern' => '/a(*sr:(?i)A)/', 'flags' => true];
        yield 'flag group in a lookahead' => ['pattern' => '/a(?=(?i)A)/', 'flags' => true];
        yield 'DEFINE body without flags' => ['pattern' => '/(?(DEFINE)(?<d>x))a/', 'flags' => false];
        yield 'script run without flags' => ['pattern' => '/a(*sr:A)/', 'flags' => false];
    }

    #[Test]
    #[DataProvider('provideContainers')]
    public function test_contains_inline_flags_walks_every_container(string $pattern, bool $flags): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''));

        $rewriter = new Rewriter();
        $root = Regex::create(['cache' => null])->parse($pattern)->pattern;

        $this->assertSame($flags, (new \ReflectionMethod($rewriter, 'containsInlineFlags'))->invoke($rewriter, $root));
    }
}
