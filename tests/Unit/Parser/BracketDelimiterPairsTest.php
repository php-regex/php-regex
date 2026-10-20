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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\Delimiters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP closes a pattern opened by "(", "[", "{" or "<" with its partner, and
 * any other delimiter with itself: the parser, the printer, the engine's
 * rewriting and the split around the body all close it the same way.
 */
final class BracketDelimiterPairsTest extends TestCase
{
    /**
     * @return iterable<string, array{open: string, close: string}>
     */
    public static function provideDelimiters(): iterable
    {
        yield 'parentheses' => ['open' => '(', 'close' => ')'];
        yield 'square brackets' => ['open' => '[', 'close' => ']'];
        yield 'braces' => ['open' => '{', 'close' => '}'];
        yield 'angle brackets' => ['open' => '<', 'close' => '>'];
        yield 'a slash closes itself' => ['open' => '/', 'close' => '/'];
        yield 'a hash closes itself' => ['open' => '#', 'close' => '#'];
    }

    #[Test]
    #[DataProvider('provideDelimiters')]
    public function test_every_reader_closes_a_delimiter_as_php_does(string $open, string $close): void
    {
        $regex = $open.'a+'.$close.'i';

        // Oracle: PHP closes the pattern there.
        $this->assertSame(1, preg_match($regex, 'A'));

        $this->assertSame($close, Delimiters::closing($open));
        $this->assertSame([$open, 'a+', $close, 'i'], NoJit::split($regex));
        $this->assertSame($open.'(*NO_JIT)a+'.$close.'i', (new PcreEngine())->prepare($regex));
        $this->assertSame($regex, RegexParser::create()->parse($regex)->accept(new PatternPrinter()));
    }
}
