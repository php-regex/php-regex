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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationRunner;
use PHPRegex\Redos\ConfirmationRunnerInterface;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Redos\WitnessCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness says which call of preg_match() it drives into the backtrack
 * limit: "/(?:|(?:a|a)*$)/" exhausts it only without $matches, where the
 * call with $matches settles on the empty match. The library's runner
 * replays a witness by that call, whether the analyzer made it or the
 * caller injected it.
 */
final class RedosWitnessCallTest extends TestCase
{
    private const WITHOUT_MATCHES = '/(?:|(?:a|a)*$)/';

    /**
     * @param \Closure(): ?ConfirmationRunnerInterface $runner
     */
    #[Test]
    #[DataProvider('provideRunners')]
    public function test_a_witness_that_needs_the_call_without_matches_is_replayed_by_it(\Closure $runner): void
    {
        $this->assertSame([false, true], self::underTheLimit(self::WITHOUT_MATCHES, str_repeat('aa', 30).'!'), 'The engine gives up without $matches alone.');

        $analysis = (new RedosAnalyzer(RegexParser::create(['cache' => null]), [], $runner()))->analyze(self::WITHOUT_MATCHES, new RedosOptions(RedosMode::Confirmed));

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertInstanceOf(RedosWitness::class, $analysis->witness);
        $this->assertSame(WitnessCall::WithoutMatches, $analysis->witness->call);
        $this->assertTrue($analysis->replayed);
        $this->assertInstanceOf(Confirmation::class, $analysis->confirmation);
        $this->assertTrue($analysis->confirmation->confirmed);
        $this->assertSame(Confirmation::WITHOUT_MATCHES, $analysis->confirmation->note);
        $this->assertSame('without_matches', $analysis->jsonSerialize()['witness']['call'] ?? null);
    }

    /**
     * @param \Closure(): ?ConfirmationRunnerInterface $runner
     */
    #[Test]
    #[DataProvider('provideRunners')]
    public function test_a_witness_that_needs_no_such_call_is_replayed_with_matches(\Closure $runner): void
    {
        $this->assertSame([true, true], self::underTheLimit('/(a+)+$/', str_repeat('a', 30).'!'), 'The engine gives up with $matches and without.');

        $analysis = (new RedosAnalyzer(RegexParser::create(['cache' => null]), [], $runner()))->analyze('/(a+)+$/', new RedosOptions(RedosMode::Confirmed));

        $this->assertInstanceOf(RedosWitness::class, $analysis->witness);
        $this->assertSame(WitnessCall::WithMatches, $analysis->witness->call);
        $this->assertTrue($analysis->replayed);
        $this->assertInstanceOf(Confirmation::class, $analysis->confirmation);
        $this->assertNull($analysis->confirmation->note);
        $this->assertSame('with_matches', $analysis->jsonSerialize()['witness']['call'] ?? null);
    }

    /**
     * @return iterable<string, array{runner: \Closure(): ?ConfirmationRunnerInterface}>
     */
    public static function provideRunners(): iterable
    {
        yield 'the default runner' => ['runner' => static fn (): ?ConfirmationRunnerInterface => null];
        yield 'the library runner, injected' => ['runner' => static fn (): ConfirmationRunner => new ConfirmationRunner()];
    }

    /**
     * The runner reads the call from the witness it is given: the same
     * witness gives up without $matches and not with them.
     */
    #[Test]
    public function test_the_runner_replays_the_call_the_witness_names(): void
    {
        $this->assertSame([false, true], self::underTheLimit(self::WITHOUT_MATCHES, str_repeat('aa', 30).'!'));

        $options = new ConfirmationOptions();
        $without = (new ConfirmationRunner())->confirm(self::WITHOUT_MATCHES, self::exponential(new RedosWitness('', 'aa', '!', false, WitnessCall::WithoutMatches)), $options);
        $with = (new ConfirmationRunner())->confirm(self::WITHOUT_MATCHES, self::exponential(new RedosWitness('', 'aa', '!', false, WitnessCall::WithMatches)), $options);

        $this->assertTrue($without->confirmed);
        $this->assertSame(Confirmation::WITHOUT_MATCHES, $without->note);
        $this->assertFalse($with->confirmed);
        $this->assertNull($with->note);
    }

    #[Test]
    public function test_a_witness_is_one_of_the_call_with_matches_unless_it_names_another(): void
    {
        $witness = new RedosWitness('', 'a', '!', false);

        $this->assertSame(WitnessCall::WithMatches, $witness->call);
        $this->assertSame(['prefix' => '', 'pump' => 'a', 'suffix' => '!', 'call' => 'with_matches'], $witness->toArray());
        $this->assertSame(['prefix' => '', 'pump' => 'a', 'suffix' => '!', 'call' => 'without_matches'], (new RedosWitness('', 'a', '!', false, WitnessCall::WithoutMatches))->toArray());
        $this->assertSame(['with_matches', 'without_matches'], array_map(static fn (WitnessCall $call): string => $call->value, WitnessCall::cases()));
    }

    private static function exponential(RedosWitness $witness): RedosAnalysis
    {
        return new RedosAnalysis(RedosSeverity::Critical, 100, mode: RedosMode::Confirmed, complexity: RedosComplexity::Exponential, proof: RedosProof::Proven, witness: $witness);
    }

    /**
     * Whether preg_match() gives up on the subject, with $matches then
     * without, under a backtrack limit of 100,000 and without the JIT.
     *
     * @return array{bool, bool}
     */
    private static function underTheLimit(string $pattern, string $subject): array
    {
        $limit = \ini_get('pcre.backtrack_limit');
        $jit = \ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '100000');
        ini_set('pcre.jit', '0');

        try {
            $with = false !== @preg_match($pattern, $subject, $matches);
            $without = false !== @preg_match($pattern, $subject);

            return [!$with, !$without];
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
        }
    }
}
