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

namespace PHPRegex\Tests\Unit\Bridge\PHPStan;

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\PHPStan\ErrorFactory;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\TipRuleError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wording of every error the extension reports: PHPStan baselines hold
 * the messages as they are written, so each one stays word for word.
 */
final class ErrorFactoryTest extends TestCase
{
    #[Test]
    public function test_a_pattern_the_target_refuses_reads_the_first_line_of_the_refusal(): void
    {
        $error = ErrorFactory::invalidForTarget('PHP 8.2 with PCRE2 10.40', "Unknown group (?^)\nat offset 2", 'Write it for PCRE2 10.43.', 7);

        $this->assertError('Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Unknown group (?^).', 'regex.invalidForTarget', 7, 'Write it for PCRE2 10.43.', $error);
        $this->assertError('Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: Done.', 'regex.invalidForTarget', 1, null, ErrorFactory::invalidForTarget('PHP 8.4 with PCRE2 10.44', 'Done.', '', 1));
    }

    #[Test]
    public function test_a_lint_issue_keeps_its_message_and_links_its_rule(): void
    {
        $this->assertError(
            'Quantifier is useless.',
            'regex.lint.quantifier.useless',
            4,
            "Remove it.\nRead more: https://php-regex.com/reference/rules/#useless-quantifier",
            ErrorFactory::lintIssue('regex.lint.quantifier.useless', 'Quantifier is useless.', 'Remove it.', 4),
        );
        $this->assertError('Something.', 'regex.lint.nowhere', 4, null, ErrorFactory::lintIssue('regex.lint.nowhere', 'Something.', null, 4));
    }

    #[Test]
    public function test_an_optimization_quotes_the_pattern_and_its_rewrite(): void
    {
        $this->assertError('Regex pattern can be optimized: "/[0-9]+/"', 'regex.optimization', 9, 'Consider using: /\d+/', ErrorFactory::optimization('/[0-9]+/', '/\d+/', 9));
    }

    #[Test]
    public function test_a_quadratic_search_tip_writes_a_less_than_sign_as_an_escape(): void
    {
        $this->assertError(
            'Quadratic search (ReDoS): /\s+$/',
            'regex.redos.search',
            5,
            "Quadratic search: \\x3C.\nAttack \\x3Cb>.\n\nRead more about catastrophic backtracking: https://php-regex.com/reference/rules/#catastrophic-backtracking",
            ErrorFactory::searchCost('/\s+$/', 'Quadratic search: <.', 'Attack <b>.', 5),
        );
    }

    #[Test]
    public function test_the_markers_that_judge_nothing_say_why(): void
    {
        $unreadable = new TargetMarker('@regex-target', 3, 4, 4, 'php>=banana', null, 'The @regex-target marker "php>=banana" names no version.', 0);
        $this->assertError('The @regex-target marker "php>=banana" names no version.', 'regex.target.invalid', 3, 'Write the versions the code runs on as a Composer constraint after "php", "pcre", or both.', ErrorFactory::unreadableMarker($unreadable));

        $empty = new TargetMarker('@regex-target-next-line', 6, 7, 7, 'php>=9.0', TargetConstraint::phpFrom(90000, null), null, 0);
        $this->assertError('The @regex-target-next-line marker "php>=9.0" keeps no version the project is judged for (PHP 8.2 to 8.4): the patterns it covers are not judged.', 'regex.target.empty', 6, 'Widen the marker to versions of the range, or remove the code it covers if it never runs there.', ErrorFactory::emptyMarker($empty, '8.2 to 8.4'));
    }

    #[Test]
    public function test_the_verdict_of_an_incomplete_analysis_says_it_was_not_analyzed(): void
    {
        // The oracle: PCRE2 refuses a reversed range; the analysis stops at
        // the library's validation, unfinished, of unknown severity.
        $pattern = '/[z-a]+$/';
        $this->assertFalse(self::runningEngineCompiles($pattern));
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low);
        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);

        $verdict = (new \ReflectionMethod(ErrorFactory::class, 'redosVerdict'))->invoke(null, $analysis);

        $this->assertSame('unknown, not analyzed', $verdict);
    }

    private function assertError(string $message, string $identifier, int $line, ?string $tip, IdentifierRuleError $error): void
    {
        $this->assertSame($message, $error->getMessage());
        $this->assertSame($identifier, $error->getIdentifier());
        $this->assertInstanceOf(LineRuleError::class, $error);
        $this->assertSame($line, $error->getLine());
        $this->assertSame($tip, $error instanceof TipRuleError ? $error->getTip() : null);
    }

    private static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }
}
