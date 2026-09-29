<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\Regex;

/**
 * "\c\" is the control character 0x1C. Written right before the closing
 * delimiter, PHP reads "\/" as an escaped delimiter and finds no end: the
 * compiled and optimized forms must not put it there, whatever the source
 * had between them (a space under "x", an empty "\E").
 */
final class ControlBackslashCompilationTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_compiled_and_optimized_forms_still_compile(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $regex = Regex::create(['cache' => null]);
        $compiled = $regex->parse($pattern)->accept(new CompilerNodeVisitor());
        $optimized = $regex->optimize($pattern)->optimized;

        $this->assertSame(1, @preg_match($compiled, $subject), \sprintf('%s compiled into %s', $pattern, $compiled));
        $this->assertSame(1, @preg_match($optimized, $subject), \sprintf('%s optimized into %s', $pattern, $optimized));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'before an empty quote end' => ['pattern' => '/\\N\\{\\{\\{\\c\\ \\E/x', 'subject' => "a{{{\x1c"];
        yield 'before a space under x' => ['pattern' => '/a\\c\\ /x', 'subject' => "a\x1c"];
        yield 'in the middle' => ['pattern' => '/\\c\\b/', 'subject' => "\x1cb"];
    }
}
