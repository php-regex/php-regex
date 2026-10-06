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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationRunnerInterface;
use PHPRegex\Redos\ConfirmationSample;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Confirmed mode where the witness cannot be replayed: ini_set() disabled
 * leaves the engine no way to set the replay's limits, nor to turn the JIT
 * off for a pattern whose delimiter leaves no room for "(*NO_JIT)". The
 * proof stands at its severity, the replay is skipped ("replayed" null),
 * and the confirmation says why.
 *
 * Only the library's own exceptions make a pattern "not analyzed"; any
 * other throwable met during the analysis is a defect and surfaces.
 *
 * Oracle (PHP 8.4, PCRE2 10.49, JIT off): every pattern below fails
 * preg_match() with "Backtrack limit exhausted" on "a" x 30 . "!".
 */
final class RedosConfirmedReplayUnavailableTest extends TestCase
{
    private const LIMITS_UNAVAILABLE = 'engine limits unavailable';

    private const JIT_STAYS_ON = 'JIT cannot be turned off';

    /**
     * @param list<string> $evidence the evidence the confirmation may give
     */
    #[Test]
    #[DataProvider('provideUnreplayablePatterns')]
    public function test_confirmed_mode_keeps_the_proof_when_the_replay_cannot_run(string $pattern, array $evidence): void
    {
        $result = self::analyzeWithoutIniSet($pattern);

        $this->assertFalse($result['ini_set'], 'The child process runs with ini_set() disabled.');
        $this->assertNull($result['error'], (string) json_encode($result));
        $this->assertSame(RedosSeverity::Critical->value, $result['severity']);
        $this->assertSame(RedosProof::Proven->value, $result['proof']);
        $this->assertSame('exponential', $result['complexity']);
        $this->assertTrue($result['witness'], 'The witness of the proof is kept.');
        $this->assertNull($result['replayed']);
        $this->assertIsArray($result['confirmation'], 'The confirmation says why the replay was skipped.');
        $this->assertFalse($result['confirmation']['confirmed']);
        $this->assertSame([], $result['confirmation']['samples'], 'Nothing was run.');
        $this->assertContains($result['confirmation']['evidence'], $evidence);
    }

    /**
     * @return iterable<string, array{pattern: string, evidence: list<string>}>
     */
    public static function provideUnreplayablePatterns(): iterable
    {
        yield 'alternation under a plus, end anchor' => ['pattern' => '/(a|a)+$/', 'evidence' => [self::LIMITS_UNAVAILABLE]];
        yield 'anchored alternation under a star' => ['pattern' => '/^(a|a)*$/', 'evidence' => [self::LIMITS_UNAVAILABLE]];
        // The body holds every spare delimiter and leaves no bracket pair
        // whole: the JIT could only be turned off through ini_set(), and the
        // limits need it too. Either cause is the evidence.
        yield 'a delimiter that leaves no room for (*NO_JIT)' => [
            'pattern' => "_^[])(}{><\x01#~%!@;,]?(a|a)*\$_",
            'evidence' => [self::LIMITS_UNAVAILABLE, self::JIT_STAYS_ON],
        ];
    }

