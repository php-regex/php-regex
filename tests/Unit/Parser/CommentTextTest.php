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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A comment keeps the text it was written with.
 *
 * The parser reads it back from the pattern through the span each token
 * carries. Rebuilding it from the token values instead used to mean guessing
 * how each one had been spelled — a "\d" inside a comment is a backslash and
 * a letter, not a character type.
 */
final class CommentTextTest extends TestCase
{
    #[Test]
    #[DataProvider('provideComments')]
    public function test_a_comment_keeps_its_text(string $pattern, string $comment): void
    {
        $ast = Regex::create()->parse($pattern);

        $this->assertSame($comment, $this->firstComment($ast->pattern));
        $this->assertSame($pattern, $ast->accept(new PatternPrinter()));
    }

    /**
     * @return iterable<string, array{pattern: string, comment: string}>
     */
    public static function provideComments(): iterable
    {
        yield 'a group comment holding escapes and brackets' => [
            'pattern' => '/(?# a \d comment [x] )a/',
            'comment' => ' a \d comment [x] ',
        ];

        yield 'a group comment holding a quote start' => [
            'pattern' => '/(?#\Q)/',
            'comment' => '\Q',
        ];

        yield 'an extended-mode comment holding a property' => [
            'pattern' => "/a # trailing \p{L} note\nb/x",
            'comment' => "# trailing \p{L} note\n",
        ];

        yield 'an extended-mode comment holding a metacharacter' => [
            'pattern' => "/a # (?<name>x) | [a-z]+\nb/x",
            'comment' => "# (?<name>x) | [a-z]+\n",
        ];
    }

    private function firstComment(object $node): string
    {
        if ($node instanceof CommentNode) {
            return $node->comment;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if ($child instanceof CommentNode) {
                    return $child->comment;
                }
            }
        }

        self::fail('The pattern holds no comment.');
    }
}
