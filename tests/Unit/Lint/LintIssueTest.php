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

use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A finding of a lint run, read the same way by every report.
 */
final class LintIssueTest extends TestCase
{
    #[Test]
    public function test_a_finding_at_an_occurrence_takes_its_place(): void
    {
        $occurrence = new PatternOccurrence('/a{1}/', 'src/a.php', 3, 'preg_match()', column: 12, fileOffset: 40);

        $issue = LintIssue::at($occurrence, LintSeverity::Style, 'Quantifier is redundant.', 'regex.lint.quantifier.useless', 1, 'Remove it.');

        $this->assertSame(LintSeverity::Style, $issue->severity);
        $this->assertSame('Quantifier is redundant.', $issue->message);
        $this->assertSame('src/a.php', $issue->file);
        $this->assertSame(3, $issue->line);
        $this->assertSame(12, $issue->column);
        $this->assertSame(40, $issue->fileOffset);
        $this->assertSame(1, $issue->position);
        $this->assertSame('regex.lint.quantifier.useless', $issue->issueId);
        $this->assertSame('Remove it.', $issue->hint);
        $this->assertNull($issue->tip);
        $this->assertSame('preg_match()', $issue->source);
        $this->assertNull($issue->validation);
        $this->assertNull($issue->analysis);
        $this->assertNull($issue->target);
    }

    #[Test]
    public function test_the_identifier_of_an_invalid_pattern_is_its_error_code(): void
    {
        $validation = RegexParser::create()->validate(self::unclosedGroup());
        $this->assertInstanceOf(ErrorCode::class, $validation->errorCode);

        $issue = new LintIssue(LintSeverity::Error, 'Unclosed.', 'a.php', 1, validation: $validation);

        $this->assertNull($issue->issueId);
        $this->assertSame($validation->errorCode->value, $issue->id());
        $this->assertSame('regex.lint.x', (new LintIssue(LintSeverity::Error, 'X.', 'a.php', 1, issueId: 'regex.lint.x', validation: $validation))->id());
        $this->assertNull((new LintIssue(LintSeverity::Info, 'X.', 'a.php', 1))->id());
    }

    #[Test]
    public function test_with_file_and_with_target_change_that_alone(): void
    {
        $issue = new LintIssue(LintSeverity::Warning, 'M.', './a.php', 2, 5, 9, 1, 'regex.lint.x', 'H.', 'T.', 'preg_match()');

        $moved = $issue->withFile('a.php');
        $this->assertSame('a.php', $moved->file);
        $this->assertSame('./a.php', $issue->file);
        $this->assertEquals(new LintIssue(LintSeverity::Warning, 'M.', 'a.php', 2, 5, 9, 1, 'regex.lint.x', 'H.', 'T.', 'preg_match()'), $moved);

        $target = ['php' => '8.4', 'pcre' => '10.44', 'guard' => '>=8.4'];
        $judged = $issue->withTarget($target);
        $this->assertSame($target, $judged->target);
        $this->assertNull($issue->target);
        $this->assertEquals(new LintIssue(LintSeverity::Warning, 'M.', './a.php', 2, 5, 9, 1, 'regex.lint.x', 'H.', 'T.', 'preg_match()', target: $target), $judged);
    }

    #[Test]
    public function test_a_lint_finding_is_a_lint_diagnostic_with_its_own_severity(): void
    {
        $diagnostic = (new LintIssue(LintSeverity::Style, 'M.', 'a.php', 1, issueId: 'regex.lint.charclass.single', hint: 'Write a.'))->diagnostic();

        $this->assertSame(DiagnosticType::Lint, $diagnostic->type);
        $this->assertSame(LintSeverity::Style, $diagnostic->severity);
        $this->assertSame('M.', $diagnostic->message);
        $this->assertSame('regex.lint.charclass.single', $diagnostic->code);
        $this->assertNull($diagnostic->snippet);
        $this->assertSame('Write a.', $diagnostic->suggestion);
    }

    #[Test]
    public function test_an_invalid_pattern_is_a_syntax_diagnostic_with_its_snippet_and_fix(): void
    {
        $validation = RegexParser::create()->validate(self::unclosedGroup());

        $diagnostic = (new LintIssue(LintSeverity::Error, 'Unclosed.', 'a.php', 1, hint: 'unused', validation: $validation))->diagnostic();

        $this->assertSame(DiagnosticType::Syntax, $diagnostic->type);
        $this->assertSame(LintSeverity::Error, $diagnostic->severity);
        $this->assertSame('Unclosed.', $diagnostic->message);
        $this->assertSame($validation->errorCode?->value, $diagnostic->code);
        $this->assertSame($validation->caretSnippet, $diagnostic->snippet);
        $this->assertSame($validation->hint, $diagnostic->suggestion);
    }

