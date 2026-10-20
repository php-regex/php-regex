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

use PHPRegex\Linter\Parallel\WorkerCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The worker count the lint commands start by default: one per CPU, capped
 * by the container's memory limit, as each worker is a copy of the main
 * process; one where the process cannot fork. Every file is a fake, so the
 * machine running the suite never shows through.
 */
final class WorkerCountTest extends TestCase
{
    private const MIB = 1024 * 1024;

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideLimits')]
    public function test_the_count_is_capped_by_the_memory_limit(array $files, int $cores, int $processBytes, int $expected): void
    {
        $count = new WorkerCount(
            cores: static fn (): int => $cores,
            readFile: static fn (string $path): ?string => $files[$path] ?? null,
            canFork: static fn (): bool => true,
            processBytes: static fn (): int => $processBytes,
        );

        $this->assertSame($expected, $count->count());
    }

    /**
     * @return iterable<string, array{files: array<string, string>, cores: int, processBytes: int, expected: int}>
     */
    public static function provideLimits(): iterable
    {
        $v2 = ['/proc/self/cgroup' => "0::/lint.slice\n"];
        $v1 = ['/proc/self/cgroup' => "12:cpu,cpuacct:/lint\n9:memory:/lint\n"];

        yield 'no cgroup' => ['files' => [], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 8];
        yield 'v2, 1 GiB: the main process and 7 workers' => ['files' => $v2 + ['/sys/fs/cgroup/lint.slice/memory.max' => (string) (1024 * self::MIB)."\n"], 'cores' => 16, 'processBytes' => 128 * self::MIB, 'expected' => 7];
        yield 'v2, more memory than the cores need' => ['files' => $v2 + ['/sys/fs/cgroup/lint.slice/memory.max' => (string) (64 * 1024 * self::MIB)."\n"], 'cores' => 4, 'processBytes' => 128 * self::MIB, 'expected' => 4];
        yield 'v2, no limit' => ['files' => $v2 + ['/sys/fs/cgroup/lint.slice/memory.max' => "max\n"], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 8];
        yield 'v2, read at the container root' => ['files' => $v2 + ['/sys/fs/cgroup/memory.max' => (string) (512 * self::MIB)."\n"], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 3];
        yield 'v1, 512 MiB' => ['files' => $v1 + ['/sys/fs/cgroup/memory/lint/memory.limit_in_bytes' => (string) (512 * self::MIB)."\n"], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 3];
        yield 'v1, read at the mount root' => ['files' => $v1 + ['/sys/fs/cgroup/memory/memory.limit_in_bytes' => (string) (256 * self::MIB)."\n"], 'cores' => 8, 'processBytes' => 64 * self::MIB, 'expected' => 3];
        yield 'v2, the path wins over the container root' => ['files' => $v2 + ['/sys/fs/cgroup/lint.slice/memory.max' => (string) (1024 * self::MIB)."\n", '/sys/fs/cgroup/memory.max' => (string) (512 * self::MIB)."\n"], 'cores' => 16, 'processBytes' => 128 * self::MIB, 'expected' => 7];
        yield 'v1, the unlimited value' => ['files' => $v1 + ['/sys/fs/cgroup/memory/lint/memory.limit_in_bytes' => "9223372036854771712\n"], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 8];
        yield 'a limit below two processes' => ['files' => $v2 + ['/sys/fs/cgroup/lint.slice/memory.max' => (string) (100 * self::MIB)."\n"], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 1];
        yield 'an unreadable limit' => ['files' => $v2 + ['/sys/fs/cgroup/lint.slice/memory.max' => "garbage\n"], 'cores' => 8, 'processBytes' => 128 * self::MIB, 'expected' => 8];
    }

    #[Test]
    public function test_a_process_that_cannot_fork_runs_alone(): void
    {
        $count = new WorkerCount(
            cores: static fn (): int => 8,
            readFile: static fn (string $path): ?string => null,
            canFork: static fn (): bool => false,
        );

        $this->assertSame(1, $count->count());
    }

    #[Test]
    public function test_the_default_reads_this_machine(): void
    {
        $this->assertGreaterThanOrEqual(1, (new WorkerCount())->count());
    }
}
