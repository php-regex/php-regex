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

use PHPRegex\Cli\Command\AbstractCommand;
use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What "regex analyze" and "regex debug" print around the headline: one
 * "Model:" line when the verdict is about an abstracted model, and the
 * analysis error named in the headline.
 */
final class RedosModelLineTest extends TestCase
{
    /**
     * {1,20} is past the cutoff of 16 and analysed as {1,}; a…a! fails at
     * n=19 (PCRE2 10.49).
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_model_line_lists_the_abstraction(string $command): void
    {
        $buffer = $this->runCommand($command, ['/(a{1,20})+$/']);

        $this->assertSame(1, preg_match_all('/^\s*Model: (.*)$/m', $buffer, $lines), $buffer);
        $this->assertStringContainsString(self::abstractions('/(a{1,20})+$/')[0], $lines[1][0]);
    }

    /**
     * Two bounded repeats past the cutoff: both on the one line.
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_model_line_lists_every_abstraction_on_one_line(string $command): void
    {
        $pattern = '/(a{1,20})+(b{1,30})+$/';
        $abstractions = self::abstractions($pattern);
        $this->assertCount(2, $abstractions);

        $buffer = $this->runCommand($command, [$pattern]);

        $this->assertSame(1, preg_match_all('/^\s*Model: (.*)$/m', $buffer, $lines), $buffer);
        foreach ($abstractions as $abstraction) {
            $this->assertStringContainsString($abstraction, $lines[1][0]);
        }
    }

    #[Test]
    #[DataProvider('provideCommands')]
    public function test_no_model_line_without_abstraction(string $command): void
    {
        $this->assertSame([], self::abstractions('/(a+)+$/'));

        $buffer = $this->runCommand($command, ['/(a+)+$/']);

        $this->assertDoesNotMatchRegularExpression('/^\s*Model:/m', $buffer);
    }

    /**
     * "regex debug" analyses before it parses: a pattern the parser refuses
     * reaches the verdict as an analysis error.
     */
    #[Test]
    public function test_debug_names_the_analysis_error_in_the_headline(): void
    {
        $buffer = $this->runCommand('debug', ['/(unclosed/']);

        $this->assertStringContainsString('not analyzed (analysis error)', $buffer);
        $this->assertStringContainsString('Expected ) at end of input', $buffer);
    }

    /**
     * PCRE refuses to compile [z-a] ("range out of order"): no match attempt
     * exists, so the verdict is never "safe (proven)".
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_pattern_rejected_by_validation_is_never_proven_safe(string $command): void
    {
        $buffer = $this->runCommand($command, ['/[z-a]/']);

        $this->assertStringNotContainsString('safe (proven)', $buffer);
        $this->assertStringContainsString('not analyzed (analysis error)', $buffer);
    }

    /**
     * "regex analyze" parses first and stops at "Analyze failed" on a
     * pattern the parser refuses, so no input reaches its verdict with an
     * error: the headline rule it shares with "regex debug" is pinned here.
     */
    #[Test]
    public function test_headline_of_an_analysis_error(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::Unknown, 0, error: 'boom', proof: RedosProof::NotAnalyzed);

        $this->assertSame('not analyzed (analysis error)', self::headline($analysis));
    }

    /**
     * Not analysed without an error (an ignored pattern): the bare form.
     */
    #[Test]
    public function test_headline_of_a_pattern_not_analyzed_without_error(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::Safe, 0, proof: RedosProof::NotAnalyzed);

        $this->assertSame('not analyzed', self::headline($analysis));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'analyze' => ['analyze'];
        yield 'debug' => ['debug'];
    }

    /**
     * @return list<string>
     */
    private static function abstractions(string $pattern): array
    {
        return (new RedosAnalyzer())->analyze($pattern)->abstractions;
    }

    private static function headline(RedosAnalysis $analysis): string
    {
        $command = new class extends AbstractCommand {
            public function getName(): string
            {
                return 'headline';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getDescription(): string
            {
                return '';
            }

            public function run(Input $input, Output $output): int
            {
                return 0;
            }

            public function headline(RedosAnalysis $analysis): string
            {
                return $this->redosHeadline($analysis);
            }
        };

        return $command->headline($analysis);
    }

    /**
     * @param list<string> $arguments
     */
    private function runCommand(string $command, array $arguments): string
    {
        $handler = self::command($command);
        $input = new Input($command, $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            $handler->run($input, new Output(false, false, errorStream: fopen('php://memory', 'w+')));
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $buffer;
    }

    private static function command(string $name): CommandInterface
    {
        return 'debug' === $name ? new DebugCommand() : new AnalyzeCommand();
    }
}
