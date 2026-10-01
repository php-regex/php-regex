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

namespace PhpRegex\Tests\Integration\Bridge\PHPStan;

use PhpRegex\PHPStan\RegexPatternRule;
use PHPStan\Analyser\Error;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleTest extends RuleTestCase
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
            [
                'Potential ReDoS risk (theoretical) (severity: MEDIUM, confidence: MEDIUM): /a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a*a...',
                24,
                "Adjacent quantified tokens with overlapping character sets can cause ambiguous backtracking (e.g., a+a+ or a*a*). Suggested (verify behavior): Merge repetitions, add a delimiter, or make one quantifier possessive to remove ambiguity.\n".
                "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n\nRead more about possessive quantifiers: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#possessive-quantifiers\nRead more about atomic groups: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#atomic-groups\nRead more about catastrophic backtracking: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#catastrophic-backtracking",
            ],
            [
                'Concatenated quantifiers can be optimized when one character set is a subset of the other.',
                24,
                "Consider tightening the first quantifier to its minimum.\nRead more: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#optimal-quantifier-concatenation",
            ],
            [
                'Potential ReDoS risk (theoretical) (severity: MEDIUM, confidence: MEDIUM): /[0-9]+/',
                28,
                "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n\nRead more about possessive quantifiers: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#possessive-quantifiers\nRead more about atomic groups: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#atomic-groups\nRead more about catastrophic backtracking: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#catastrophic-backtracking",
            ],
        ]);
    }

    public function test_preg_replace_callback_array(): void
    {
        $this->analyse([__DIR__.'/Fixtures/PregReplaceCallbackArray.php'], [
            [
                'Potential ReDoS risk (theoretical) (severity: CRITICAL, confidence: HIGH): /(a+)+$/',
                20,
                "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n".
                "Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++). Suggested (verify behavior): Replace inner quantifiers with possessive variants or wrap them in (?>...).\n".
                "\n".
                "Read more about possessive quantifiers: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#possessive-quantifiers\n".
                "Read more about atomic groups: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#atomic-groups\n".
                'Read more about catastrophic backtracking: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#catastrophic-backtracking',
            ],
            [
                'Nested quantifiers can cause catastrophic backtracking.',
                20,
                "Consider using atomic groups (?>...) or possessive quantifiers.\nRead more: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#nested-quantifiers",
            ],
            [
                'Quantified capturing group "(...)" with "+": only the last iteration\'s capture is retained.',
                20,
                'Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.',
            ],
        ]);
    }

    public function test_useless_flag_linter(): void
    {
        $this->analyse([__DIR__.'/Fixtures/UselessFlagFixture.php'], [
            [
                'Flag \'s\' is useless: the pattern contains no dots.',
                20,
                'Read more: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#useless-flag-s-dotall',
            ],
        ]);
    }

    public function test_redos_with_links(): void
    {
        $this->analyse([__DIR__.'/Fixtures/ReDoSFixture.php'], [
            [
                'Potential ReDoS risk (theoretical) (severity: MEDIUM, confidence: MEDIUM): /[0-9]+/',
                20,
                "Unbounded quantifier detected. May cause backtracking on non-matching input. Consider making it possessive (*+) or using atomic groups (?>...). Suggested (verify behavior): Consider using possessive quantifiers or atomic groups to limit backtracking.\n\nRead more about possessive quantifiers: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#possessive-quantifiers\nRead more about atomic groups: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#atomic-groups\nRead more about catastrophic backtracking: https://github.com/php-regex/regex-parser/blob/main/docs/reference.md#catastrophic-backtracking",
            ],
        ]);
    }

    #[Test]
    public function test_redos_is_reported_under_one_identifier_whatever_the_severity(): void
    {
        // The severity lives in the message ("severity: CRITICAL", "severity: MEDIUM"), not in the identifier.
        $this->assertSame(
            [[23, 'regex.redos'], [24, 'regex.redos'], [28, 'regex.redos']],
            $this->identifiersOf(__DIR__.'/Fixtures/MyClass.php', 'Potential ReDoS risk'),
        );
    }

    #[Test]
    public function test_lint_keeps_its_identifiers(): void
    {
        $this->assertSame(
            [[20, 'regex.lint.flag.useless.s']],
            $this->identifiersOf(__DIR__.'/Fixtures/UselessFlagFixture.php', ''),
        );
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => true],
                'redos' => ['enabled' => true, 'threshold' => 'low'], // Report all ReDoS issues for testing
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }

    /**
     * @return list<array{int|null, string|null}>
     */
    private function identifiersOf(string $file, string $messagePrefix): array
    {
        $errors = array_values(array_filter(
            $this->gatherAnalyserErrors([$file]),
            static fn (Error $error): bool => str_starts_with($error->getMessage(), $messagePrefix),
        ));
        usort($errors, static fn (Error $a, Error $b): int => $a->getLine() <=> $b->getLine());

        return array_map(static fn (Error $error): array => [$error->getLine(), $error->getIdentifier()], $errors);
    }
}
