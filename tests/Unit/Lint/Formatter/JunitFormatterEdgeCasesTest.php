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

use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPUnit\Framework\TestCase;

final class JunitFormatterEdgeCasesTest extends TestCase
{
    public function test_flatten_problems_skips_non_regex_problem(): void
    {
        $formatter = new JunitFormatter();
        $method = (new \ReflectionClass($formatter))->getMethod('flattenProblems');

        $result = $method->invoke($formatter, [[
            'file' => 'file.php',
            'line' => 1,
            'problems' => ['not-a-problem'],
        ]]);

        $this->assertSame([], $result);
    }
}
