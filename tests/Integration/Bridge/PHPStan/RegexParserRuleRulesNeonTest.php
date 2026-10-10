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
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped extension.neon plus the opt-in rules.neon: lint and ReDoS on.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleRulesNeonTest extends RuleTestCase
{
    private const DOCS = 'https://github.com/php-regex/php-regex/blob/2.x/docs/reference/rules.md';

    public static function getAdditionalConfigFiles(): array
    {
        return [
            ...parent::getAdditionalConfigFiles(),
            __DIR__.'/../../../../src/PHPStan/extension.neon',
            __DIR__.'/../../../../src/PHPStan/rules.neon',
        ];
    }

    #[Test]
    public function test_rules_neon_reports_lint_and_redos(): void
    {
        // Line 23 is refused by the running engine: PHPStan core reports it, this rule stays silent.
        // Line 25 compiles wherever the target (the running PHP) is judged: no validity error.
        $this->analyse([__DIR__.'/Fixtures/NeonConfigFixture.php'], [
            [
                'Redundant non-capturing group; it can be removed without changing behavior.',
                20,
                'Read more: '.self::DOCS.'#redundant-non-capturing-group',
            ],
            [
                'Flag \'s\' is useless: the pattern contains no unescaped dot outside a character class.',
                21,
                'Read more: '.self::DOCS.'#useless-flag-s-dotall',
            ],
            [
                'Exponential backtracking (ReDoS): /(a+)+$/',
                22,
                RedosTip::expected(
                    '/(a+)+$/',
                    'critical, exponential (proven)',
                    true,
                    "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n".
                    "Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++). Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).\n".
                    "\n".
                    "Read more about possessive quantifiers: https://github.com/php-regex/php-regex/blob/2.x/docs/tutorial/04-quantifiers.md#possessive-quantifiers-performance\n".
                    "Read more about atomic groups: https://github.com/php-regex/php-regex/blob/2.x/docs/tutorial/08-performance-redos.md#1-atomic-groups-\n".
                    'Read more about catastrophic backtracking: '.self::DOCS.'#catastrophic-backtracking',
                ),
            ],
            [
                'Nested quantifiers can cause catastrophic backtracking.',
                22,
                "Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.\nRead more: ".self::DOCS.'#nested-quantifiers-redos-risk',
            ],
            [
                'Quantified capturing group "(...)" with "+": only the last iteration\'s capture is retained.',
                22,
                'Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.',
            ],
        ]);
    }

    #[Test]
    public function test_rules_neon_errors_carry_stable_identifiers(): void
    {
        $identifiers = array_map(
            static fn (Error $error): string => $error->getLine().' '.$error->getIdentifier(),
            $this->gatherAnalyserErrors([__DIR__.'/Fixtures/NeonConfigFixture.php']),
        );
        sort($identifiers);

        $this->assertSame([
            '20 regex.lint.group.redundant',
            '21 regex.lint.flag.useless.s',
            '22 regex.lint.group.quantifiedCapture',
            '22 regex.lint.quantifier.nested',
            '22 regex.redos',
        ], $identifiers);
    }

    #[Test]
    public function test_rules_neon_turns_lint_and_redos_on_and_nothing_else(): void
    {
        $parameters = self::getContainer()->getParameter('phpRegex');

        $this->assertSame([
            'phpVersion' => null,
            'lint' => true,
            'redos' => true,
            'threshold' => 'critical',
            'optimizations' => false,
        ], [
            'phpVersion' => NeonParameters::read($parameters, 'phpVersion'),
            'lint' => NeonParameters::read($parameters, 'checks', 'lint', 'enabled'),
            'redos' => NeonParameters::read($parameters, 'checks', 'redos', 'enabled'),
            'threshold' => NeonParameters::read($parameters, 'checks', 'redos', 'threshold'),
            'optimizations' => NeonParameters::read($parameters, 'checks', 'optimizations', 'enabled'),
        ]);
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RegexPatternRule::class);
    }
}
