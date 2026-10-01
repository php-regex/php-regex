<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\PHPStan;

use PhpRegex\PHPStan\RegexPatternRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleHighThresholdTest extends RuleTestCase
{
    public function test_rule(): void
    {
        $this->analyse([__DIR__.'/Fixtures/MyClass.php'], [
            [
                'Potential ReDoS risk (theoretical) (severity: CRITICAL, confidence: HIGH): /(a+)+$/',
                23,
                "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n".
                "Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++). Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).\n".
                "\n".
                "Read more about possessive quantifiers: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#possessive-quantifiers\n".
                "Read more about atomic groups: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#atomic-groups\n".
                'Read more about catastrophic backtracking: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#catastrophic-backtracking',
            ],
            [
                'Nested quantifiers can cause catastrophic backtracking.',
                23,
                "Consider using atomic groups (?>...) or possessive quantifiers.\nRead more: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#nested-quantifiers",
            ],
            [
                'Quantified capturing group "(...)" with "+": only the last iteration\'s capture is retained.',
                23,
                'Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.',
            ],
            // Note: MEDIUM ReDoS on line 24 is filtered out by 'high' threshold
            [
                'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                24,
                "Consider tightening the first quantifier to its minimum.\nRead more: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#optimal-quantifier-concatenation",
            ],
        ]);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => true],
                'redos' => ['enabled' => true, 'threshold' => 'high'],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }
}
