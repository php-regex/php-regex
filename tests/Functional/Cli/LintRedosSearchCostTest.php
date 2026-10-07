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

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex lint --redos" and the search cost: regex.lint.redos.search is
 * medium, so the default high threshold hides it and --redos-threshold=medium
 * shows it; a warning that never fails the run; --disable-rule turns it off.
 */
final class LintRedosSearchCostTest extends TestCase
{
    use TemporaryProject;

    private const SEARCH = 'regex.lint.redos.search';

    private const TRAILING_WHITESPACE_FILE = <<<'PHP'
        <?php

        preg_match('/\s+$/', $subject);

        PHP;

    private const ANCHORED_FILE = <<<'PHP'
        <?php

        preg_match('/^\s+$/', $subject);

        PHP;

    #[Test]
    public function test_lint_redos_reports_the_search_cost_at_the_medium_threshold(): void
    {
        $this->enterProject(['src/a.php' => self::TRAILING_WHITESPACE_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--redos', '--redos-threshold=medium', '--format=json', '--jobs=1']);

        $issues = self::issuesWithId($stdout, self::SEARCH);
        $this->assertCount(1, $issues, $stdout);
        $this->assertSame('warning', $issues[0]['severity'] ?? null, $stdout);
        $this->assertSame(0, $code, $stdout);
        $this->assertSame([], self::issuesWithId($stdout, 'regex.lint.redos'), $stdout);
    }

    /**
     * The issue carries the analysis, its search_cost filled in, and the
     * document keeps the contract.
     */
    #[Test]
    public function test_lint_json_carries_the_search_cost_of_the_analysis(): void
    {
        $this->enterProject(['src/a.php' => self::TRAILING_WHITESPACE_FILE]);

        [, $stdout] = $this->runLint(['src', '--redos', '--redos-threshold=medium', '--format=json', '--jobs=1']);

        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('lint', $document);

        $issues = self::issuesWithId($stdout, self::SEARCH);
        $this->assertCount(1, $issues, $stdout);
        $analysis = JsonContract::asArray($issues[0]['analysis'] ?? null, $stdout);
        $cost = JsonContract::asArray($analysis['search_cost'] ?? null, $stdout);
        $this->assertSame(2, $cost['degree'] ?? null);
        $witness = JsonContract::asArray($cost['witness'] ?? null, $stdout);
        $this->assertSame(['prefix', 'run', 'breaker'], array_keys($witness));
        $this->assertSame(['degree', 'witness', 'replayed'], array_keys($cost));
        $this->assertNull($cost['replayed']);
        $this->assertArrayNotHasKey('jit_linear', $cost);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRunsWithoutTheIssue')]
    public function test_lint_hides_the_search_cost(string $file, array $arguments): void
    {
        $this->enterProject(['src/a.php' => self::TRAILING_WHITESPACE_FILE]);
        [, $control] = $this->runLint(['src', '--redos', '--redos-threshold=medium', '--format=json', '--jobs=1']);
        $this->assertCount(1, self::issuesWithId($control, self::SEARCH), 'control: '.$control);

        $this->enterProject(['src/a.php' => $file]);

        [$code, $stdout] = $this->runLint(['src', ...$arguments, '--format=json', '--jobs=1']);

        $this->assertSame([], self::issuesWithId($stdout, self::SEARCH), $stdout);
        $this->assertSame(0, $code, $stdout);
    }

    /**
     * @return iterable<string, array{file: string, arguments: list<string>}>
     */
    public static function provideRunsWithoutTheIssue(): iterable
    {
        yield 'default threshold, high' => ['file' => self::TRAILING_WHITESPACE_FILE, 'arguments' => ['--redos']];
        yield 'high threshold' => ['file' => self::TRAILING_WHITESPACE_FILE, 'arguments' => ['--redos', '--redos-threshold=high']];
        yield 'no ReDoS check' => ['file' => self::TRAILING_WHITESPACE_FILE, 'arguments' => ['--no-redos', '--redos-threshold=medium']];
        yield 'rule disabled' => ['file' => self::TRAILING_WHITESPACE_FILE, 'arguments' => ['--redos', '--redos-threshold=medium', '--disable-rule='.self::SEARCH]];
        yield 'anchored pattern' => ['file' => self::ANCHORED_FILE, 'arguments' => ['--redos', '--redos-threshold=low']];
    }

    /**
     * Replayed or not, never an error: the run still exits 0.
     */
    #[Test]
    public function test_lint_confirmed_search_cost_stays_a_warning(): void
    {
        $this->enterProject(['src/a.php' => self::TRAILING_WHITESPACE_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--redos', '--redos-mode=confirmed', '--redos-threshold=low', '--format=json', '--jobs=1']);

        $issues = self::issuesWithId($stdout, self::SEARCH);
        $this->assertCount(1, $issues, $stdout);
        $this->assertSame('warning', $issues[0]['severity'] ?? null, $stdout);
        $this->assertSame(0, $code, $stdout);
    }

    /**
     * The reports built from diagnostics (GitHub, Checkstyle) keep it a
     * warning, with the search attack and no per-attempt advice: a
     * possessive run is retried at each start position all the same.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideDiagnosticFormats')]
    public function test_lint_diagnostic_formats_report_the_search_cost_as_a_warning(string $format, array $expected, string $unexpected): void
    {
        $this->enterProject(['src/a.php' => self::TRAILING_WHITESPACE_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--redos', '--redos-threshold=medium', '--format='.$format, '--jobs=1']);

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $stdout);
        }
        $this->assertStringNotContainsString($unexpected, $stdout);
        $this->assertStringNotContainsString('possessive', $stdout);
        $this->assertSame(0, $code, $stdout);
    }

    /**
     * @return iterable<string, array{format: string, expected: list<string>, unexpected: string}>
     */
    public static function provideDiagnosticFormats(): iterable
    {
        yield 'github' => ['format' => 'github', 'expected' => ['::warning ', '(regex.lint.redos.search)', 'Attack: " " x n'], 'unexpected' => '::notice '];
        yield 'checkstyle' => ['format' => 'checkstyle', 'expected' => ['severity="warning"', 'source="php-regex.regex.lint.redos.search"', 'pcre.backtrack_limit'], 'unexpected' => 'severity="info"'];
    }

    /**
     * No switch of its own: regex.json's ReDoS check and threshold drive it.
     */
    #[Test]
    public function test_lint_reports_the_search_cost_from_the_project_configuration(): void
    {
        $this->enterProject([
            'regex.json' => '{"checks": {"redos": {"enabled": true, "threshold": "medium"}}}',
            'src/a.php' => self::TRAILING_WHITESPACE_FILE,
        ]);

        [, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1']);

        $this->assertCount(1, self::issuesWithId($stdout, self::SEARCH), $stdout);
    }

    /**
     * regex.json's rules map takes the id as it takes the others, without
     * "regex.lint.".
     */
    #[Test]
    public function test_lint_rules_map_of_the_project_configuration_turns_the_search_cost_off(): void
    {
        $this->enterProject([
            'regex.json' => '{"checks": {"redos": {"enabled": true, "threshold": "medium"}, "lint": {"rules": {"redos.search": true}}}}',
            'src/a.php' => self::TRAILING_WHITESPACE_FILE,
        ]);
        [, $control] = $this->runLint(['src', '--format=json', '--jobs=1']);
        $this->assertCount(1, self::issuesWithId($control, self::SEARCH), 'control: '.$control);

        $this->enterProject([
            'regex.json' => '{"checks": {"redos": {"enabled": true, "threshold": "medium"}, "lint": {"rules": {"redos.search": false}}}}',
            'src/a.php' => self::TRAILING_WHITESPACE_FILE,
        ]);

        [$code, $stdout] = $this->runLint(['src', '--format=json', '--jobs=1']);

        $this->assertSame(0, $code, $stdout);
        $this->assertSame([], self::issuesWithId($stdout, self::SEARCH), $stdout);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function issuesWithId(string $stdout, string $issueId): array
    {
        $payload = json_decode($stdout, true);
        self::assertIsArray($payload, 'stdout is not JSON: '.$stdout);
        self::assertIsArray($payload['results'] ?? null, $stdout);

        $issues = [];
        foreach ($payload['results'] as $result) {
            self::assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                self::assertIsArray($issue);
                if ($issueId === ($issue['issue_id'] ?? null)) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function runLint(array $args): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $input = new Input('lint', $args, new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            $exitCode = $command->run($input, OutputFactory::create());
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exitCode, $stdout];
    }
}
