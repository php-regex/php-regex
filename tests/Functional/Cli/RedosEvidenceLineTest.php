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
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationSample;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex analyze" and "regex debug" say what the engine did with a verdict
 * in the words of the lint report, and decide the exit code by the rule the
 * linter uses for an error.
 *
 * The analyses are built by hand: a real replay runs with the JIT off and
 * stops at the backtrack limit, so the JIT setting and the other limits are
 * reached only this way.
 */
final class RedosEvidenceLineTest extends TestCase
{
    private const EVIDENCE_PREFIXES = ['Attack: ', 'Replayed on ', 'Not reproduced on '];

    /**
     * The attack and the replay lines of the console are the lint report's,
     * one for one.
     */
    #[Test]
    #[DataProvider('provideAnalyses')]
    public function test_console_evidence_is_the_lint_evidence(RedosAnalysis $analysis): void
    {
        $lines = array_values(array_filter(
            self::verdictLines($analysis),
            static function (string $line): bool {
                foreach (self::EVIDENCE_PREFIXES as $prefix) {
                    if (str_starts_with($line, $prefix)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $this->assertSame(RedosVerdict::evidence($analysis), $lines);
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis}>
     */
    public static function provideAnalyses(): iterable
    {
        yield 'replayed with the JIT on' => ['analysis' => self::replayed('1', \PREG_BACKTRACK_LIMIT_ERROR, 'backtrack_limit')];
        yield 'replayed with the JIT off' => ['analysis' => self::replayed('0', \PREG_BACKTRACK_LIMIT_ERROR, 'backtrack_limit')];
        yield 'replayed, evidence the recursion limit' => ['analysis' => self::replayed('0', \PREG_RECURSION_LIMIT_ERROR, 'recursion_limit')];
        yield 'replayed, evidence the JIT stack limit' => ['analysis' => self::replayed('1', \PREG_JIT_STACKLIMIT_ERROR, 'jit_stack_limit')];
        yield 'not reproduced' => ['analysis' => self::analysis(replayed: false, confirmation: self::confirmation(false, '0', null, null))];
    }

    /**
     * The JIT setting printed is the one the confirmation reports, not a
     * fixed "JIT off".
     */
    #[Test]
    public function test_console_replay_line_names_the_jit_setting_of_the_confirmation(): void
    {
        $lines = self::verdictLines(self::replayed('1', \PREG_BACKTRACK_LIMIT_ERROR, 'backtrack_limit'));

        $replay = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Replayed on ')));
        $this->assertCount(1, $replay, implode("\n", $lines));
        $this->assertStringContainsString('JIT on', $replay[0]);
        $this->assertStringNotContainsString('JIT off', $replay[0]);
    }

    /**
     * A replay the engine stopped at another limit than the backtrack limit
     * still has its line: the verdict was reproduced.
     */
    #[Test]
    #[DataProvider('provideOtherLimits')]
    public function test_console_replay_line_is_printed_for_another_limit(int $pregErrorCode, string $evidence): void
    {
        $lines = self::verdictLines(self::replayed('0', $pregErrorCode, $evidence));

        $replay = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Replayed on PCRE2 ')));
        $this->assertCount(1, $replay, implode("\n", $lines));
    }

    /**
     * @return iterable<string, array{pregErrorCode: int, evidence: string}>
     */
    public static function provideOtherLimits(): iterable
    {
        yield 'recursion limit' => ['pregErrorCode' => \PREG_RECURSION_LIMIT_ERROR, 'evidence' => 'recursion_limit'];
        yield 'JIT stack limit' => ['pregErrorCode' => \PREG_JIT_STACKLIMIT_ERROR, 'evidence' => 'jit_stack_limit'];
    }

    /**
     * Exit code 1 when the linter would report an error: a confirmed
     * analysis (RedosAnalysis::isConfirmed()) at high or above, the rule of
     * AnalysisService. The witness' replay flag alone does not decide.
     */
    #[Test]
    #[DataProvider('provideExitDecisions')]
    public function test_console_exit_decision_is_the_lint_error_rule(RedosAnalysis $analysis, bool $error): void
    {
        $this->assertSame($error, $analysis->isConfirmed() && $analysis->exceedsThreshold(RedosSeverity::High));

        $this->assertSame($error, self::confirmedRedos($analysis));
    }

    /**
     * @return iterable<string, array{analysis: RedosAnalysis, error: bool}>
     */
    public static function provideExitDecisions(): iterable
    {
        yield 'replayed without a confirmation' => [
            'analysis' => self::analysis(replayed: true, confirmation: null),
            'error' => false,
        ];
        yield 'confirmed, witness not reproduced' => [
            'analysis' => self::analysis(replayed: false, confirmation: self::confirmation(true, '0', \PREG_BACKTRACK_LIMIT_ERROR, 'backtrack_limit')),
            'error' => true,
        ];
        yield 'replayed and confirmed' => [
            'analysis' => self::replayed('0', \PREG_BACKTRACK_LIMIT_ERROR, 'backtrack_limit'),
            'error' => true,
        ];
    }

    private static function replayed(string $jitSetting, int $pregErrorCode, string $evidence): RedosAnalysis
    {
        return self::analysis(replayed: true, confirmation: self::confirmation(true, $jitSetting, $pregErrorCode, $evidence));
    }

    private static function analysis(?bool $replayed, ?Confirmation $confirmation): RedosAnalysis
    {
        return new RedosAnalysis(
            RedosSeverity::Critical,
            100,
            mode: RedosMode::Confirmed,
            confirmation: $confirmation,
            complexity: RedosComplexity::Exponential,
            proof: RedosProof::Proven,
            witness: new RedosWitness('', 'a', '!', false),
            replayed: $replayed,
            pcreVersion: '10.49',
        );
    }

    private static function confirmation(bool $confirmed, string $jitSetting, ?int $pregErrorCode, ?string $evidence): Confirmation
    {
        $samples = [new ConfirmationSample(4, 0.1)];
        if (null !== $pregErrorCode) {
            $samples[] = new ConfirmationSample(17, 2.0, null, $pregErrorCode, 'limit exhausted');
        }

        return new Confirmation($confirmed, $samples, $jitSetting, 100000, 100000, 64, 0.0, evidence: $evidence);
    }

    /**
     * @return list<string>
     */
    private static function verdictLines(RedosAnalysis $analysis): array
    {
        return self::probe($analysis)['lines'];
    }

    private static function confirmedRedos(RedosAnalysis $analysis): bool
    {
        return self::probe($analysis)['error'];
    }

    /**
     * The verdict lines and the exit decision of a command, through the
     * helpers "regex analyze" and "regex debug" share.
     *
     * @return array{lines: list<string>, error: bool}
     */
    private static function probe(RedosAnalysis $analysis): array
    {
        $command = new class extends AbstractCommand {
            public function getName(): string
            {
                return 'evidence';
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

            /**
             * @return array{lines: list<string>, error: bool}
             */
            public function probe(RedosAnalysis $analysis): array
            {
                return ['lines' => $this->redosVerdictLines($analysis), 'error' => $this->isConfirmedRedos($analysis, null)];
            }
        };

        return $command->probe($analysis);
    }
}
