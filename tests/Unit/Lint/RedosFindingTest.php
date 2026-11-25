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

use PHPRegex\Linter\RedosFinding;
use PHPRegex\Optimizer\RedosRepair;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The words the PHPStan extension and the Psalm plugin give a ReDoS finding:
 * a headline frozen for 2.x, the verdict class only when the model proved
 * it, then the details of the verdict or of the search cost.
 */
final class RedosFindingTest extends TestCase
{
    /**
     * Each with a run of "a" long enough for one attempt to show its cost.
     *
     * @return iterable<string, array{pattern: string, run: int, headline: string}>
     */
    public static function provideHeadlines(): iterable
    {
        yield 'proven exponential' => ['pattern' => '/(a+)+$/', 'run' => 10, 'headline' => 'Exponential backtracking (ReDoS): /(a+)+$/'];
        yield 'proven polynomial' => ['pattern' => '/a*a*a*$/', 'run' => 20, 'headline' => 'Polynomial backtracking (ReDoS): /a*a*a*$/'];
        yield 'heuristic' => ['pattern' => '/(a+)+\1$/', 'run' => 10, 'headline' => 'Potential backtracking (ReDoS): /(a+)+\1$/'];
    }

    #[Test]
    #[DataProvider('provideHeadlines')]
    public function test_headline_names_the_class_the_model_proved(string $pattern, int $run, string $headline): void
    {
        $attempt = PcreAttemptSteps::pinned($pattern);
        $short = PcreAttemptSteps::steps($attempt, str_repeat('a', $run).'!');
        $long = PcreAttemptSteps::steps($attempt, str_repeat('a', 2 * $run).'!');
        $this->assertGreaterThanOrEqual(4 * $short, $long, \sprintf('Oracle: one attempt of %s takes %d then %d steps.', $pattern, $short, $long));

        $this->assertSame($headline, RedosFinding::headline($pattern, (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low)));
    }

    #[Test]
    public function test_details_carry_the_verdict_the_attack_and_the_proven_repair(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(a+)+$/', RedosSeverity::Low);
        $repair = RedosFinding::repair(RegexParser::create(), '/(a+)+$/');
        $this->assertInstanceOf(RedosRepair::class, $repair);
        $this->assertInstanceOf(RedosWitness::class, $analysis->witness);
        $this->assertSame(1, preg_match('/(a+)$/', str_repeat('a', 40)), 'Oracle: the repair matches the run.');
        $this->assertSame(0, preg_match('/(a+)$/', str_repeat('a', 40).'!'), 'Oracle: the repair refuses the attack.');

        $this->assertSame([
            'Severity: critical, exponential (proven).',
            'Attack: '.$analysis->witness->render(),
            'Proven repair: /(a+)$/ (same subjects, same matches, linear).',
        ], RedosFinding::details($analysis, $repair));
    }

    /**
     * How the verdict was reached, when the model did not prove it: a
     * heuristic, a heuristic past the budget, or no analysis. A "<" of the
     * attack is written "\x3C".
     *
     * @return iterable<string, array{proof: RedosProof, details: list<string>}>
     */
    public static function provideUnprovenVerdicts(): iterable
    {
        yield 'heuristic' => ['proof' => RedosProof::Heuristic, 'details' => ['Severity: medium, heuristic.', 'Attack: "\x3Cb>" x n . "!"']];
        yield 'budget exceeded' => ['proof' => RedosProof::BudgetExceeded, 'details' => ['Severity: medium, heuristic (budget exceeded).', 'Attack: "\x3Cb>" x n . "!"']];
        yield 'not analyzed' => ['proof' => RedosProof::NotAnalyzed, 'details' => ['Severity: medium, not analyzed.', 'Attack: "\x3Cb>" x n . "!"']];
    }

    /**
     * @param list<string> $details
     */
    #[Test]
    #[DataProvider('provideUnprovenVerdicts')]
    public function test_details_say_how_an_unproven_verdict_was_reached(RedosProof $proof, array $details): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::Medium, 5, complexity: RedosComplexity::Unknown, proof: $proof, witness: new RedosWitness('', '<b>', '!', false));

        $this->assertSame($details, RedosFinding::details($analysis, null));
    }

    #[Test]
    public function test_search_cost_writes_a_less_than_sign_as_an_escape(): void
    {
        $this->assertSame('Quadratic search (ReDoS): /<b>\s+$/', RedosFinding::searchHeadline('/<b>\s+$/'));
        $this->assertSame(['Cost of "\x3Cb>".', 'Attack: "\x3Cb>" x n.'], RedosFinding::searchDetails('Cost of "<b>".', 'Attack: "<b>" x n.'));
        $this->assertSame(['Cost of "\x3Cb>".'], RedosFinding::searchDetails('Cost of "<b>".', ''));
        $this->assertSame(['Cost.'], RedosFinding::searchDetails('Cost.', null));
    }

    /**
     * @return iterable<string, array{pattern: string, length: int, shown: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'within the length' => ['pattern' => '/'.str_repeat('a', 48).'/', 'length' => 50, 'shown' => '/'.str_repeat('a', 48).'/'];
        yield 'past the length' => ['pattern' => '/'.str_repeat('a', 60).'/', 'length' => 50, 'shown' => '/'.str_repeat('a', 49).'...'];
        yield 'an escape the cut would split' => ['pattern' => '/'.str_repeat('a', 47).'\x{202E}/u', 'length' => 50, 'shown' => '/'.str_repeat('a', 47).'...'];
        yield 'an escape before the cut' => ['pattern' => '/\d'.str_repeat('a', 60).'/', 'length' => 10, 'shown' => '/\d'.str_repeat('a', 7).'...'];
        yield 'a multibyte character the cut does not split' => ['pattern' => '/'.str_repeat('é', 12).'/u', 'length' => 5, 'shown' => '/éééé...'];
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_display_pattern_cuts_between_characters_and_escapes(string $pattern, int $length, string $shown): void
    {
        $this->assertSame($shown, RedosFinding::displayPattern($pattern, $length));
    }

    #[Test]
    public function test_threshold_reads_a_severity_in_any_case(): void
    {
        $this->assertSame('medium', RedosFinding::threshold('Medium'));
        $this->assertSame('critical', RedosFinding::threshold('CRITICAL'));

        $this->expectException(InvalidRegexOptionException::class);
        RedosFinding::threshold('safe');
    }
}
