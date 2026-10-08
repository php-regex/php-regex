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
 * The rule tightens a run whose set is a subset of the next one. The sets
 * it reads stop at 0x7F and fold no case: a run that may take a character
 * above ASCII, or a letter under i, is never known to be a subset.
 */
final class ConcatenationSubsetTest extends TestCase
{
    private const RULE = 'regex.lint.quantifier.concatenation';

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern matches the subject,
     * the rewrite the hint asks for does not.
     */
    #[Test]
    #[DataProvider('provideSubsetsTheSetsCannotTell')]
    public function test_a_run_the_sets_cannot_place_is_not_reported(string $pattern, string $rewrite, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match($rewrite, $subject));

        $this->assertNotContains(self::RULE, $this->ids($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, subject: string}>
     */
    public static function provideSubsetsTheSetsCannotTell(): iterable
    {
        yield 'multibyte class after a letter under u' => ['pattern' => '/^b+[é]+\z/u', 'rewrite' => '/^b+[é]\z/u', 'subject' => 'béé'];
        yield 'horizontal space before space and tab' => ['pattern' => '/^\h{1,3}[\t ]+\z/', 'rewrite' => '/^\h[\t ]+\z/', 'subject' => "\xA0\xA0 "];
        yield 'letter under i before a negated class' => ['pattern' => '/^A?[^a]*\z/i', 'rewrite' => '/^[^a]*\z/i', 'subject' => 'a'];
        yield 'letter under i before a group holding a negated class' => ['pattern' => '/^A?(?:[^a])*\z/i', 'rewrite' => '/^(?:[^a])*\z/i', 'subject' => 'a'];
    }

    #[Test]
    #[DataProvider('provideKnownSubsets')]
    public function test_a_known_subset_is_still_reported(string $pattern): void
    {
        $this->assertContains(self::RULE, $this->ids($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideKnownSubsets(): iterable
    {
        yield 'digits before a digit class' => ['pattern' => '/^\d+[0-9]+\z/'];
        yield 'letter before a letter range' => ['pattern' => '/^b+[a-c]+\z/'];
        yield 'digits before a digit class under i' => ['pattern' => '/^\d+[0-9]+\z/i'];
        // A dot or a negated ASCII class takes every character above ASCII.
        yield 'dot before a dot' => ['pattern' => '/.*.*x/'];
        yield 'multibyte class before a dot under u' => ['pattern' => '/^[é]+.+\z/u'];
        yield 'horizontal space before a negated ASCII class' => ['pattern' => '/^\h+[^x]+\z/'];
        yield 'letters before a dot under i' => ['pattern' => '/^[a-c]+.+\z/i'];
        yield 'letter before a letter range under i' => ['pattern' => '/^b+[a-c]+\z/i'];
        yield 'letter before a negated class of no letter under i' => ['pattern' => '/^b+[^0-9]+\z/i'];
        yield 'negated class before non-word characters' => ['pattern' => '/^[^A-Za-z0-9_]+\W+\z/'];
    }

    /**
     * @return list<string>
     */
    private function ids(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
    }
}
