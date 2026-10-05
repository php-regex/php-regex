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

use PHPRegex\Laravel\Output\LaravelConsoleFormatter;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Symfony\Output\SymfonyConsoleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under an issue the console prints its hint, the fix. A lint rule at Error
 * fails the run and still needs its fix; only an invalid pattern goes
 * without one, its message and caret saying it all.
 */
final class ConsoleHintVisibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{formatter: string}>
     */
    public static function provideFormatters(): iterable
    {
        yield 'console' => ['formatter' => 'console'];
        yield 'symfony' => ['formatter' => 'symfony'];
        yield 'laravel' => ['formatter' => 'laravel'];
    }

    #[Test]
    #[DataProvider('provideFormatters')]
    public function test_an_error_rule_shows_its_hint(string $formatter): void
    {
        // Oracle: without /u the class holds the two bytes of "é", one at a
        // time, so it matches a lone byte and not "é" itself; with /u it is
        // one character.
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));
        $this->assertSame(0, preg_match('/^[é]$/', 'é'));
        $this->assertSame(1, preg_match('/^[é]$/u', 'é'));

        $report = self::lint('/[é]/');
        $issue = self::onlyIssue($report);
        $this->assertSame('error', $issue['type'] ?? null);
        $this->assertIsString($issue['hint'] ?? null);
        $this->assertStringStartsWith('Add the /u flag', $issue['hint']);

        $output = self::plain(self::formatter($formatter)->format($report));

        $this->assertMatchesRegularExpression('/^\s*FAIL\b/m', $output);
        $this->assertStringContainsString('↳ '.$issue['hint'], $output);
    }

    #[Test]
    #[DataProvider('provideFormatters')]
    public function test_an_invalid_pattern_shows_no_tip(string $formatter): void
    {
        // Oracle: PCRE refuses a pattern without its closing delimiter.
        $this->assertFalse(@preg_match(self::unclosedPattern(), ''));

        $report = self::lint(self::unclosedPattern());
        $issue = self::onlyIssue($report);
        $this->assertSame('error', $issue['type'] ?? null);
        $this->assertInstanceOf(ValidationResult::class, $issue['validation'] ?? null);
        $this->assertIsString($issue['tip'] ?? null);
        $this->assertNotSame('', $issue['tip']);

        $output = self::plain(self::formatter($formatter)->format($report));

        $this->assertMatchesRegularExpression('/^\s*FAIL\b/m', $output);
        $this->assertStringNotContainsString($issue['tip'], $output);
    }

    /**
     * The guard itself: an invalid pattern's issue that carries a hint keeps
     * it to itself, while the same hint on a lint error is printed.
     */
    #[Test]
    #[DataProvider('provideFormatters')]
    public function test_a_hint_is_hidden_only_on_an_invalid_pattern(string $formatter): void
    {
        $validation = new ValidationResult(false, 'Expected ) at end of input (found eof)', 0, null, 9);

        $invalid = self::plain(self::formatter($formatter)->format(self::reportWithHint($validation)));
        $lintError = self::plain(self::formatter($formatter)->format(self::reportWithHint(null)));

        $this->assertStringNotContainsString('Close the group.', $invalid);
        $this->assertStringContainsString('↳ Close the group.', $lintError);
    }

    private static function formatter(string $name): OutputFormatterInterface
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $links = new LinkFormatter(null, new RelativePathHelper('/project'));

        return match ($name) {
            'console' => new ConsoleFormatter($service, new OutputConfiguration(ansi: false)),
            'symfony' => new SymfonyConsoleFormatter($service, $links, false),
            default => new LaravelConsoleFormatter($service, $links, false),
        };
    }

    private static function lint(string $pattern): LintReport
    {
        $service = new LintService(new AnalysisService(RegexParser::create(['cache' => null])), new PatternSourceCollection([]));

        return $service->analyze(
            [new PatternOccurrence($pattern, 'f.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkOptimizations: false),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function onlyIssue(LintReport $report): array
    {
        self::assertCount(1, $report->results);
        $issues = $report->results[0]['issues'] ?? [];
        self::assertCount(1, $issues);

        return $issues[0];
    }

    /**
     * An error carrying the hint "Close the group.": an invalid pattern's
     * when it comes with its validation, a lint rule's otherwise.
     */
    private static function reportWithHint(?ValidationResult $validation): LintReport
    {
        $issue = null === $validation
            ? ['type' => 'error', 'message' => 'Rule at Error.', 'hint' => 'Close the group.', 'issueId' => 'regex.lint.test', 'file' => 'f.php', 'line' => 1]
            : ['type' => 'error', 'message' => 'Expected ) at end of input (found eof)', 'hint' => 'Close the group.', 'validation' => $validation, 'file' => 'f.php', 'line' => 1];

        return new LintReport([[
            'file' => 'f.php',
            'line' => 1,
            'pattern' => '/(a/',
            'issues' => [$issue],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);
    }

    /**
     * The output without its console tags.
     */
    private static function plain(string $output): string
    {
        $plain = preg_replace('/<(?:[a-z]+=[^<>]*|\/)>/', '', $output);
        self::assertIsString($plain);

        return $plain;
    }

    /**
     * Through a call, so that static analysis does not compile it.
     */
    private static function unclosedPattern(): string
    {
        return '#foo';
    }
}
