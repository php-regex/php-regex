<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cli\Command\AnalyzeCommand;
use RegexParser\Cli\Command\CommandInterface;
use RegexParser\Cli\Command\DebugCommand;
use RegexParser\Cli\GlobalOptions;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\Lint\Command\LintArgumentParser;
use RegexParser\Lint\Command\LintArguments;
use RegexParser\Lint\Command\LintConfigLoader;
use RegexParser\Lint\Command\LintDefaultsBuilder;
use RegexParser\Tests\Support\TemporaryProject;

/**
 * --redos-threshold on the command line reads like every other threshold:
 * low, medium, high or critical, in any case; "safe" and "unknown" are
 * verdicts, refused with the value quoted.
 */
final class RedosThresholdOptionTest extends TestCase
{
    use TemporaryProject;

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function provideRefusedOptions(): iterable
    {
        yield 'safe, equals form' => [['--redos-threshold=safe'], 'safe'];
        yield 'unknown, separate value' => [['--redos-threshold', 'UNKNOWN'], 'UNKNOWN'];
        yield 'a word that is no severity' => [['--redos-threshold=severe'], 'severe'];
    }

    /**
     * @param list<string> $option
     */
    #[Test]
    #[DataProvider('provideRefusedOptions')]
    public function test_lint_refuses_a_threshold_that_names_no_severity(array $option, string $value): void
    {
        $result = (new LintArgumentParser())->parse($option);

        $this->assertNull($result->arguments);
        $this->assertStringContainsString('--redos-threshold', (string) $result->error);
        $this->assertStringContainsString('"'.$value.'"', (string) $result->error);
    }

    #[Test]
    public function test_lint_reads_a_threshold_in_any_case(): void
    {
        $result = (new LintArgumentParser())->parse(['--redos-threshold=Critical']);

        $this->assertInstanceOf(LintArguments::class, $result->arguments);
        $this->assertSame('critical', $result->arguments->redosThreshold);
    }

    /**
     * @param list<string> $option
     */
    #[Test]
    #[DataProvider('provideRefusedOptions')]
    public function test_analyze_refuses_a_threshold_that_names_no_severity(array $option, string $value): void
    {
        [$exitCode, $buffer] = $this->runCommand(new AnalyzeCommand(), 'analyze', ['/a+/', ...$option]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('"'.$value.'"', $buffer);
    }

    /**
     * @param list<string> $option
     */
    #[Test]
    #[DataProvider('provideRefusedOptions')]
    public function test_debug_refuses_a_threshold_that_names_no_severity(array $option, string $value): void
    {
        [$exitCode, $buffer] = $this->runCommand(new DebugCommand(), 'debug', ['/a+/', ...$option]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('"'.$value.'"', $buffer);
    }

    #[Test]
    public function test_analyze_and_debug_read_a_threshold_in_any_case(): void
    {
        [$analyzeExit] = $this->runCommand(new AnalyzeCommand(), 'analyze', ['/a+/', '--redos-threshold=HIGH']);
        [$debugExit] = $this->runCommand(new DebugCommand(), 'debug', ['/a+/', '--redos-threshold', 'Low']);

        $this->assertSame(0, $analyzeExit);
        $this->assertSame(0, $debugExit);
    }

    #[Test]
    public function test_debug_reads_the_threshold_of_regex_json_in_any_case(): void
    {
        // "(a+)+$" is critical: reported from a CRITICAL threshold.
        $this->enterProject(['regex.json' => '{"checks": {"redos": {"threshold": "CRITICAL"}}}']);

        [$exitCode, $buffer] = $this->runCommand(
            new DebugCommand(new LintConfigLoader(), new LintDefaultsBuilder()),
            'debug',
            ['/(a+)+$/', '--format=json'],
        );

        $this->assertSame(0, $exitCode, $buffer);
        $this->assertIsArray(json_decode($buffer, true), $buffer);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function runCommand(CommandInterface $command, string $name, array $args): array
    {
        $input = new Input($name, $args, new GlobalOptions(false, false, false, true, null, null), []);
        $output = new Output(false, false);

        ob_start();
        $exitCode = $command->run($input, $output);
        $buffer = (string) ob_get_clean();

        return [$exitCode, $buffer];
    }
}
