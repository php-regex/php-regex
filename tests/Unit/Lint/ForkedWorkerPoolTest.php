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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Internal\ForkedWorkerPool;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a forked child does before it ends, run in this process: the fork
 * itself would end the test with the child.
 */
final class ForkedWorkerPoolTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function test_a_child_whose_work_gives_a_result_writes_it_and_ends_with_zero(): void
    {
        $file = $this->payloadFile();

        $code = (new ForkedWorkerPool())->runChild(static fn (): array => ['a', 'b'], $file);

        $this->assertSame(0, $code);
        $this->assertSame(['ok' => true, 'result' => ['a', 'b']], $this->written($file));
    }

    #[Test]
    public function test_a_child_whose_work_throws_writes_the_failure_and_ends_with_one(): void
    {
        $file = $this->payloadFile();

        $code = (new ForkedWorkerPool())->runChild(static fn (): never => throw new \DomainException('Boom'), $file);

        $this->assertSame(1, $code);
        $this->assertSame(['ok' => false, 'error' => ['message' => 'Boom', 'class' => \DomainException::class]], $this->written($file));
    }

    /**
     * The payload replaces whatever the file held: the parent reads only
     * what this child wrote.
     */
    #[Test]
    public function test_a_child_overwrites_the_payload_file(): void
    {
        $file = $this->payloadFile();
        file_put_contents($file, 'stale');

        (new ForkedWorkerPool())->runChild(static fn (): int => 7, $file);

        $this->assertSame(serialize(['ok' => true, 'result' => 7]), file_get_contents($file));
    }

    /**
     * The parent gets what the fork gave: a process id, or -1.
     */
    #[Test]
    public function test_the_parent_gets_the_process_id_of_the_child(): void
    {
        LintFunctionOverrides::queuePcntlForkResult(4321);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        $pool = new ForkedWorkerPool();

        $this->assertSame(4321, $pool->fork(static fn (): null => null, $this->payloadFile()));
        $this->assertSame(-1, $pool->fork(static fn (): null => null, $this->payloadFile()));
    }

    /**
     * A child inherits the output buffers of its parent: it drops them, so
     * what the parent buffered is not written once more when the child
     * ends. Run in a PHP process of its own, the fork ending the child.
     */
    #[Test]
    public function test_a_child_does_not_write_out_what_its_parent_buffered(): void
    {
        // The fork runs in a PHP of its own, where no test support can
        // stand in for the extension.
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $autoload = \dirname(__DIR__, 3).'/vendor/autoload.php';
        $script = \sprintf(<<<'PHP_WRAP'
            require %s;
            $pool = new PHPRegex\Linter\Internal\ForkedWorkerPool();
            ob_start();
            echo "buffered by the parent";
            $file = tempnam(sys_get_temp_dir(), 'pool');
            $pid = $pool->fork(static fn (): int => 1, $file);
            pcntl_waitpid($pid, $status);
            ob_end_clean();
            @unlink($file);
            PHP_WRAP, var_export($autoload, true));

        $process = proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('', $stdout);
    }

    /**
     * A child ends without running what its parent registered to run at
     * shutdown, nor its destructors: those belong to the parent process (a
     * logger flush, a connection close), and loading the classes they need
     * from a phar in several children at once reads corrupted bytes (the
     * suite saw "Unterminated comment" in a PHPStan class).
     */
    #[Test]
    public function test_a_child_does_not_run_its_parents_shutdown_functions(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $marker = $this->payloadFile();
        $autoload = \dirname(__DIR__, 3).'/vendor/autoload.php';
        $script = \sprintf(<<<'PHP_WRAP'
            require %s;
            $parent = getmypid();
            register_shutdown_function(static function () use ($parent): void {
                if (getmypid() !== $parent) {
                    file_put_contents(%s, 'shutdown ran in the child', FILE_APPEND);
                }
            });
            $guard = new class($parent) {
                public function __construct(private int $parent) {}
                public function __destruct() { if (getmypid() !== $this->parent) { file_put_contents(%2$s, 'destructor ran in the child', FILE_APPEND); } }
            };
            $file = tempnam(sys_get_temp_dir(), 'pool');
            $pid = (new PHPRegex\Linter\Internal\ForkedWorkerPool())->fork(static fn (): int => 1, $file);
            pcntl_waitpid($pid, $status);
            echo serialize(unserialize((string) file_get_contents($file)));
            @unlink($file);
            PHP_WRAP, var_export($autoload, true), var_export($marker, true));

        $process = proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame(serialize(['ok' => true, 'result' => 1]), $stdout, 'The child still writes its payload.');
        $this->assertFileDoesNotExist($marker, 'A shutdown function or a destructor of the parent ran in the child.');
    }

    private function payloadFile(): string
    {
        $file = sys_get_temp_dir().'/regexparser_pool_'.uniqid('', true);
        $this->files[] = $file;

        return $file;
    }

    private function written(string $file): mixed
    {
        $data = file_get_contents($file);
        $this->assertIsString($data);

        return unserialize($data);
    }
}
