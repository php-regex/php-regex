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

use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quantifier past a comment, a "\E" or /x whitespace repeats the last
 * character before them, as it does written right after it: "\1000" is
 * the octal "\100" then "0", and only the "0" repeats.
 */
final class QuantifierPastACommentTest extends TestCase
{
    #[Test]
    #[DataProvider('provideQuantifiersPastWhatPcreSkips')]
    public function test_the_printed_pattern_matches_what_the_pattern_matches(string $pattern): void
    {
        $printed = Regex::create(['cache' => null])->parse($pattern)->accept(new PatternPrinter());

        foreach (['@00', '@0@0', '@0', '@000'] as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($printed, $subject), $pattern.' printed as '.$printed.' on '.$subject);
        }
    }

    /**
     * An inline comment whose text starts with "#" stays inline: printed as
     * "#..." under x it would run to the end and swallow what follows
     * (preg_match('/^a(?##c)b$/x', 'a') is 0, '/^a#cb$/x' matches it).
     */
    #[Test]
    public function test_an_inline_comment_starting_with_a_hash_keeps_its_parentheses(): void
    {
        $printed = Regex::create(['cache' => null])->parse('/^a(?##c)b$/x')->accept(new PatternPrinter());

        foreach (['a', 'ab', 'b'] as $subject) {
            $this->assertSame(preg_match('/^a(?##c)b$/x', $subject), preg_match($printed, $subject), $printed.' on '.$subject);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideQuantifiersPastWhatPcreSkips(): iterable
    {
        // preg_match('/^\1000(?#c)+$/', '@00') is 1: the "0" repeats.
        yield 'comment' => ['pattern' => '/^\1000(?#c)+$/'];
        yield 'x whitespace' => ['pattern' => '/^\1000 +$/x'];
        yield 'empty quote' => ['pattern' => '/^\1000\Q\E+$/'];
    }
}
