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
 * The replacements PHPStan cannot pair with a pattern for sure, and the
 * patterns that cannot be read, are left alone: a group count is only
 * claimed for a pattern the target reads.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexParserRuleReplacementGroupEdgeTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/ReplacementGroupEdgeFixture.php';

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49.
     */
    #[Test]
    public function test_the_engine_pairs_by_position_whatever_the_keys(): void
    {
        $this->assertSame('[a][x]', preg_replace(['/(a)/', '/b/'], [5 => '[$1]', 0 => '[x]'], 'ab'));
        // An entry missing from the replacements is the empty string.
        $this->assertSame('[a]', preg_replace([0 => '/(a)/', 5 => '/b/'], ['[$1]'], 'ab'));
        // "(?r)" is PCRE2 10.43: the PCRE2 10.40 that PHP 8.2 bundles refuses it.
        $this->assertSame(1, preg_match('/(?r)(a)/', 'a'));
    }

    #[Test]
    public function test_only_a_pairing_certain_on_a_readable_pattern_is_reported(): void
    {
        $errors = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if (RegexPatternRule::IDENTIFIER_REPLACEMENT_UNDEFINED_GROUP === $error->getIdentifier()) {
                $errors[] = $error->getLine().' '.$error->getMessage();
            }
        }

        // Line 20 is refused by the engine (PHPStan core reports it), line
        // 21 by the target; on line 31 a key may be missing from either
        // array, which moves the pairing; line 32 is a TypeError.
        $this->assertSame([
            '22 Replacement reference $2 names group 2, but /(a)/ has 1 capturing group: preg_replace() substitutes an empty string.',
            '23 Replacement reference $2 names group 2, but /(a)/ has 1 capturing group: preg_replace() substitutes an empty string.',
        ], $errors);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: ['phpVersion' => '8.2']);
    }
}
