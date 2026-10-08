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
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The character sets the rewriter compares stop at 0x7F: a repeat that may
 * take a character above ASCII, or a suffix that may start with one, is
 * never known to be disjoint from the other, and stays greedy. Made
 * possessive, "/.+é/" and "/é+é/u" would match nothing.
 * Oracle, PHP 8.4.26 / PCRE2 10.49.
 */
final class AutoPossessifyAboveAsciiTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_a_repeat_next_to_a_character_above_ascii_stays_greedy(string $pattern, string $subject): void
    {
        $rewritten = $this->rewrite($pattern);

        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(1, preg_match($rewritten, $subject), $rewritten);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'dot before a multibyte character' => ['pattern' => '/^.+é$/', 'subject' => 'aé'];
        yield 'multibyte character repeated before itself under u' => ['pattern' => '/^é+é$/u', 'subject' => 'éé'];
        yield 'negated class before a multibyte character under u' => ['pattern' => '/^[^a]+é$/u', 'subject' => 'bé'];
        yield 'code point escape repeated before itself under u' => ['pattern' => '/^\x{e9}+é$/u', 'subject' => 'éé'];
        yield 'letter before a multibyte character under u' => ['pattern' => '/^a+é$/u', 'subject' => 'aé'];
        yield 'group of letters and a boundary' => ['pattern' => '/^(?:a\b)+ c$/', 'subject' => 'a c'];
        yield 'group holding a callout' => ['pattern' => '/^(?:a(?C1))+b$/', 'subject' => 'ab'];
        yield 'optional suffix' => ['pattern' => '/^a+b?$/', 'subject' => 'aab'];
    }

    #[Test]
    public function test_a_repeat_held_to_ascii_is_still_made_possessive(): void
    {
        $this->assertSame('/a++b/', $this->rewrite('/a+b/'));
    }

    private function rewrite(string $pattern): string
    {
        return Regex::create(['cache' => null])->parse($pattern)->accept(new Rewriter(autoPossessify: true))->accept(new PatternPrinter());
    }
}
