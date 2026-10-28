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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An ignore entry written with delimiters and modifiers names one pattern:
 * the same body under the same modifiers, whatever the delimiter and the
 * order of the letters. Any other entry, a body, a fragment or a delimited
 * pattern without modifiers, skips its body under any modifiers. The
 * analyzer and the lint read an entry alike.
 */
final class RedosIgnoreEntryTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEntries')]
    public function test_the_analyzer_skips_what_the_entry_names(string $entry, string $pattern, bool $skipped): void
    {
        $analysis = (new RedosAnalyzer(ignoredPatterns: [$entry]))->analyze($pattern);

        $this->assertSame($skipped, RedosProof::NotAnalyzed === $analysis->proof, \sprintf('%s for the entry %s', $pattern, $entry));
    }

    #[Test]
    #[DataProvider('provideEntries')]
    public function test_the_lint_skips_what_the_entry_names(string $entry, string $pattern, bool $skipped): void
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]), redosThreshold: 'low', redosIgnoredPatterns: [$entry], redosEnabled: true);
        $issues = array_filter($analysis->lint([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')]), static fn (LintIssue $issue): bool => RedosVerdict::ISSUE_ID === $issue->issueId);

        $this->assertSame($skipped, [] === $issues, \sprintf('%s for the entry %s', $pattern, $entry));
    }

    /**
     * @return iterable<string, array{entry: string, pattern: string, skipped: bool}>
     */
    public static function provideEntries(): iterable
    {
        // With modifiers: that pattern only.
        yield 'exact, the same pattern' => ['entry' => '/(a+)+b/i', 'pattern' => '/(a+)+b/i', 'skipped' => true];
        yield 'exact, another delimiter' => ['entry' => '/(a+)+b/i', 'pattern' => '#(a+)+b#i', 'skipped' => true];
        yield 'exact, bracket delimiters' => ['entry' => '/(a+)+b/i', 'pattern' => '{(a+)+b}i', 'skipped' => true];
        yield 'exact, the letters in another order' => ['entry' => '/(a+)+b/is', 'pattern' => '~(a+)+b~si', 'skipped' => true];
        yield 'exact, a letter repeated' => ['entry' => '/(a+)+b/ii', 'pattern' => '/(a+)+b/i', 'skipped' => true];
        yield 'exact, without its modifier' => ['entry' => '/(a+)+b/i', 'pattern' => '/(a+)+b/', 'skipped' => false];
        yield 'exact, with one more modifier' => ['entry' => '/(a+)+b/i', 'pattern' => '/(a+)+b/is', 'skipped' => false];
        yield 'exact, u against i' => ['entry' => '/(a+)+b/u', 'pattern' => '/(a+)+b/i', 'skipped' => false];
        yield 'exact, another body' => ['entry' => '/(a+)+b/i', 'pattern' => '/(a+)+c/i', 'skipped' => false];
        yield 'exact, never its unanchored body' => ['entry' => '/(a+)+b/i', 'pattern' => '/^(a+)+b$/i', 'skipped' => false];

        // Without modifiers: the body, under any modifiers.
        yield 'delimited, the same pattern' => ['entry' => '/(a+)+b/', 'pattern' => '/(a+)+b/', 'skipped' => true];
        yield 'delimited, with a modifier' => ['entry' => '/(a+)+b/', 'pattern' => '/(a+)+b/i', 'skipped' => true];
        yield 'delimited, another delimiter' => ['entry' => '{(a+)+b}', 'pattern' => '#(a+)+b#s', 'skipped' => true];
        yield 'a body' => ['entry' => '(a+)+b', 'pattern' => '/(a+)+b/i', 'skipped' => true];
        yield 'a body, another body' => ['entry' => '(a+)+b', 'pattern' => '/(a+)+c/i', 'skipped' => false];
    }
}
