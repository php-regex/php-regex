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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Output;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function test_color_respects_ansi_setting(): void
    {
        $output = new Output(false, false);

        $this->assertSame('text', $output->color('text', Output::RED));

        $output->setAnsi(true);

        $this->assertSame(Output::RED.'text'.Output::RESET, $output->color('text', Output::RED));
    }

    public function test_badge_pads_plain_text_without_ansi(): void
    {
        $output = new Output(false, false);

        $this->assertSame('PASS', $output->badge('PASS', Output::WHITE, Output::BG_GREEN));
        $this->assertSame('OK  ', $output->badge('OK', Output::WHITE, Output::BG_GREEN));
    }

    public function test_write_error_goes_to_the_error_stream_even_when_quiet(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $output = new Output(false, true, errorStream: $stream);

        ob_start();
        $output->write('report');
        $output->writeError('failure');
        $stdout = (string) ob_get_clean();

        rewind($stream);
        $this->assertSame('', $stdout);
        $this->assertSame('failure', stream_get_contents($stream));
    }

    /**
     * Without a terminal, a CI log for one, the bar is not drawn: a plain
     * line says where the run stands every 30 seconds, so a long step does
     * not look stuck, and nothing else is written.
     */
    public function test_without_ansi_the_progress_writes_a_line_every_thirty_seconds(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $now = 1000.0;
        $output = new Output(false, false, errorStream: $stream, clock: static function () use (&$now): float {
            return $now;
        });

        $output->progressStart(200);
        $output->progressAdvance(10);
        $now += 29;
        $output->progressAdvance(10);
        $now += 2;
        $output->progressAdvance(40);
        $now += 10;
        $output->progressTick();
        $now += 25;
        $output->progressTick();
        $output->progressFinish();

        $output->progressLabel('Declarations');
        $output->progressStart(null);
        $now += 31;
        $output->progressAdvance(7);
        $output->progressFinish();

        rewind($stream);
        $this->assertSame(
            "   60/200 (30%) 00:31\n   60/200 (30%) 01:06\n   Declarations: 7 found 00:31\n",
            stream_get_contents($stream),
        );
    }

    public function test_without_ansi_and_quiet_the_progress_writes_nothing(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $now = 0.0;
        $output = new Output(false, true, errorStream: $stream, clock: static function () use (&$now): float {
            return $now;
        });

        $output->progressStart(10);
        $now += 60;
        $output->progressAdvance(5);
        $output->progressFinish();

        rewind($stream);
        $this->assertSame('', stream_get_contents($stream));
    }

    public function test_without_ansi_an_abandoned_progress_writes_no_more_lines(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $now = 0.0;
        $output = new Output(false, false, errorStream: $stream, clock: static function () use (&$now): float {
            return $now;
        });

        $output->progressStart(10);
        $output->progressAbandon();
        $now += 60;
        $output->progressAdvance(5);
        $output->progressTick();

        rewind($stream);
        $this->assertSame('', stream_get_contents($stream));
    }
}
