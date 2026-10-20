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

namespace PHPRegex\Linter\Parallel;

/**
 * Where the files of a controller of this process's cgroup are read, from
 * the lines of /proc/self/cgroup, "<id>:<controllers>:<path>": the unified
 * hierarchy of cgroup v2 ("0::<path>") under /sys/fs/cgroup, a cgroup v1
 * controller under the mount its controller list names.
 *
 * Inside a container, the path is the cgroup's path on the host, while the
 * container's own cgroup is mounted at the root of its /sys/fs/cgroup: a
 * file not found at the path is read at the mount root.
 *
 * @internal
 */
final class Cgroup
{
    private function __construct() {}

    /**
     * For each line of the file that holds the controller, in the order of
     * the file: whether it is the v2 hierarchy, the directory at the path
     * it names, and the mount root.
     *
     * @param string|null $cgroup what /proc/self/cgroup holds, null when it cannot be read
     *
     * @return list<array{bool, string, string}>
     */
    public static function directories(?string $cgroup, string $controller): array
    {
        $directories = [];
        foreach (explode("\n", (string) $cgroup) as $line) {
            $parts = explode(':', $line, 3);
            if (3 !== \count($parts)) {
                continue;
            }

            [$id, $controllers, $path] = $parts;
            $path = rtrim($path, '/');

            if ('0' === $id && '' === $controllers) {
                $directories[] = [true, '/sys/fs/cgroup'.$path, '/sys/fs/cgroup'];
            } elseif (\in_array($controller, explode(',', $controllers), true)) {
                $directories[] = [false, '/sys/fs/cgroup/'.$controllers.$path, '/sys/fs/cgroup/'.$controllers];
            }
        }

        return $directories;
    }
}
