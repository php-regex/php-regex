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

use PHPRegex\Linter\Parallel\Cgroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the files of a controller of this process's cgroup are read, from
 * /proc/self/cgroup: under the unified hierarchy for cgroup v2, under the
 * controller's own mount for v1, each at the path the line names, then at
 * the mount root, where a container sees its own cgroup.
 */
final class CgroupTest extends TestCase
{
    /**
     * @param list<array{bool, string, string}> $expected
     */
    #[Test]
    #[DataProvider('provideCgroups')]
    public function test_a_controller_is_read_where_its_line_says(?string $cgroup, string $controller, array $expected): void
    {
        $this->assertSame($expected, Cgroup::directories($cgroup, $controller));
    }

    /**
     * @return iterable<string, array{cgroup: string|null, controller: string, expected: list<array{bool, string, string}>}>
     */
    public static function provideCgroups(): iterable
    {
        yield 'no file' => ['cgroup' => null, 'controller' => 'cpu', 'expected' => []];
        yield 'empty' => ['cgroup' => '', 'controller' => 'memory', 'expected' => []];
        yield 'v2' => ['cgroup' => "0::/lint.slice\n", 'controller' => 'cpu', 'expected' => [[true, '/sys/fs/cgroup/lint.slice', '/sys/fs/cgroup']]];
        yield 'v2 at the root' => ['cgroup' => "0::/\n", 'controller' => 'memory', 'expected' => [[true, '/sys/fs/cgroup', '/sys/fs/cgroup']]];
        yield 'v1, the controller among others' => ['cgroup' => "12:cpu,cpuacct:/lint/\n9:memory:/lint\n", 'controller' => 'cpu', 'expected' => [[false, '/sys/fs/cgroup/cpu,cpuacct/lint', '/sys/fs/cgroup/cpu,cpuacct']]];
        yield 'v1, another controller' => ['cgroup' => "12:cpu,cpuacct:/lint\n9:memory:/lint\n", 'controller' => 'memory', 'expected' => [[false, '/sys/fs/cgroup/memory/lint', '/sys/fs/cgroup/memory']]];
        yield 'v1, a controller whose name holds another' => ['cgroup' => "12:cpuacct:/lint\n", 'controller' => 'cpu', 'expected' => []];
        yield 'hybrid, in the order of the file' => ['cgroup' => "9:memory:/a\n0::/b\n", 'controller' => 'memory', 'expected' => [[false, '/sys/fs/cgroup/memory/a', '/sys/fs/cgroup/memory'], [true, '/sys/fs/cgroup/b', '/sys/fs/cgroup']]];
        yield 'a line of fewer parts' => ['cgroup' => "garbage\n0:/x\n", 'controller' => 'cpu', 'expected' => []];
        yield 'a path holding a colon' => ['cgroup' => "0::/a:b\n", 'controller' => 'cpu', 'expected' => [[true, '/sys/fs/cgroup/a:b', '/sys/fs/cgroup']]];
        yield 'v2 numbered otherwise' => ['cgroup' => "1::/x\n", 'controller' => 'cpu', 'expected' => []];
    }
}