    #[Test]
    public function test_a_pattern_the_grammar_reads_and_the_rules_refuse_is_a_semantic_diagnostic(): void
    {
        $validation = RegexParser::create()->validate('/a{2,1}/');
        $this->assertFalse($validation->isValid);

        $this->assertSame(DiagnosticType::Semantic, (new LintIssue(LintSeverity::Error, 'Reversed.', 'a.php', 1, validation: $validation))->diagnostic()->type);
    }

    #[Test]
    public function test_a_validation_without_an_error_code_gives_a_diagnostic_without_one(): void
    {
        $diagnostic = (new LintIssue(LintSeverity::Error, 'Refused.', 'a.php', 1, validation: new ValidationResult(false, 'Refused.')))->diagnostic();

        $this->assertSame(DiagnosticType::Syntax, $diagnostic->type);
        $this->assertNull($diagnostic->code);
    }

    /**
     * An analysis that found a search cost still gives the ReDoS verdict
     * its own attack: only the search cost issue shows the search's.
     */
    #[Test]
    public function test_a_redos_verdict_shows_its_own_attack_beside_a_search_cost(): void
    {
        $analysis = (new RedosAnalyzer(RegexParser::create()))->analyze('/\s+$/', RedosSeverity::Low, RedosMode::Theoretical);
        $this->assertInstanceOf(RedosSearchCost::class, $analysis->searchCost);
        $evidence = RedosVerdict::evidence($analysis);

        $diagnostic = (new LintIssue(LintSeverity::Warning, 'ReDoS.', 'a.php', 1, issueId: RedosVerdict::ISSUE_ID, analysis: $analysis))->diagnostic();

        $this->assertSame([] === $evidence ? null : implode("\n", $evidence), $diagnostic->snippet);
        $this->assertNotSame(implode("\n", RedosVerdict::searchEvidence($analysis->searchCost, $analysis->pcreVersion)), $diagnostic->snippet);
    }

    #[Test]
    public function test_a_pattern_past_a_limit_is_a_limit_diagnostic(): void
    {
        $validation = RegexParser::create(['max_pattern_length' => 5])->validate('/abcdefgh/');
        $this->assertFalse($validation->isValid);

        $diagnostic = (new LintIssue(LintSeverity::Warning, 'Not analyzed.', 'a.php', 1, validation: $validation))->diagnostic();

        $this->assertSame(DiagnosticType::Limit, $diagnostic->type);
        $this->assertSame(LintSeverity::Warning, $diagnostic->severity);
    }

    #[Test]
    public function test_a_redos_finding_is_a_security_diagnostic_with_its_attack(): void
    {
        $analysis = (new RedosAnalyzer(RegexParser::create()))->analyze('/(a+)+$/', RedosSeverity::Low, RedosMode::Theoretical);
        $this->assertNotSame([], RedosVerdict::evidence($analysis));

        $diagnostic = (new LintIssue(LintSeverity::Warning, 'ReDoS.', 'a.php', 1, issueId: RedosVerdict::ISSUE_ID, hint: 'unused', analysis: $analysis))->diagnostic();

        $this->assertSame(DiagnosticType::Security, $diagnostic->type);
        $this->assertSame(LintSeverity::Warning, $diagnostic->severity);
        $this->assertSame(RedosVerdict::ISSUE_ID, $diagnostic->code);
        $this->assertSame(implode("\n", RedosVerdict::evidence($analysis)), $diagnostic->snippet);
        $this->assertSame($analysis->recommendations[0] ?? null, $diagnostic->suggestion);
    }

    #[Test]
    public function test_a_search_cost_is_a_security_diagnostic_with_its_own_attack(): void
    {
        $analysis = (new RedosAnalyzer(RegexParser::create()))->analyze('/\s+$/', RedosSeverity::Low, RedosMode::Theoretical);
        $searchCost = $analysis->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $searchCost);

        $diagnostic = (new LintIssue(LintSeverity::Warning, 'Quadratic.', 'a.php', 1, issueId: RedosVerdict::SEARCH_ISSUE_ID, analysis: $analysis))->diagnostic();

        $this->assertSame(DiagnosticType::Security, $diagnostic->type);
        $this->assertSame(RedosVerdict::SEARCH_ISSUE_ID, $diagnostic->code);
        $this->assertSame(implode("\n", RedosVerdict::searchEvidence($searchCost, $analysis->pcreVersion)), $diagnostic->snippet);
        $this->assertNull($diagnostic->suggestion);
    }

    /**
     * Through a call, so that static analysis does not compile it.
     */
    private static function unclosedGroup(): string
    {
        return '/(a/';
    }
}
