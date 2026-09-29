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

namespace RegexParser\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Regex;

/**
 * "\x" reads one or two hexadecimal digits: "\x0" is NUL, "\xA" a line feed,
 * and "\x4g" the character 4 followed by "g" (PHP matches each).
 */
final class HexEscapeCodePointTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEscapes')]
    public function test_the_escape_carries_its_code_point(string $pattern, string $subject, int $codePoint): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $node = Regex::create(['cache' => null])->parse($pattern)->pattern;
        $first = $node instanceof SequenceNode ? $node->children[1] : $node;

        $this->assertInstanceOf(CharLiteralNode::class, $first);
        $this->assertSame($codePoint, $first->codePoint, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, codePoint: int}>
     */
    public static function provideEscapes(): iterable
    {
        yield 'one digit, NUL' => ['pattern' => '/^\\x0$/', 'subject' => "\0", 'codePoint' => 0];
        yield 'one letter digit' => ['pattern' => '/^\\xA$/', 'subject' => "\n", 'codePoint' => 10];
        yield 'one digit before a letter' => ['pattern' => '/^\\x4g$/', 'subject' => "\x04g", 'codePoint' => 4];
        yield 'two digits' => ['pattern' => '/^\\x41$/', 'subject' => 'A', 'codePoint' => 65];
    }
}
