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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The weekly runs start one after the other, never on the hour: GitHub
 * delays or drops the scheduled runs queued at the top of the hour, and a
 * dozen workflows starting together compete for the same runners.
 */
final class WorkflowScheduleTest extends TestCase
{
    private const MINIMUM_GAP_MINUTES = 20;

    #[Test]
    public function test_every_scheduled_workflow_runs_weekly_off_the_hour(): void
    {
        $schedules = self::schedules();

        $this->assertNotSame([], $schedules);
        foreach ($schedules as $workflow => $cron) {
            $this->assertMatchesRegularExpression('~^([1-9]|[1-5]\d) \d{1,2} \* \* [0-6]$~', $cron, $workflow);
        }
    }

    #[Test]
    public function test_the_scheduled_workflows_start_apart(): void
    {
        $starts = [];
        foreach (self::schedules() as $workflow => $cron) {
            [$minute, $hour, , , $day] = explode(' ', $cron);
            $starts[$workflow] = ((int) $day * 24 + (int) $hour) * 60 + (int) $minute;
        }

        asort($starts);
        $crowded = [];
        $previous = null;
        foreach ($starts as $workflow => $start) {
            if (null !== $previous && $start - $starts[$previous] < self::MINIMUM_GAP_MINUTES) {
                $crowded[] = $previous.' and '.$workflow;
            }

            $previous = $workflow;
        }

        $this->assertSame([], $crowded);
    }

    /**
     * @return array<string, string> the cron line of each scheduled workflow
     */
    private static function schedules(): array
    {
        $schedules = [];
        foreach (glob(\dirname(__DIR__, 3).'/.github/workflows/*.yml') ?: [] as $file) {
            preg_match_all("~^    - cron: '([^']+)'$~m", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $index => $cron) {
                $schedules[basename($file).(0 === $index ? '' : '#'.$index)] = $cron;
            }
        }

        return $schedules;
    }
}
