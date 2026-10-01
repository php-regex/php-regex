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

namespace PhpRegex\Tests\Unit\Cli;

use PhpRegex\Cli\Output;
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
}
