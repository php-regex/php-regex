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

use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One object says how a pattern is analysed: whether its verdict is
 * replayed on the engine, from which severity, under which limits, and the
 * budget of the proof. The analyzer and the facade take it the same way.
 */
final class RedosOptionsTest extends TestCase
{
    #[Test]
    public function test_the_defaults_analyse_without_running_the_pattern(): void
    {
        $options = new RedosOptions();

        $this->assertSame(RedosMode::Theoretical, $options->mode);
        $this->assertSame(RedosSeverity::High, $options->threshold);
        $this->assertNull($options->confirmation);
        $this->assertSame([2000, 250_000, 16], [$options->maxStates, $options->maxSteps, $options->boundedRepeatCutoff]);
    }

    #[Test]
    public function test_the_options_of_a_call_replace_those_of_the_analyzer(): void
    {
        $analyzer = new RedosAnalyzer(options: new RedosOptions(maxStates: 1));

        $this->assertSame(RedosProof::BudgetExceeded, $analyzer->analyze('/^(a+)+$/')->proof, 'One state proves nothing.');
        $this->assertSame(RedosProof::Proven, $analyzer->analyze('/^(a+)+$/', new RedosOptions())->proof);
    }

    #[Test]
    public function test_the_mode_of_the_options_is_the_mode_of_the_analysis(): void
    {
        $analyzer = new RedosAnalyzer();

        $this->assertEquals(RedosAnalysis::skipped(RedosMode::Off), $analyzer->analyze('/^(a+)+$/', new RedosOptions(RedosMode::Off)));
        $this->assertNull($analyzer->analyze('/^(a+)+$/', new RedosOptions(RedosMode::Theoretical))->confirmation);

        $confirmed = $analyzer->analyze('/^(a+)+$/', new RedosOptions(RedosMode::Confirmed));
        $this->assertSame(RedosMode::Confirmed, $confirmed->mode);
        $this->assertInstanceOf(Confirmation::class, $confirmed->confirmation);
    }

    #[Test]
    public function test_the_confirmation_options_reach_the_replay(): void
    {
        $options = new RedosOptions(RedosMode::Confirmed, RedosSeverity::Critical, new ConfirmationOptions(backtrackLimit: 50_000));

        $this->assertSame(50_000, (new RedosAnalyzer())->analyze('/^(a+)+$/', $options)->confirmation?->backtrackLimit);
        $this->assertSame(100_000, (new RedosAnalyzer())->analyze('/^(a+)+$/', new RedosOptions(RedosMode::Confirmed))->confirmation?->backtrackLimit, 'The default of ConfirmationOptions.');
    }

    #[Test]
    public function test_the_facade_takes_the_options_of_the_analyzer(): void
    {
        $regex = Regex::create();
        $options = new RedosOptions(RedosMode::Confirmed, confirmation: new ConfirmationOptions(backtrackLimit: 50_000));

        $this->assertSame(50_000, $regex->redos('/^(a+)+$/', $options)->confirmation?->backtrackLimit);
        $this->assertSame(50_000, $regex->analyze('/^(a+)+$/', $options)->redos->confirmation?->backtrackLimit);
        $this->assertNull($regex->redos('/^(a+)+$/')->confirmation, 'Theoretical by default.');
    }
}
