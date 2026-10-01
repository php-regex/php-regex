<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Linter\PatternLinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterPortedRulesTest extends TestCase
{
    #[DataProvider('provideUselessRangePatterns')]
    public function test_useless_range_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.range.useless', $issues);
        } else {
            $this->assertNotContains('regex.lint.range.useless', $issues);
        }
    }

    #[DataProvider('provideDuplicateCharClassPatterns')]
    public function test_duplicate_char_class_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.charclass.duplicateChars', $issues);
        } else {
            $this->assertNotContains('regex.lint.charclass.duplicateChars', $issues);
        }
    }

    #[DataProvider('provideZeroQuantifierPatterns')]
    public function test_zero_quantifier_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.quantifier.zero', $issues);
        } else {
            $this->assertNotContains('regex.lint.quantifier.zero', $issues);
        }
    }

    #[DataProvider('provideUselessQuantifierPatterns')]
    public function test_useless_quantifier_rule(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);

        if ($shouldWarn) {
            $this->assertContains('regex.lint.quantifier.useless', $issues);
        } else {
            $this->assertNotContains('regex.lint.quantifier.useless', $issues);
        }
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideUselessRangePatterns(): \Iterator
    {
        yield 'single char range' => ['/[\x61-\x61]/', true];
        yield 'adjacent range' => ['/[\x61-\x62]/', true];
        yield 'three chars range' => ['/[\x61-\x63]/', false];
        yield 'digit range' => ['/[\x30-\x39]/', false];
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideDuplicateCharClassPatterns(): \Iterator
    {
        yield 'digit class with range' => ['/[\d0-9]/', true];
        yield 'range with digit class' => ['/[0-9\d]/', true];
        yield 'word class with range' => ['/[\wA-Z]/', true];
        yield 'escaped literal duplicate' => ['/[\x41A]/', true];
        yield 'basic duplicate is handled elsewhere' => ['/[aa]/', false];
        yield 'no duplicates' => ['/[A-Z0-9_]/', false];
        yield 'unicode digit class is skipped' => ['/[\d0-9]/u', false];
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideZeroQuantifierPatterns(): \Iterator
    {
        yield 'zero quantifier' => ['/a{0}/', true];
        yield 'zero range quantifier' => ['/(?:a){0,0}/', true];
        yield 'zero quantifier in class' => ['/\\d{0}/', true];
        yield 'optional quantifier' => ['/a{0,1}/', false];
        yield 'unbounded quantifier' => ['/a{0,}/', false];
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function provideUselessQuantifierPatterns(): \Iterator
    {
        yield 'constant one quantifier' => ['/a{1}/', true];
        yield 'explicit one range quantifier' => ['/(?:a){1,1}/', true];
        yield 'literal quantifier' => ['/a{2}/', false];
        yield 'plus quantifier' => ['/a+/', false];
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
