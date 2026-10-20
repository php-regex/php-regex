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
use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every printer writes back a pattern PHP compiles and that matches what
 * the pattern it was given matches. Each row is a pattern some printer
 * once wrote back as another pattern, or as one PHP refused, with subjects
 * that tell the two apart; each is checked against preg_match_all, here
 * with PHP 8.4.26 and PCRE2 10.49.
 */
final class PatternPrinterRoundTripTest extends TestCase
{
    use PcreGates;

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideClassBrackets')]
    public function test_a_class_bracket_next_to_a_range_prints_back_the_same_pattern(string $pattern, array $subjects): void
    {
        $this->assertEveryPrinterKeepsThePattern($pattern, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideClassBrackets(): iterable
    {
        // Unescaped before ":", "." or "=", a "[" opens a POSIX class,
        // whatever the member after it: a range starting with one too.
        yield 'a quoted "[" before a range from ":"' => ['pattern' => '~[\Q[\E:-;:]~', 'subjects' => ['[', ':', ';', 'a']];
        yield 'a quoted "[" before a range from "."' => ['pattern' => '~[\Q[\E.-/.]~', 'subjects' => ['[', '.', '/', 'a']];
        yield 'a quoted "[" before a range from "="' => ['pattern' => '%[\Q[\E=->=]%', 'subjects' => ['[', '=', '>', 'a']];
        yield 'an escaped "[" before a range from ":", under a bracket delimiter' => ['pattern' => '([\[:-;:])', 'subjects' => ['[', ':', ';', 'a']];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideExtendedClassOperations')]
    public function test_an_extended_class_prints_back_the_same_pattern(string $pattern, array $subjects): void
    {
        $this->assertEveryPrinterKeepsThePattern($pattern, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideExtendedClassOperations(): iterable
    {
        // PCRE2 refuses parentheses nested past a depth in "(?[...])": an
        // operation in parentheses where its precedence needs none counts.
        $nested = str_repeat('\n+[a]&\n+(', 14).'\n&\n'.str_repeat(')', 14);
        yield 'operations nested as deep as PCRE2 reads them' => ['pattern' => '/(?['.$nested.'])/', 'subjects' => ['a', "\n", 'b']];
        // "&" binds tighter than "+", "|", "-" and "^", which bind left to
        // right; "!" binds tightest.
        yield 'an intersection after a union' => ['pattern' => '/(?[[a]+[b]&[c]])/', 'subjects' => ['a', 'b', 'c']];
        yield 'a union in parentheses before an intersection' => ['pattern' => '/(?[([a]+[b])&[b]])/', 'subjects' => ['a', 'b', 'c']];
        yield 'a difference of a difference' => ['pattern' => '/(?[[ab]-[b]-[a]])/', 'subjects' => ['a', 'b']];
        yield 'a difference from a difference in parentheses' => ['pattern' => '/(?[[ab]-([b]-[a])])/', 'subjects' => ['a', 'b']];
        yield 'a complement of an intersection' => ['pattern' => '/(?[!([a]&[ab])&[ab]])/', 'subjects' => ['a', 'b', 'c']];
        yield 'a complement before an intersection' => ['pattern' => '/(?[![a]&[ab]])/', 'subjects' => ['a', 'b', 'c']];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuotedBrackets')]
    public function test_a_quoted_bracket_of_the_delimiter_prints_back_the_same_pattern(string $pattern, array $subjects): void
    {
        $this->assertEveryPrinterKeepsThePattern($pattern, $subjects);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideQuotedBrackets(): iterable
    {
        // PHP counts the "{" of "\Q{\E": spelled "\{" it would not, and
        // the "}" after it would end the pattern.
        yield 'a quoted brace before a count' => ['pattern' => '{^a\Q{\E2}$}', 'subjects' => ['a{2}', 'aa', 'a{2', '{2}']];
        yield 'a quoted brace before a closing one' => ['pattern' => '{\Q{\E}}', 'subjects' => ['{}', '{', '}']];
        yield 'a quoted run holding a brace and a line break' => ['pattern' => "{\\Q{a\nb\\E}}", 'subjects' => ["{a\nb}", "{a\nb", 'ab}']];
        yield 'a quoted angle bracket before a closing one' => ['pattern' => '<a\Q<\E>b>', 'subjects' => ['a<>b', 'a<b', 'ab']];
    }

    /**
     * @param list<string> $subjects
     */
    private function assertEveryPrinterKeepsThePattern(string $pattern, array $subjects): void
    {
        $this->requiresCompile($pattern);
        $regex = RegexParser::create(['cache' => null])->parse($pattern);

        $printers = [
            'as written' => PatternPrinter::asWritten(),
            'normalized' => PatternPrinter::normalized(),
            'with its comments collapsed' => new PatternPrinter(false, true),
            'pretty' => PatternPrinter::pretty(),
            'pretty with its comments collapsed' => new PatternPrinter(true, true),
            'formatted at 20' => PatternPrinter::forFormatting(20),
            'formatted at 40' => PatternPrinter::forFormatting(40),
            'formatted at 80' => PatternPrinter::forFormatting(80),
            'compacted' => PatternPrinter::forCompacting(),
        ];

        foreach ($printers as $name => $printer) {
            $printed = $regex->accept($printer);
            $this->assertNotFalse(@preg_match($printed, ''), \sprintf('Printed %s, %s gives %s, which PHP refuses.', $name, $pattern, $printed));
            foreach ($subjects as $subject) {
                $this->assertSame(
                    PatternEquivalence::engineRun($pattern, $subject),
                    PatternEquivalence::engineRun($printed, $subject),
                    \sprintf('Printed %s, %s gives %s, which matches %s otherwise.', $name, $pattern, $printed, json_encode($subject)),
                );
            }
        }

        // The formatter reads its output back, and throws where it is
        // another pattern.
        $formatter = new PatternFormatter(20);
        $this->assertNotSame('', $formatter->format($regex)->pattern);
        $this->assertNotSame('', $formatter->compact($regex)->pattern);
    }
}
