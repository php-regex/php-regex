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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What follows an end anchor is impossible only when every way through it
 * is known to fail: an assertion, a lookaround, a reference, a call or a
 * character set the rule cannot read may let the match go on, and the rule
 * then says nothing.
 */
final class EndAnchorZeroWidthTailTest extends TestCase
{
    use PcreGates;

    /**
     * The rows that need a release past the floor: extended classes come
     * with PCRE2 10.45.
     */
    private const RELEASES = ['10.45' => ['/a$(?[ \p{Cc} ])/u', '/a$(?[ \p{L} ])/u']];

    #[Test]
    #[DataProvider('provideTailsTheEngineMatches')]
    public function test_a_tail_the_engine_matches_is_not_reported(string $pattern, string $subject): void
    {
        $this->requiresPcre2For($pattern, self::RELEASES);

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern matches.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $this->assertNotContains('regex.lint.anchor.impossible.end', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideTailsTheEngineMatches(): iterable
    {
        yield 'word boundary before the newline' => ['pattern' => '/a$\b\n/', 'subject' => "a\n"];
        yield 'word boundary before the newline under m' => ['pattern' => '/a$\b\n/m', 'subject' => "a\n"];
        yield 'lookahead for the newline before it' => ['pattern' => '/a$(?=\n)\n/', 'subject' => "a\n"];
        yield 'lookahead for the newline before it under m' => ['pattern' => '/a$(?=\n)\n/m', 'subject' => "a\n"];
        yield 'negative lookahead before the newline' => ['pattern' => '/a$(?!x)\n/', 'subject' => "a\n"];
        yield 'negative lookahead before the newline under m' => ['pattern' => '/a$(?!x)\n/m', 'subject' => "a\n"];
        yield 'lookbehind before the newline' => ['pattern' => '/a$(?<=a)\n/', 'subject' => "a\n"];
        yield 'lookbehind before the newline under m' => ['pattern' => '/a$(?<=a)\n/m', 'subject' => "a\n"];
        yield 'lookbehind after the newline' => ['pattern' => '/a$\n(?<=\n)/', 'subject' => "a\n"];
        yield 'lookbehind for the letter and the newline after it' => ['pattern' => '/a$\n(?<=a\n)/', 'subject' => "a\n"];
        yield 'keep after capital Z' => ['pattern' => '/a\Z\K\n/', 'subject' => "a\n"];
        yield 'grapheme cluster' => ['pattern' => '/a$\X/', 'subject' => "a\n"];
        yield 'grapheme cluster under u' => ['pattern' => '/a$\X/u', 'subject' => "a\n"];
        yield 'grapheme cluster under m' => ['pattern' => '/a$\X/m', 'subject' => "a\n"];
        yield 'single code unit' => ['pattern' => '/a$\C/', 'subject' => "a\n"];
        yield 'property holding the newline' => ['pattern' => '/a$\p{Cc}/', 'subject' => "a\n"];
        yield 'property holding the newline under u' => ['pattern' => '/a$\p{Cc}/u', 'subject' => "a\n"];
        yield 'class of every ASCII character under u' => ['pattern' => '/a$[\x{0}-\x{7f}]/u', 'subject' => "a\n"];
        yield 'class of everything but letters under u' => ['pattern' => '/a$[^\pL]/u', 'subject' => "a\n"];
        yield 'newline or nothing twice' => ['pattern' => '/a$(?:\n|){2}/', 'subject' => "a\n"];
        yield 'non-boundary after the newline' => ['pattern' => '/a$\n\B/', 'subject' => "a\n"];
        yield 'non-boundary after the enclosing group' => ['pattern' => '/(?:a\Z\n)\B/', 'subject' => "a\n"];
        yield 'negative lookahead after the enclosing group' => ['pattern' => '/(?:a$\n)(?!x)/', 'subject' => "a\n"];
        yield 'reference to an empty group' => ['pattern' => '/()a$\1/', 'subject' => 'a'];
        yield 'reference to an empty group after small z' => ['pattern' => '/()a\z\1/', 'subject' => 'a'];
        yield 'call of an empty group' => ['pattern' => '/a$(?1)()/', 'subject' => 'a'];
        yield 'call of an optional newline' => ['pattern' => '/(\n)?a$(?1)/', 'subject' => "a\n"];
        yield 'accept before a letter' => ['pattern' => '/a$(*ACCEPT)b/', 'subject' => 'a'];
        yield 'accept after the newline before a letter' => ['pattern' => '/a$\n(*ACCEPT)b/', 'subject' => "a\n"];
        yield 'accept in a group before a letter' => ['pattern' => '/a$(?:(*ACCEPT))b/', 'subject' => 'a'];
        yield 'circumflex before the newline at the start' => ['pattern' => '/$^\n/', 'subject' => "\n"];
        yield 'subject start before the newline at the start' => ['pattern' => '/\Z\A\n/', 'subject' => "\n"];
        yield 'match start before the newline at the start' => ['pattern' => '/$\G\n/', 'subject' => "\n"];
        yield 'circumflex after a newline before another under m' => ['pattern' => '/\n$^\n/m', 'subject' => "\n\n"];
        yield 'circumflex in the group a reference reads' => ['pattern' => '/(^)a\z\1/', 'subject' => 'a'];
        yield 'conditional with an empty yes branch after small z' => ['pattern' => '/(a)b\z(?(1)|c)/', 'subject' => 'ab'];
        yield 'conditional with an empty no branch after small z' => ['pattern' => '/(a)?b\z(?(1)c|)/', 'subject' => 'b'];
        yield 'accept before a letter after small z' => ['pattern' => '/a\z(*ACCEPT)b/', 'subject' => 'a'];
        yield 'extended class holding the newline under u' => ['pattern' => '/a$(?[ \p{Cc} ])/u', 'subject' => "a\n"];
        yield 'capital Z before the newline' => ['pattern' => '/a$\Z\n/', 'subject' => "a\n"];
        yield 'reference to an empty group in the yes branch after small z' => ['pattern' => '/()a\z(?(1)\1|c)/', 'subject' => 'a'];
        yield 'reference to an empty group in the no branch after small z' => ['pattern' => '/()a\z(?(?=x)c|\1)/', 'subject' => 'a'];
        yield 'reference to a group of a non-boundary after small z' => ['pattern' => '/(\B)a\z\1/', 'subject' => 'ba'];
        yield 'script run that may match nothing after the newline' => ['pattern' => '/a$\n(*sr:x?)/', 'subject' => "a\n"];
        yield 'non-boundary after a non-word character' => ['pattern' => '/-$\B\n/', 'subject' => "-\n"];
        yield 'word boundary after a letter above ASCII under UCP' => ['pattern' => '/(*UCP)\xaa$\b\n/', 'subject' => "\xaa\n"];
        yield 'word boundary after the last letter of Latin-1 under UCP' => ['pattern' => '/(*UCP)\xff$\b\n/', 'subject' => "\xff\n"];
    }

    #[Test]
    #[DataProvider('provideTailsTheEngineNeverMatches')]
    public function test_a_tail_known_to_fail_is_still_reported(string $pattern): void
    {
        $this->requiresPcre2For($pattern, self::RELEASES);

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern never matches.
        foreach (['', 'a', "\n", "a\n", "a\n\n", "aa\n", "a\nx", 'ax', "a\r\n", "\n\n", "a\x00", 'ab', "a\n1", "a\nb", "-\n"] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), $pattern.' '.json_encode($subject));
        }

        $this->assertContains('regex.lint.anchor.impossible.end', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTailsTheEngineNeverMatches(): iterable
    {
        yield 'digit under u' => ['pattern' => '/a$\d/u'];
        yield 'word character under u' => ['pattern' => '/a$\w/u'];
        yield 'letter property under u' => ['pattern' => '/a$\pL/u'];
        yield 'class of a letter property under u' => ['pattern' => '/a$[\pL]/u'];
        yield 'subject start after the newline' => ['pattern' => '/a$\n\A/'];
        yield 'match start after the newline' => ['pattern' => '/a$\n\G/'];
        yield 'circumflex after the final newline under m' => ['pattern' => '/a\Z\n^/m'];
        yield 'word boundary after the enclosing group' => ['pattern' => '/(?:a$\n)\b/'];
        yield 'lookahead for a letter after the enclosing group' => ['pattern' => '/(?:a$\n)(?=x)/'];
        yield 'lookahead for a letter before the newline' => ['pattern' => '/a$(?=x)\n/'];
        yield 'lookahead for a letter before the newline under m' => ['pattern' => '/a$(?=x)\n/m'];
        yield 'empty negative lookahead before the newline' => ['pattern' => '/a$(?!)\n/'];
        yield 'empty negative lookahead after the newline' => ['pattern' => '/a$\n(?!)/'];
        yield 'reference to a letter' => ['pattern' => '/(a)a$\1/'];
        yield 'reference to a letter under m' => ['pattern' => '/(a)$\1\n/m'];
        yield 'reference to an empty group then a letter under m' => ['pattern' => '/()a$\1x/m'];
        yield 'call then a letter after small z' => ['pattern' => '/a\z(?1)(b)/'];
        yield 'call of a letter' => ['pattern' => '/(a)a$(?1)/'];
        yield 'call of a letter by name' => ['pattern' => '/(?<n>a)a$\g<n>/'];
        yield 'lookbehind for a letter after the newline' => ['pattern' => '/a$\n(?<=a)/'];
        yield 'two newlines' => ['pattern' => '/a$(?:\n){2}/'];
        yield 'extended class of letters under u' => ['pattern' => '/a$(?[ \p{L} ])/u'];
        yield 'script run of a letter after the newline' => ['pattern' => '/a$\n(*sr:x)/'];
        yield 'group of optional letters then a letter under m' => ['pattern' => '/a$(?:b?c?)d/m'];
        yield 'small z before the newline' => ['pattern' => '/a$\z\n/'];
        yield 'non-boundary after a letter before the newline' => ['pattern' => '/a$\B\n/'];
        yield 'word boundary after a non-word character before the newline' => ['pattern' => '/-$\b\n/'];
        yield 'conditional of two letters after small z' => ['pattern' => '/a\z(?(?=x)b|c)/'];
        yield 'circumflex after the final newline under m, nothing before' => ['pattern' => '/\Z\n^/m'];
        yield 'circumflex at the end after a newline' => ['pattern' => '/()\n\z^\1/'];
        yield 'subject start after the newline, nothing before' => ['pattern' => '/$\n\A/'];
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
    }
}
