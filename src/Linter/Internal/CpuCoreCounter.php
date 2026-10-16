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
 * How many processes the lint commands start when not told: the CPUs this
 * process may run on, capped by the CPU quota of its container.
 *
 * The first finder that gives a positive count wins: the CPU affinity
 * (Cpus_allowed_list, among the online CPUs), nproc, sysctl hw.logicalcpu,
 * the processor lines of /proc/cpuinfo, then 1. The count is then capped by
 * the cgroup quota (v2 cpu.max, v1 cfs quota and period), rounded up, and by
 * KUBERNETES_CPU_LIMIT.
 *
 * The approach follows theofidry/cpu-core-counter (MIT); the code is written
 * from scratch, so the linter depends on no package for it.
 *
 * @internal
 */
final class CpuCoreCounter
{
    /**
     * @var \Closure(string): ?string
     */
    private readonly \Closure $readFile;

    /**
     * @var \Closure(string): ?string
     */
    private readonly \Closure $runCommand;

    /**
     * @var \Closure(string): (string|false)
     */
    private readonly \Closure $getEnv;

    private ?int $count = null;

    /**
     * @param (\Closure(string): ?string)|null        $readFile   null when unreadable
     * @param (\Closure(string): ?string)|null        $runCommand trimmed stdout, null on failure / proc_open unavailable
     * @param (\Closure(string): (string|false))|null $getEnv
     */
    public function __construct(?\Closure $readFile = null, ?\Closure $runCommand = null, ?\Closure $getEnv = null)
    {
        $this->readFile = $readFile ?? self::readFile(...);
        $this->runCommand = $runCommand ?? self::runCommand(...);
        $this->getEnv = $getEnv ?? self::env(...);
    }

    /**
     * At least 1; worked out once.
     */
    public function count(): int
    {
        return $this->count ??= $this->capped($this->found());
    }

    private function found(): int
    {
        return $this->affinity()
            ?? self::positive(($this->runCommand)('nproc'))
            ?? self::positive(($this->runCommand)('sysctl -n hw.logicalcpu'))
            ?? $this->cpuInfo()
            ?? 1;
    }

    /**
     * The CPUs the scheduler lets this process run on: taskset and
     * container cpusets narrow it below what the machine has.
     */
    private function affinity(): ?int
    {
        $status = ($this->readFile)('/proc/self/status');
        if (null === $status || 1 !== LibraryPcre::match('/^Cpus_allowed_list:[ \t]*(\S*)/m', $status, $match)) {
            return null;
        }

        $allowed = self::cpuList($match[1]);
        if (null === $allowed) {
            return null;
        }

        // The affinity may name CPUs that are offline.
        $online = self::cpuList((string) ($this->readFile)('/sys/devices/system/cpu/online'));
        if (null !== $online) {
            $allowed = array_intersect_key($allowed, $online);
        }

        return [] === $allowed ? null : \count($allowed);
    }

    /**
     * Counts "processor : 0" (x86, arm) and "processor 0: version = …"
     * (s390x) lines alike.
     */
    private function cpuInfo(): ?int
    {
        $count = LibraryPcre::matchAll('/^processor\s*\d*\s*:/m', (string) ($this->readFile)('/proc/cpuinfo'));

        return \is_int($count) && $count > 0 ? $count : null;
    }

    private function capped(int $count): int
    {
        $env = ($this->getEnv)('KUBERNETES_CPU_LIMIT');
        $limits = array_filter(
            [$this->cgroupQuota($count), self::positive(false === $env ? null : $env)],
            static fn (?int $limit): bool => null !== $limit,
        );

        return min([$count, ...$limits]);
    }

