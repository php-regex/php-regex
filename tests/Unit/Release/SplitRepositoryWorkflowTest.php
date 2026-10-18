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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each package directory carries the workflow its read-only repository runs
 * on a pull request: one file, the same in every package, so a change made
 * to one reaches all seventeen.
 */
final class SplitRepositoryWorkflowTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/close-pull-request.yml';

    #[Test]
    public function test_every_package_closes_the_pull_requests_its_split_receives(): void
    {
        $missing = [];
        foreach (self::packages() as $package) {
            if (!is_file($package.'/'.self::WORKFLOW)) {
                $missing[] = basename($package);
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function test_the_workflow_is_the_same_in_every_package(): void
    {
        $reference = (string) file_get_contents(\dirname(__DIR__, 3).'/src/Parser/'.self::WORKFLOW);
        $different = [];
        foreach (self::packages() as $package) {
            if ((string) file_get_contents($package.'/'.self::WORKFLOW) !== $reference) {
                $different[] = basename($package);
            }
        }

        $this->assertSame([], $different);
    }

    /**
     * @return list<string>
     */
    private static function packages(): array
    {
        $packages = [];
        foreach (glob(\dirname(__DIR__, 3).'/src/*/composer.json') ?: [] as $manifest) {
            $packages[] = \dirname($manifest);
        }

        self::assertCount(17, $packages);

        return $packages;
    }
}
