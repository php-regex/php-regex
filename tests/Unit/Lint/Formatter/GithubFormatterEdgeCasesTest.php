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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\GithubFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GithubFormatterEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_flatten_problems_of_a_result_without_issue_or_optimization_is_empty(): void
    {
        $formatter = new GithubFormatter();
        $method = (new \ReflectionClass($formatter))->getMethod('flattenProblems');

        $result = $method->invoke($formatter, [[
            'file' => 'file.php',
            'line' => 1,
            'pattern' => '/a/',
            'issues' => [],
            'optimizations' => [],
        ]]);

        $this->assertSame([], $result);
    }
}
