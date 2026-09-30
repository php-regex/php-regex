<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Lint;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Lint\ForkedWorkerPool;
use RegexParser\Tests\Support\LintFunctionOverrides;

/**
 * What a forked child does before it ends, run in this process: the fork
 * itself would end the test with the child.
 */
final class ForkedWorkerPoolTest extends TestCase
{
    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_a_child_whose_work_gives_a_result_writes_it_and_ends_with_zero(): void
    {
        $written = [];

        $code = (new ForkedWorkerPool())->runChild(
            static fn (): array => ['a', 'b'],
            static function (array $payload) use (&$written): void {
                $written = $payload;
            },
        );

        $this->assertSame(0, $code);
        $this->assertSame(['ok' => true, 'result' => ['a', 'b']], $written);
    }

    #[Test]
    public function test_a_child_whose_work_throws_writes_the_failure_and_ends_with_one(): void
    {
        $written = [];

        $code = (new ForkedWorkerPool())->runChild(
            static fn (): never => throw new \DomainException('Boom'),
            static function (array $payload) use (&$written): void {
                $written = $payload;
            },
        );

        $this->assertSame(1, $code);
        $this->assertSame(['ok' => false, 'error' => ['message' => 'Boom', 'class' => \DomainException::class]], $written);
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

        $this->assertSame(4321, $pool->fork(static fn (): null => null, static function (): void {}));
        $this->assertSame(-1, $pool->fork(static fn (): null => null, static function (): void {}));
    }
}
