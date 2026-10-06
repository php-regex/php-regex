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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\OptimizationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptimizationResultJsonTest extends TestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideResults')]
    public function test_json_serialize_writes_the_original_the_optimized_and_the_changes(OptimizationResult $result, array $expected): void
    {
        $this->assertSame($expected, $result->jsonSerialize());
    }

    /**
     * @return iterable<string, array{result: OptimizationResult, expected: array<string, mixed>}>
     */
    public static function provideResults(): iterable
    {
        yield 'unchanged' => [
            'result' => new OptimizationResult('/abc/', '/abc/'),
            'expected' => ['original' => '/abc/', 'optimized' => '/abc/', 'changes' => []],
        ];

        yield 'changed' => [
            'result' => new OptimizationResult('/[0-9]+/', '/\d+/', ['Replaced [0-9] with \d']),
            'expected' => ['original' => '/[0-9]+/', 'optimized' => '/\d+/', 'changes' => ['Replaced [0-9] with \d']],
        ];
    }
}
