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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A docblock directly followed by another documents nothing: PHP keeps the
 * second for the element after them, and so do reflection, the IDE and the
 * API reference, so the first is lost.
 */
final class PrinterDocBlocksTest extends TestCase
{
    #[Test]
    #[DataProvider('providePrinterSources')]
    public function test_no_docblock_of_the_printers_is_followed_by_another(string $file): void
    {
        $stacked = [];
        $previous = '';
        foreach (file($file, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if ('*/' === trim($previous) && str_starts_with(trim($line), '/**')) {
                $stacked[] = $index + 1;
            }

            $previous = $line;
        }

        $this->assertSame([], $stacked, basename($file).': a docblock stands right before the one on these lines.');
    }

    /**
     * @return iterable<string, array{file: string}>
     */
    public static function providePrinterSources(): iterable
    {
        foreach (glob(__DIR__.'/../../../../src/Parser/Printer/*.php') ?: [] as $file) {
            yield basename($file) => ['file' => $file];
        }
    }
}
