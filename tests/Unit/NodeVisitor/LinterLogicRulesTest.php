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

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterLogicRulesTest extends TestCase
{
    #[DataProvider('provideEmptyAlternativePatterns')]
    public function test_empty_alternative_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.alternation.empty', $issues);
        } else {
            $this->assertNotContains('regex.lint.alternation.empty', $issues);
        }
    }

    #[DataProvider('provideDuplicateDisjunctionPatterns')]
    public function test_duplicate_disjunction_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.alternation.duplicateDisjunction', $issues);
        } else {
            $this->assertNotContains('regex.lint.alternation.duplicateDisjunction', $issues);
        }
    }

    #[DataProvider('provideOptimalQuantifierConcatenationPatterns')]
    public function test_optimal_quantifier_concatenation_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.quantifier.concatenation', $issues);
        } else {
            $this->assertNotContains('regex.lint.quantifier.concatenation', $issues);
        }
    }

    #[DataProvider('provideUselessBackreferencePatterns')]
    public function test_useless_backreference_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.backref.useless', $issues);
        } else {
            $this->assertNotContains('regex.lint.backref.useless', $issues);
        }
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideEmptyAlternativePatterns(): \Iterator
    {
        yield 'trailing empty' => ['/a|/', true];
        yield 'leading empty' => ['/|a/', true];
        yield 'double pipe' => ['/a||b/', true];
        yield 'explicit empty group' => ['/(?:)|a/', false];
        yield 'no alternation' => ['/a/', false];
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideDuplicateDisjunctionPatterns(): \Iterator
    {
        yield 'duplicate literal' => ['/a|a/', true];
        yield 'duplicate class' => ['/[ab]|[ab]/', true];
        yield 'duplicate non-capturing' => ['/(?:a)|(?:a)/', true];
        yield 'capturing groups skipped' => ['/(a)|(a)/', false];
        yield 'no duplicates' => ['/a|b/', false];
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideOptimalQuantifierConcatenationPatterns(): \Iterator
    {
        yield 'left subset right' => ['/\d+\w+/', true];
        yield 'right subset left' => ['/\w+\d+/', true];
        yield 'equal sets' => ['/a+a+/', true];
        yield 'bounded left' => ['/\w{3,5}\d*/', false];
        yield 'fixed right' => ['/\w+\d{4}/', false];
        yield 'capturing group' => ['/(a)+a+/', false];
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideUselessBackreferencePatterns(): \Iterator
    {
        yield 'forward reference' => ['/\1(a)/', true];
        yield 'nested backreference' => ['/(a\1)/', true];
        yield 'different alternative' => ['/(a)|\1/', true];
        yield 'empty group' => ['/(\\b)a\\1/', true];
        yield 'valid backreference' => ['/(a)\\1/', false];
        yield 'optional group' => ['/(a)?\\1/', false];
    }

    /**
     * @return array<string>
     */
    private function lint(string $pattern): array
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        return array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
    }
}
