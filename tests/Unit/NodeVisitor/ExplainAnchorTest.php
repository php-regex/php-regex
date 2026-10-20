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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What "^", "$" and the anchoring escapes match depends on the flags in
 * force where they stand: m makes "^" and "$" line anchors, D keeps "$" off
 * a final newline, and the newline convention says what a newline is.
 */
final class ExplainAnchorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAnchors')]
    public function test_an_anchor_is_explained_by_the_flags_in_force(string $pattern, string $description, string $subject, int $matches): void
    {
        // The engine: the subject tells the meaning described from the others.
        $this->assertSame($matches, preg_match($pattern, $subject), 'engine');

        $tree = RegexParser::create()->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer());
        $html = html_entity_decode((string) $tree->accept(new HtmlExplainer()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // The whole description, not the start of a longer one.
        $this->assertMatchesRegularExpression('/: '.preg_quote($description, '/').'$/m', $text, 'text');
        $this->assertMatchesRegularExpression('/[>("]'.preg_quote($description, '/').'[)"<]/', $html, 'html');
    }

    /**
     * @return iterable<string, array{pattern: string, description: string, subject: string, matches: int}>
     */
    public static function provideAnchors(): iterable
    {
        $subjectStart = 'the start of the subject';
        $lineStart = 'the start of a line: the start of the subject, or just after a newline (\n) that does not end it';
        $subjectEnd = 'the end of the subject';
        $finalNewline = 'the end of the subject, or just before a newline (\n) that ends it';
        $lineEnd = 'the end of a line: just before a newline (\n), or at the end of the subject';

        yield 'a caret without m' => ['pattern' => '/^b/', 'description' => $subjectStart, 'subject' => "a\nb", 'matches' => 0];
        yield 'a caret under m' => ['pattern' => '/^b/m', 'description' => $lineStart, 'subject' => "a\nb", 'matches' => 1];
        yield 'a dollar without m' => ['pattern' => '/a$/', 'description' => $finalNewline, 'subject' => "a\n", 'matches' => 1];
        yield 'a dollar without m, mid-subject' => ['pattern' => '/a$/', 'description' => $finalNewline, 'subject' => "a\nb", 'matches' => 0];
        yield 'a dollar under D' => ['pattern' => '/a$/D', 'description' => $subjectEnd, 'subject' => "a\n", 'matches' => 0];
        yield 'a dollar under m' => ['pattern' => '/a$/m', 'description' => $lineEnd, 'subject' => "a\nb", 'matches' => 1];
        // m wins over D.
        yield 'a dollar under m and D' => ['pattern' => '/a$/mD', 'description' => $lineEnd, 'subject' => "a\nb", 'matches' => 1];
        yield 'a caret after an inline m' => ['pattern' => '/(?m)x|^b/', 'description' => $lineStart, 'subject' => "a\nb", 'matches' => 1];
        yield 'a caret after an inline m in a branch before' => ['pattern' => '/(x(?m)y|^b)/', 'description' => $lineStart, 'subject' => "a\nb", 'matches' => 1];
        yield 'a caret after a group that set m' => ['pattern' => '/(?m:x)|^b/', 'description' => $subjectStart, 'subject' => "a\nb", 'matches' => 0];
        yield 'a dollar after an inline m ended by its group' => ['pattern' => '/(?:(?m))a$/', 'description' => $finalNewline, 'subject' => "a\nb", 'matches' => 0];
        yield 'a caret in a group that sets m' => ['pattern' => '/x|(?m:^b)/', 'description' => $lineStart, 'subject' => "a\nb", 'matches' => 1];
        yield 'a caret after a setting that leaves m alone' => ['pattern' => '/(?i)^b/', 'description' => $subjectStart, 'subject' => "a\nb", 'matches' => 0];
        yield 'a caret after a caret reset, without m' => ['pattern' => '/(?^)^b/', 'description' => $subjectStart, 'subject' => "a\nb", 'matches' => 0];
        yield 'a dollar after m is turned off' => ['pattern' => '/(?-m)a$/m', 'description' => $finalNewline, 'subject' => "a\nb", 'matches' => 0];
        yield 'a dollar after a caret reset' => ['pattern' => '/(?^)a$/m', 'description' => $finalNewline, 'subject' => "a\nb", 'matches' => 0];
        yield 'a dollar under CR' => ['pattern' => '/(*CR)a$/', 'description' => 'the end of the subject, or just before a newline (\r) that ends it', 'subject' => "a\r", 'matches' => 1];
        yield 'a dollar under CRLF' => ['pattern' => '/(*CRLF)a$/', 'description' => 'the end of the subject, or just before a newline (\r\n) that ends it', 'subject' => "a\r\n", 'matches' => 1];
        yield 'a caret under ANYCRLF and m' => ['pattern' => '/(*ANYCRLF)^b/m', 'description' => 'the start of a line: the start of the subject, or just after a newline (\r, \n or \r\n) that does not end it', 'subject' => "a\rb", 'matches' => 1];
        yield 'a dollar under ANY' => ['pattern' => '/(*ANY)a$/u', 'description' => 'the end of the subject, or just before a newline (any Unicode line break) that ends it', 'subject' => "a\u{2028}", 'matches' => 1];
        yield 'a dollar under NUL' => ['pattern' => '/(*NUL)a$/', 'description' => 'the end of the subject, or just before a newline (the NUL character) that ends it', 'subject' => "a\0", 'matches' => 1];
        yield 'a dollar under LF passes over a carriage return' => ['pattern' => '/a$/', 'description' => $finalNewline, 'subject' => "a\r", 'matches' => 0];
        yield 'the subject start, whatever m says' => ['pattern' => '/\Ab/m', 'description' => $subjectStart, 'subject' => "a\nb", 'matches' => 0];
        yield 'the subject end' => ['pattern' => '/a\z/', 'description' => $subjectEnd, 'subject' => "a\n", 'matches' => 0];
        yield 'the subject end but a final newline, whatever D says' => ['pattern' => '/a\Z/D', 'description' => $finalNewline, 'subject' => "a\n", 'matches' => 1];
        yield 'the subject end but a final newline under CR' => ['pattern' => '/(*CR)a\Z/', 'description' => 'the end of the subject, or just before a newline (\r) that ends it', 'subject' => "a\n", 'matches' => 0];
        yield 'where the attempt starts' => ['pattern' => '/\Gb/', 'description' => 'where the match attempt starts: the offset the search starts at, or the end of the previous match', 'subject' => 'ab', 'matches' => 0];
    }

    #[Test]
    public function test_the_start_of_the_attempt_is_the_offset_given(): void
    {
        $this->assertSame(1, preg_match('/\Gb/', 'ab', $matches, 0, 1));
        $this->assertSame(0, preg_match('/^b/', 'ab', $matches, 0, 1));
    }

    #[Test]
    public function test_a_lone_anchor_is_explained_with_no_flag_in_force(): void
    {
        $tree = RegexParser::create()->parse('/^/m');

        $this->assertSame('Anchor: the start of the subject', (string) $tree->pattern->accept(new TextExplainer()));
    }
}
