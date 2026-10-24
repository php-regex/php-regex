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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Printer\PatternFormatter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP finds the end of a pattern pairing each backslash with the character
 * after it, while PCRE reads "\c\" as one control character, 0x1C: in
 * "{\c\\Q{}}" PHP reads "\c", "\\", "Q", PCRE "\c\" then "\Q". Whatever
 * the printer writes after a "\c\", a delimiter must end the pattern where
 * it ends for PCRE, and the pattern must match what the source matches.
 * Semantics verified on PHP 8.4.26 / PCRE2 10.49.
 */
final class ControlBackslashBeforeDelimiterTest extends TestCase
{
    private const SUBJECTS = ["\x1c", "\x1c{", "\x1c{}", "\x1c}", "a\x1c/b", "\x1c#a", '}', '{', '\\', '/', "\x1c\x1c", "a\x1c\x1c", "\x1cb"];

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_printed_pattern_ends_where_the_source_ends(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $tree = RegexParser::create()->parse($pattern);

        foreach (['default' => new PatternPrinter(), 'pretty' => new PatternPrinter(pretty: true)] as $printer => $visitor) {
            $this->assertSameMatches($pattern, $tree->accept($visitor), $printer);
        }

        $this->assertSameMatches($pattern, (new PatternFormatter())->format($tree)->pattern, 'format');
        $this->assertSameMatches($pattern, (new PatternFormatter())->compact($tree)->pattern, 'compact');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a quote of braces after it' => ['pattern' => '{\c\\\\Q{}}', 'subject' => "\x1c{}"];
        yield 'a literal opening brace after it' => ['pattern' => '{\c\{}', 'subject' => "\x1c{"];
        yield 'a closing bracket in a class after it' => ['pattern' => '{[\c\}]}', 'subject' => '}'];
        yield 'a group closing after it, a space between under x' => ['pattern' => '((?:\c\ ))x', 'subject' => "\x1c"];
        yield 'the delimiter after it' => ['pattern' => '/a\c\/b/', 'subject' => "a\x1c/b"];
        yield 'an escaped delimiter after it, a space between under x' => ['pattern' => '/a\c\ \/b/x', 'subject' => "a\x1c/b"];
        yield 'a hash delimiter after it' => ['pattern' => '#\c\#a#', 'subject' => "\x1c#a"];
        // Under x, "#c" is a comment PHP reads only after the "\c\", and the
        // quantifier after it repeats the "\c\": written between them, the
        // quantifier would bare the "#", and PHP end the pattern there.
        yield 'a quantifier after a comment it hides' => ['pattern' => "#a\\c\\#c\n+#x", 'subject' => "a\x1c\x1c"];
        yield 'a lazy quantifier after a comment it hides' => ['pattern' => "#\\c\\# c\n*?b#x", 'subject' => "\x1cb"];
        yield 'a counted quantifier after a comment it hides' => ['pattern' => "#\\c\\#c\n{2}#x", 'subject' => "\x1c\x1c"];
    }

    private function assertSameMatches(string $source, string $printed, string $printer): void
    {
        $this->assertNotFalse(@preg_match($printed, ''), \sprintf('%s printed (%s) as %s, which does not compile', $source, $printer, $printed));
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($source, $subject), preg_match($printed, $subject), \sprintf('%s printed (%s) as %s, on %s', $source, $printer, $printed, json_encode($subject)));
        }
    }
}
