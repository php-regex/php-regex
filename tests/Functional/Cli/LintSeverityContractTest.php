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
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex lint" honours the severity a rule declares: a rule at Error fails
 * the run, an Info rule is an info, and the summary names a lint error apart
 * from a pattern PCRE refuses.
 *
 * Each pattern compiles; without /u, [é] matches the byte \xC3 on its own
 * and \p{L} stops at the first 256 code points (oracle in
 * LintSeverityContractTest).
 */
final class LintSeverityContractTest extends TestCase
{
    use TemporaryProject;

    private const MULTIBYTE_CLASS_FILE = <<<'PHP'
        <?php

        preg_match('/[é]/', $subject);

        PHP;

    private const UNNAMED_QUANTIFIED_CAPTURE_FILE = <<<'PHP'
        <?php

        preg_match('/(a)+/', $subject);

        PHP;

    private const UNICODE_PROPERTY_FILE = <<<'PHP'
        <?php

        preg_match('/\p{L}/', $subject);

        PHP;

    // PCRE: "missing closing parenthesis at offset 9".
    private const INVALID_FILE = <<<'PHP'
        <?php

        preg_match('/(unclosed/', $subject);

        PHP;

    private const CLEAN_FILE = <<<'PHP'
        <?php

        preg_match('/abc/', $subject);

        PHP;

