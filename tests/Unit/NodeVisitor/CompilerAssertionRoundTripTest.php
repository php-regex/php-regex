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

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Non-atomic assertions and callout conditions, compiled back to a pattern.
 *
 * A non-atomic assertion lets PCRE backtrack into it, so writing it back as
 * the atomic "(?=" or "(?<=" changes what the pattern matches. Each pattern
 * below compiles on PCRE2 10.40 and 10.48, and at least one subject of each
 * non-atomic case matches differently once the assertion is made atomic.
 */
final class CompilerAssertionRoundTripTest extends TestCase
{
    #[Test]
    #[DataProvider('provideAssertions')]
    public function test_compile_writes_assertion_back_as_pcre_spells_it(string $pattern, string $compiled): void
    {
        $this->assertSame($compiled, $this->compile($pattern));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideAssertionsWithSubjects')]
    public function test_compile_keeps_what_the_pattern_matches(string $pattern, array $subjects): void
    {
        $compiled = $this->compile($pattern);

        foreach ($subjects as $subject) {
            $this->assertSame(
                $this->matchOf($pattern, $subject),
                $this->matchOf($compiled, $subject),
                \sprintf('"%s" compiled to "%s" matches "%s" differently.', $pattern, $compiled, $subject),
            );
        }
    }

    #[Test]
    #[DataProvider('providePrettyAssertions')]
    public function test_pretty_compile_keeps_the_assertion_kind(string $pattern, string $compiled): void
    {
        $pretty = Regex::create(['cache' => new NullCache()])
            ->parse($pattern)
            ->accept(new PatternPrinter(true));

        $this->assertSame($compiled, $pretty);
    }

    /**
     * @return iterable<string, array{pattern: string, compiled: string}>
     */
    public static function provideAssertions(): iterable
    {
        yield 'short non-atomic lookahead' => ['pattern' => '/(?*(a|ab))\\1c/', 'compiled' => '/(?*(a|ab))\\1c/'];
        yield 'named non-atomic lookahead' => ['pattern' => '/(*napla:(a|ab))\\1c/', 'compiled' => '/(?*(a|ab))\\1c/'];
        yield 'long named non-atomic lookahead' => ['pattern' => '/(*non_atomic_positive_lookahead:(a|ab))\\1c/', 'compiled' => '/(?*(a|ab))\\1c/'];
        yield 'short non-atomic lookbehind' => ['pattern' => '/(?<*(.)..|(.)...)(\\1|\\2)/', 'compiled' => '/(?<*(.)..|(.)...)(\\1|\\2)/'];
        yield 'named non-atomic lookbehind' => ['pattern' => '/(*naplb:(.)..|(.)...)(\\1|\\2)/', 'compiled' => '/(?<*(.)..|(.)...)(\\1|\\2)/'];
        yield 'long named non-atomic lookbehind' => ['pattern' => '/(*non_atomic_positive_lookbehind:(.)..|(.)...)(\\1|\\2)/', 'compiled' => '/(?<*(.)..|(.)...)(\\1|\\2)/'];
        yield 'callout before a lookahead condition' => ['pattern' => '/(?(?C25)(?=abc)abcd|xyz)/', 'compiled' => '/(?(?C25)(?=abc)abcd|xyz)/'];
        yield 'callout before a lookbehind condition' => ['pattern' => '/(?(?C1)(?<=a)b|c)/', 'compiled' => '/(?(?C1)(?<=a)b|c)/'];
        yield 'string callout before a negative lookahead condition' => ['pattern' => '/(?(?C"x")(?!a)b|a)/', 'compiled' => '/(?(?C"x")(?!a)b|a)/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideAssertionsWithSubjects(): iterable
    {
        // "abc": the atomic "(?=(a|ab))" keeps "a" and fails; "(?*" retries "ab".
        yield 'short non-atomic lookahead' => ['pattern' => '/(?*(a|ab))\\1c/', 'subjects' => ['abc', 'ababc', 'aac', 'xyz']];
        yield 'named non-atomic lookahead' => ['pattern' => '/(*napla:(a|ab))\\1c/', 'subjects' => ['abc', 'ababc', 'aac', 'xyz']];
        // "abcda": only a lookbehind PCRE backtracks into tries the second branch.
        yield 'short non-atomic lookbehind' => ['pattern' => '/(?<*(.)..|(.)...)(\\1|\\2)/', 'subjects' => ['abcda', 'abcdb', 'abcdc', 'abcab']];
        yield 'named non-atomic lookbehind' => ['pattern' => '/(*naplb:(.)..|(.)...)(\\1|\\2)/', 'subjects' => ['abcda', 'abcdb', 'abcdc', 'abcab']];
        yield 'callout before a lookahead condition' => ['pattern' => '/(?(?C25)(?=abc)abcd|xyz)/', 'subjects' => ['abcd', 'xyz', 'abxyz', 'abc']];
        yield 'callout before a lookbehind condition' => ['pattern' => '/(?(?C1)(?<=a)b|c)/', 'subjects' => ['ab', 'c', 'xb', 'ac']];
    }

    /**
     * @return iterable<string, array{pattern: string, compiled: string}>
     */
    public static function providePrettyAssertions(): iterable
    {
        yield 'non-atomic lookahead' => ['pattern' => '/(*napla:ab)/', 'compiled' => "/(?*\nab\n)/"];
        yield 'non-atomic lookbehind' => ['pattern' => '/(?<*ab)c/', 'compiled' => "/(?<*\nab\n)c/"];
        yield 'atomic lookahead' => ['pattern' => '/(?=ab)/', 'compiled' => "/(?=\nab\n)/"];
        yield 'atomic lookbehind' => ['pattern' => '/(?<=ab)c/', 'compiled' => "/(?<=\nab\n)c/"];
        yield 'callout before a lookahead condition' => ['pattern' => '/(?(?C25)(?=abc)abcd|xyz)/', 'compiled' => "/(?(?C25)(?=\nabc\n)\nabcd\n|xyz\n)/"];
    }

    private function compile(string $pattern): string
    {
        return Regex::create(['cache' => new NullCache()])
            ->parse($pattern)
            ->accept(new PatternPrinter());
    }

    /**
     * The match and its groups, or the error PCRE gave.
     *
     * @return array<int|string, string>|string
     */
    private function matchOf(string $pattern, string $subject): array|string
    {
        // The JIT refuses callouts and warns before falling back to the
        // interpreter; the match itself is unaffected.
        set_error_handler(static fn (): bool => true);
        $result = @preg_match($pattern, $subject, $matches);
        restore_error_handler();

        return false === $result ? 'error: '.preg_last_error_msg() : $matches;
    }
}
