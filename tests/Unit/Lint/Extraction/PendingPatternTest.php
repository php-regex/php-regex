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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\PendingPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The column both strategies give an occurrence: 1-based, counted in bytes
 * from the last newline before it; none for an offset the parser does not
 * know.
 */
final class PendingPatternTest extends TestCase
{
    /**
     * @return iterable<string, array{content: string, offset: int, column: int|null}>
     */
    public static function provideOffsets(): iterable
    {
        yield 'the first byte' => ['content' => "<?php\n", 'offset' => 0, 'column' => 1];
        yield 'on the first line' => ['content' => '<?php f();', 'offset' => 6, 'column' => 7];
        yield 'after a newline' => ['content' => "<?php\n  f();", 'offset' => 8, 'column' => 3];
        yield 'right after the newline' => ['content' => "<?php\nf();", 'offset' => 6, 'column' => 1];
        yield 'an offset the parser does not know' => ['content' => "<?php\n", 'offset' => -1, 'column' => null];
    }

    #[Test]
    #[DataProvider('provideOffsets')]
    public function test_the_column_of_an_offset(string $content, int $offset, ?int $column): void
    {
        $this->assertSame($column, PendingPattern::column($content, $offset));
    }
}
