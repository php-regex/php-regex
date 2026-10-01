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

use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whitespace, "#" and a trailing newline inside \Q...\E are literal, in
 * extended mode too. Compiling must keep them literal: a quoted newline at
 * the very end of the pattern must survive, and a quoted space or "#" under
 * x must not turn into ignored whitespace or a comment.
 */
final class QuotedWhitespaceRoundTripTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_compiling_keeps_quoted_whitespace_literal(string $pattern, array $subjects): void
    {
        $compiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s on %s', json_encode($pattern), json_encode($compiled), json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        // A leading (*UTF) makes the pattern UTF-8 like the u flag: a quoted
        // non-ASCII character must not come back as separate bytes.
        yield "/(*UTF)\\Q\u{e9}\\E/" => ['pattern' => "/(*UTF)\\Q\u{e9}\\E/", 'subjects' => ["\u{e9}", 'e']];
        yield "/(*UTF)^[\\Q\u{e9}\\E]$/" => ['pattern' => "/(*UTF)^[\\Q\u{e9}\\E]$/", 'subjects' => ["\u{e9}", 'e']];
        yield "/(*CR)(*UTF8)\\Q\u{e9}\\E/" => ['pattern' => "/(*CR)(*UTF8)\\Q\u{e9}\\E/", 'subjects' => ["\u{e9}", 'e']];
        yield "/(*LIMIT_MATCH=10)(*UTF)\\Q\u{e9}\\E/" => ['pattern' => "/(*LIMIT_MATCH=10)(*UTF)\\Q\u{e9}\\E/", 'subjects' => ["\u{e9}", 'e']];
        yield '/(*CR)/' => ['pattern' => '/(*CR)/', 'subjects' => ['', 'a']];
        yield "/^a\\Q\n/" => ['pattern' => "/^a\\Q\n/", 'subjects' => ["a\n", 'a', '']];
        yield "/^\\Q\n/x" => ['pattern' => "/^\\Q\n/x", 'subjects' => ["\n", '', ' ']];
        yield '/^a\\Q \\E$/x' => ['pattern' => '/^a\\Q \\E$/x', 'subjects' => ['a ', 'a']];
        yield '/^\\Q#\\E$/x' => ['pattern' => '/^\\Q#\\E$/x', 'subjects' => ['#', '']];
        yield "/^\\Q\t\\Ea\$/x" => ['pattern' => "/^\\Q\t\\Ea\$/x", 'subjects' => ["\ta", 'a']];
        yield "/^\\K\\Q-\n/x" => ['pattern' => "/^\\K\\Q-\n/x", 'subjects' => ["-\n", '-']];
        yield "/^\\Q#x\n\\E\$/x" => ['pattern' => "/^\\Q#x\n\\E\$/x", 'subjects' => ["#x\n", 'x']];
    }
}
