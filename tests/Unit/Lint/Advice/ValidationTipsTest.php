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

namespace PHPRegex\Tests\Unit\Lint\Advice;

use PHPRegex\Linter\Advice\ValidationTips;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tip of an invalid pattern: one written for the pattern when its
 * error allows, else one for the error alone, else none.
 */
final class ValidationTipsTest extends TestCase
{
    public function test_pattern_specific_tips(): void
    {
        $tip = ValidationTips::forError('No closing delimiter', '#foo', self::validation('No closing delimiter'));
        $this->assertIsString($tip);
        $this->assertStringContainsString('Add the missing closing delimiter', (string) $tip);

        $tip = ValidationTips::forError('Unclosed character class', '/[a-z/', self::validation('Unclosed character class'));
        $this->assertIsString($tip);
        $this->assertStringContainsString('Add missing closing bracket', (string) $tip);

        $tip = ValidationTips::forError('Invalid quantifier range', '/a{3,2}/', self::validation('Invalid quantifier range'));
        $this->assertIsString($tip);
        $this->assertStringContainsString('Swap min and max values', (string) $tip);

        $tip = ValidationTips::forError('Backreference to non-existent group', '/(a)\\2/', self::validation('Backreference to non-existent group'));
        $this->assertIsString($tip);
        $this->assertStringContainsString('Backreference', (string) $tip);

        $offset = \strlen('/(?<=\\w*)foo/');
        $tip = ValidationTips::forError('Lookbehind is unbounded', '/(?<=\\w*)foo/', self::validation('Lookbehind is unbounded', $offset));
        $this->assertIsString($tip);
        $this->assertStringContainsString('Replace unbounded quantifiers', (string) $tip);
    }

    /**
     * A pattern the pattern-specific advice has nothing to say about gets
     * the tip of its error.
     *
     * @return iterable<string, array{message: string, pattern: string, offset: int, expected: string}>
     */
    public static function provideGenericFallbacks(): iterable
    {
        yield 'class already closed' => ['message' => 'Unclosed character class', 'pattern' => '/[a-z]/', 'offset' => 0, 'expected' => 'Character classes must be closed'];
        yield 'bounds in order' => ['message' => 'Invalid quantifier range', 'pattern' => '/a{1,2}/', 'offset' => 0, 'expected' => 'Quantifier ranges must have min <= max'];
        yield 'reference to an existing group' => ['message' => 'Backreference to non-existent group', 'pattern' => '/(a)\\1/', 'offset' => 0, 'expected' => 'Backreferences like'];
        yield 'no lookbehind before the offset' => ['message' => 'Lookbehind is unbounded', 'pattern' => '/abc/', 'offset' => 0, 'expected' => 'Variable-length lookbehinds are not allowed'];
        yield 'bounded lookbehind' => ['message' => 'Lookbehind is unbounded', 'pattern' => '/(?<=\\w{2})foo/', 'offset' => \strlen('/(?<=\\w{2})foo/'), 'expected' => 'Variable-length lookbehinds are not allowed'];
    }

    #[DataProvider('provideGenericFallbacks')]
    public function test_pattern_specific_advice_falls_back_to_the_tip_of_the_error(string $message, string $pattern, int $offset, string $expected): void
    {
        $tip = ValidationTips::forError($message, $pattern, self::validation($message, $offset));

        $this->assertIsString($tip);
        $this->assertStringContainsString($expected, (string) $tip);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideQuantifierRangeTips(): iterable
    {
        yield 'bounds swapped in the pattern' => ['pattern' => '/a{3,2}/', 'expected' => 'Swap min and max values: /a{2,3}/'];
        yield 'leading zero on the lower bound' => ['pattern' => '/a{03,2}/', 'expected' => 'Swap min and max values: /a{2,3}/'];
        yield 'leading zero on the upper bound' => ['pattern' => '/a{3,02}/', 'expected' => 'Swap min and max values: /a{2,3}/'];
    }

    /**
     * Each pattern is refused by PCRE: "numbers out of order in {}
     * quantifier". The tip gives the whole pattern back with the bounds
     * swapped, written as numbers.
     */
    #[DataProvider('provideQuantifierRangeTips')]
    public function test_quantifier_range_tip_spells_the_swapped_pattern(string $pattern, string $expected): void
    {
        $this->assertSame($expected, ValidationTips::forError('Invalid quantifier range', $pattern, self::validation('Invalid quantifier range')));
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideBackreferenceTips(): iterable
    {
        yield 'one group, reference to the second' => [
            'pattern' => '/(a)\\2/',
            'expected' => 'Backreference \\2 refers to group 2, but only 1 capturing groups exist in the pattern. Valid backreferences are \\1 through \\1.',
        ];
        yield 'valid reference before the missing one' => [
            'pattern' => '/(a)(b)\\1\\3/',
            'expected' => 'Backreference \\3 refers to group 3, but only 2 capturing groups exist in the pattern. Valid backreferences are \\1 through \\2.',
        ];
    }

    #[DataProvider('provideBackreferenceTips')]
    public function test_backreference_tip_names_the_missing_group(string $pattern, string $expected): void
    {
        $this->assertSame($expected, ValidationTips::forError('Backreference to non-existent group', $pattern, self::validation('Backreference to non-existent group')));
    }

    /**
     * @return iterable<string, array{message: string, expected: string}>
     */
    public static function provideGenericTips(): iterable
    {
        yield 'unclosed class' => ['message' => 'Unclosed character class', 'expected' => 'Character classes must be closed'];
        yield 'quantifier range' => ['message' => 'Invalid quantifier range', 'expected' => 'Quantifier ranges must have min <= max'];
        yield 'missing group' => ['message' => 'Backreference to non-existent group', 'expected' => 'Backreferences like'];
        yield 'unknown flag' => ['message' => 'Unknown regex flag', 'expected' => 'Only valid PCRE flags'];
        yield 'conditional' => ['message' => 'Invalid conditional construct', 'expected' => 'Conditionals need a valid condition'];
    }

    #[DataProvider('provideGenericTips')]
    public function test_generic_tips_cover_other_messages(string $message, string $expected): void
    {
        $tip = ValidationTips::forError($message, '', self::validation($message));

        $this->assertIsString($tip);
        $this->assertStringContainsString($expected, (string) $tip);
    }

    public function test_delimiter_tip_names_a_delimiter_inside_the_pattern(): void
    {
        $this->assertSame(
            "Your pattern contains the delimiter '#' inside. Either escape it as \\# or use a different delimiter like #pattern#.",
            ValidationTips::forError('No closing delimiter', '#a#b', self::validation('No closing delimiter')),
        );
    }

    public function test_an_error_without_advice_gets_no_tip(): void
    {
        $this->assertNull(ValidationTips::forError('Completely unrelated', '/a/', self::validation('Completely unrelated')));
    }

    private static function validation(string $message, int $offset = 0): ValidationResult
    {
        return new ValidationResult(false, $message, 0, null, $offset);
    }
}
