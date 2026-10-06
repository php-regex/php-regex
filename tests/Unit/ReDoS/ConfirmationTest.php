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

namespace PHPRegex\Tests\Unit\ReDoS;

use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A confirmation tells a caller whether its replay was skipped: the engine
 * could not set its limits, so nothing ran and the verdict stands on the
 * proof alone.
 */
final class ConfirmationTest extends TestCase
{
    #[Test]
    public function test_limits_unavailable_names_the_evidence_of_a_skipped_replay(): void
    {
        $this->assertSame('engine limits unavailable', Confirmation::LIMITS_UNAVAILABLE);
    }

    #[Test]
    public function test_was_skipped_is_true_for_a_replay_that_could_not_run(): void
    {
        // The fields of a replay skipped where ini_set() is disabled.
        $confirmation = new Confirmation(false, [], null, null, null, 0, 50.0, false, Confirmation::LIMITS_UNAVAILABLE);

        $this->assertTrue($confirmation->wasSkipped());
    }

    #[Test]
    #[DataProvider('provideConfirmationsThatRan')]
    public function test_was_skipped_is_false_for_a_replay_that_ran(Confirmation $confirmation): void
    {
        $this->assertFalse($confirmation->wasSkipped());
    }

    /**
     * @return iterable<string, array{confirmation: Confirmation}>
     */
    public static function provideConfirmationsThatRan(): iterable
    {
        yield 'ran and did not reproduce' => [
            'confirmation' => new Confirmation(false, [new ConfirmationSample(31, 0.2)], '0', 1_000_000, 100_000, 1, 50.0),
        ];
        yield 'ran and reproduced' => [
            'confirmation' => new Confirmation(true, [new ConfirmationSample(31, 0.2, 'aaa', \PREG_BACKTRACK_LIMIT_ERROR, 'Backtrack limit exhausted')], '0', 1_000_000, 100_000, 1, 50.0, false, 'backtrack_limit'),
        ];
        yield 'ran no sample and found nothing' => [
            'confirmation' => new Confirmation(false, [], '0', 1_000_000, 100_000, 1, 50.0),
        ];
    }
}
