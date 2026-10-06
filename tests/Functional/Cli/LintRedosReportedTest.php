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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex lint --redos" runs the ReDoS analysis: 1.x parsed the flag, kept
 * it in the request, and built the analysis with ReDoS switched off, so only
 * the lint-rule issues ever appeared.
 */
final class LintRedosReportedTest extends TestCase
{
    use TemporaryProject;

    private const VULNERABLE_FILE = <<<'PHP'
        <?php

        preg_match('/(a+)+$/', $subject);

        PHP;

    private const CUBIC_FILE = <<<'PHP'
        <?php

        preg_match('/a*a*a*$/', $subject);

        PHP;

    #[Test]
    public function test_lint_redos_flag_reports_the_redos_issue(): void
    {
        $this->enterProject(['src/a.php' => self::VULNERABLE_FILE]);

        [, $stdout] = $this->runLint(['src', '--redos', '--format=json', '--jobs=1']);

        $this->assertContains('regex.lint.redos', self::issueIds($stdout), $stdout);
    }

    #[Test]
    public function test_lint_without_redos_flag_reports_no_redos_issue(): void
    {
        $this->enterProject(['src/a.php' => self::VULNERABLE_FILE]);

        [, $stdout] = $this->runLint(['src', '--no-redos', '--format=json', '--jobs=1']);

        $this->assertNotContains('regex.lint.redos', self::issueIds($stdout), $stdout);
    }

    /**
     * Only a replayed verdict at or above high fails the run.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideExitCodes')]
    public function test_lint_redos_exit_code_follows_the_replay(string $file, array $arguments, string $type, int $exitCode): void
    {
        $this->enterProject(['src/a.php' => $file]);

        [$code, $stdout] = $this->runLint(['src', '--redos', ...$arguments, '--format=json', '--jobs=1']);

        $types = self::redosIssueTypes($stdout);
        $this->assertSame([$type], $types, $stdout);
        $this->assertSame($exitCode, $code, $stdout);
    }

    /**
     * @return iterable<string, array{file: string, arguments: list<string>, type: string, exitCode: int}>
     */
    public static function provideExitCodes(): iterable
    {
        yield 'theoretical exponential' => ['file' => self::VULNERABLE_FILE, 'arguments' => [], 'type' => 'warning', 'exitCode' => 0];
        yield 'replayed exponential' => ['file' => self::VULNERABLE_FILE, 'arguments' => ['--redos-mode=confirmed'], 'type' => 'error', 'exitCode' => 1];
        // Degree 3, high, never replayed: reported, but never an error.
        yield 'confirmed cubic' => ['file' => self::CUBIC_FILE, 'arguments' => ['--redos-mode=confirmed'], 'type' => 'warning', 'exitCode' => 0];
    }

    /**
     * @return list<string>
     */
    private static function issueIds(string $json): array
    {
        return array_map(static fn (array $issue): string => \is_string($issue['issue_id'] ?? null) ? $issue['issue_id'] : '', self::issues($json));
    }

    /**
     * @return list<string>
     */
    private static function redosIssueTypes(string $json): array
    {
        $types = [];
        foreach (self::issues($json) as $issue) {
            if ('regex.lint.redos' === ($issue['issue_id'] ?? null)) {
                $types[] = \is_string($issue['severity'] ?? null) ? $issue['severity'] : '';
            }
        }

        return $types;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function issues(string $json): array
    {
        $payload = json_decode($json, true);
        self::assertIsArray($payload, 'stdout is not JSON: '.$json);
        self::assertIsArray($payload['results'] ?? null);

        $issues = [];
        foreach ($payload['results'] as $result) {
            self::assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                self::assertIsArray($issue);
                $issues[] = $issue;
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
