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

use PHPRegex\Linter\Internal\CpuCoreCounter;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The process count the lint commands start by default: the first finder
 * giving a positive count wins (CPU affinity, nproc, sysctl, /proc/cpuinfo,
 * then 1), capped by the container's CPU quota and KUBERNETES_CPU_LIMIT.
 * Every file, command and variable is a fake, so the machine running the
 * suite never shows through.
 */
final class CpuCoreCounterTest extends TestCase
{
    private const STATUS = '/proc/self/status';
    private const ONLINE = '/sys/devices/system/cpu/online';
    private const CPUINFO = '/proc/cpuinfo';
    private const CGROUP = '/proc/self/cgroup';
    private const NPROC = 'nproc';
    private const SYSCTL = 'sysctl -n hw.logicalcpu';

    protected function setUp(): void
    {
        LintFunctionOverrides::reset();
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_count_intersects_the_affinity_with_the_online_cpus(): void
    {
        $counter = $this->counter(
            files: [
                self::STATUS => self::statusText('0-3,8'),
                self::ONLINE => "0-15\n",
            ],
            commands: [self::NPROC => '99', self::SYSCTL => '99'],
        );

        $this->assertSame(5, $counter->count());
    }

    /**
     * The affinity may name CPUs that are offline: only those both allowed
     * and online count.
     */
    #[Test]
    public function test_count_keeps_only_the_allowed_cpus_that_are_online(): void
    {
        $counter = $this->counter(
            files: [
                self::STATUS => self::statusText('0-7'),
                self::ONLINE => "0-3\n",
            ],
            commands: [self::NPROC => '99'],
        );

        $this->assertSame(4, $counter->count());
    }

    /**
     * A process pinned to CPU #0 alone: the list "0" is one CPU, not zero.
     */
    #[Test]
    public function test_count_reads_an_affinity_of_a_single_cpu(): void
    {
        $counter = $this->counter(
            files: [
                self::STATUS => self::statusText('0'),
                self::ONLINE => "0-15\n",
            ],
            commands: [self::NPROC => '6'],
        );

        $this->assertSame(1, $counter->count());
    }

    #[Test]
    public function test_count_falls_back_to_nproc_without_an_affinity(): void
    {
        $counter = $this->counter(
            files: [self::ONLINE => "0-15\n", self::CPUINFO => self::x86CpuInfoText(3)],
            commands: [self::NPROC => '6', self::SYSCTL => '12'],
        );

        $this->assertSame(6, $counter->count());
    }

    #[Test]
    public function test_count_falls_back_to_sysctl_when_nproc_fails(): void
    {
        $counter = $this->counter(
            files: [self::CPUINFO => self::x86CpuInfoText(3)],
            commands: [self::NPROC => null, self::SYSCTL => '10'],
        );

        $this->assertSame(10, $counter->count());
    }

    /**
     * @return iterable<string, array{cpuinfo: string, expected: int}>
     */
    public static function provideCpuInfo(): iterable
    {
        yield 'x86 form, one "processor : n" line per cpu' => [
            'cpuinfo' => self::x86CpuInfoText(4),
            'expected' => 4,
        ];

        yield 's390x form, "processor n: version = ..." lines' => [
            'cpuinfo' => "vendor_id       : IBM/S390\n"
                ."# processors    : 2\n"
                ."bogomips per cpu: 3033.00\n"
                ."features\t: esan3 zarch stfle msa ldisp eimm dfp edat etf3eh highgprs te vx sie\n"
                ."processor 0: version = FF,  identification = 0133E8,  machine = 2964\n"
                ."processor 1: version = FF,  identification = 0133E8,  machine = 2964\n",
            'expected' => 2,
        ];
    }

    #[Test]
    #[DataProvider('provideCpuInfo')]
    public function test_count_reads_proc_cpuinfo_when_both_commands_fail(string $cpuinfo, int $expected): void
    {
        $counter = $this->counter(
            files: [self::CPUINFO => $cpuinfo],
            commands: [self::NPROC => null, self::SYSCTL => null],
        );

        $this->assertSame($expected, $counter->count());
    }

    #[Test]
    public function test_count_is_one_when_every_finder_fails(): void
    {
        $counter = $this->counter(files: [], commands: []);

        $this->assertSame(1, $counter->count());
    }

    /**
     * @return iterable<string, array{files: array<string, string>, commands: array<string, string>, expected: int}>
     */
    public static function provideGarbage(): iterable
    {
        $cpuinfo = self::x86CpuInfoText(3);

        foreach (['abc' => 'abc', 'zero' => '0', 'negative' => '-3', 'empty' => ''] as $name => $garbage) {
            // "0" is no garbage in an affinity list: it names CPU #0, a
            // single CPU (see the test right below).
            if ('0' !== $garbage) {
                yield 'affinity list '.$name.', nproc wins' => [
                    'files' => [self::STATUS => self::statusText($garbage), self::ONLINE => "0-15\n"],
                    'commands' => [self::NPROC => '6'],
                    'expected' => 6,
                ];
            }

            yield 'nproc '.$name.', sysctl wins' => [
                'files' => [self::CPUINFO => $cpuinfo],
                'commands' => [self::NPROC => $garbage, self::SYSCTL => '7'],
                'expected' => 7,
            ];

            yield 'sysctl '.$name.', cpuinfo wins' => [
                'files' => [self::CPUINFO => $cpuinfo],
                'commands' => [self::SYSCTL => $garbage],
                'expected' => 3,
            ];

            yield 'cpuinfo '.$name.', falls to one' => [
                'files' => [self::CPUINFO => $garbage],
                'commands' => [],
                'expected' => 1,
            ];
        }
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $commands
     */
    #[Test]
    #[DataProvider('provideGarbage')]
    public function test_count_ignores_a_finder_that_gives_garbage(array $files, array $commands, int $expected): void
    {
        $this->assertSame($expected, $this->counter(files: $files, commands: $commands)->count());
    }

    /**
     * @return iterable<string, array{cgroup: string, files: array<string, string>, expected: int}>
     */
    public static function provideCgroup(): iterable
    {
        yield 'v2 quota of 1.5 cpu rounds up to 2' => [
            'cgroup' => "0::/user.slice\n",
            'files' => ['/sys/fs/cgroup/user.slice/cpu.max' => "150000 100000\n"],
            'expected' => 2,
        ];

        yield 'v2 max means no quota' => [
            'cgroup' => "0::/user.slice\n",
            'files' => ['/sys/fs/cgroup/user.slice/cpu.max' => "max 100000\n"],
            'expected' => 8,
        ];

        yield 'v2 quota of half a cpu rounds up to 1' => [
            'cgroup' => "0::/user.slice\n",
            'files' => ['/sys/fs/cgroup/user.slice/cpu.max' => "50000 100000\n"],
            'expected' => 1,
        ];

        yield 'v2 quota above the core count keeps the core count' => [
            'cgroup' => "0::/user.slice\n",
            'files' => ['/sys/fs/cgroup/user.slice/cpu.max' => "1600000 100000\n"],
            'expected' => 8,
        ];

        yield 'v2 root cgroup, as in a container' => [
            'cgroup' => "0::/\n",
            'files' => ['/sys/fs/cgroup/cpu.max' => "200000 100000\n"],
            'expected' => 2,
        ];

        yield 'v1 quota of -1 means no quota' => [
            'cgroup' => "12:memory:/docker/abc\n4:cpu,cpuacct:/docker/abc\n1:name=systemd:/docker/abc\n",
            'files' => [
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_quota_us' => "-1\n",
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_period_us' => "100000\n",
            ],
            'expected' => 8,
        ];

        yield 'v1 quota of 2 cpus' => [
            'cgroup' => "12:memory:/docker/abc\n4:cpu,cpuacct:/docker/abc\n1:name=systemd:/docker/abc\n",
            'files' => [
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_quota_us' => "200000\n",
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_period_us' => "100000\n",
            ],
            'expected' => 2,
        ];

        yield 'v1 quota of half a cpu rounds up to 1' => [
            'cgroup' => "4:cpu,cpuacct:/docker/abc\n",
            'files' => [
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_quota_us' => "50000\n",
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_period_us' => "100000\n",
            ],
            'expected' => 1,
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideCgroup')]
    public function test_count_is_capped_by_the_cgroup_quota(string $cgroup, array $files, int $expected): void
    {
        $counter = $this->counter(
            files: [self::CGROUP => $cgroup] + $files,
            commands: [self::NPROC => '8'],
        );

        $this->assertSame($expected, $counter->count());
    }

    /**
     * Inside a container, /proc/self/cgroup names the path of the cgroup on
     * the host, but the container's own cgroup is mounted at the root of
     * its /sys/fs/cgroup: nothing is found at the host path, the quota is
     * read at the mount root.
     *
     * @return iterable<string, array{cgroup: string, files: array<string, string>, expected: int}>
     */
    public static function provideContainerRootCgroup(): iterable
    {
        yield 'v1 quota of 2 cpus at the cpu,cpuacct mount root (docker --cpus=2)' => [
            'cgroup' => "12:pids:/docker/abc\n4:cpu,cpuacct:/docker/abc\n1:name=systemd:/docker/abc\n",
            'files' => [
                '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_quota_us' => "200000\n",
                '/sys/fs/cgroup/cpu,cpuacct/cpu.cfs_period_us' => "100000\n",
            ],
            'expected' => 2,
        ];

        yield 'v2 quota of 2 cpus at the mount root, nothing at the host path' => [
            'cgroup' => "0::/kubepods/pod1/ctr\n",
            'files' => ['/sys/fs/cgroup/cpu.max' => "200000 100000\n"],
            'expected' => 2,
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideContainerRootCgroup')]
    public function test_count_is_capped_by_a_cgroup_quota_mounted_at_the_container_root(string $cgroup, array $files, int $expected): void
    {
        $counter = $this->counter(
            files: [
                self::STATUS => self::statusText('0-15'),
                self::ONLINE => "0-15\n",
                self::CGROUP => $cgroup,
            ] + $files,
            commands: [self::NPROC => '16'],
        );

        $this->assertSame($expected, $counter->count());
    }

    /**
     * A quota too large for an int caps nothing: the count stays the core
     * count, never zero nor negative.
     *
     * @return iterable<string, array{cgroup: string, files: array<string, string>}>
     */
    public static function provideAbsurdQuota(): iterable
    {
        yield 'v2 quota beyond an int' => [
            'cgroup' => "0::/\n",
            'files' => ['/sys/fs/cgroup/cpu.max' => "99999999999999999999999 1\n"],
        ];

        yield 'v2 quota of the largest int' => [
            'cgroup' => "0::/\n",
            'files' => ['/sys/fs/cgroup/cpu.max' => "9223372036854775807 1\n"],
        ];

        yield 'v1 quota beyond an int' => [
            'cgroup' => "4:cpu,cpuacct:/docker/abc\n",
            'files' => [
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_quota_us' => "99999999999999999999999\n",
                '/sys/fs/cgroup/cpu,cpuacct/docker/abc/cpu.cfs_period_us' => "1\n",
            ],
        ];
    }

    /**
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideAbsurdQuota')]
    public function test_count_is_at_least_one_whatever_the_quota(string $cgroup, array $files): void
    {
        $count = $this->counter(
            files: [
                self::STATUS => self::statusText('0-15'),
                self::ONLINE => "0-15\n",
                self::CGROUP => $cgroup,
            ] + $files,
            commands: [self::NPROC => '16'],
        )->count();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertSame(16, $count);
    }

    #[Test]
    public function test_count_takes_the_lower_of_the_cgroup_quota_and_the_kubernetes_limit(): void
    {
        $counter = $this->counter(
            files: [
                self::CGROUP => "0::/\n",
                '/sys/fs/cgroup/cpu.max' => "400000 100000\n",
            ],
            commands: [self::NPROC => '8'],
            env: ['KUBERNETES_CPU_LIMIT' => '3'],
        );

        $this->assertSame(3, $counter->count());
    }

    #[Test]
    public function test_count_is_capped_by_the_kubernetes_limit_alone(): void
    {
        $counter = $this->counter(files: [], commands: [self::NPROC => '8'], env: ['KUBERNETES_CPU_LIMIT' => '2']);

        $this->assertSame(2, $counter->count());
    }

    #[Test]
    public function test_count_keeps_the_core_count_under_a_higher_kubernetes_limit(): void
    {
        $counter = $this->counter(files: [], commands: [self::NPROC => '8'], env: ['KUBERNETES_CPU_LIMIT' => '16']);

        $this->assertSame(8, $counter->count());
    }

    /**
     * @return iterable<string, array{limit: string}>
     */
    public static function provideInvalidKubernetesLimit(): iterable
    {
        yield 'not a number' => ['limit' => 'abc'];
        yield 'zero' => ['limit' => '0'];
        yield 'negative' => ['limit' => '-2'];
        yield 'empty' => ['limit' => ''];
        yield 'not an integer' => ['limit' => '1.5'];
    }

    /**
     * Only a positive integer caps the count; anything else is ignored and
     * the cgroup quota (4) still applies.
     */
    #[Test]
    #[DataProvider('provideInvalidKubernetesLimit')]
    public function test_count_ignores_an_invalid_kubernetes_limit(string $limit): void
    {
        $counter = $this->counter(
            files: [
                self::CGROUP => "0::/\n",
                '/sys/fs/cgroup/cpu.max' => "400000 100000\n",
            ],
            commands: [self::NPROC => '8'],
            env: ['KUBERNETES_CPU_LIMIT' => $limit],
        );

        $this->assertSame(4, $counter->count());
    }

    #[Test]
    public function test_count_is_memoized(): void
    {
        $commandCalls = 0;
        $fileReads = 0;
        $counter = new CpuCoreCounter(
            static function (string $path) use (&$fileReads): ?string {
                $fileReads++;

                return null;
            },
            static function (string $command) use (&$commandCalls): ?string {
                $commandCalls++;

                return self::NPROC === $command ? '6' : null;
            },
            static fn (string $name): false => false,
        );

        $first = $counter->count();
        $callsAfterFirst = $commandCalls;
        $readsAfterFirst = $fileReads;
        $second = $counter->count();

        $this->assertSame(6, $first);
        $this->assertSame(6, $second);
        $this->assertGreaterThan(0, $callsAfterFirst);
        $this->assertSame($callsAfterFirst, $commandCalls, 'The second count() ran a command again.');
        $this->assertSame($readsAfterFirst, $fileReads, 'The second count() read a file again.');
    }

    /**
     * Without proc_open, the command finders give nothing and the chain
     * goes on: no files here, so 1, and nothing is thrown.
     */
    #[Test]
    public function test_count_skips_the_commands_when_proc_open_is_unavailable(): void
    {
        LintFunctionOverrides::$missingFunctions = ['proc_open'];

        $counter = new CpuCoreCounter(
            static fn (string $path): ?string => null,
            null,
            static fn (string $name): false => false,
        );

        $this->assertSame(1, $counter->count());
    }

    /**
     * The default command runner gives what the command prints, trimmed,
     * and nothing when the command is absent: the same as running the two
     * commands here, in the chain's order.
     */
    #[Test]
    public function test_count_runs_the_real_commands_by_default(): void
    {
        $expected = 1;
        foreach ([self::NPROC, self::SYSCTL] as $command) {
            $output = $this->commandOutput($command);
            if (1 === preg_match('/^[1-9]\d*$/', $output)) {
                $expected = (int) $output;

                break;
            }
        }

        $counter = new CpuCoreCounter(
            static fn (string $path): ?string => null,
            null,
            static fn (string $name): false => false,
        );

        $this->assertSame($expected, $counter->count());
    }

    /**
     * @param array<string, string|null> $files    path => content, null or absent = unreadable
     * @param array<string, string|null> $commands command => stdout, null or absent = failed
     * @param array<string, string>      $env
     */
    private function counter(array $files, array $commands, array $env = []): CpuCoreCounter
    {
        return new CpuCoreCounter(
            // "/sys/fs/cgroup" + "/" + "/cpu.max" and "/sys/fs/cgroup/cpu.max"
            // name the same file.
            static fn (string $path): ?string => $files[preg_replace('~/{2,}~', '/', $path)] ?? null,
            static fn (string $command): ?string => $commands[$command] ?? null,
            static fn (string $name): string|false => $env[$name] ?? false,
        );
    }

    private static function statusText(string $allowedList): string
    {
        return "Name:\tphp\nUmask:\t0022\nState:\tR (running)\nPid:\t4242\n"
            ."Cpus_allowed:\tffff\n"
            ."Cpus_allowed_list:\t".$allowedList."\n"
            ."Mems_allowed_list:\t0\nvoluntary_ctxt_switches:\t12\n";
    }

    private static function x86CpuInfoText(int $count): string
    {
        $text = '';
        for ($i = 0; $i < $count; $i++) {
            $text .= "processor\t: {$i}\nvendor_id\t: GenuineIntel\nmodel name\t: Intel(R) Xeon(R)\ncpu cores\t: {$count}\n\n";
        }

        return $text;
    }

    private function commandOutput(string $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return '';
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($stdout);
    }
}
