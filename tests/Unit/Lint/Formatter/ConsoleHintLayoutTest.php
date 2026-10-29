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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Advice\RedosHints;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\ConsoleTagFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The console writes a hint one line per sentence behind the arrow, folded
 * into the width with a hanging indent, the suggestions once under one
 * heading as dashed items; an issue message folds under its badge. The
 * risky part and a quoted span never break.
 */
final class ConsoleHintLayoutTest extends TestCase
{
    private const WIDTH = 60;

    private const RISKY = 'The risky part is: (?:[^\s"\']+|"[^"]*"|\'[^\']*\'|["\'])+ and more than sixty columns of it';

    #[Test]
    public function test_each_hint_line_folds_under_the_arrow(): void
    {
        $output = self::render([
            'Attack: "a b" x n . "!"',
            'Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).',
            self::RISKY,
            'Unbounded quantifier detected. May cause backtracking on non-matching input.',
            RedosHints::SUGGESTION.'Consider using possessive quantifiers or atomic groups to limit backtracking.',
            RedosHints::SUGGESTION.'Replace inner quantifiers with possessive variants or wrap them in (?>...).',
            'Test with adversarial inputs like repeated strings followed by a non-matching character.',
        ]);
        $lines = explode("\n", rtrim($output, "\n"));

        $this->assertContains('         ↳ Attack: "a b" x n . "!"', $lines);
        $this->assertContains('         ↳ '.self::RISKY, $lines);
        foreach ($lines as $line) {
            if (str_contains($line, '→') || str_contains($line, self::RISKY)) {
                continue;
            }
            $this->assertLessThanOrEqual(self::WIDTH, mb_strwidth($line), $line);
        }

        // The replay folds: the arrow line, then continuation lines at the column of its text.
        $this->assertContains('         ↳ Replayed on PCRE2 10.49: preg_match fails from', $lines);
        $this->assertContains('           length 17 (backtrack_limit 100000, JIT off).', $lines);

        // One heading, two dashed items two columns further right.
        $this->assertSame(1, substr_count($output, 'Suggested (verify behavior)'));
        $this->assertStringContainsString(
            "         ↳ Suggested (verify behavior):\n"
            ."             - Consider using possessive quantifiers or\n"
            ."               atomic groups to limit backtracking.\n"
            ."             - Replace inner quantifiers with possessive\n"
            ."               variants or wrap them in (?>...).\n",
            $output,
        );
        $this->assertStringContainsString("         ↳ Test with adversarial inputs like repeated\n           strings followed by a non-matching character.\n", $output);
    }

    #[Test]
    public function test_the_bridges_console_folds_the_same_way(): void
    {
        $formatter = new ConsoleTagFormatter(new AnalysisService(RegexParser::create(['cache' => null])), new LinkFormatter(null, new RelativePathHelper('/project')), false, self::WIDTH);
        $tagged = $formatter->format(self::report([
            'Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).',
            RedosHints::SUGGESTION.'Consider using possessive quantifiers or atomic groups to limit backtracking.',
        ], 'Message.'));
        $output = preg_replace('/<(?:[a-z]+=[^<>]*|\/)>/', '', $tagged);
        $this->assertIsString($output);

        $this->assertStringContainsString(
            "         ↳ Replayed on PCRE2 10.49: preg_match fails from\n"
            ."           length 17 (backtrack_limit 100000, JIT off).\n"
            ."         ↳ Suggested (verify behavior):\n"
            ."             - Consider using possessive quantifiers or\n"
            ."               atomic groups to limit backtracking.\n",
            (string) $output,
        );
    }

    #[Test]
    public function test_an_attack_string_with_escaped_quotes_stays_whole(): void
    {
        $attack = 'Attack: "a \\"b\\" c" x n . "!" and a tail long enough to fold the line once';

        $this->assertStringContainsString("         ↳ Attack: \"a \\\"b\\\" c\" x n . \"!\" and a tail long\n           enough to fold the line once\n", self::render([$attack]));
    }

