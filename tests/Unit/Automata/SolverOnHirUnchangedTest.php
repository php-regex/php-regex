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
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Cli\Graph\GraphvizDumper;
use PHPRegex\Cli\Graph\MermaidDumper;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verdicts the migration onto the normalized form must not move: the case
 * folding corpus, the possessive gate's answers and refusals, byte versus
 * /u literals, the public Dfa shape and the graph label format. Every test
 * here passes before the change and must pass after it.
 */
final class SolverOnHirUnchangedTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFoldRows')]
    public function test_the_fold_corpus_keeps_its_verdicts(string $left, string $right, bool $expectedEmpty): void
    {
        $solver = new LanguageSolver();

        $result = $solver->intersection($left, $right, $this->fullMatchOptions());

        $this->assertSame($expectedEmpty, $result->isEmpty, $left.' n '.$right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, expectedEmpty: bool}>
     */
    public static function provideFoldRows(): iterable
    {
        // Oracle: PCRE folds case as equivalence classes, so "/k/iu" matches
        // the Kelvin sign U+212A and "/s/iu" the long s U+017F.
        yield 'k folds with the kelvin sign' => ['left' => '/k/iu', 'right' => '/\x{212A}/u', 'expectedEmpty' => false];
        yield 'a range folds with the kelvin sign' => ['left' => '/[a-k]/iu', 'right' => '/\x{212A}/u', 'expectedEmpty' => false];
        yield 's folds with the long s' => ['left' => '/s/iu', 'right' => '/\x{17F}/u', 'expectedEmpty' => false];

        // Oracle: "/i/iu" on U+0131 and on U+0130 is 0 — the Turkish pair
        // stays out of every fold (PCRE does not join it, neither does the
        // solver).
        yield 'the dotless i stays out' => ['left' => '/i/iu', 'right' => '/\x{131}/u', 'expectedEmpty' => true];
        yield 'the dotted capital i stays out' => ['left' => '/i/iu', 'right' => '/\x{130}/u', 'expectedEmpty' => true];
    }

    #[Test]
    public function test_the_block_fold_equivalence_keeps_its_verdict(): void
    {
        $solver = new LanguageSolver();

        // Oracle rows exist in UnicodeSupportTest: under /iu the class also
        // matches the long s, the Kelvin sign and the angstrom sign, and
        // nothing else — the sharp s, İ and DŽ stay out.
        $result = $solver->equivalent('/[à-öa-z]/iu', '/[à-öÀ-Öa-zA-Z\x{17F}\x{212A}\x{212B}]/u', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_a_fold_outside_the_bmp_keeps_its_verdict(): void
    {
        $solver = new LanguageSolver();

        // Oracle: U+10400 DESERET CAPITAL LONG I folds to U+10428.
        $result = $solver->equivalent('/\u{10400}/iu', '/[\u{10400}\u{10428}]/u', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    #[DataProvider('provideAnsweredPossessiveRows')]
    public function test_a_safe_possessive_stays_answered(string $left, string $right, bool $expectedEmpty): void
    {
        $solver = new LanguageSolver();

        $result = $solver->intersection($left, $right, $this->fullMatchOptions());

        $this->assertSame($expectedEmpty, $result->isEmpty, $left.' n '.$right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, expectedEmpty: bool}>
     */
    public static function provideAnsweredPossessiveRows(): iterable
    {
        yield 'a possessive before a disjoint follower' => ['left' => '/a++b/', 'right' => '/aab/', 'expectedEmpty' => false];
        yield 'the symfony requirement idiom' => ['left' => '#^/users/[^/]++$#', 'right' => '#^/users/list$#', 'expectedEmpty' => false];
        yield 'the symfony requirement idiom stays disjoint' => ['left' => '#^/users/[^/]++$#', 'right' => '#^/users/list/extra$#', 'expectedEmpty' => true];
        yield 'through groups and sequences' => ['left' => '/(?:ab*+)c/', 'right' => '/abbc/', 'expectedEmpty' => false];
        yield 'a follower made of alternatives' => ['left' => '/a*+(?:[bc]|d)e/', 'right' => '/ade/', 'expectedEmpty' => false];
        yield 'through a skippable follower' => ['left' => '/a*+b?c/', 'right' => '/ac/', 'expectedEmpty' => false];
    }

    #[Test]
    #[DataProvider('provideRefusedPossessiveRows')]
    public function test_an_unsafe_possessive_stays_refused(string $pattern): void
    {
        $solver = new LanguageSolver();

        try {
            $solver->intersection($pattern, '/a/', $this->fullMatchOptions());
            $this->fail(sprintf('%s was answered; the solver must refuse it.', $pattern));
        } catch (ComplexityException $e) {
            $this->assertStringContainsString('pure language', $e->getMessage(), $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPossessiveRows(): iterable
    {
        yield 'possessive before itself' => ['pattern' => '/^a*+a$/'];
        yield 'possessive group before its own letter' => ['pattern' => '/(?:ab*+)b/'];
        yield 'a bare possessive' => ['pattern' => '/a*+/'];
        yield 'alternation under a possessive star' => ['pattern' => '/(?:a|b)*+c/'];
        yield 'two possessives in a row' => ['pattern' => '/x*+(?:ab*+)/'];
        yield 'a nullable follower' => ['pattern' => '/a++(?:ab)?/'];
        yield 'a nullable star follower' => ['pattern' => '/a*+(?:ab)?/'];
        yield 'a nullable caseless follower' => ['pattern' => '/k++(?:kx)?/iu'];
        yield 'an empty alternative follower' => ['pattern' => '/a++(?:ab|)/'];
    }

    #[Test]
    public function test_the_quantifier_spellings_stay_equivalent(): void
    {
        $solver = new LanguageSolver();

        $result = $solver->equivalent('/a{0,}b{1,}c{0,1}d{1}\d\d/', '/a*+b++c?d\d\d/', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    public function test_a_zero_lower_bound_quantifier_stays_epsilon(): void
    {
        $solver = new LanguageSolver();

        $result = $solver->equivalent('/a{0}b/', '/b/', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
    }

    #[Test]
    #[DataProvider('provideLiteralRows')]
    public function test_byte_and_unicode_literals_keep_their_verdicts(string $left, string $right): void
    {
        $solver = new LanguageSolver();

        $result = $solver->equivalent($left, $right, $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent, $left.' <=> '.$right);
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function provideLiteralRows(): iterable
    {
        yield 'a literal reads the same with and without /u' => ['left' => '/abc/', 'right' => '/abc/u'];
        yield 'a class reads the same with and without /u' => ['left' => '/[a-c]+/', 'right' => '/[a-c]+/u'];
    }

    #[Test]
    public function test_dfa_keeps_its_default_arguments(): void
    {
        $dfa = new Dfa(0, [
            0 => new DfaState(0, [], false, [[65, 90, 1]]),
            1 => new DfaState(1, [], true),
        ]);

        $this->assertSame(0, $dfa->minCodePoint);
        $this->assertSame(255, $dfa->maxCodePoint);
        $this->assertSame([], $dfa->alphabetRanges);

        $this->assertSame(1, $dfa->getState(0)->transitionFor(\ord('A')));
        $this->assertSame(1, $dfa->getState(0)->transitionFor(\ord('Z')));
        $this->assertNull($dfa->getState(0)->transitionFor(\ord('@')));
    }

    #[Test]
    public function test_the_mermaid_dumper_keeps_the_label_format(): void
    {
        $output = (new MermaidDumper())->dump($this->nfaOf('/[a-z]+/'));

        $this->assertMatchesRegularExpression('/^\s+\d+ --> \d+ : \[a-z\]$/m', $output);
        $this->assertMatchesRegularExpression('/^\s+\d+ --> \d+ : ε$/m', $output);
    }

    #[Test]
    public function test_the_mermaid_dumper_keeps_the_full_set_symbol(): void
    {
        $output = (new MermaidDumper())->dump($this->nfaOf('/./s'));

        $this->assertMatchesRegularExpression('/^\s+\d+ --> \d+ : Σ$/m', $output);
    }

    #[Test]
    public function test_the_graphviz_dumper_keeps_the_label_format(): void
    {
        $output = (new GraphvizDumper())->dump($this->nfaOf('/[a-z]+/'));

        $this->assertMatchesRegularExpression('/^\s+\d+ -> \d+ \[label="\[a-z\]"\];$/m', $output);
        $this->assertMatchesRegularExpression('/^\s+\d+ -> \d+ \[label="ε", style=dashed, color=gray\];$/m', $output);
    }

    #[Test]
    public function test_the_graphviz_dumper_keeps_the_full_set_symbol(): void
    {
        $output = (new GraphvizDumper())->dump($this->nfaOf('/./s'));

        $this->assertMatchesRegularExpression('/^\s+\d+ -> \d+ \[label="Σ"\];$/m', $output);
    }

    /**
     * The one place that reaches for the transformer: if the class moves,
     * this helper moves with it and the format pins above stay untouched.
     */
    private function nfaOf(string $pattern): Nfa
    {
        $ast = RegexParser::create()->parse($pattern);

        return (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast)))
            ->transform((new HirTranslator())->translate($ast), new SolverOptions());
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