    /**
     * A throwable that is not one of the library's exceptions surfaces from
     * the analysis, from the proven path (the replay) as from the heuristic
     * one (the confirmation of a verdict over the model's budget).
     *
     * @param class-string<\Throwable> $class
     */
    #[Test]
    #[DataProvider('provideForeignThrowables')]
    public function test_a_throwable_from_outside_the_library_surfaces(string $pattern, \Throwable $thrown, string $class): void
    {
        $analyzer = new RedosAnalyzer(confirmationRunner: self::throwingRunner($thrown));

        $this->expectException($class);
        $this->expectExceptionMessage($thrown->getMessage());

        $analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);
    }

    /**
     * @return iterable<string, array{pattern: string, thrown: \Throwable, class: class-string<\Throwable>}>
     */
    public static function provideForeignThrowables(): iterable
    {
        yield 'logic exception, proven verdict' => ['pattern' => '/(a|a)+$/', 'thrown' => new \LogicException('runner bug'), 'class' => \LogicException::class];
        yield 'type error, proven verdict' => ['pattern' => '/(a|a)+$/', 'thrown' => new \TypeError('runner type bug'), 'class' => \TypeError::class];
        // 16 x 16 x 16 unrolled states exceed the model's budget: the
        // heuristics decide and the runner confirms.
        yield 'logic exception, verdict over the budget' => ['pattern' => '/^(?:(?:a{16}){16}){16}(a+)+$/', 'thrown' => new \LogicException('runner bug'), 'class' => \LogicException::class];
    }

    /**
     * The library's own exception still makes the pattern "not analyzed".
     */
    #[Test]
    public function test_a_library_exception_makes_the_pattern_not_analyzed(): void
    {
        $analyzer = new RedosAnalyzer(confirmationRunner: self::throwingRunner(new InvalidRegexOptionException('runner refused its options')));

        $analysis = $analyzer->analyze('/(a|a)+$/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosProof::NotAnalyzed, $analysis->proof);
        $this->assertSame(RedosSeverity::Unknown, $analysis->severity);
        $this->assertStringContainsString('runner refused its options', (string) $analysis->error);
    }

    /**
     * A runner given in place of the engine answers for the replay. When
     * its answer is a replay it could not make, nothing run and the limits
     * named as the cause, the proof stands with its witness and "replayed"
     * stays null; when it ran the witness without reproducing it,
     * "replayed" is false. Either way the confidence stays medium.
     */
    #[Test]
    #[DataProvider('provideRunnerAnswers')]
    public function test_a_runner_answer_that_does_not_reproduce_keeps_the_proof(Confirmation $answer, ?bool $replayed): void
    {
        $analysis = (new RedosAnalyzer(confirmationRunner: self::answeringRunner($answer)))->analyze('/(a|a)+$/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertInstanceOf(RedosWitness::class, $analysis->witness);
        $this->assertSame($replayed, $analysis->replayed);
        $this->assertSame(RedosConfidence::Medium, $analysis->confidenceLevel());
        $this->assertSame($answer, $analysis->confirmation);
    }

    /**
     * @return iterable<string, array{answer: Confirmation, replayed: bool|null}>
     */
    public static function provideRunnerAnswers(): iterable
    {
        yield 'a replay the runner could not make' => [
            'answer' => new Confirmation(false, [], null, null, null, 0, 100.0, false, self::LIMITS_UNAVAILABLE),
            'replayed' => null,
        ];
        yield 'a replay that ran without reproducing' => [
            'answer' => new Confirmation(false, [new ConfirmationSample(31, 0.5)], 'off', 1000, 1000, 1, 100.0, false, 'none'),
            'replayed' => false,
        ];
    }

    /**
     * Only a confirmation that is not confirmed, holds no sample and names
     * the engine limits as its evidence is a replay that ran nothing.
     */
    #[Test]
    #[DataProvider('provideConfirmations')]
    public function test_was_skipped_recognises_only_a_replay_that_ran_nothing(?Confirmation $confirmation, bool $skipped): void
    {
        $this->assertSame(self::LIMITS_UNAVAILABLE, Confirmation::LIMITS_UNAVAILABLE);
        $this->assertSame($skipped, true === $confirmation?->wasSkipped());
    }

    /**
     * @return iterable<string, array{confirmation: Confirmation|null, skipped: bool}>
     */
    public static function provideConfirmations(): iterable
    {
        yield 'no confirmation' => ['confirmation' => null, 'skipped' => false];
        yield 'a replay that ran nothing' => [
            'confirmation' => new Confirmation(false, [], null, null, null, 0, 100.0, false, self::LIMITS_UNAVAILABLE),
            'skipped' => true,
        ];
        yield 'the same evidence, confirmed' => [
            'confirmation' => new Confirmation(true, [], null, null, null, 0, 100.0, false, self::LIMITS_UNAVAILABLE),
            'skipped' => false,
        ];
        yield 'the same evidence, with a sample' => [
            'confirmation' => new Confirmation(false, [new ConfirmationSample(31, 0.5)], null, null, null, 1, 100.0, false, self::LIMITS_UNAVAILABLE),
            'skipped' => false,
        ];
        yield 'another evidence' => [
            'confirmation' => new Confirmation(false, [], 'off', 1000, 1000, 0, 100.0, false, 'none'),
            'skipped' => false,
        ];
    }

    private static function answeringRunner(Confirmation $answer): ConfirmationRunnerInterface
    {
        return new class($answer) implements ConfirmationRunnerInterface {
            public function __construct(private readonly Confirmation $answer) {}

            public function confirm(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options = null): Confirmation
            {
                return $this->answer;
            }
        };
    }

    private static function throwingRunner(\Throwable $thrown): ConfirmationRunnerInterface
    {
        return new class($thrown) implements ConfirmationRunnerInterface {
            public function __construct(private readonly \Throwable $thrown) {}

            public function confirm(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options = null): Confirmation
            {
                throw $this->thrown;
            }
        };
    }

    /**
     * The analysis in a PHP process with ini_set() disabled, as JSON.
     *
     * @return array<array-key, mixed>
     */
    private static function analyzeWithoutIniSet(string $pattern): array
    {
        $script = 'require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'
            .'$analysis = (new \PHPRegex\Redos\RedosAnalyzer())->analyze('.var_export($pattern, true).', \PHPRegex\Redos\RedosSeverity::Low, \PHPRegex\Redos\RedosMode::Confirmed);'
            .'echo json_encode(['
            .'"ini_set" => function_exists("ini_set"),'
            .'"error" => $analysis->error,'
            .'"severity" => $analysis->severity->value,'
            .'"proof" => $analysis->proof->value,'
            .'"complexity" => $analysis->complexity->value,'
            .'"witness" => null !== $analysis->witness,'
            .'"replayed" => $analysis->replayed,'
            .'"confirmation" => $analysis->confirmation?->jsonSerialize(),'
            .'], JSON_INVALID_UTF8_SUBSTITUTE);';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'disable_functions=ini_set', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errors.$output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, $errors.$output);

        return $decoded;
    }
}
