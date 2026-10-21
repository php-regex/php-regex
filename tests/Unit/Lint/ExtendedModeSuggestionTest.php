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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern under /x gets the suggestion the optimizer gives it with the
 * factorizing of alternatives left off, whatever the options say: the same
 * check of a rewrite that adds a possessive quantifier, verification on or
 * off, and the same names for the changes made.
 */
final class ExtendedModeSuggestionTest extends TestCase
{
    /**
     * "[0-9]+" made possessive before "(?!b)" gives nothing back where the
     * lookahead needs it: on "12b" the pattern matches "1" and the rewrite
     * nothing. With verification off, the optimizer still checks a rewrite
     * that adds a possessive quantifier, and withdraws this one.
     */
    #[Test]
    public function test_an_unproven_possessive_is_not_suggested_under_x_with_verification_off(): void
    {
        $this->assertSame(1, preg_match('/ [0-9]+(?!b) /x', '12b'));
        $this->assertSame(0, preg_match('/ \d++(?!b) /x', '12b'), 'The possessive rewrite misses what the pattern matches.');

        $options = new OptimizerOptions(possessive: true, verifyWithAutomata: false);
        $this->assertSame([], $this->suggestions('/ [0-9]+(?!b) /x', $options));
        $this->assertSame([], $this->suggestions("/\n  a+(?!b)  # a run\n  [0-9]\n/x", $options));
    }

    /**
     * The changes of a suggestion under /x are named as the optimizer names
     * them.
     */
    #[Test]
    public function test_a_suggestion_under_x_names_the_changes_made(): void
    {
        $suggestions = $this->suggestions("/\n  [0-9]+[a-z]  # digits, a letter\n/x", new OptimizerOptions(possessive: true));

        $this->assertCount(1, $suggestions);
        $this->assertSame("/\n  \\d++[a-z]  # digits, a letter\n/x", $suggestions[0]->optimized);
        $this->assertSame([Rewriter::DIGITS, Rewriter::POSSESSIVE], $suggestions[0]->changes);
    }

    /**
     * Under /x the alternatives keep the order and the layout the author
     * gave them, factorizing asked for or not.
     */
    #[Test]
    public function test_alternatives_are_not_factorized_under_x(): void
    {
        $pattern = "/ (?:foo_one|foo_two)  # either\n/x";
        $options = new OptimizerOptions(factorize: true, verifyWithAutomata: false);

        $this->assertNotSame($pattern, (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, $options)->optimized, 'Asked for, the optimizer factorizes.');
        $this->assertSame([], $this->suggestions($pattern, $options));
    }

    /**
     * The suggestion is the optimizer's own, factorizing off, and it
     * matches what the pattern matches on subjects that tell the two apart.
     *
     * @param list<string> $subjects
     */
    #[DataProvider('provideExtendedPatterns')]
    #[Test]
    public function test_a_suggestion_under_x_is_the_optimizers_and_matches_alike(string $pattern, OptimizerOptions $options, array $subjects): void
    {
        $expected = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(
            digits: $options->digits,
            word: $options->word,
            ranges: $options->ranges,
            canonicalizeCharClasses: $options->canonicalizeCharClasses,
            possessive: $options->possessive,
            factorize: false,
            minQuantifierCount: $options->minQuantifierCount,
            verifyWithAutomata: $options->verifyWithAutomata,
        ));
        $this->assertTrue($expected->isChanged(), 'The optimizer rewrites the pattern.');

        $suggestions = $this->suggestions($pattern, $options);
        $this->assertCount(1, $suggestions);
        $this->assertSame($expected->optimized, $suggestions[0]->optimized);
        $this->assertSame($expected->changes, $suggestions[0]->changes);

        foreach ($subjects as $subject) {
            $this->assertSame(
                [preg_match($pattern, $subject, $original), $original],
                [preg_match($suggestions[0]->optimized, $subject, $optimized), $optimized],
                \sprintf('The suggestion matches as the pattern does on "%s".', addcslashes($subject, "\0..\37")),
            );
        }
    }

    /**
     * @return iterable<string, array{pattern: string, options: OptimizerOptions, subjects: list<string>}>
     */
    public static function provideExtendedPatterns(): iterable
    {
        yield 'digits, laid out' => [
            'pattern' => "/^\n  [0-9]{3}  # area\n  -\n  [0-9]{4}  # line\n\$/x",
            'options' => new OptimizerOptions(),
            'subjects' => ['555-1234', '555-123', "555-1234\n", '٣٣٣-1234', 'x555-1234'],
        ];
        yield 'a run counted' => [
            'pattern' => '/ a a a a a  b /x',
            'options' => new OptimizerOptions(),
            'subjects' => ['aaaaab', 'aaaab', 'aaaaaab', 'aaaaa b'],
        ];
        yield 'a quantifier written long' => [
            'pattern' => "/ x{0,1} y{1,} # options\n/x",
            'options' => new OptimizerOptions(),
            'subjects' => ['xy', 'y', 'xyyy', 'x', ''],
        ];
        yield 'a possessive proven, verification off' => [
            'pattern' => "/\n  [0-9]+[a-z]   # digits, a letter\n/x",
            'options' => new OptimizerOptions(possessive: true, verifyWithAutomata: false),
            'subjects' => ['12a', '12', 'a', '1a2b', "1\na"],
        ];
        yield 'an alternation of characters, factorizing asked for' => [
            'pattern' => "/ (?: a | b | c ) # one of three\n d /x",
            'options' => new OptimizerOptions(factorize: true),
            'subjects' => ['ad', 'bd', 'cd', 'dd', 'a d', 'abd'],
        ];
    }

    /**
     * @return list<OptimizationResult>
     */
    private function suggestions(string $pattern, OptimizerOptions $options): array
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]));
        $result = $analysis->suggestOptimizations([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')], 0, $options);

        return array_values(array_map(static fn (array $suggestion): OptimizationResult => $suggestion['optimization'], $result));
    }
}
