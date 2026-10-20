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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\PatternChecker;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * "checks.complexity" of the "phpRegex" parameter: a pattern whose
 * complexity score reaches the threshold is reported as
 * regex.lint.complexity, with a link to the reference, and only while the
 * check is on.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleComplexityTest extends RuleTestCase
{
    use PhpUnitForwardCompat;

    private const FIXTURE = __DIR__.'/Fixtures/ComplexityFixture.php';

    private const TIP = 'Read more: https://php-regex.com/reference/rules/#complexity-threshold';

    /**
     * @var array{lint?: array{enabled?: bool}, redos?: array{enabled?: bool, threshold?: string}, complexity?: array{enabled?: bool, threshold?: int}}
     */
    private array $checks = [];

    #[Test]
    public function test_the_fixture_scores_what_the_cases_assume(): void
    {
        $parser = RegexParser::create();

        $this->assertSame(56, $parser->validate('/^(ab|cd)(ef|gh)(ij|kl)(mn|op)(qr|st)(uv|wx)(yz|AB)(CD|EF)(GH|IJ)$/')->complexityScore);
        $this->assertSame(16, $parser->validate('/^(a|b|c)(d|e|f)(g|h)$/')->complexityScore);
    }

    #[Test]
    public function test_complexity_is_reported_from_the_threshold_with_a_link(): void
    {
        $this->checks = ['complexity' => ['enabled' => true, 'threshold' => 16]];

        $this->analyse([self::FIXTURE], [
            ['Pattern is complex (score: 56).', 20, self::TIP],
            ['Pattern is complex (score: 16).', 21, self::TIP],
        ]);
    }

    #[Test]
    public function test_the_identifier_is_the_lint_id(): void
    {
        $this->checks = ['complexity' => ['enabled' => true, 'threshold' => 16]];

        $identifiers = array_map(
            static fn (Error $error): ?string => $error->getIdentifier(),
            $this->gatherAnalyserErrors([self::FIXTURE]),
        );

        $this->assertSame(['regex.lint.complexity', 'regex.lint.complexity'], $identifiers);
    }

    #[Test]
    public function test_a_pattern_under_the_threshold_is_not_reported(): void
    {
        $this->checks = ['complexity' => ['enabled' => true, 'threshold' => 17]];

        $this->analyse([self::FIXTURE], [
            ['Pattern is complex (score: 56).', 20, self::TIP],
        ]);
    }

    #[Test]
    public function test_the_default_threshold_is_50(): void
    {
        $this->checks = ['complexity' => ['enabled' => true]];

        $this->analyse([self::FIXTURE], [
            ['Pattern is complex (score: 56).', 20, self::TIP],
        ]);
    }

    /**
     * @return iterable<string, array{checks: array{lint?: array{enabled?: bool}, redos?: array{enabled?: bool, threshold?: string}, complexity?: array{enabled?: bool, threshold?: int}}}>
     */
    public static function provideChecksWithoutComplexity(): iterable
    {
        yield 'every check off' => ['checks' => []];
        yield 'complexity off' => ['checks' => ['complexity' => ['enabled' => false, 'threshold' => 1]]];
        yield 'a threshold alone' => ['checks' => ['complexity' => ['threshold' => 1]]];
        yield 'redos on' => ['checks' => ['redos' => ['enabled' => true, 'threshold' => 'low']]];
    }

    /**
     * @param array{lint?: array{enabled?: bool}, redos?: array{enabled?: bool, threshold?: string}, complexity?: array{enabled?: bool, threshold?: int}} $checks
     */
    #[Test]
    #[DataProvider('provideChecksWithoutComplexity')]
    public function test_complexity_is_not_reported_while_the_check_is_off(array $checks): void
    {
        $this->checks = $checks;

        $this->analyse([self::FIXTURE], []);
    }

    #[Test]
    public function test_lint_and_complexity_report_side_by_side(): void
    {
        $this->checks = ['lint' => ['enabled' => true], 'complexity' => ['enabled' => true, 'threshold' => 17]];

        $lines = array_map(
            static fn (Error $error): string => ($error->getLine() ?? '').' '.($error->getIdentifier() ?? ''),
            $this->gatherAnalyserErrors([self::FIXTURE]),
        );
        sort($lines);

        $this->assertSame(['20 regex.lint.complexity', '23 regex.lint.quantifier.useless'], $lines);
    }

    /**
     * @return iterable<string, array{threshold: mixed}>
     */
    public static function provideUnreadableThresholds(): iterable
    {
        yield 'zero' => ['threshold' => 0];
        yield 'negative' => ['threshold' => -1];
        yield 'a string' => ['threshold' => '50'];
    }

    #[Test]
    #[DataProvider('provideUnreadableThresholds')]
    public function test_an_unreadable_threshold_is_refused_even_while_off(mixed $threshold): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains('"checks.complexity.threshold" must be an integer of at least 1');

        new PatternChecker(['checks' => ['complexity' => ['enabled' => false, 'threshold' => $threshold]]]);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: ['checks' => $this->checks]);
    }
}
