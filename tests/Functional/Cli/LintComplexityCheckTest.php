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
use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * checks.complexity in regex.json: regex lint reports a pattern whose
 * complexity score reaches the threshold, and says nothing of it while the
 * check is off, as it is by default.
 */
final class LintComplexityCheckTest extends TestCase
{
    use TemporaryProject;

    /**
     * Scores 56, and nothing else reports it.
     */
    private const HIGH = '/^(ab|cd)(ef|gh)(ij|kl)(mn|op)(qr|st)(uv|wx)(yz|AB)(CD|EF)(GH|IJ)$/';

    /**
     * Scores 16, and nothing else reports it.
     */
    private const LOW = '/^(a|b|c)(d|e|f)(g|h)$/';

    #[Test]
    public function test_the_fixtures_score_what_the_cases_assume(): void
    {
        $parser = RegexParser::create();

        $this->assertSame(56, $parser->validate(self::HIGH)->complexityScore);
        $this->assertSame(16, $parser->validate(self::LOW)->complexityScore);
    }

    /**
     * @return iterable<string, array{config: string|null, expected: list<string>}>
     */
    public static function provideConfigs(): iterable
    {
        yield 'no regex.json' => ['config' => null, 'expected' => []];
        yield 'checks without complexity' => ['config' => '{"checks": {"lint": {"enabled": true}}}', 'expected' => []];
        yield 'turned off' => ['config' => '{"checks": {"complexity": {"enabled": false, "threshold": 1}}}', 'expected' => []];
        yield 'a threshold alone' => ['config' => '{"checks": {"complexity": {"threshold": 1}}}', 'expected' => []];
        yield 'on at the default threshold' => ['config' => '{"checks": {"complexity": {"enabled": true}}}', 'expected' => ['Pattern is complex (score: 56).']];
        yield 'on at the low score' => ['config' => '{"checks": {"complexity": {"enabled": true, "threshold": 16}}}', 'expected' => ['Pattern is complex (score: 56).', 'Pattern is complex (score: 16).']];
        yield 'on one above the low score' => ['config' => '{"checks": {"complexity": {"enabled": true, "threshold": 17}}}', 'expected' => ['Pattern is complex (score: 56).']];
        yield 'on at the high score' => ['config' => '{"checks": {"complexity": {"enabled": true, "threshold": 56}}}', 'expected' => ['Pattern is complex (score: 56).']];
        yield 'on one above the high score' => ['config' => '{"checks": {"complexity": {"enabled": true, "threshold": 57}}}', 'expected' => []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideConfigs')]
    public function test_lint_reports_complexity_at_the_threshold_only_when_turned_on(?string $config, array $expected): void
    {
        $this->enterProject(['src/a.php' => self::phpFile()] + (null === $config ? [] : ['regex.json' => $config]));

        [$exitCode, $stdout] = $this->runLint(['src', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame($expected, array_column($this->complexityIssues($stdout), 'message'));
    }

    #[Test]
    public function test_lint_reports_complexity_as_a_warning_with_its_identifier(): void
    {
        $this->enterProject(['src/a.php' => self::phpFile(), 'regex.json' => '{"checks": {"complexity": {"enabled": true}}}']);

        [, $stdout] = $this->runLint(['src', '--format=json']);
        $issues = $this->complexityIssues($stdout);

        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertSame(3, $issues[0]['line']);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['format' => 'console'];
        yield 'github' => ['format' => 'github'];
        yield 'checkstyle' => ['format' => 'checkstyle'];
        yield 'junit' => ['format' => 'junit'];
    }

    #[Test]
    #[DataProvider('provideFormats')]
    public function test_every_output_format_carries_the_warning(string $format): void
    {
        $this->enterProject(['src/a.php' => self::phpFile()]);
        [, $without] = $this->runLint(['src', '--format='.$format]);

        $this->enterProject(['src/a.php' => self::phpFile(), 'regex.json' => '{"checks": {"complexity": {"enabled": true}}}']);
        [, $with] = $this->runLint(['src', '--format='.$format]);

        $this->assertStringNotContainsString('Pattern is complex', $without);
        $this->assertStringContainsString('Pattern is complex (score: 56).', $with);
    }

    #[Test]
    public function test_a_baseline_silences_the_warning(): void
    {
        $this->enterProject(['src/a.php' => self::phpFile(), 'regex.json' => '{"checks": {"complexity": {"enabled": true, "threshold": 16}}}']);

        [$generated] = $this->runLint(['src', '--format=json', '--generate-baseline=regex-baseline.json']);
        $this->assertSame(0, $generated);
        $this->assertStringContainsString('regex.lint.complexity', (string) file_get_contents('regex-baseline.json'));

        [$exitCode, $stdout] = $this->runLint(['src', '--format=json', '--baseline=regex-baseline.json']);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame([], $this->complexityIssues($stdout));
    }

    private static function phpFile(): string
    {
        return "<?php\n\npreg_match('".self::HIGH."', \$subject);\npreg_match('".self::LOW."', \$subject);\n";
    }

    /**
     * @return list<array<mixed>>
     */
    private function complexityIssues(string $stdout): array
    {
        $payload = json_decode($stdout, true);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$stdout);
        $this->assertIsArray($payload['results'] ?? null);

        $issues = [];
        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result);
            $this->assertIsArray($result['issues'] ?? null);
            foreach ($result['issues'] as $issue) {
                $this->assertIsArray($issue);
                if ('regex.lint.complexity' === ($issue['issue_id'] ?? null)) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * Runs the command the way the application builds its input.
     *
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
        $input = new Input('lint', [...$args, '--jobs=1', '--no-redos', '--no-optimize'], new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            $exitCode = $command->run($input, OutputFactory::create());
        } finally {
            $stdout = (string) ob_get_clean();
        }

        return [$exitCode, $stdout];
    }
}
