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
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverlappingAlternationBudgetTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBranches')]
    public function test_pairwise_work_is_bounded_and_reported(string $suffix, string $prefix): void
    {
        $branches = array_map(static fn (int $i): string => $prefix.sprintf('%03d', $i).$suffix, range(1, 100));
        $pattern = '/(?:'.implode('|', $branches).')+/';
        $this->assertSame(0, preg_match($pattern, ''));
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $this->assertCount(1, self::issues($linter, 'regex.lint.alternation.incomplete'));

        // Reusing the linter starts a fresh budget and clears the diagnostic.
        Regex::create(['cache' => null])->parse('/(?:a|ab)+/')->accept($linter);
        $this->assertCount(0, self::issues($linter, 'regex.lint.alternation.incomplete'));
        $this->assertCount(1, self::issues($linter, OverlappingAlternationRule::ID_OVERLAP));
    }

    /**
     * @return iterable<string, array{suffix: string, prefix: string}>
     */
    public static function provideBranches(): iterable
    {
        yield 'literal pairs' => ['suffix' => '', 'prefix' => 'a'];
        yield 'semantic pairs' => ['suffix' => '[xy]', 'prefix' => 'a'];
        yield 'PCRE fallback pairs' => ['suffix' => '[xy]', 'prefix' => "\xE9"];
    }

    #[Test]
    public function test_alternations_share_one_pattern_budget(): void
    {
        $branches = array_map(static fn (int $i): string => sprintf('a%03d', $i), range(1, 40));
        $group = '(?:'.implode('|', $branches).')+';
        $linter = new PatternLinter();
        $regex = Regex::create(['cache' => null]);
        $regex->parse('/'.$group.'/')->accept($linter);
        $this->assertCount(0, self::issues($linter, 'regex.lint.alternation.incomplete'));
        $regex->parse('/'.str_repeat($group, 4).'/')->accept($linter);
        $this->assertCount(1, self::issues($linter, 'regex.lint.alternation.incomplete'));
    }

    /**
     * @return list<RuleViolation>
     */
    private static function issues(PatternLinter $linter, string $id): array
    {
        return array_values(array_filter($linter->getIssues(), static fn (RuleViolation $issue): bool => $id === $issue->id));
    }
}
