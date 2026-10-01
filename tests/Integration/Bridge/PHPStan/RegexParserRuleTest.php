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
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleTest extends RuleTestCase
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
                "Consider using atomic groups (?>...) or possessive quantifiers.\nRead more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#nested-quantifiers",
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
                "Consider tightening the first quantifier to its minimum.\nRead more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#optimal-quantifier-concatenation",
            ],
        ]);
    }

    public function test_preg_replace_callback_array(): void
    {
        $this->analyse([__DIR__.'/Fixtures/PregReplaceCallbackArray.php'], [
            [
                'Exponential backtracking (ReDoS): /(a+)+$/',
                20,
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
                20,
                "Consider using atomic groups (?>...) or possessive quantifiers.\nRead more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#nested-quantifiers",
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
                'Read more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#useless-flag-s-dotall',
            ],
        ]);
    }

    public function test_redos_with_links(): void
    {
        $this->analyse([__DIR__.'/Fixtures/ReDoSFixture.php'], [
            [
                'Exponential backtracking (ReDoS): /(a+)+$/',
                20,
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
                20,
                "Consider using atomic groups (?>...) or possessive quantifiers.\nRead more: https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md#nested-quantifiers",
            ],
            [
                'Quantified capturing group "(...)" with "+": only the last iteration\'s capture is retained.',
                20,
                'Use a non-capturing group (?:...) for the repetition and capture the whole match, or restructure the pattern.',
            ],
        ]);
    }

    #[Test]
    public function test_redos_is_reported_under_one_identifier_whatever_the_severity(): void
    {
        // The class lives in the message ("Exponential …", "Polynomial …"), not in the identifier.
        // Line 28, /[0-9]+/, is proven linear and no longer reported.
        $this->assertSame(
            [[23, 'regex.redos'], [24, 'regex.redos']],
            [
                ...$this->identifiersOf(__DIR__.'/Fixtures/MyClass.php', 'Exponential backtracking (ReDoS): '),
                ...$this->identifiersOf(__DIR__.'/Fixtures/MyClass.php', 'Polynomial backtracking (ReDoS): '),
            ],
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