    /**
     * The CPU quota of the cgroup this process is in, in whole CPUs: 1.5
     * CPU of quota is 2 processes, half a CPU is 1, never more than $count.
     *
     * Inside a container, /proc/self/cgroup names the cgroup's path on the
     * host, while the container's own cgroup is mounted at the root of its
     * /sys/fs/cgroup: when nothing is found at the path, the quota is read
     * at the mount root.
     */
    private function cgroupQuota(int $count): ?int
    {
        $cgroup = ($this->readFile)('/proc/self/cgroup');

        foreach (explode("\n", (string) $cgroup) as $line) {
            $parts = explode(':', $line, 3);
            if (3 !== \count($parts)) {
                continue;
            }

            [$id, $controllers, $path] = $parts;
            $path = rtrim($path, '/');

            if ('0' === $id && '' === $controllers) {
                // cgroup v2: "<quota> <period>", or "max <period>" for none.
                $max = ($this->readFile)('/sys/fs/cgroup'.$path.'/cpu.max')
                    ?? ($this->readFile)('/sys/fs/cgroup/cpu.max');
                $quota = 1 === LibraryPcre::match('/^(\d+)\s+(\d+)\s*$/', (string) $max, $match)
                    ? self::quota($match[1], $match[2], $count)
                    : null;
            } elseif (\in_array('cpu', explode(',', $controllers), true)) {
                // cgroup v1: the controller list names the mount directory;
                // a quota of -1 is none.
                $directory = '/sys/fs/cgroup/'.$controllers.$path;
                $cfsQuota = ($this->readFile)($directory.'/cpu.cfs_quota_us');
                if (null === $cfsQuota) {
                    $directory = '/sys/fs/cgroup/'.$controllers;
                    $cfsQuota = ($this->readFile)($directory.'/cpu.cfs_quota_us');
                }

                $quota = self::quota($cfsQuota, ($this->readFile)($directory.'/cpu.cfs_period_us'), $count);
            } else {
                continue;
            }

            if (null !== $quota) {
                return $quota;
            }
        }

        return null;
    }

    /**
     * Null when there is no quota; otherwise between 1 and $count. Worked
     * out in floats: a quota near the largest int overflows once rounded
     * up as an int.
     */
    private static function quota(?string $quota, ?string $period, int $count): ?int
    {
        $quota = self::positive($quota);
        $period = self::positive($period);
        if (null === $quota || null === $period) {
            return null;
        }

        // Both positive: at least 1.
        $cpus = ceil((float) $quota / (float) $period);

        return $cpus < $count ? (int) $cpus : $count;
    }

    /**
     * A list such as "0-3,8,10-11", as a set of CPU numbers; null when it
     * is anything else.
     *
     * @return array<int, true>|null
     */
    private static function cpuList(string $list): ?array
    {
        $cpus = [];
        foreach (explode(',', trim($list, " \t\n\r\0\x0B")) as $range) {
            if (1 !== LibraryPcre::match('/^(\d+)(?:-(\d+))?$/D', $range, $match)) {
                return null;
            }

            $first = (int) $match[1];
            $last = isset($match[2]) ? (int) $match[2] : $first;
            for ($cpu = $first; $cpu <= $last; $cpu++) {
                $cpus[$cpu] = true;
            }
        }

        return [] === $cpus ? null : $cpus;
    }

    private static function positive(?string $value): ?int
    {
        $value = trim((string) $value, " \t\n\r\0\x0B");

        return 1 === LibraryPcre::match('/^[1-9]\d*$/D', $value) ? (int) $value : null;
    }

    private static function env(string $name): string|false
    {
        return getenv($name);
    }

    private static function readFile(string $path): ?string
    {
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        return false === $contents ? null : $contents;
    }

    /**
     * Runs a command without a shell_exec(), its errors discarded: what it
     * prints, trimmed, or null when it cannot run, fails or prints nothing.
     */
    private static function runCommand(string $command): ?string
    {
        // Missing, or listed in disable_functions.
        if (!function_exists('proc_open')) {
            return null;
        }

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null; // proc_open() refused to start the shell: no test can make it.
        }

        $stdout = trim((string) stream_get_contents($pipes[1]), " \t\n\r\0\x0B");
        fclose($pipes[1]);

        return 0 === proc_close($process) && '' !== $stdout ? $stdout : null;
    }
}
