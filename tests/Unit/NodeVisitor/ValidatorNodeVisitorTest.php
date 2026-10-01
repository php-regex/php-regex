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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorNodeVisitorTest extends TestCase
{
    public function test_throws_on_invalid_quantifier_range(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid quantifier range "{3,1}": min > max.');
        $this->validate('/foo{3,1}/');
    }

    public function test_throws_on_invalid_flags(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Unknown regex flag(s) found: "z"');
        $this->validate('/foo/imz');
    }

    public function test_throws_on_invalid_unicode_property(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid or unsupported Unicode property: \p{Invalid}.');
        $this->validate('/\p{Invalid}/');
    }

    public function test_throws_on_invalid_unicode_property_with_suggestion(): void
    {
        $this->expectException(SemanticErrorException::class);
        // Note: Suggestion may not be implemented yet
        $this->expectExceptionMessage('Invalid or unsupported Unicode property: \p{Letter}.');
        $this->validate('/\p{Letter}/');
    }

    public function test_throws_on_invalid_unicode_named_character(): void
    {
        // PCRE2 supports no "\N{name}", a known name or not: preg_match()
        // warns "PCRE2 does not support ... \N{name} ... at offset 3".
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('PCRE does not support the escape "\N{"');
        $this->validate('/\N{INVALID}/');
    }

    public function test_throws_on_invalid_range(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid range "z-a": start character comes after end character.');
        $this->validate('/[z-a]/');
    }

    public function test_chartype_at_end_of_range_is_rejected(): void
    {
        // PCRE compile-errors on a character type as a range endpoint.
        $this->expectException(ParserException::class);
        $this->validate('/[a-\d]/');
    }

    public function test_throws_on_invalid_backref(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference to non-existent group: \2.');
        $this->validate('/\2/'); // No group 2
    }

    public function test_multi_digit_backref_without_octal_fallback_is_rejected(): void
    {
        // "81" starts with a non-octal digit: neither a group nor an octal escape.
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference to non-existent group: \81.');
        $this->validate('/\81/');
    }

    public function test_throws_on_invalid_unicode_prop(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid or unsupported Unicode property: \p{invalid}.');
        $this->validate('/\p{invalid}/');
    }

    public function test_throws_on_invalid_numeric_subroutine(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Subroutine call to non-existent group: 1.');
        $this->validate('/(?1)/'); // No group 1
    }

    public function test_throws_on_invalid_named_subroutine(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Subroutine call to non-existent named group: "name".');
        $this->validate('/(?&name)/'); // No group "name"
    }

    public function test_throws_on_duplicate_group_name(): void
    {
        $this->expectException(ParserException::class);
        // PHP: "two named subpatterns have the same name ... at offset 18".
        $this->expectExceptionMessage('Duplicate group name "name" at position 18.');
        $this->validate('/(?<name>a)(?<name>b)/');
    }

    public function test_allows_variable_length_quantifier_in_lookbehind(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Lookbehind is unbounded');
        $this->validate('/(?<=a*b)/');
    }

    /**
     * PHP 8.1 to 8.4 compile every pattern with
     * PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 never banned \K in
     * lookarounds before 10.38: preg_match returns 0, without a warning, for
     * each of these. PHP 8.5 dropped the option.
     */
    #[DataProvider('provideKeepInsideLookarounds')]
    public function test_accepts_keep_inside_lookarounds(string $pattern): void
    {
        $this->assertTrue(Regex::create(['php_version' => '8.4'])->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideKeepInsideLookarounds(): iterable
    {
        yield 'positive lookbehind' => ['pattern' => '/(?<=a\K)/'];
        yield 'keep first in a lookbehind' => ['pattern' => '/(?<=\Ka)/'];
        yield 'negative lookbehind' => ['pattern' => '/(?<!\Ka)b/'];
        yield 'positive lookahead' => ['pattern' => '/(?=a\K)a/'];
        yield 'lookahead inside a lookbehind' => ['pattern' => '/(?<=a(?=b\K))/'];
    }

    public function test_throws_on_backref_to_non_existent_named_group(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference to non-existent named group: "name".');
        $this->validate('/a\k<name>/');
    }

    public function test_chartype_at_start_of_range_is_rejected(): void
    {
        // PCRE compile-errors on a character type as a range endpoint.
        $this->expectException(ParserException::class);
        $this->validate('/[\d-z]/');
    }

    public function test_throws_on_relative_backref_out_of_bounds(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference relative reference -2 is outside the range of available capture groups.');
        $this->validate('/(a)\g{-2}/');
    }

    public function test_throws_on_conditional_invalid_condition_type(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE).');
        // A literal is not a valid condition
        $this->validate('/(?(a)b|c)/');
    }

    public function test_throws_on_invalid_pcre_verb(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid or unsupported PCRE verb: "INVALID"');
        $this->validate('/(*INVALID)/');
    }

    public function test_throws_on_invalid_unicode_codepoint(): void
    {
        $this->expectException(SemanticErrorException::class);
        // Codepoint U+110000 is beyond the valid Unicode range U+10FFFF
        $this->expectExceptionMessage('Invalid Unicode codepoint "\u{110000}" (out of range).');
        $this->validate('/\u{110000}/u');
    }

    public function test_throws_on_invalid_octal_codepoint(): void
    {
        $this->expectException(SemanticErrorException::class);
        // Octal 77777777 = 16777215 decimal = 0xFFFFFF, which exceeds 0xFF (PCRE limit for \o{})
        $this->expectExceptionMessage('Invalid octal codepoint "\o{77777777}" (out of Unicode range).');
        $this->validate('/\o{77777777}/u');
    }

    public function test_throws_on_invalid_octal_legacy_codepoint(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference to non-existent group: \999.');
        $this->validate('/\999/');
    }

    #[DataProvider('providePosixClasses')]
    public function test_negated_posix_classes_match_pcre(string $class): void
    {
        $pattern = \sprintf('/[[:^%s:]]/', $class);

        $this->assertNotFalse(@preg_match($pattern, ''), 'PCRE must accept the pattern');

        $this->validate($pattern);
        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePosixClasses(): iterable
    {
        foreach ([
            'alnum', 'alpha', 'ascii', 'blank', 'cntrl', 'digit', 'graph',
            'lower', 'print', 'punct', 'space', 'upper', 'word', 'xdigit',
        ] as $class) {
            yield $class => [$class];
        }
    }

    private function validate(string $pattern): void
    {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new Validator();
        $ast->accept($visitor);
    }
}
