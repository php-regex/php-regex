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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under (*CRLF) the dot and "\N" take a "\r" and a "\n", each alone, and
 * not the "\r" a "\n" follows: no set of one character says it, so a dot
 * there reads like no class, and unlike the dot that takes every character.
 */
final class DotUnderCrLfTest extends TestCase
{
    /**
     * The engine tells the two apart on the witness, with the JIT and
     * without; so do the questions a rewrite is checked through, and the
     * strings a search finds a match in.
     */
    #[Test]
    #[DataProvider('providePatternsReadApart')]
    public function test_a_dot_under_crlf_reads_unlike_what_takes_every_character(string $left, string $right, string $witness): void
    {
        $this->assertSame(0, JitInAFreshProcess::matched($left, $witness));
        $this->assertSame(1, JitInAFreshProcess::matched($right, $witness));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($left, 1), $witness));
        $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($right, 1), $witness));

        $solver = new LanguageSolver();

        $this->assertFalse($solver->readAlike($left, $right));
        $this->assertFalse($solver->rewritesAlikeInPlace($left, $right));
        $this->assertNotSame([], $solver->runsOfEveryCall($left, $right));

        $search = $solver->equivalent($left, $right, new SolverOptions(matchMode: MatchMode::Partial));
        $this->assertFalse($search->isEquivalent);
        $this->assertNull($search->leftOnlyExample);
        $this->assertNotNull($search->rightOnlyExample);
        $this->assertSame(0, preg_match($left, $search->rightOnlyExample));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($left, 1), $search->rightOnlyExample));
        $this->assertSame(1, preg_match($right, $search->rightOnlyExample));
    }

    /**
     * Two spellings of one reading read alike, under (*CRLF) as elsewhere:
     * the engine matches the same strings with both.
     */
    #[Test]
    #[DataProvider('providePatternsReadAlike')]
    public function test_two_spellings_of_one_dot_under_crlf_read_alike(string $left, string $right): void
    {
        foreach (["a\r\n", "a\r", "a\n", "a\ra", "a\n\n", 'aa', 'a'] as $subject) {
            $this->assertSame(preg_match($left, $subject), preg_match($right, $subject), $left.' and '.$right.' on '.json_encode($subject));
            $this->assertSame(preg_match('/(*NO_JIT)'.substr($left, 1), $subject), preg_match('/(*NO_JIT)'.substr($right, 1), $subject), $left.' and '.$right.' on '.json_encode($subject));
        }

        $solver = new LanguageSolver();

        $this->assertTrue($solver->readAlike($left, $right));
        $this->assertSame([], $solver->runsOfEveryCall($left, $right));
        $this->assertTrue($solver->equivalent($left, $right, new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent);
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string}>
     */
    public static function providePatternsReadApart(): iterable
    {
        yield 'a dot and a dot under s' => ['left' => '/(*CRLF)a./', 'right' => '/(*CRLF)a./s', 'witness' => "a\r\n"];
        yield 'a dot and a class of every character' => ['left' => '/(*CRLF)a.\nb/', 'right' => '/(*CRLF)a[\s\S]\nb/', 'witness' => "a\r\nb"];
        yield 'no newline and a class of every byte' => ['left' => '/(*CRLF)a\N/', 'right' => '/(*CRLF)a[\x00-\xff]/', 'witness' => "a\r\n"];
        yield 'a dot and a dot in a group under s' => ['left' => '/(*CRLF)a.\n/', 'right' => '/(*CRLF)a(?s:.)\n/', 'witness' => "a\r\n"];
        yield 'a run of dots and a run of anything' => ['left' => '/(*CRLF)a.*b/', 'right' => '/(*CRLF)a[\s\S]*b/', 'witness' => "a\r\nb"];
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function providePatternsReadAlike(): iterable
    {
        yield 'a dot and no newline' => ['left' => '/(*CRLF)a./', 'right' => '/(*CRLF)a\N/'];
        yield 'a dot under s and a dot in a group under s' => ['left' => '/(*CRLF)a./s', 'right' => '/(*CRLF)a(?s:.)/'];
        yield 'a dot in a group and a dot' => ['left' => '/(*CRLF)a(?:.)b/', 'right' => '/(*CRLF)a.b/'];
    }
}
