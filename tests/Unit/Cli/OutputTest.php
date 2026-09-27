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

namespace RegexParser\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use RegexParser\Cli\Output;

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
}
