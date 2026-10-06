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

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Once JSON is asked for, stdout holds one JSON document even when PHP
 * stops the run: its diagnostics go to stderr, and a fatal error is
 * reported as the envelope, stage "internal", exit code 1.
 */
final class JsonFatalErrorTest extends TestCase
{
    use RunsRegexCli;

    private string $displayErrors = '';

    protected function setUp(): void
    {
        $this->displayErrors = (string) \ini_get('display_errors');
    }

    protected function tearDown(): void
    {
        ini_set('display_errors', $this->displayErrors);
    }

    #[Test]
    public function test_fatal_error_in_json_mode_prints_the_internal_envelope(): void
    {
        // The interpreter backtracks on every iteration: the one-second time
        // limit stops the run long before the iterations are done.
        [$exitCode, $stdout, $stderr] = $this->runRegexProcess([
            'redos', '/(a+)+$/',
            '--input', 'aaaaaaaaaaaaaaaaaa!',
            '--jit', '0',
            '--iterations', '100000',
            '--warmup', '0',
            '--time-limit', '1',
            '--json',
        ]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage'], array_keys($document), $stdout);
        $this->assertSame('internal', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertStringContainsString('Maximum execution time', (string) $document['error']);
        $this->assertStringContainsString('Maximum execution time', $stderr);
    }

    #[Test]
    public function test_fatal_error_in_console_mode_keeps_the_message_of_php(): void
    {
        [$exitCode, $stdout] = $this->runRegexProcess([
            'redos', '/(a+)+$/',
            '--input', 'aaaaaaaaaaaaaaaaaa!',
            '--jit', '0',
            '--iterations', '100000',
            '--warmup', '0',
            '--time-limit', '1',
        ]);

        $this->assertSame(255, $exitCode, $stdout);
        $this->assertNull(json_decode($stdout, true));
    }

    /**
     * Memory exhausted leaves nothing to load the envelope with: the run
     * prepares it up front and frees a reserve before printing it.
     */
    #[Test]
    public function test_memory_exhaustion_in_json_mode_prints_the_internal_envelope(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runFatalRun('exhaust');

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage'], array_keys($document), $stdout);
        $this->assertSame('internal', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertStringContainsString('Allowed memory size', (string) $document['error']);
    }

    /**
     * A forked child runs the shutdown code of its parent: its fatal error
     * is its parent's to report, and stdout keeps the parent's document.
     */
    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function test_fatal_error_in_a_forked_child_prints_nothing(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runFatalRun('fork');

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(['ok' => true], JsonContract::decodeDocument($stdout), $stdout);
        $this->assertStringContainsString('Allowed memory size', $stderr);
    }

    /**
     * An in-process run gives the display_errors setting back: only the
     * process of a JSON run sends PHP's diagnostics to stderr.
     */
    #[Test]
    public function test_in_process_json_run_gives_display_errors_back(): void
    {
        ini_set('display_errors', '1');

        [$exitCode] = $this->runRegex(['analyze', '/a/', '--json']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('1', \ini_get('display_errors'));
    }

    /**
     * Runs tests/Fixtures/Cli/json_fatal_run.php in a process of its own.
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runFatalRun(string $mode): array
    {
        $script = \dirname(__DIR__, 2).'/Fixtures/Cli/json_fatal_run.php';
        $environment = getenv();
        $environment['XDEBUG_MODE'] = 'off';

        $process = proc_open(
            [\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=1', $script, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