    #[Test]
    public function test_an_error_rule_fails_lint(): void
    {
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));
        $this->enterProject(['src/a.php' => self::MULTIBYTE_CLASS_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--jobs=1']);

        $this->assertSame(1, $code, $stdout);
        $this->assertMatchesRegularExpression('/^\s*FAIL Character class holds "é"/m', $stdout);
        $this->assertStringStartsWith('FAIL ', self::summaryLine($stdout));

        [$jsonCode, $json] = $this->runLint(['src', '--jobs=1', '--format=json']);

        $this->assertSame(1, $jsonCode, $json);
        $this->assertSame(['regex.lint.unicode.multibyteInClassWithoutU' => 'error'], self::issueTypes($json));
        $this->assertSame(1, self::stats($json)['errors'] ?? null);
        $this->assertSame(1, self::stats($json)['lint_errors'] ?? null);
    }

    #[Test]
    public function test_an_info_rule_is_counted_as_info(): void
    {
        preg_match('/(a)+/', 'aaa', $matches);
        $this->assertSame(['aaa', 'a'], $matches);
        $this->enterProject(['src/a.php' => self::UNNAMED_QUANTIFIED_CAPTURE_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--jobs=1']);

        $this->assertSame(0, $code, $stdout);
        $this->assertMatchesRegularExpression('/^\s*INFO Quantified capturing group/m', $stdout);
        $this->assertDoesNotMatchRegularExpression('/^\s*WARN Quantified capturing group/m', $stdout);

        [$jsonCode, $json] = $this->runLint(['src', '--jobs=1', '--format=json']);

        $this->assertSame(0, $jsonCode, $json);
        $this->assertSame(['regex.lint.group.quantifiedCapture' => 'info'], self::issueTypes($json));
        $this->assertSame(1, self::stats($json)['infos'] ?? null);
        $this->assertSame(0, self::stats($json)['warnings'] ?? null);
        $this->assertSame(0, self::stats($json)['errors'] ?? null);
    }

    #[Test]
    public function test_summary_separates_lint_errors_from_invalid_patterns(): void
    {
        $this->assertNotFalse(@preg_match('/\p{L}/', ''));
        $this->enterProject(['src/a.php' => self::UNICODE_PROPERTY_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--jobs=1']);

        $this->assertSame(1, $code, $stdout);
        $summary = self::summaryLine($stdout);
        $this->assertStringContainsString('1 lint error', $summary);
        $this->assertDoesNotMatchRegularExpression('/[1-9]\d* invalid patterns?/', $summary);
    }

    /**
     * A pattern PCRE refuses still fails the run under its own label, and
     * is no lint error.
     */
    #[Test]
    public function test_an_invalid_pattern_still_fails_as_invalid(): void
    {
        $this->assertFalse(@preg_match(self::invalidPattern(), ''));
        $this->enterProject(['src/a.php' => self::INVALID_FILE]);

        [$code, $stdout] = $this->runLint(['src', '--jobs=1']);

        $this->assertSame(1, $code, $stdout);
        $summary = self::summaryLine($stdout);
        $this->assertStringContainsString('1 invalid patterns', $summary);
        $this->assertStringNotContainsString('lint error', $summary);

        [, $json] = $this->runLint(['src', '--jobs=1', '--format=json']);

        $this->assertSame(1, self::stats($json)['errors'] ?? null);
        $this->assertSame(0, self::stats($json)['lint_errors'] ?? null);
    }

    /**
     * Like "redos", the JSON report carries every count on every run.
     */
    #[Test]
    public function test_json_stats_carry_every_count_on_a_clean_run(): void
    {
        $this->enterProject(['src/a.php' => self::CLEAN_FILE]);

        [$code, $json] = $this->runLint(['src', '--jobs=1', '--format=json']);

        $this->assertSame(0, $code, $json);
        $stats = self::stats($json);
        $this->assertSame(0, $stats['infos'] ?? null);
        $this->assertSame(0, $stats['lint_errors'] ?? null);
        $this->assertSame(0, $stats['redos_errors'] ?? null);
    }

    /**
     * A baseline that filters nothing leaves every count as it was: the lint
     * error and the info are still counted apart.
     */
    #[Test]
    public function test_an_empty_baseline_keeps_lint_errors_and_infos(): void
    {
        $this->assertSame(0, preg_match('/^[é]$/', 'é'));
        $this->assertSame(1, preg_match('/(a)+/', 'aab', $matches));
        $this->assertSame(['aa', 'a'], $matches);
        $this->enterProject([
            'src/a.php' => self::MULTIBYTE_CLASS_FILE,
            'src/b.php' => self::UNNAMED_QUANTIFIED_CAPTURE_FILE,
            'baseline.json' => '[]',
        ]);

        [$code, $stdout] = $this->runLint(['src', '--jobs=1', '--baseline=baseline.json']);

        $this->assertSame(1, $code, $stdout);
        $summary = self::summaryLine($stdout);
        $this->assertStringContainsString('1 lint errors', $summary);
        $this->assertStringContainsString('1 infos found', $summary);

        [$jsonCode, $json] = $this->runLint(['src', '--jobs=1', '--format=json', '--baseline=baseline.json']);

        $this->assertSame(1, $jsonCode, $json);
        $this->assertSame(1, self::stats($json)['lint_errors'] ?? null);
        $this->assertSame(1, self::stats($json)['infos'] ?? null);
    }

    /**
     * The ReDoS count survives an empty baseline as well: (a+)+$ on a…a!
     * exhausts the backtrack limit at 19 pumps, a confirmed high verdict.
     */
    #[Test]
    public function test_an_empty_baseline_keeps_the_redos_count(): void
    {
        $this->enterProject([
            'src/a.php' => <<<'PHP'
                <?php

                preg_match('/(a+)+$/', $subject);

                PHP,
            'baseline.json' => '[]',
        ]);
        $arguments = ['src', '--jobs=1', '--format=json', '--redos', '--redos-mode=confirmed', '--redos-threshold=high', '--no-optimize'];

        [, $withoutBaseline] = $this->runLint($arguments);
        $this->assertSame(1, self::stats($withoutBaseline)['redos_errors'] ?? null);

        [, $json] = $this->runLint([...$arguments, '--baseline=baseline.json']);

        $this->assertSame(1, self::stats($json)['redos_errors'] ?? null);
    }

    /**
     * An empty baseline counts each kind as the report did: a lint error,
     * an info, a warning, and the optimizations of every pattern summed.
     * "a\d+?" stops its lazy run at the minimum ("a1" in "a123"); "[0-9]"
     * is "\d".
     */
    #[Test]
    public function test_an_empty_baseline_keeps_every_count(): void
    {
        $this->assertSame(1, preg_match('/a\d+?/', 'a123', $matches));
        $this->assertSame(['a1'], $matches);
        $this->enterProject([
            'src/a.php' => self::MULTIBYTE_CLASS_FILE,
            'src/b.php' => self::UNNAMED_QUANTIFIED_CAPTURE_FILE,
            'src/c.php' => <<<'PHP'
                <?php

                preg_match('/a\d+?/', $subject);
                preg_match('/x[0-9]y/', $subject);
                preg_match('/z[0-9]w/', $subject);

                PHP,
            'baseline.json' => '[]',
        ]);

        [, $withoutBaseline] = $this->runLint(['src', '--jobs=1', '--format=json']);
        [, $json] = $this->runLint(['src', '--jobs=1', '--format=json', '--baseline=baseline.json']);

        $expected = ['errors' => 1, 'warnings' => 1, 'optimizations' => 2, 'redos_errors' => 0, 'infos' => 1, 'lint_errors' => 1];
        $this->assertSame($expected, self::stats($withoutBaseline));
        $this->assertSame($expected, self::stats($json));
    }

    /**
     * Through a call so that static analysis does not compile it.
     */
    private static function invalidPattern(): string
    {
        return '/(unclosed/';
    }

    private static function summaryLine(string $stdout): string
    {
        self::assertNotFalse(preg_match_all('/^\s*((?:FAIL|PASS|WARN)\b.*)$/m', $stdout, $lines));
        self::assertNotSame([], $lines[1], $stdout);

        return $lines[1][\count($lines[1]) - 1];
    }

    /**
     * @return array<mixed>
     */
    private static function stats(string $json): array
    {
        $payload = json_decode($json, true);
        self::assertIsArray($payload, 'stdout is not JSON: '.$json);
        self::assertIsArray($payload['stats'] ?? null, $json);

        return $payload['stats'];
    }

    /**
     * @return array<string, string> issue id => type
     */
    private static function issueTypes(string $json): array
    {
        $payload = json_decode($json, true);
        self::assertIsArray($payload, 'stdout is not JSON: '.$json);
        self::assertIsArray($payload['results'] ?? null);

        $types = [];
        foreach ($payload['results'] as $result) {
            self::assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                self::assertIsArray($issue);
                self::assertIsString($issue['issue_id'] ?? null);
                self::assertIsString($issue['severity'] ?? null);
                $types[$issue['issue_id']] = $issue['severity'];
            }
        }

        return $types;
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
