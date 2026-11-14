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
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A run under a deadline that blocks on a read is interrupted when the
 * deadline passes: the test fails, the suite goes on. The run is made in a
 * child, which the test kills after ten seconds, so that a deadline that
 * does not interrupt fails here instead of hanging the suite.
 */
#[RequiresPhpExtension('pcntl')]
final class DeadlineTest extends TestCase
{
    #[Test]
    #[RequiresFunction('pcntl_alarm')]
    public function test_a_run_blocked_on_a_read_is_interrupted_at_the_deadline(): void
    {
        $autoload = \dirname(__DIR__, 3).'/vendor/autoload.php';
        $code = <<<PHP
            require '{$autoload}';
            \$silent = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [1 => ['pipe', 'w']], \$pipes);
            try {
                PHPRegex\\Tests\\Support\\Deadline::within(1, static fn () => fread(\$pipes[1], 1));
                echo 'returned';
            } catch (RuntimeException \$e) {
                echo \$e->getMessage();
            }
            proc_terminate(\$silent);
            PHP;
        $process = proc_open([\PHP_BINARY, '-r', $code], ProcessPipes::descriptors(), $pipes);
        $this->assertIsResource($process);

        $start = hrtime(true);
        while (proc_get_status($process)['running'] && hrtime(true) - $start < 10e9) {
            usleep(50_000);
        }
        $hung = proc_get_status($process)['running'];
        if ($hung) {
            proc_terminate($process, 9);
        }
        [$stdout] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertFalse($hung, 'The deadline did not interrupt the blocking read.');
        $this->assertSame('The run did not return within 1 s.', $stdout);
    }
}
