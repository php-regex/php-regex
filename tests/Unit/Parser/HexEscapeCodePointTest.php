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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    #[Test]
    public function test_a_bare_x_is_nul_where_pcre_takes_it(): void
    {
        // PCRE2 10.40 to 10.44, which PHP 8.2 to 8.5 bundle, read "\x" with
        // no digit as NUL, and "\xg" as NUL then "g"; 10.45 refuses it at
        // the character after "\x" (pcre2test on each).
        foreach ([80200, 80400, 80500] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);
            $node = $regex->parse('/a\\xg/')->pattern;

            $this->assertInstanceOf(SequenceNode::class, $node);
            $this->assertInstanceOf(CharLiteralNode::class, $node->children[1]);
            $this->assertSame(0, $node->children[1]->codePoint);
            $this->assertTrue($regex->validate('/a\\xg/')->isValid);
            $this->assertSame(['a'], $regex->literals('/a\\xg/')->literalSet->prefixes);
        }

        $running = Regex::create(['cache' => null])->validate('/a\\xg/');
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=')) {
            $this->assertSame(ErrorCode::EscapeDigitsMissing, $running->errorCode);
            $this->assertSame(3, $running->offset);
        } else {
            $this->assertTrue($running->isValid);
        }
    }

    /**
     * Between "\Q" and "\E" a backslash is text: "\Q\x\E" is the two
     * characters "\x", no escape at all, whatever the release does with a
     * bare "\x". preg_match('/\Q\x\E/', '\x') is 1 and on 'x' it is 0.
     */
    #[Test]
    public function test_a_quoted_x_is_the_text_backslash_x(): void
    {
        $this->assertSame([1, 0], [preg_match('/\Q\x\E/', '\x'), preg_match('/\Q\x\E/', 'x')], 'Oracle.');

        $regex = Regex::create(['cache' => null]);

        $this->assertTrue($regex->validate('/\Q\x\E/')->isValid, (string) $regex->validate('/\Q\x\E/')->error);

        $node = $regex->parse('/\Q\x\E/')->pattern;
        $this->assertInstanceOf(LiteralNode::class, $node);
        $this->assertSame('\x', $node->value);
    }

    /**
     * Each pattern quotes a "\x": it is valid, holds no code point escape,
     * and the printed pattern reads back to the same text, matching the
     * subjects the original matches.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuotedX')]
    public function test_a_quoted_x_is_no_code_point_and_its_printed_pattern_matches_the_same(string $pattern, array $subjects): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'Oracle: '.$pattern.' compiles.');

        $regex = Regex::create(['cache' => null]);
        $result = $regex->validate($pattern);
        $this->assertTrue($result->isValid, \sprintf('%s: %s', $pattern, (string) $result->error));

        $tree = $regex->parse($pattern);
        $this->assertSame([], self::charLiterals($tree->pattern), $pattern.' reads a code point escape.');

        $printed = $tree->accept(new PatternPrinter());
        $this->assertNotFalse(@preg_match($printed, ''), \sprintf('%s printed as %s, which does not compile.', $pattern, $printed));
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($printed, $subject), \sprintf('%s printed as %s, which disagrees on %s.', $pattern, $printed, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideQuotedX(): iterable
    {
        yield 'the quote alone' => ['pattern' => '/\Q\x\E/', 'subjects' => ['\x', 'x', "\0"]];
        yield 'between two letters' => ['pattern' => '/a\Q\x\Eb/', 'subjects' => ['a\xb', 'axb', "a\0b"]];
        yield 'quote running to the end' => ['pattern' => '/\Q\x/', 'subjects' => ['\x', 'x', "\0"]];
        yield 'quantified after the quote' => ['pattern' => '/^\Q\x\E{2}$/', 'subjects' => ['\xx', '\x\x', 'xx']];
        yield 'under the u flag' => ['pattern' => '/\Q\x\E/u', 'subjects' => ['\x', 'x']];
        yield 'followed by braces inside the quote' => ['pattern' => '/\Q\x{41}\E/', 'subjects' => ['\x{41}', 'A']];
        // A quote in a class is read as text already: kept as a guard.
        yield 'inside a class' => ['pattern' => '/^[\Q\x\E]$/', 'subjects' => ['\\', 'x', "\0"]];
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

    /**
     * @return list<CharLiteralNode>
     */
    private static function charLiterals(NodeInterface $node): array
    {
        $found = $node instanceof CharLiteralNode ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            array_push($found, ...self::charLiterals($child));
        }

        return $found;
    }
}
