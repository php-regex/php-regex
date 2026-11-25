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
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a character repeated before a possessive or atomic group that
 * may match nothing possessive, as if the group could not be skipped, and
 * the brackets decide which: "/a+(?:b)?+a/" finds nothing in "aa", where
 * "/a+b?+a/" finds it, with and without the JIT, from PCRE2 10.34 to 10.49.
 * A rewrite that changes those brackets ships only once the automata prove
 * it, whether or not the options ask them to check the others.
 */
final class PossessiveBracketsUnverifiedTest extends TestCase
{
    /**
     * The unchecked rewrite, written here, tells "aa" apart from the
     * pattern; the optimizer leaves the pattern as written.
     */
    #[Test]
    #[DataProvider('provideBracketsTheEngineReads')]
    public function test_a_rewrite_of_brackets_the_engine_reads_is_checked_whatever_the_options(string $pattern, string $unchecked): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.34 and 10.39.
        $this->assertNotSame(preg_match($pattern, 'aa'), preg_match($unchecked, 'aa'));
        $this->assertNotSame(preg_match(PcreInterpreter::pattern($pattern), 'aa'), preg_match(PcreInterpreter::pattern($unchecked), 'aa'));

        $optimized = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(verifyWithAutomata: false))->optimized;

        $this->assertSame(preg_match($pattern, 'aa'), preg_match($optimized, 'aa'), $optimized);
        $this->assertSame(preg_match(PcreInterpreter::pattern($pattern), 'aa'), preg_match(PcreInterpreter::pattern($optimized), 'aa'), $optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, unchecked: string}>
     */
    public static function provideBracketsTheEngineReads(): iterable
    {
        yield 'a group repeated possessively, the group dropped' => ['pattern' => '/a+(?:b)?+a/', 'unchecked' => '/a+b?+a/'];
        yield 'an alternation repeated possessively, made a class' => ['pattern' => '/a+(?:b|c)?+a/', 'unchecked' => '/a+[bc]?+a/'];
        yield 'a class in a group repeated possessively, the group dropped' => ['pattern' => '/a+(?:[b])?+a/', 'unchecked' => '/a+[b]?+a/'];
        yield 'an optional group in an atomic group, the group dropped' => ['pattern' => '/a+(?>(?:b)?)a/', 'unchecked' => '/a+(?>b?)a/'];
        yield 'an empty group in an atomic group, dropped' => ['pattern' => '/a+(?>(?:)|b)a/', 'unchecked' => '/a+(?>|b)a/'];
    }

    /**
     * Where no repeat stands before such a group, the rewrite ships as
     * written: the brackets decide nothing.
     */
    #[Test]
    public function test_a_rewrite_with_no_repeat_before_such_a_group_still_ships(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49.
        foreach (['', 'a', 'ab', 'abc', 'xbc', 'xc'] as $subject) {
            $this->assertSame(preg_match('/x(?:b)?+c/', $subject), preg_match('/xb?+c/', $subject), $subject);
        }

        $this->assertSame('/xb?+c/', (new Optimizer(RegexParser::create(['cache' => null])))->optimize('/x(?:b)?+c/', new OptimizerOptions(verifyWithAutomata: false))->optimized);
    }
}
