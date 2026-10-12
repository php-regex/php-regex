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
 * A double-quoted "\t" reaches the pattern as a raw tab, though the source
 * spells it: PHPStan reports a raw character only where the literal at the
 * call holds it as written. A pattern held in a variable is not read back
 * to its literal, and is not reported.
 *
 * @extends RuleTestCase<RegexPatternRule>
 */
final class RegexPatternRuleInvisibleCharacterTest extends RuleTestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/InvisibleCharacterFixture.php';

    #[Test]
    public function test_a_raw_character_is_reported_only_where_the_literal_holds_it_raw(): void
    {
        $lines = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            if ('regex.lint.literal.invisible' === $error->getIdentifier()) {
                $lines[] = $error->getLine();
            }
        }
        sort($lines);

        // Line 20 holds a raw tab, 21 a "\t", 22 a raw tab in the second
        // part of a concatenation, 23 a raw tab in its first key only, 25 a
        // pattern held in a variable, 26 a "\t" in a concatenation.
        $this->assertSame([20, 22, 23], $lines);
    }

    protected function getRule(): Rule
    {
        return new RegexPatternRule(config: [
            'checks' => [
                'lint' => ['enabled' => true],
                'redos' => ['enabled' => false],
                'optimizations' => ['enabled' => false],
            ],
        ]);
    }
}
