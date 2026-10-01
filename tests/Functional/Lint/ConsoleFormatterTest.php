<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Functional\Lint;

use PhpRegex\Linter\Formatter\ConsoleFormatter;
use PHPUnit\Framework\TestCase;

final class ConsoleFormatterTest extends TestCase
{
    public function test_split_lines_normalizes_line_endings(): void
    {
        $formatter = new ConsoleFormatter();
        $method = new \ReflectionMethod(ConsoleFormatter::class, 'splitLines');

        $result = $method->invoke($formatter, "one\r\ntwo\rthree\nfour\n");

        $this->assertSame(['one', 'two', 'three', 'four', ''], $result);
    }

    public function test_diff_lines_marks_inserts_and_deletes(): void
    {
        $formatter = new ConsoleFormatter();
        $method = new \ReflectionMethod(ConsoleFormatter::class, 'diffLines');

        /** @var array<int, array{type: string, line: string}> $ops */
        $ops = $method->invoke($formatter, ['a', 'b'], ['a', 'c']);
        $this->assertIsArray($ops);
        $types = array_map(static fn (array $op) => $op['type'], $ops);

        $this->assertSame(['equal', 'delete', 'insert'], $types);
    }
}
