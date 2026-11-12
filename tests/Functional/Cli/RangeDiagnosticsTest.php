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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\SinglePatternRange;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the later versions of a range say of a pattern reaches every place
 * a command that reads one pattern reads it: a change of meaning that
 * keeps the tree, the --safe pattern of redos, each pattern of compare, a
 * pattern transpile refuses to translate. The words are lint's.
 */
final class RangeDiagnosticsTest extends TestCase
{
    use RunsRegexCli;
    use SinglePatternRange;
    use TemporaryProject;

    private const RANGE = '--php-version=>=8.1';

    /**
     * "(a|ab){1}+c" and "^(a|ab|\1x)+b$" parse into the same tree at every
     * version from 7.3, and match otherwise from PHP 8.0 (PCRE2 10.35): lint
     * warns of it, and so does every command that reads one pattern, on
     * stderr, exit 0.
     *
     * @param list<string> $command the command line after the pattern
     */
    #[Test]
    #[DataProvider('provideSilentChanges')]
    public function test_a_change_of_meaning_that_keeps_the_tree_is_a_warning_in_the_words_of_lint(string $pattern, string $words, array $command): void
    {
        // The reading of the running engine, PCRE2 10.35 or later on every
        // PHP this suite runs on.
        self::assertEngineBacktracksIntoSelfReference();
        $this->assertSame(0, preg_match('/(a|ab){1}+c/', 'abc'), '"{1}+" makes the group atomic: PCRE2 '.\PCRE_VERSION);
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match(".var_export($pattern, true).", \$s);\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['--php-version=>=7.3', ...$command, $pattern]);
        $warning = $this->lintWarning($pattern);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString($words, $warning);
        $this->assertStringContainsString($warning, $stderr);
        $this->assertStringNotContainsString($warning, $stdout, 'stdout keeps the command\'s own output.');
    }

    /**
     * @return iterable<string, array{pattern: string, words: string, command: list<string>}>
     */
    public static function provideSilentChanges(): iterable
    {
        $atomic = ['pattern' => '/(a|ab){1}+c/', 'words' => '"{1}+" makes the group it repeats atomic'];
        $selfReference = ['pattern' => '/^(a|ab|\1x)+b$/', 'words' => 'a group that holds a reference to itself may be backtracked into'];

        yield 'validate, "{1}+"' => $atomic + ['command' => ['validate']];
        yield 'explain, "{1}+"' => $atomic + ['command' => ['explain']];
        yield 'validate, a group referring to itself' => $selfReference + ['command' => ['validate']];
        yield 'explain, a group referring to itself' => $selfReference + ['command' => ['explain']];
    }

    /**
     * redos judges its --safe pattern for every version of the range too: a
     * later version that refuses it is an error, exit 1.
     */
    #[Test]
    public function test_redos_judges_the_safe_pattern_over_the_range(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex([self::RANGE, 'redos', '/(a+)+$/', '--safe='.RangePatterns::KEEP_IN_LOOKBEHIND]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString(self::refusedOnMessage('PHP 8.5 and later', 80500, RangePatterns::KEEP_IN_LOOKBEHIND), $stderr);
    }

    /**
     * The JSON document gives the --safe pattern's verdict at each version
     * as "safe_range", beside the "range" of the pattern judged; a run
     * without --safe has none.
     */
    #[Test]
    public function test_redos_json_gives_the_range_of_the_safe_pattern(): void
    {
        self::assertEngineKeepInLookbehind();
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex([self::RANGE, 'redos', '/(a+)+$/', '--safe='.RangePatterns::KEEP_IN_LOOKBEHIND, '--format=json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('redos', $document);
        self::assertRange(
            $document['safe_range'] ?? null,
            RangePatterns::FROM_8_1,
            RangePatterns::KEEP_IN_LOOKBEHIND,
            static fn (int $point): bool => $point < 80500,
            static fn (int $point): bool => false,
        );
        self::assertRange(
            $document['range'] ?? null,
            RangePatterns::FROM_8_1,
            '/(a+)+$/',
            static fn (int $point): bool => true,
            static fn (int $point): bool => false,
        );

        [, $withoutSafe] = $this->runRegex([self::RANGE, 'redos', '/(a+)+$/', '--format=json']);

        $this->assertArrayNotHasKey('safe_range', JsonContract::decodeDocument($withoutSafe));
    }

    /**
     * compare reads two patterns: each line of the range names the one it
     * is about, as the banner names them, "Pattern 1" and "Pattern 2".
     */
    #[Test]
    public function test_compare_names_the_pattern_each_range_line_is_about(): void
    {
        self::assertEngineKeepInLookbehind();
        self::assertEngineReadsQuantifierWithoutMin();
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex([self::RANGE, 'compare', RangePatterns::QUANTIFIER_WITHOUT_MIN, RangePatterns::KEEP_IN_LOOKBEHIND]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $warning = self::parsesDifferentlyMessage(self::firstPointReadingQuantifierOtherwise(RangePatterns::FROM_8_1));
        $refusal = self::refusedOnMessage('PHP 8.5 and later', 80500, RangePatterns::KEEP_IN_LOOKBEHIND);
        $this->assertStringContainsString('Warning: Pattern 1: '.$warning."\n", $stderr);
        $this->assertStringContainsString('Error: Pattern 2: '.$refusal."\n", $stderr);

        [, , $stderr] = $this->runRegex([self::RANGE, 'compare', '/b/', RangePatterns::QUANTIFIER_WITHOUT_MIN]);

        $this->assertStringContainsString('Warning: Pattern 2: '.$warning."\n", $stderr);
        $this->assertStringNotContainsString('Pattern 1: ', $stderr);
    }

    /**
     * A translation that fails still leaves the range's verdict: a later
     * version that refuses the pattern is said on stderr, as JSON too.
     *
     * @param list<string> $format
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_transpile_reports_a_later_refusal_when_the_translation_fails(array $format): void
    {
        self::assertEngineKeepInLookbehind();
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex([self::RANGE, 'transpile', RangePatterns::KEEP_IN_LOOKBEHIND, '--target=javascript', ...$format]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString(self::refusedOnMessage('PHP 8.5 and later', 80500, RangePatterns::KEEP_IN_LOOKBEHIND), $stderr);
    }

    /**
     * @return iterable<string, array{format: list<string>}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['format' => []];
        yield 'json' => ['format' => ['--format=json']];
    }

    /**
     * The warning lint gives the pattern in the project's src/a.php over
     * the same range.
     */
    private function lintWarning(string $pattern): string
    {
        [, $stdout] = $this->runRegex(['--php-version=>=7.3', 'lint', 'src', '--jobs=1', '--no-redos', '--format=json']);

        foreach (JsonContract::asArray(JsonContract::decodeDocument($stdout)['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issue = JsonContract::asArray($issue);
                if ('regex.lint.compat.meaningChanges' === ($issue['issue_id'] ?? null) && \is_string($issue['message'] ?? null)) {
                    return $issue['message'];
                }
            }
        }

        $this->fail('lint gives '.$pattern.' no change of meaning: '.$stdout);
    }
}
