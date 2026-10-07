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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Linter\Rule\Support\QuestionBudget;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each question a rule puts to the automata costs milliseconds: a rule
 * asks a bounded number of them per pattern, and past that number stays
 * silent for the rest of the pattern. The count starts again with the next
 * pattern the same linter reads.
 */
final class QuestionBudgetTest extends TestCase
{
    #[Test]
    public function test_the_budget_allows_its_number_of_questions_per_pattern(): void
    {
        $budget = new QuestionBudget(3);
        $context = self::context();

        $this->assertTrue($budget->allows($context));
        $this->assertTrue($budget->allows($context));
        $this->assertTrue($budget->allows($context));
        $this->assertFalse($budget->allows($context));
        $this->assertFalse($budget->allows($context));
        $this->assertSame(3, $budget->asked());
    }

    #[Test]
    public function test_the_count_starts_again_with_the_next_pattern(): void
    {
        $budget = new QuestionBudget(1);
        $first = self::context();
        $this->assertTrue($budget->allows($first));
        $this->assertFalse($budget->allows($first));

        $next = self::context();
        $this->assertTrue($budget->allows($next));
        $this->assertSame(1, $budget->asked());
        $this->assertFalse($budget->allows($next));
    }

    /**
     * A pattern with more questions than the budget allows: the rule
     * answers the first ones, which the issues count, and stays silent on
     * the rest.
     *
     * @param array<string, bool> $rules
     */
    #[Test]
    #[DataProvider('providePatternsAskingTooMany')]
    public function test_a_rule_asks_at_most_its_budget_per_pattern(string $piece, string $flags, string $id, int $questionsEach, array $rules): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: a piece never matches.
        $one = \sprintf($piece, 0x101, 0x201);
        $this->assertSame(0, preg_match('/'.$one.'/'.$flags, "\u{101}\u{201}\u{101}\u{101}"), $one);
        $other = \sprintf($piece, 0x3B1, 0x3B2);
        $this->assertSame(0, preg_match('/'.$other.'/'.$flags, "\u{3B1}\u{3B2}\u{3B1}\u{3B1}"), $other);

        $linter = new PatternLinter($rules);
        $expected = intdiv(QuestionBudget::QUESTIONS_PER_PATTERN, $questionsEach);

        $this->assertCount($expected, self::issues($linter, self::pieces($piece, $flags, 0x100, 0x200), $id));
        // The same linter reads the next patterns with a fresh budget,
        // atoms it has not asked about yet.
        $this->assertCount(1, self::issues($linter, '/'.$other.'/'.$flags, $id));
        $this->assertCount($expected, self::issues($linter, self::pieces($piece, $flags, 0x400, 0x500), $id));
    }

    /**
     * @return iterable<string, array{piece: string, flags: string, id: string, questionsEach: int, rules: array<string, bool>}>
     */
    public static function providePatternsAskingTooMany(): iterable
    {
        // One question per letter beside the boundary.
        yield 'word boundaries between letters' => ['piece' => '\x{%X}\b\x{%2$X}', 'flags' => 'u', 'id' => 'regex.lint.anchor.impossible.boundary', 'questionsEach' => 2, 'rules' => []];
        yield 'possessive repeats taking what follows' => ['piece' => '\x{%X}*+\x{%1$X}', 'flags' => 'iu', 'id' => 'regex.lint.quantifier.possessiveImpossible', 'questionsEach' => 1, 'rules' => []];
        yield 'lookaheads the next letter contradicts' => ['piece' => '(?=\x{%X})\x{%X}', 'flags' => 'u', 'id' => 'regex.lint.lookaround.impossible', 'questionsEach' => 1, 'rules' => []];
    }

    /**
     * A non-word atom takes two questions, whether it is a word character
     * and whether it is none: when the budget runs out between the two,
     * the atom has no answer, and none is kept for the next pattern.
     */
    #[Test]
    public function test_a_boundary_atom_cut_between_its_two_questions_is_asked_again_in_the_next_pattern(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no piece matches.
        foreach (['/\x{101}\B\x{AB}/u' => "\u{101}\u{AB}", '/\x{A1}\b\x{BB}/u' => "\u{A1}\u{BB}", '/\x{BF}\b\x{D7}/u' => "\u{BF}\u{D7}"] as $piece => $subject) {
            $this->assertSame(0, preg_match($piece, $subject), $piece);
        }

        // "\x{101}" takes one question, each non-word atom two: the eighth
        // question finds "\x{BF}" no word character, and the budget leaves
        // none to ask whether it is a non-word one.
        $linter = new PatternLinter();
        $this->assertSame(8, QuestionBudget::QUESTIONS_PER_PATTERN);
        $this->assertCount(2, self::issues($linter, '/\x{101}\B\x{AB}\x{A1}\b\x{BB}\x{BF}\b\x{D7}/u', 'regex.lint.anchor.impossible.boundary'));
        $this->assertCount(1, self::issues($linter, '/\x{BF}\b\x{D7}/u', 'regex.lint.anchor.impossible.boundary'));
    }

    /**
     * Each lazy dot before a closing character asks about the whole
     * pattern: one question per alternative of ".*?A|.*?B|...".
     */
    #[Test]
    public function test_lazy_to_class_asks_at_most_its_budget_per_pattern(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the lazy dot and the class match the same text.
        preg_match('/.*?A|.*?B/', "xB\nA", $lazy);
        preg_match('/[^A\n]*A|[^B\n]*B/', "xB\nA", $class);
        $this->assertSame(['xB'], $lazy);
        $this->assertSame($lazy, $class);

        $alternatives = array_map(static fn (int $i): string => '.*?'.\chr(0x40 + $i), range(1, QuestionBudget::QUESTIONS_PER_PATTERN + 2));
        $linter = new PatternLinter(['quantifier.lazyToClass' => true]);

        $this->assertCount(QuestionBudget::QUESTIONS_PER_PATTERN, self::issues($linter, '/'.implode('|', $alternatives).'/', 'regex.lint.quantifier.lazyToClass'));
        $this->assertCount(1, self::issues($linter, '/.*?;/', 'regex.lint.quantifier.lazyToClass'));
    }

    /**
     * Two hundred pieces, the first code point of piece $i at $first + $i,
     * the second at $second + $i.
     */
    private static function pieces(string $piece, string $flags, int $first, int $second): string
    {
        return '/'.implode('', array_map(static fn (int $i): string => \sprintf($piece, $first + $i, $second + $i), range(1, 200))).'/'.$flags;
    }

    /**
     * @return list<RuleViolation>
     */
    private static function issues(PatternLinter $linter, string $pattern, string $id): array
    {
        Regex::create()->parse($pattern)->accept($linter);

        return array_values(array_filter($linter->getIssues(), static fn (RuleViolation $issue): bool => $id === $issue->id));
    }

    private static function context(): LintContext
    {
        return new LintContext(new PatternInfo('', '/', '', false), new GroupIndex(0, [], [], [], false), new CharSetAnalyzer(''));
    }
}
