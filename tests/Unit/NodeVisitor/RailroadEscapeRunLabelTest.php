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

use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A run of literals that holds a character written as an escape is shown
 * as one label that reads back, as a pattern in the mode of the pattern it
 * was cut from, as the text the run matches. An escape that a following
 * character would make longer ("\0" before "1", "\x4" before "a", "\12"
 * before "3") does not take that character into it.
 *
 * Oracle (PHP 8.4, PCRE2 10.49, JIT off): each pattern matches its subject,
 * and "\01", "\x4a" and "\123" are the single characters U+0001, "J" and
 * "S", not the two the run holds.
 */
final class RailroadEscapeRunLabelTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_railroad_run_label_reads_back_as_the_run(string $pattern, string $flags, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'Oracle: the pattern matches the subject.');

        $label = $this->label($pattern);

        $this->assertSame(1, @preg_match('/^'.$label.'$/'.$flags, $subject), \sprintf('The label "%s" reads back as the run.', $label));
    }

    /**
     * @return iterable<string, array{pattern: string, flags: string, subject: string}>
     */
    public static function provideRuns(): iterable
    {
        yield 'octal NUL before a quoted digit' => ['pattern' => '/^a\0\Q1\E$/', 'flags' => '', 'subject' => "a\x001"];
        yield 'one-digit hex escape before a quoted hex letter' => ['pattern' => '/^a\x4\Qa\E$/', 'flags' => '', 'subject' => "a\x04a"];
        yield 'two-digit octal escape before a quoted digit' => ['pattern' => '/^a\12\Q3\E$/', 'flags' => '', 'subject' => "a\n3"];
        yield 'three-digit octal escape before a quoted digit' => ['pattern' => '/^a\101\Q1\E$/', 'flags' => '', 'subject' => 'aA1'];
        yield 'one-digit hex escape before an escape' => ['pattern' => '/^a\x4\x61$/', 'flags' => '', 'subject' => "a\x04a"];
        yield 'hidden character written as an escape under u' => ['pattern' => '/^a\x{202E}b$/u', 'flags' => 'u', 'subject' => "a\u{202E}b"];
        yield 'hidden character written as an escape under a UTF verb' => ['pattern' => '/(*UTF)^a\x{202E}b$/', 'flags' => 'u', 'subject' => "a\u{202E}b"];
        yield 'named character under u' => ['pattern' => '/^a\N{U+202E}b$/u', 'flags' => 'u', 'subject' => "a\u{202E}b"];
        yield 'tab inside the braces of a hex escape' => ['pattern' => "/^a\\x{\t41}b$/", 'flags' => '', 'subject' => 'aAb'];
        yield 'escape under x with parentheses as delimiters' => ['pattern' => '(^a\x41b$)x', 'flags' => '', 'subject' => 'aAb'];
        yield 'space inside the braces of an octal escape' => ['pattern' => '/^a\o{ 101 }b$/', 'flags' => '', 'subject' => 'aAb'];
    }

    /**
     * The escape keeps its spelling unless the text after it would read as
     * more of it; it is then its code point in braces, in capital hex.
     */
    #[Test]
    #[DataProvider('provideLabels')]
    public function test_railroad_run_label_respells_only_an_escape_the_text_would_lengthen(string $pattern, string $subject, string $label): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'Oracle: the pattern matches the subject.');
        $this->assertSame(1, preg_match('/^'.$label.'$/', $subject), 'Oracle: the label reads back as the run.');

        $this->assertSame($label, $this->label($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, label: string}>
     */
    public static function provideLabels(): iterable
    {
        yield 'octal NUL ending the run' => ['pattern' => '/^a\0$/', 'subject' => "a\x00", 'label' => 'a\0'];
        yield 'octal NUL before two quoted digits' => ['pattern' => '/^a\0\Q12\E$/', 'subject' => "a\x0012", 'label' => 'a\x{0}12'];
        yield 'octal NUL before a quoted digit and a letter' => ['pattern' => '/^a\0\Q1z\E$/', 'subject' => "a\x001z", 'label' => 'a\x{0}1z'];
        yield 'one-digit hex escape before two quoted hex letters' => ['pattern' => '/^a\x4\Qab\E$/', 'subject' => "a\x04ab", 'label' => 'a\x{4}ab'];
        yield 'one-digit hex escape before a hex letter and another' => ['pattern' => '/^a\x4\Qaz\E$/', 'subject' => "a\x04az", 'label' => 'a\x{4}az'];
        yield 'one-digit hex escape before a letter that is not hex' => ['pattern' => '/^a\x4\Qz\E$/', 'subject' => "a\x04z", 'label' => 'a\x4z'];
        yield 'two-digit octal escape before a letter' => ['pattern' => '/^a\12\Qz\E$/', 'subject' => "a\nz", 'label' => 'a\12z'];
        yield 'two-digit octal escape before a digit' => ['pattern' => '/^a\12\Q3\E$/', 'subject' => "a\n3", 'label' => 'a\x{A}3'];
        yield 'two-digit hex escape before a hex letter' => ['pattern' => '/^a\x41\Qb\E$/', 'subject' => 'aAb', 'label' => 'a\x41b'];
    }

    /**
     * The label of the run that holds the escape: the one after the start
     * anchor.
     */
    private function label(string $pattern): string
    {
        $svg = Regex::create(['cache' => null])->parse($pattern)->accept(new RailroadSvgRenderer());
        $this->assertIsString($svg);

        preg_match_all('/<text\b[^>]*>([^<]*)<\/text>/', $svg, $matches);
        foreach ($matches[1] as $text) {
            $text = html_entity_decode($text, \ENT_QUOTES | \ENT_XML1);
            if (str_starts_with($text, 'a')) {
                return $text;
            }
        }

        $this->fail('No run label in the diagram.');
    }
}
