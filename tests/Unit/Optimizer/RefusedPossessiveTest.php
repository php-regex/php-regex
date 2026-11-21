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

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under the possessive option, a rewrite whose possessive quantifier the
 * check of the result cannot prove is written again without the
 * possessive step: the solver refuses every possessive in a pattern that
 * holds a lookaround or "\b", and the other rewrites, the "s" nothing reads
 * dropped or "[0-9]" written "\d", ship on their own.
 */
final class RefusedPossessiveTest extends TestCase
{
    private const SUBJECTS = ['', 'x', 'y', '1x', '1xy', '12x', '12xz', '1y', '1yx', 'a1x', 'x1y', '1x1', '1x1y', '12x34', '12x34y', '1 x', "1x\n", '1X', '1Xy', 'xy1x'];

    #[Test]
    #[DataProvider('provideRefusedPossessives')]
    public function test_the_other_rewrites_ship_without_the_refused_possessive(string $pattern, string $optimized): void
    {
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($optimized, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $pattern, $optimized, $subject));
        }

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertNotContains(Rewriter::POSSESSIVE, $result->changes);
        $this->assertSame((new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern)->optimized, $result->optimized, 'The rewrite is the one made without the possessive option.');
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function provideRefusedPossessives(): iterable
    {
        yield 'a lookahead after the repetition' => ['pattern' => '/\d+x(?!y)/s', 'optimized' => '/\d+x(?!y)/'];
        yield 'a lookahead before the repetition' => ['pattern' => '/(?=x)\d+y/s', 'optimized' => '/(?=x)\d+y/'];
        yield 'a lookbehind before the repetition' => ['pattern' => '/(?<=a)[0-9]+x/s', 'optimized' => '/(?<=a)\d+x/'];
        yield 'a word boundary' => ['pattern' => '/\d+y\b/s', 'optimized' => '/\d+y\b/'];
        yield 'no word boundary' => ['pattern' => '/[0-9]+x\B/', 'optimized' => '/\d+x\B/'];
        yield 'a class written as a shorthand' => ['pattern' => '/[0-9]+x(?!y)/', 'optimized' => '/\d+x(?!y)/'];
        yield 'two repetitions, both left greedy' => ['pattern' => '/[0-9]+x[0-9]+(?!y)/', 'optimized' => '/\d+x\d+(?!y)/'];
        yield 'a caseless pattern' => ['pattern' => '/\d+x(?!y)/is', 'optimized' => '/\d+x(?!y)/i'];
        yield 'an extended pattern, laid out as written' => ['pattern' => '/\d+ x (?!y) /xs', 'optimized' => '/\d+ x (?!y) /x'];
    }

    /**
     * A possessive the check proves ships with the rest, the rewrite made
     * once.
     */
    #[Test]
    public function test_a_proven_possessive_still_ships(): void
    {
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(self::matched('/[0-9]+x/s', $subject), self::matched('/\d++x/', $subject), \sprintf('Oracle: the two differ on "%s".', $subject));
        }

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize('/[0-9]+x/s', new OptimizerOptions(possessive: true));

        $this->assertSame('/\d++x/', $result->optimized);
        $this->assertSame([Rewriter::DIGITS, Rewriter::POSSESSIVE, Rewriter::UNUSED_FLAG], $result->changes);
    }

    /**
     * The rewrite made again goes through the checks the first went
     * through: one PCRE refuses, or the check cannot prove, leaves the
     * pattern as written.
     */
    #[Test]
    public function test_the_rewrite_made_again_is_checked_as_the_first(): void
    {
        // The PCRE2 PHP 8.2 bundles refuses a lookbehind branch of two
        // lengths: the lookbehind factorized, possessive or not.
        $parser = RegexParser::create(['cache' => null, 'php_version' => '8.2']);
        $this->assertTrue($parser->validate('/(?<=abc|abde)[0-9]+x/s')->isValid);
        $this->assertFalse($parser->validate('/(?<=ab(?:c|de))\d+x/')->isValid);

        $this->assertSame('/(?<=abc|abde)[0-9]+x/s', (new Optimizer($parser))->optimize('/(?<=abc|abde)[0-9]+x/s', new OptimizerOptions(possessive: true, factorize: true))->optimized);

        // Past the alignment the in-place proof may fill, the rewrite
        // without the possessive is not proven either.
        $pattern = '/(?<!x)'.str_repeat('\\d', 4).str_repeat('b?c?', 600).str_repeat('[e-g]', 4).'[0-9]+x/';
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));
        $this->assertNotSame($pattern, $optimizer->optimize($pattern, new OptimizerOptions(verifyWithAutomata: false))->optimized, 'Unchecked, the rewrite is made.');

        $this->assertSame($pattern, $optimizer->optimize($pattern, new OptimizerOptions(possessive: true))->optimized);
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}