    #[Test]
    public function test_the_summary_and_the_tip_badge_come_from_the_same_layout(): void
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false, width: self::WIDTH));
        $report = self::report(['Hint.'], 'Message.', [['file' => 'f.php', 'line' => 1, 'optimization' => new OptimizationResult('/a{1}/', '/a/'), 'savings' => 3]]);

        $this->assertStringContainsString(' TIP ', $formatter->format($report));
        $this->assertStringContainsString('PASS 1 warnings found', $formatter->getSummary(['errors' => 0, 'warnings' => 1, 'optimizations' => 0]));

        $bridges = new ConsoleTagFormatter(new AnalysisService(RegexParser::create(['cache' => null])), new LinkFormatter(null, new RelativePathHelper('/project')), false, self::WIDTH);
        $tagged = preg_replace('/<(?:[a-z]+=[^<>]*|\/)>/', '', $bridges->format($report));
        $this->assertIsString($tagged);
        $this->assertStringContainsString(' TIP ', (string) $tagged);
    }

    #[Test]
    public function test_an_empty_entry_prints_no_line_and_hides_none(): void
    {
        $hints = ['First.', '', RedosHints::SUGGESTION.'Second.', '', 'Third.'];

        $console = self::render($hints);
        $this->assertStringContainsString("         ↳ First.\n         ↳ Suggested (verify behavior):\n             - Second.\n         ↳ Third.\n", $console);

        $bridges = new ConsoleTagFormatter(new AnalysisService(RegexParser::create(['cache' => null])), new LinkFormatter(null, new RelativePathHelper('/project')), false, self::WIDTH);
        $tagged = preg_replace('/<(?:[a-z]+=[^<>]*|\/)>/', '', $bridges->format(self::report($hints, 'Message.')));
        $this->assertStringContainsString("         ↳ First.\n         ↳ Suggested (verify behavior):\n             - Second.\n         ↳ Third.\n", (string) $tagged);
    }

    #[Test]
    public function test_a_one_sentence_hint_keeps_its_line(): void
    {
        $this->assertStringContainsString("         ↳ Hint.\n", self::render(['Hint.']));
    }

    #[Test]
    public function test_a_quoted_span_wider_than_the_room_overflows_whole(): void
    {
        $span = '"'.str_repeat('a ', 30).'"';

        // The span alone on its line, whole: a word wider than the room is never broken.
        $this->assertStringContainsString("         ↳ Attack:\n           ".$span."\n", self::render(['Attack: '.$span]));
    }

    #[Test]
    public function test_the_message_folds_under_its_badge(): void
    {
        $message = 'Quadratic search: one attempt is linear (proven); an unanchored search is quadratic in the interpreter. Severity: MEDIUM.';
        $output = self::render(['Hint.'], $message);

        $this->assertStringContainsString(
            "    WARN Quadratic search: one attempt is linear (proven);\n"
            ."         an unanchored search is quadratic in the\n"
            ."         interpreter. Severity: MEDIUM.\n"
            ."         ↳ Hint.\n",
            $output,
        );
    }

    #[Test]
    public function test_the_default_width_is_one_hundred_and_twenty(): void
    {
        $sentence = str_repeat('word ', 30).'end.';
        $output = (new ConsoleFormatter(config: new OutputConfiguration(ansi: false, width: 120)))->format(self::report([$sentence], 'Message.'));
        $lines = explode("\n", $output);

        $this->assertContains('         ↳ '.str_repeat('word ', 21).'word', $lines);
        $this->assertContains('           '.str_repeat('word ', 8).'end.', $lines);
    }

    /**
     * @param list<string> $hints
     */
    private static function render(array $hints, string $message = 'Message.'): string
    {
        $formatter = new ConsoleFormatter(config: new OutputConfiguration(ansi: false, width: self::WIDTH));

        return $formatter->format(self::report($hints, $message));
    }

    /**
     * @param list<string>                                                                         $hints
     * @param list<array{file: string, line: int, optimization: OptimizationResult, savings: int}> $optimizations
     */
    private static function report(array $hints, string $message, array $optimizations = []): LintReport
    {
        $issue = new LintIssue(LintSeverity::Warning, $message, 'f.php', 1, hint: implode(' ', $hints), hints: $hints, issueId: 'regex.lint.test');

        return new LintReport([[
            'file' => 'f.php',
            'line' => 1,
            'pattern' => '/(a+)+$/',
            'issues' => [$issue],
            'optimizations' => $optimizations,
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);
    }
}
