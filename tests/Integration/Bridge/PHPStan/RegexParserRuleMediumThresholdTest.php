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
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleMediumThresholdTest extends RuleTestCase
{
    public function test_rule(): void
    {
        $this->analyse([__DIR__.'/Fixtures/MyClass.php'], [
            [
                'Exponential backtracking (ReDoS): /(a+)+$/',
                23,
                RedosTip::expected(
                    '/(a+)+$/',
                    'critical, exponential (proven)',
                    true,
                    "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n".
                    "Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++). Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).\n".
                    "\n".
                    "Read more about possessive quantifiers: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#possessive-quantifiers\n".
                    "Read more about atomic groups: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#atomic-groups\n".
                    'Read more about catastrophic backtracking: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#catastrophic-backtracking',
                ),
            ],
            [
                'Nested quantifiers can cause catastrophic backtracking.',
                23,
                "Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.\nRead more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#nested-quantifiers",
            ],
            [
                'Quantified capturing group "(...)" with "+": only the last iteration\'s capture is retained.',
                23,
                'Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.',
            ],
            [
                'Polynomial backtracking (ReDoS): /a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a...',
                24,
                RedosTip::expected(
                    '/'.str_repeat('a*', 60).'b/',
                    'high, polynomial degree 60 (proven)',
                    true,
                    "Adjacent quantified tokens with overlapping character sets can cause ambiguous backtracking (e.g., a+a+ or a*a*). Suggested (verify behavior): Merge repetitions, add a delimiter, or make one quantifier possessive to remove ambiguity.\n".
                    "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n\nRead more about possessive quantifiers: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#possessive-quantifiers\nRead more about atomic groups: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#atomic-groups\nRead more about catastrophic backtracking: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#catastrophic-backtracking",
                ),
            ],
            [
                'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                24,
                "The first quantifier can match zero times already: consider dropping the whole quantified term.\nRead more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#optimal-quantifier-concatenation",
            ],
        ]);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => true],
                'redos' => ['enabled' => true, 'threshold' => 'medium'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }
}
