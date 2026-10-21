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

namespace PHPRegex\Tests\Functional\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A document is written to the stream the reader holds, not through the
 * output buffering layer: a handler that leaves nothing through, as the
 * layer of a process that died of exhaustion does, cannot swallow it.
 */
final class DocumentStreamTest extends TestCase
{
    #[Test]
    public function test_a_document_is_written_past_a_swallowing_output_buffer(): void
    {
        $script = \dirname(__DIR__, 2).'/Fixtures/Cli/document_stream_run.php';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stdout.$stderr);
        $this->assertSame('{"document": true}'.\PHP_EOL, $stdout, $stderr);
    }
}
