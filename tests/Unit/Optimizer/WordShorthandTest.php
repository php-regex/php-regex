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

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under the C locale, a class whose members cover the ASCII word characters
 * and nothing else is "\w", however its members are spelled: a range inside
 * another one changes nothing. preg_match() agrees on every byte (PHP
 * 8.4.27, LC_CTYPE C).
 */
final class WordShorthandTest extends TestCase
{
    #[Test]
    #[DataProvider('provideWordClasses')]
    public function test_a_class_of_the_word_characters_is_written_as_w_under_c(string $pattern, string $optimized): void
    {
        for ($byte = 0; $byte < 256; $byte++) {
            $this->assertSame(preg_match($pattern, \chr($byte)), preg_match($optimized, \chr($byte)), \sprintf('%s and %s on 0x%02X', $pattern, $optimized, $byte));
        }

        $tree = Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(optimizeWord: true, locale: 'C'));
        $this->assertInstanceOf(RegexNode::class, $tree);
        $this->assertSame($optimized, $tree->accept(new PatternPrinter()));
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string}>
     */
    public static function provideWordClasses(): iterable
    {
        yield 'as usually written' => ['pattern' => '/[a-zA-Z0-9_]/', 'optimized' => '/\w/'];
        yield 'in another order' => ['pattern' => '/[_0-9A-Za-z]/', 'optimized' => '/\w/'];
        yield 'with a range inside another' => ['pattern' => '/[a-zA-Z0-9_b-y]/', 'optimized' => '/\w/'];
        yield 'with a letter written twice' => ['pattern' => '/[a-zA-Z0-9_q]/', 'optimized' => '/\w/'];
        yield 'negated' => ['pattern' => '/[^a-zA-Z0-9_b-y]/', 'optimized' => '/\W/'];
        // One character more is no word class.
        yield 'with one more character' => ['pattern' => '/[a-zA-Z0-9_-]/', 'optimized' => '/[\\-0-9A-Z_a-z]/'];
    }
}
