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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mistakes users make most come with a fix in `hint`, not only a
 * diagnosis in `error`: each hint names a change the engine accepts.
 */
final class ValidationHintTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, code: ErrorCode, hint: string, fixed: string}>
     */
    public static function provideCommonMistakes(): iterable
    {
        yield 'unclosed class' => [
            'pattern' => '/[abc/',
            'code' => ErrorCode::CharclassUnclosed,
            'hint' => 'Close the class with "]", or write "\[" to match a literal "[".',
            'fixed' => '/[abc]/',
        ];
        yield 'lone quantifier' => [
            'pattern' => '/+a/',
            'code' => ErrorCode::QuantifierNothingToRepeat,
            'hint' => 'Put the quantifier after the item it repeats, or escape it with a backslash to match it literally.',
            'fixed' => '/\+a/',
        ];
        yield 'reversed quantifier bounds' => [
            'pattern' => '/a{2,1}/',
            'code' => ErrorCode::QuantifierInvalidRange,
            'hint' => 'Write the smaller count first: "{1,2}".',
            'fixed' => '/a{1,2}/',
        ];
        yield 'over-long quantifier' => [
            'pattern' => '/a{70000}/',
            'code' => ErrorCode::QuantifierTooBig,
            'hint' => 'PCRE counts at most 65535 repetitions: lower the count, or use "*" or "+" for an open-ended repeat.',
            'fixed' => '/a{65535}/',
        ];
        yield 'reversed range' => [
            'pattern' => '/[z-a]/',
            'code' => ErrorCode::RangeReversed,
            'hint' => 'Write the lower end of the range first, or escape the "-" as "\-" to match it literally.',
            'fixed' => '/[a-z]/',
        ];
        yield 'missing delimiter' => [
            'pattern' => 'abc',
            'code' => ErrorCode::DelimiterInvalid,
            'hint' => 'Wrap the pattern in a pair of delimiters, such as "/.../" or "#...#".',
            'fixed' => '/abc/',
        ];
        yield 'group name starting with a digit' => [
            'pattern' => '/(?<1a>x)/',
            'code' => ErrorCode::GroupNameInvalid,
            'hint' => 'Start a group name with a letter or "_", then use letters, digits and "_" only.',
            'fixed' => '/(?<a1>x)/',
        ];
        yield 'duplicate group name' => [
            'pattern' => '/(?<n>a)(?<n>b)/',
            'code' => ErrorCode::GroupDuplicateName,
            'hint' => 'Rename one of the groups, or allow duplicate names with (?J).',
            'fixed' => '/(?J)(?<n>a)(?<n>b)/',
        ];
        yield 'call to a missing named group' => [
            'pattern' => '/(?&nope)/',
            'code' => ErrorCode::SubroutineMissingNamedGroup,
            'hint' => 'Name a group "nope" with (?<nope>...), or call a group the pattern names.',
            'fixed' => '/(?<nope>a)(?&nope)/',
        ];
        yield 'unknown Unicode property' => [
            'pattern' => '/\p{Foo}/u',
            'code' => ErrorCode::UnicodePropertyInvalid,
            'hint' => 'Use a property PCRE2 knows, such as \p{L}, \p{Lu}, \p{N} or a script such as \p{Greek}.',
            'fixed' => '/\p{L}/u',
        ];
    }

    #[Test]
    #[DataProvider('provideCommonMistakes')]
    public function test_a_common_mistake_comes_with_a_fix(string $pattern, ErrorCode $code, string $hint, string $fixed): void
    {
        // Oracle: the engine refuses the mistake and compiles the fix.
        $this->assertFalse(@preg_match($pattern, ''));
        $this->assertNotFalse(@preg_match($fixed, ''));

        $parser = RegexParser::create(['cache' => null]);
        $result = $parser->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($code, $result->errorCode);
        $this->assertSame($hint, $result->hint);
        $this->assertTrue($parser->validate($fixed)->isValid);
    }

    #[Test]
    public function test_a_recursion_condition_on_a_missing_name_names_the_group_to_add(): void
    {
        // Oracle: PCRE refuses a recursion condition on a name no group has.
        $mistake = self::conditionOnMissingName();
        $this->assertFalse(@preg_match($mistake, ''));
        $this->assertNotFalse(@preg_match('/(?<nope>b)(?(R&nope)a)/', ''));

        $result = RegexParser::create(['cache' => null])->validate($mistake);

        $this->assertSame(ErrorCode::SubroutineMissingNamedGroup, $result->errorCode);
        $this->assertSame('Name a group "nope" with (?<nope>...), or test a group the pattern names.', $result->hint);
    }

    #[Test]
    public function test_a_property_newer_than_the_target_names_the_release_it_needs(): void
    {
        // Oracle: the running PCRE2 knows the Kawi script, which 10.43 added.
        $this->assertNotFalse(@preg_match('/\p{Kawi}/u', ''));

        $result = RegexParser::create(['cache' => null, 'pcre_version' => '10.42'])->validate('/\p{Kawi}/u');

        $this->assertSame(ErrorCode::UnicodePropertyInvalid, $result->errorCode);
        $this->assertSame('Target a PHP whose PCRE2 is 10.43 or later, or use another property.', $result->hint);
    }

    #[Test]
    public function test_the_reversed_bounds_are_swapped_in_the_hint(): void
    {
        $this->assertNotFalse(@preg_match('/a{3,12}+/', ''));

        $this->assertSame('Write the smaller count first: "{3,12}".', RegexParser::create(['cache' => null])->validate('/a{12,3}+/')->hint);
    }

    /**
     * A recursion condition on a name no group has, which PCRE refuses.
     */
    private static function conditionOnMissingName(): string
    {
        return '/(?(R&nope)a)/';
    }
}
