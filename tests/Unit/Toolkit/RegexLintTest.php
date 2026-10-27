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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * lint() runs the lint rules on one pattern, read for the facade's target:
 * the rules alone, without validation, ReDoS, optimization, ignores or
 * baseline. "a{,2}" repeats from PCRE2 10.43 and is text before, so the
 * lookahead of "(?=\{)a{,2}\{" can never hold at 10.42 and can at 10.43.
 */
final class RegexLintTest extends TestCase
{
    private const TARGET_DEPENDENT = '/(?=\{)a{,2}\{/';

    #[Test]
    public function test_lint_reports_what_the_pattern_linter_reports(): void
    {
        $regex = Regex::create(['cache' => null]);
        $linter = new PatternLinter();
        $regex->parse('/(?=a)b/')->accept($linter);

        $violations = $regex->lint('/(?=a)b/');

        $this->assertSame(['regex.lint.lookaround.impossible'], self::ids($violations));
        $this->assertEquals($linter->getIssues(), $violations);
    }

    #[Test]
    public function test_lint_reports_nothing_on_a_clean_pattern(): void
    {
        $this->assertSame([], Regex::create(['cache' => null])->lint('/^[a-z]+$/'));
    }

    /**
     * @param list<string> $ids
     */
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_lint_reads_the_pattern_for_the_facades_target(string $pcreVersion, array $ids): void
    {
        $this->assertSame(1, preg_match(self::TARGET_DEPENDENT, '{'), 'Oracle: the running PCRE2 repeats "a{,2}".');

        $regex = Regex::create(['cache' => null, 'pcre_version' => $pcreVersion]);

        $this->assertSame($ids, self::ids($regex->lint(self::TARGET_DEPENDENT)));
        $this->assertSame($ids, self::ids($regex->analyze(self::TARGET_DEPENDENT)->lintIssues), 'analyze() lints as lint() does.');
    }

    /**
     * @return iterable<string, array{pcreVersion: string, ids: list<string>}>
     */
    public static function provideTargets(): iterable
    {
        yield 'text before 10.43' => ['pcreVersion' => '10.42', 'ids' => ['regex.lint.lookaround.impossible']];
        yield 'a quantifier from 10.43' => ['pcreVersion' => '10.43', 'ids' => []];
    }

    #[Test]
    public function test_lint_throws_on_an_invalid_pattern_as_parse_does(): void
    {
        $regex = Regex::create(['cache' => null]);

        try {
            $regex->parse('/a(/');
            $this->fail('The pattern must not parse.');
        } catch (ParserException $expected) {
        }

        try {
            $regex->lint('/a(/');
            $this->fail('lint() must throw on a pattern parse() refuses.');
        } catch (ParserException $actual) {
            $this->assertSame($expected::class, $actual::class);
            $this->assertSame($expected->getMessage(), $actual->getMessage());
        }
    }

    #[Test]
    public function test_the_pattern_linter_reads_the_questions_for_its_target(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.42']);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse(self::TARGET_DEPENDENT)->accept($linter);

        $this->assertSame(['regex.lint.lookaround.impossible'], self::ids($linter->getIssues()));
    }

    /**
     * @param list<RuleViolation> $violations
     *
     * @return list<string>
     */
    private static function ids(array $violations): array
    {
        return array_map(static fn (RuleViolation $violation): string => $violation->id, $violations);
    }
}
