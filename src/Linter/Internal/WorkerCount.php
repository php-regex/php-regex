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

namespace PHPRegex\Linter\Internal;

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * The worker count a lint run starts by default: one per CPU the process
 * may run on, capped by the memory limit of its container, as each worker
 * is a copy of the main process and the main process stays alive; one
 * where the process cannot fork.
 *
 * The limit is read from the cgroup this process is in: memory.max under
 * cgroup v2 ("max" for none), memory.limit_in_bytes under v1, at the path
 * /proc/self/cgroup names, else at the mount root, as CpuCoreCounter reads
 * the CPU quota.
 *
 * @internal
 */
final class WorkerCount
{
    /**
     * cgroup v1 writes "no limit" as the largest page-aligned 64-bit value:
     * anything this large is none.
     */
    private const UNLIMITED = 1 << 62;

    /**
     * @var \Closure(): int
     */
    private readonly \Closure $cores;

    /**
     * @var \Closure(string): ?string
     */
    private readonly \Closure $readFile;

    /**
     * @var \Closure(): bool
     */
    private readonly \Closure $canFork;

    /**
     * @var \Closure(): int
     */
    private readonly \Closure $processBytes;

    /**
     * @param (\Closure(): int)|null           $cores        the CPUs, CpuCoreCounter's count by default
     * @param (\Closure(string): ?string)|null $readFile     null when unreadable
     * @param (\Closure(): bool)|null          $canFork      ForkedWorkerPool::canFork() by default
     * @param (\Closure(): int)|null           $processBytes the size of the main process, in bytes
     */
    public function __construct(?\Closure $cores = null, ?\Closure $readFile = null, ?\Closure $canFork = null, ?\Closure $processBytes = null)
    {
        $this->cores = $cores ?? static fn (): int => (new CpuCoreCounter())->count();
        $this->readFile = $readFile ?? static function (string $path): ?string {
            $content = is_readable($path) ? @file_get_contents($path) : false;

            return false === $content ? null : $content;
        };
        $this->canFork = $canFork ?? ForkedWorkerPool::canFork(...);
        $this->processBytes = $processBytes ?? static fn (): int => memory_get_usage(true);
    }

    /**
     * At least 1.
     */
    public function count(): int
    {
        if (!($this->canFork)()) {
            return 1;
        }

        $cores = max(1, ($this->cores)());
        $limit = $this->memoryLimit();
        if (null === $limit) {
            return $cores;
        }

        // The main process takes its share of the limit too.
        return max(1, min($cores, intdiv($limit, max(1, ($this->processBytes)())) - 1));
    }

    /**
     * The memory limit of the cgroup this process is in, in bytes; null
     * when there is none.
     */
    private function memoryLimit(): ?int
    {
        foreach (explode("\n", (string) ($this->readFile)('/proc/self/cgroup')) as $line) {
            $parts = explode(':', $line, 3);
            if (3 !== \count($parts)) {
                continue;
            }

            [$id, $controllers, $path] = $parts;
            $path = rtrim($path, '/');

            if ('0' === $id && '' === $controllers) {
                $limit = ($this->readFile)('/sys/fs/cgroup'.$path.'/memory.max')
                    ?? ($this->readFile)('/sys/fs/cgroup/memory.max');
            } elseif (\in_array('memory', explode(',', $controllers), true)) {
                $limit = ($this->readFile)('/sys/fs/cgroup/'.$controllers.$path.'/memory.limit_in_bytes')
                    ?? ($this->readFile)('/sys/fs/cgroup/'.$controllers.'/memory.limit_in_bytes');
            } else {
                continue;
            }

            if (1 === LibraryPcre::match('/^\d{1,19}$/', trim((string) $limit, " \t\n\r\0\x0B"))) {
                $bytes = (int) trim((string) $limit, " \t\n\r\0\x0B");

                return $bytes > 0 && $bytes < self::UNLIMITED ? $bytes : null;
            }
        }

        return null;
    }
}
