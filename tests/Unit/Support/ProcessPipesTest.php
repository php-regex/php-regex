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

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Tests\Support\ProcessPipes;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A child that fills its standard error before it writes its standard
 * output waits for a reader of the error stream: read one stream to its
 * end before the other, and the two processes wait on each other.
 */
final class ProcessPipesTest extends TestCase
{
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_child_filling_its_error_stream_first_is_read_to_the_end(): void
    {
        // Four times what a pipe buffers on Linux and macOS (64 KiB).
        $code = 'fwrite(STDERR, str_repeat("e", 262144)); fwrite(STDOUT, str_repeat("o", 262144)); fwrite(STDERR, "end");';
        $process = proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        [$stdout, $stderr] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process));
        $this->assertSame(str_repeat('o', 262144), $stdout);
        $this->assertSame(str_repeat('e', 262144).'end', $stderr);
    }

    #[Test]
    public function test_a_child_writing_nothing_gives_two_empty_streams(): void
    {
        $process = proc_open([\PHP_BINARY, '-r', ''], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        $this->assertSame(['', ''], ProcessPipes::drain($pipes));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
    }
}
