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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two or more literal spaces in a row are hard to count: " {n}" says how
 * many. A style rule, off by default; never under x or xx (the spaces are
 * not literal), never inside \Q...\E or a class; the tip keeps a quantifier
 * on the last space.
 */
final class MultipleSpacesRuleTest extends TestCase
{
    private const ID = 'regex.lint.literal.multipleSpaces';

    private const ENABLED = ['literal.multipleSpaces' => true];

    private const SUBJECTS = ['', 'a', 'a ', 'a  ', 'a   ', 'a     ', 'ab', 'a b', 'a  b', 'a   b', 'xa  b'];

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertNull($this->violation('/a  b/', []));
        // Off, not missing: the same pattern trips it once enabled.
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/a  b/', self::ENABLED));
    }

    #[Test]
    #[DataProvider('provideRunsOfSpaces')]
    public function test_a_run_of_literal_spaces_is_reported_with_its_count(string $pattern, string $rewrite, string $tip): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the tip's rewrite matches the same text.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $original), preg_match($rewrite, $subject, $rewritten), var_export($subject, true));
            $this->assertSame($original, $rewritten);
        }

        $violation = $this->violation($pattern, self::ENABLED);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Style, $violation->severity);
        $this->assertNotNull($violation->hint);
        $this->assertStringContainsString($tip, (string) $violation->hint);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, tip: string}>
     */
    public static function provideRunsOfSpaces(): iterable
    {
        yield 'two spaces' => ['pattern' => '/a  b/', 'rewrite' => '/a {2}b/', 'tip' => ' {2}'];
        yield 'three spaces' => ['pattern' => '/a   b/', 'rewrite' => '/a {3}b/', 'tip' => ' {3}'];
        yield 'quantifier on the last space' => ['pattern' => '/a  +/', 'rewrite' => '/a {2,}/', 'tip' => ' {2,}'];
        yield 'exact count on the last space' => ['pattern' => '/a  {2}/', 'rewrite' => '/a {3}/', 'tip' => ' {3}'];
        yield 'bounded range on the last space' => ['pattern' => '/a  {1,3}/', 'rewrite' => '/a {2,4}/', 'tip' => ' {2,4}'];
        yield 'lazy range on the last space' => ['pattern' => '/a  {1,3}?b/', 'rewrite' => '/a {2,4}?b/', 'tip' => ' {2,4}?'];
        yield 'after x is turned off' => ['pattern' => '/(?x)a(?-x)  b/', 'rewrite' => '/a {2}b/', 'tip' => ' {2}'];
    }

    #[Test]
    #[DataProvider('provideSpacesThatAreNotARun')]
    public function test_spaces_that_are_not_a_literal_run_are_not_reported(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $this->assertNull($this->violation($pattern, self::ENABLED));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSpacesThatAreNotARun(): iterable
    {
        yield 'one space' => ['pattern' => '/a b/'];
        yield 'extended mode' => ['pattern' => '/a  b/x'];
        yield 'extra extended mode' => ['pattern' => '/a  b/xx'];
        yield 'inline extended mode' => ['pattern' => '/(?x)a  b/'];
        yield 'quoted' => ['pattern' => '/\Qa  b\E/'];
        yield 'inside a class' => ['pattern' => '/a[  ]b/'];
    }

    #[Test]
    public function test_the_engine_skips_the_spaces_under_x_and_keeps_them_quoted(): void
    {
        $this->assertSame(1, preg_match('/^a  b$/x', 'ab'));
        $this->assertSame(1, preg_match('/^\Qa  b\E$/x', 'a  b'));
        $this->assertSame(1, preg_match('/^(?x)a(?-x)  b$/', 'a  b'));
    }

    /**
     * @param array<string, bool> $rules
     */
    private function violation(string $pattern, array $rules): ?RuleViolation
    {
        $linter = new PatternLinter($rules);
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
