<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\Internal\ForkedWorkerPool;
use PhpRegex\Tests\Support\LintFunctionOverrides;
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
