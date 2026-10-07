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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The search cost in PHPStan: identifier regex.redos.search, its own message,
 * under the existing ReDoS setting and threshold (medium, so the default
 * critical threshold hides it). The three frozen regex.redos messages do not
 * move (RegexParserRuleProvenRedosTest).
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleSearchCostTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/SearchCostFixture.php';

    private const SEARCH = RegexPatternRule::IDENTIFIER_REDOS_SEARCH;

    /**
     * @var array<string, mixed>
     */
    private array $redos = ['enabled' => true, 'threshold' => 'medium'];

    /**
     * Frozen like the three other ReDoS identifiers: baselines name it.
     */
    #[Test]
    public function test_search_cost_identifier_is_a_public_constant(): void
    {
        $this->assertSame('regex.redos.search', RegexPatternRule::IDENTIFIER_REDOS_SEARCH);
    }

    #[Test]
    public function test_search_cost_error_has_its_own_identifier_and_message(): void
    {
        $this->assertSame([
            [20, 'Quadratic search (ReDoS): /\s+$/', self::SEARCH],
        ], self::described($this->errorsWithIdentifier(self::SEARCH)));
    }

    /**
     * The exponential pattern keeps its frozen message and identifier, and
     * gets no search error beside it.
     */
    #[Test]
    public function test_search_cost_leaves_the_frozen_redos_errors_alone(): void
    {
        $this->assertSame([
            [22, 'Exponential backtracking (ReDoS): /(a+)+$/', RegexPatternRule::IDENTIFIER_REDOS],
        ], self::described($this->errorsWithIdentifier(RegexPatternRule::IDENTIFIER_REDOS)));
    }

    /**
     * The lint issue id must not leak through the lint-rule path.
     */
    #[Test]
    public function test_search_cost_is_not_reported_under_the_lint_issue_id(): void
    {
        $this->assertCount(1, $this->errorsWithIdentifier(self::SEARCH), 'control: the search error is there');

        $this->assertSame([], $this->errorsWithIdentifier('regex.lint.redos.search'));
    }

    #[Test]
    public function test_search_cost_tip_says_one_attempt_is_linear_and_the_search_quadratic(): void
    {
        $errors = $this->errorsWithIdentifier(self::SEARCH);
        $this->assertCount(1, $errors);

        $tip = (string) $errors[0]->getTip();
        $this->assertStringContainsString("one attempt is linear (proven); an unanchored search is quadratic in PCRE2's interpreter", $tip);
        $this->assertStringContainsString('the JIT may avoid it for some patterns', $tip);
    }

    /**
     * @param array<string, mixed> $redos
     */
    #[Test]
    #[DataProvider('provideSettingsHidingTheSearchCost')]
    public function test_search_cost_follows_the_redos_setting_and_threshold(array $redos): void
    {
        $this->assertCount(1, (new self('control'))->errorsWithIdentifier(self::SEARCH), 'control: reported at medium');

        $this->redos = $redos;

        $this->assertSame([], $this->errorsWithIdentifier(self::SEARCH));
    }

    /**
     * @return iterable<string, array{redos: array<string, mixed>}>
     */
    public static function provideSettingsHidingTheSearchCost(): iterable
    {
        yield 'default threshold, critical' => ['redos' => ['enabled' => true]];
        yield 'high threshold' => ['redos' => ['enabled' => true, 'threshold' => 'high']];
        yield 'ReDoS check off' => ['redos' => ['enabled' => false, 'threshold' => 'medium']];
    }

    #[Test]
    public function test_search_cost_is_reported_at_the_low_threshold(): void
    {
        $this->redos = ['enabled' => true, 'threshold' => 'low'];

        $this->assertSame([20], array_map(static fn (Error $error): ?int => $error->getLine(), $this->errorsWithIdentifier(self::SEARCH)));
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => false],
                'redos' => $this->redos,
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }

    /**
     * @return list<Error>
     */
    private function errorsWithIdentifier(string $identifier): array
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([self::FIXTURE]),
            static fn (Error $error): bool => $identifier === $error->getIdentifier(),
        ));
        usort($errors, static fn (Error $a, Error $b): int => $a->getLine() <=> $b->getLine());

        return $errors;
    }

    /**
     * @param list<Error> $errors
     *
     * @return list<array{int|null, string, string|null}>
     */
    private static function described(array $errors): array
    {
        return array_map(
            static fn (Error $error): array => [$error->getLine(), $error->getMessage(), $error->getIdentifier()],
            $errors,
        );
    }
}
