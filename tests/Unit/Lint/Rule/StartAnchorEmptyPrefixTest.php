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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What is written before a start anchor makes it impossible only when it
 * must read a character: a reference to a group that captured nothing, or
 * a call of an empty group, reads none, and an "(*ACCEPT)" ends the match
 * before the anchor.
 */
final class StartAnchorEmptyPrefixTest extends TestCase
{
    #[Test]
    #[DataProvider('providePrefixesTheEngineMatchesThrough')]
    public function test_a_prefix_the_engine_matches_through_is_not_reported(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern matches.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);

        $this->assertNotContains('regex.lint.anchor.impossible.start', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePrefixesTheEngineMatchesThrough(): iterable
    {
        yield 'reference to an empty group' => ['pattern' => '/()\1^a/', 'subject' => 'a'];
        yield 'reference to a group that may be empty' => ['pattern' => '/(a?)\1^a/', 'subject' => 'a'];
        yield 'reference by name to an empty group before \A' => ['pattern' => '/(?<n>)\k<n>\Aa/', 'subject' => 'a'];
        yield 'reference to an empty group before \G' => ['pattern' => '/()\1\Ga/', 'subject' => 'a'];
        yield 'call of an empty group' => ['pattern' => '/()(?1)^a/', 'subject' => 'a'];
        yield 'call of an empty group written after' => ['pattern' => '/(?1)()^a/', 'subject' => 'a'];
        yield 'conditional holding a reference to an empty group' => ['pattern' => '/()(?(1)\1|b)^a/', 'subject' => 'a'];
        yield 'accept after a letter' => ['pattern' => '/a(*ACCEPT)^b/', 'subject' => 'a'];
        yield 'accept in a group after a letter' => ['pattern' => '/a(?:(*ACCEPT))^b/', 'subject' => 'a'];
    }

    #[Test]
    #[DataProvider('providePrefixesThatMustRead')]
    public function test_a_prefix_that_must_read_a_character_is_still_reported(string $pattern): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern never matches.
        foreach (['', 'a', 'b', 'aa', 'ab', 'ba', 'bc', 'abc', "a\nb"] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), $pattern.' '.json_encode($subject));
        }

        $this->assertContains('regex.lint.anchor.impossible.start', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePrefixesThatMustRead(): iterable
    {
        yield 'reference to a letter' => ['pattern' => '/(a)\1^a/'];
        yield 'reference to an optional letter' => ['pattern' => '/(a)?\1^a/'];
        yield 'call of a letter' => ['pattern' => '/(a)(?1)^a/'];
        yield 'conditional whose branches read' => ['pattern' => '/(a)(?(1)\1|b)^a/'];
        yield 'keep after a letter' => ['pattern' => '/a\K^b/'];
        yield 'lookahead after a letter' => ['pattern' => '/a(?=b)^b/'];
        yield 'commit after a letter' => ['pattern' => '/a(*COMMIT)^b/'];
        yield 'alternation of letters' => ['pattern' => '/(?:a|b)^c/'];
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
