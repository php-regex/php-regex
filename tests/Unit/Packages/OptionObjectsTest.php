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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An option object refuses a limit that means nothing, where it is built,
 * as OptimizerOptions already does: a negative backtrack limit or a solver
 * allowed no state would fail later, far from the caller's mistake.
 */
final class OptionObjectsTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * @param \Closure(): object $build
     */
    #[Test]
    #[DataProvider('provideMeaninglessLimits')]
    public function test_an_option_object_refuses_a_meaningless_limit(\Closure $build, string $option): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains($option);

        $build();
    }

    /**
     * @return iterable<string, array{build: \Closure(): object, option: string}>
     */
    public static function provideMeaninglessLimits(): iterable
    {
        yield 'negative backtrack limit' => ['build' => static fn (): object => new PcreLimits(-1, 100), 'option' => 'backtrackLimit'];
        yield 'zero recursion limit' => ['build' => static fn (): object => new PcreLimits(100, 0), 'option' => 'recursionLimit'];
        yield 'no ReDoS state' => ['build' => static fn (): object => new RedosOptions(maxStates: 0), 'option' => 'maxStates'];
        yield 'negative ReDoS steps' => ['build' => static fn (): object => new RedosOptions(maxSteps: -5), 'option' => 'maxSteps'];
        yield 'negative repeat cutoff' => ['build' => static fn (): object => new RedosOptions(boundedRepeatCutoff: -1), 'option' => 'boundedRepeatCutoff'];
        yield 'no NFA state' => ['build' => static fn (): object => new SolverOptions(maxNfaStates: 0), 'option' => 'maxNfaStates'];
        yield 'no DFA state' => ['build' => static fn (): object => new SolverOptions(maxDfaStates: -1), 'option' => 'maxDfaStates'];
        yield 'negative transition budget' => ['build' => static fn (): object => new SolverOptions(maxTransitionsProcessed: -1), 'option' => 'maxTransitionsProcessed'];
        yield 'empty probe subject' => ['build' => static fn (): object => new ConfirmationOptions(minInputLength: 0), 'option' => 'minInputLength'];
        yield 'probe maximum below its minimum' => ['build' => static fn (): object => new ConfirmationOptions(minInputLength: 32, maxInputLength: 16), 'option' => 'maxInputLength'];
        yield 'no probe step' => ['build' => static fn (): object => new ConfirmationOptions(steps: 0), 'option' => 'steps'];
        yield 'no probe iteration' => ['build' => static fn (): object => new ConfirmationOptions(iterations: -1), 'option' => 'iterations'];
        yield 'probe timeout below a millisecond' => ['build' => static fn (): object => new ConfirmationOptions(timeoutMs: 0.5), 'option' => 'timeoutMs'];
        yield 'probe timeout not a number' => ['build' => static fn (): object => new ConfirmationOptions(timeoutMs: \NAN), 'option' => 'timeoutMs'];
        yield 'no probe backtracking' => ['build' => static fn (): object => new ConfirmationOptions(backtrackLimit: 0), 'option' => 'backtrackLimit'];
        yield 'no probe recursion' => ['build' => static fn (): object => new ConfirmationOptions(recursionLimit: 0), 'option' => 'recursionLimit'];
        yield 'negative preview' => ['build' => static fn (): object => new ConfirmationOptions(previewLength: -1), 'option' => 'previewLength'];
    }

    #[Test]
    public function test_the_smallest_meaningful_limits_are_taken(): void
    {
        $this->assertSame(1, (new PcreLimits(1, 1))->backtrackLimit);
        $this->assertSame(0, (new RedosOptions(maxStates: 1, maxSteps: 1, boundedRepeatCutoff: 0))->boundedRepeatCutoff);
        $this->assertNull((new SolverOptions(maxNfaStates: 1, maxDfaStates: 1, maxTransitionsProcessed: null))->maxTransitionsProcessed);

        $smallest = new ConfirmationOptions(1, 1, 1, 1, 1.0, 1, 1, 0);
        $this->assertSame([1, 1, 1, 1, 1.0, 1, 1, 0], [$smallest->minInputLength, $smallest->maxInputLength, $smallest->steps, $smallest->iterations, $smallest->timeoutMs, $smallest->backtrackLimit, $smallest->recursionLimit, $smallest->previewLength]);
    }
}
