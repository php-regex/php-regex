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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Internal\ForkedWorkerPool;
use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Library code returns; the binaries exit. The one exception is the child
 * of a forked worker, which must end without running the parent's shutdown
 * code: it ends in one place, the worker pool.
 */
final class LibraryExitTest extends TestCase
{
    #[Test]
    public function test_only_the_forked_worker_pool_exits(): void
    {
        $exits = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            $lines = LibrarySource::exits($tokens);
            if ([] !== $lines) {
                $exits[$path] = \count($lines);
            }
        }

        $this->assertSame(['src/Linter/Internal/ForkedWorkerPool.php' => 1], $exits);
    }

    /**
     * The file allowed to exit holds the worker pool, and nothing else.
     */
    #[Test]
    public function test_the_file_that_exits_declares_the_forked_worker_pool(): void
    {
        $file = (string) (new \ReflectionClass(ForkedWorkerPool::class))->getFileName();

        $this->assertStringEndsWith('src/Linter/Internal/ForkedWorkerPool.php', str_replace('\\', '/', $file));
    }

    #[Test]
    public function test_the_scan_sees_exit_and_die(): void
    {
        $tokens = token_get_all("<?php\nexit(1);\ndie;\n\$exit = 'exit';\n");

        $this->assertSame([2, 3], LibrarySource::exits($tokens));
    }
}
