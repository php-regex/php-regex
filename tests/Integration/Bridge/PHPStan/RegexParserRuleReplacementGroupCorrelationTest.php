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
use PHPUnit\Framework\Attributes\Test;

/**
 * A pattern and a replacement that both vary may vary together (a ternary
 * on the same condition, two maps read with the same key, an if/else that
 * assigns both), a correlation PHPStan's types do not keep. A reference is
 * then reported only when no pattern the call may take defines its group.
 * A single replacement meets every pattern, and stays fully checked.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleReplacementGroupCorrelationTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/ReplacementGroupCorrelationFixture.php';

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: each branch, run as written.
     */
    #[Test]
    public function test_the_engine_substitutes_every_group_of_each_branch(): void
    {
        $this->assertSame('12:34', self::replace('/^(\d+)-(\d+)$/', '$1:$2', '12-34'));
        $this->assertSame('12', self::replace('/^(\d+)$/', '$1', '12'));
        $this->assertSame('<12>', self::replace('/^(\d+)$/', '<$1>', '12'));
        $this->assertSame('[a][bc]', self::replace(['/(a)/', '/(b)(c)/'], ['[$1]', '[$1$2]'], 'abc'));
        $this->assertSame('[a][d]', self::replace(['/(a)/', '/(d)/'], ['[$1]', '[$1]'], 'ad'));
        // Group 3 is the empty string in either branch.
        $this->assertSame('12:', self::replace('/^(\d+)-(\d+)$/', '$1:$3', '12-34'));
        $this->assertSame('', self::replace('/^(\d+)$/', '$3', '12'));
        // A single replacement meets each pattern: group 2 of the one-group branch.
        $this->assertSame('', self::replace('/^(\d+)$/', '$2', '12'));
    }

    #[Test]
    public function test_only_a_group_no_possible_pattern_defines_is_reported(): void
    {
        $errors = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if (RegexPatternRule::IDENTIFIER_REPLACEMENT_UNDEFINED_GROUP === $error->getIdentifier()) {
                $errors[] = $error->getLine().' '.$error->getMessage();
            }
        }

        // Lines 26 to 37 pair each pattern with its own replacement; on line
        // 37 one pattern cannot be read, so it may define any group.
        $this->assertSame([
            '44 Replacement reference $3 names group 3, but /^(\d+)-(\d+)$/ has 2 capturing groups: preg_replace() substitutes an empty string.',
            '44 Replacement reference $3 names group 3, but /^(\d+)$/ has 1 capturing group: preg_replace() substitutes an empty string.',
            '45 Replacement reference $3 names group 3, but /(b)(c)/ has 2 capturing groups: preg_replace() substitutes an empty string.',
            '45 Replacement reference $3 names group 3, but /(d)/ has 1 capturing group: preg_replace() substitutes an empty string.',
            '46 Replacement reference $2 names group 2, but /^(\d+)$/ has 1 capturing group: preg_replace() substitutes an empty string.',
        ], $errors);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule();
    }

    /**
     * The engine, its arguments typed loosely: the calls this test runs on
     * purpose are not the ones the rule should see.
     *
     * @param string|list<string> $pattern
     * @param string|list<string> $replacement
     */
    private static function replace(string|array $pattern, string|array $replacement, string $subject): ?string
    {
        return preg_replace($pattern, $replacement, $subject);
    }
}
