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

namespace PhpRegex\Tests\Unit\Linter\Bugs;

use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the character class range optimization bug.
 *
 * This test ensures that the optimizer does NOT create ranges (e.g., `A-C`)
 * when merging characters unless ALL intermediate characters are present
 * in the original pattern.
 *
 * Bug description:
 * The optimizer was incorrectly creating ranges when merging characters,
 * even if intermediate characters were NOT present in the original pattern.
 * This effectively broadened the regex matching rules, breaking user logic.
 *
 * Examples of incorrect behavior (now fixed):
 * - Input: `[+-\/]` (matches literals `+`, `-`, `/`)
 *   Bad output: `[+-/]` (would match range `+` through `/`, including `,` and `.`)
 * - Input: `[@\]]` (matches literals `@`, `]`)
 *   Bad output: `[@-\]]` (would match range `@` through `]`, including `A-Z`, `[`, `\`)
 *
 * The optimization must be strictly equivalent:
 * - `[AC]` must NEVER become `[A-C]`
 * - Only `[ABC]` can become `[A-C]`
 */
final class CharacterClassRangeBugTest extends TestCase
{
    private Regex $regex;

    private PatternPrinter $compiler;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->compiler = new PatternPrinter();
    }

    /**
     * Test that the optimizer does not create ranges when intermediate characters are missing.
     */
    #[DataProvider('nonConsecutiveCharactersProvider')]
    public function test_optimizer_does_not_create_invalid_ranges(string $input, string $expectedPattern): void
    {
        $optimizer = new Rewriter();
        $ast = $this->regex->parse($input);
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($this->compiler);

        // The result should NOT contain a range that includes missing characters
        $this->assertSame($expectedPattern, $result, sprintf(
            'Pattern %s should optimize to %s (no invalid ranges), but got %s',
            $input,
            $expectedPattern,
            $result,
        ));
    }

    /**
     * Test that the optimizer correctly creates ranges when ALL intermediate characters are present.
     */
    #[DataProvider('consecutiveCharactersProvider')]
    public function test_optimizer_creates_valid_ranges(string $input, string $expectedPattern): void
    {
        $optimizer = new Rewriter();
        $ast = $this->regex->parse($input);
        $optimized = $ast->accept($optimizer);
        $result = $optimized->accept($this->compiler);

        $this->assertSame($expectedPattern, $result, sprintf(
            'Pattern %s should optimize to %s, but got %s',
            $input,
            $expectedPattern,
            $result,
        ));
    }

    /**
     * Data provider for patterns with non-consecutive characters.
     * These should NOT be converted to ranges.
     *
     * @return \Iterator<string, array{0: string, 1: string}>
     */
    public static function nonConsecutiveCharactersProvider(): \Iterator
    {
        // Issue example 1: [+\-\/] matches +, -, / (ASCII 43, 45, 47 - not consecutive)
        // Should NOT become a range like [+-/] which would include , (44) and . (46)
        yield 'plus minus slash - non-consecutive' => [
            '/[+\-\/]/',
            '/[+\-\/]/',
        ];

        // Issue example 2: [@\]] matches @, ] (ASCII 64, 93 - not consecutive)
        // Should NOT become a range like [@-\]] which would include A-Z, [, \
        yield 'at sign and bracket - non-consecutive' => [
            '/[@\]]/',
            '/[@\]]/',
        ];

        // [AC] should NOT become [A-C] (missing B)
        yield 'AC without B' => [
            '/[AC]/',
            '/[AC]/',
        ];

        // [ACE] should NOT become any range (missing B and D)
        yield 'ACE without BD' => [
            '/[ACE]/',
            '/[ACE]/',
        ];

        // [ABDE] should NOT become [A-E] (missing C)
        // But AB and DE are consecutive pairs
        yield 'ABDE without C' => [
            '/[ABDE]/',
            '/[ABDE]/',
        ];

        // [ACD] - A is alone, CD are consecutive but only 2 chars (no range created for 2 chars)
        yield 'ACD - A alone, CD pair' => [
            '/[ACD]/',
            '/[ACD]/',
        ];

        // [13579] - odd digits only, should not become [1-9]
        yield 'odd digits only' => [
            '/[13579]/',
            '/[13579]/',
        ];

        // [aeiou] - vowels only, should not become any range
        yield 'vowels only' => [
            '/[aeiou]/',
            '/[aeiou]/',
        ];

        // Mautic bug: [@\[\]] matches @, [, ] (ASCII 64, 91, 93 - gap between 64 and 91)
        // Should NOT become [@-\]] which would include A-Z (ASCII 65-90), [, \
        // Note: [ doesn't need escaping inside a character class (except at the start)
        yield 'mautic bug - at bracket backslash bracket with gap' => [
            '/[@\[\]]/',
            '/[@\[\]]/',
        ];

        // Extended Mautic case: [@\[\]\\] matches @, [, \, ] (ASCII 64, 91, 92, 93)
        // The gap between @ (64) and [ (91) means no range should span them
        // But [, \, ] (91, 92, 93) are consecutive - however only 3 chars,
        // and they are in "other" category, so with strictRanges they should form [-\]]
        // Actually, the issue is that @ should NOT be merged with the rest
        yield 'mautic extended - at with consecutive brackets' => [
            '/[@\[\]\\\\]/',
            '/[@[-\]]/',
        ];
    }

    /**
     * Data provider for patterns with consecutive characters.
     * These CAN be converted to ranges (when 3+ consecutive chars).
     *
     * @return \Iterator<string, array{0: string, 1: string}>
     */
    public static function consecutiveCharactersProvider(): \Iterator
    {
        // [ABC] CAN become [A-C] (all consecutive)
        yield 'ABC - all consecutive' => [
            '/[ABC]/',
            '/[A-C]/',
        ];

        // [ABCDE] CAN become [A-E] (all consecutive)
        yield 'ABCDE - all consecutive' => [
            '/[ABCDE]/',
            '/[A-E]/',
        ];

        // [0123456789] CAN become [0-9] (all consecutive)
        // Note: The \d optimization only applies when input is already [0-9] range
        yield 'all digits' => [
            '/[0123456789]/',
            '/[0-9]/',
        ];

        // [abc] CAN become [a-c] (all consecutive)
        yield 'abc - all consecutive lowercase' => [
            '/[abc]/',
            '/[a-c]/',
        ];

        // [xyz] CAN become [x-z] (all consecutive)
        yield 'xyz - all consecutive' => [
            '/[xyz]/',
            '/[x-z]/',
        ];
    }
}
