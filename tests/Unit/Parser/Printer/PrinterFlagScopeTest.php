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

use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The modifiers in force are the printer's own: the layout prints the body
 * of a group under the group's modifiers through the printer, which puts
 * its own back afterwards, and nothing outside the printer sets them.
 */
final class PrinterFlagScopeTest extends TestCase
{
    #[Test]
    public function test_a_print_under_other_flags_puts_the_flags_back(): void
    {
        $printer = new PatternPrinter();
        (new RegexNode(new LiteralNode('a', 0, 1), '', '/', 0, 1))->accept($printer);
        $literal = new LiteralNode('a b', 0, 3);

        $this->assertSame('a b', $literal->accept($printer), 'Without x, the space is written as is.');
        $this->assertSame('a\\ b x', $printer->underFlags('x', static fn (): string => $literal->accept($printer).' '.$printer->flags()), 'Under x, the space is escaped and the printer reports x.');
        $this->assertSame('', $printer->flags());
        $this->assertSame('a b', $literal->accept($printer), 'The flags are back once the print is done.');
    }

    #[Test]
    public function test_the_flags_come_back_when_the_print_fails(): void
    {
        $printer = new PatternPrinter();
        (new RegexNode(new LiteralNode('a', 0, 1), 'i', '/', 0, 1))->accept($printer);

        try {
            $printer->underFlags('x', static function (): string {
                throw new \LogicException('The body does not print.');
            });
            $this->fail('The failure of the print surfaces.');
        } catch (\LogicException) {
        }

        $this->assertSame('i', $printer->flags());
    }
}
