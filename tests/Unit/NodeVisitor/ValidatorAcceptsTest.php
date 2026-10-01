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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Patterns the validator must accept.
 *
 * Most of these were reported as errors at some point — a nested quantifier
 * mistaken for catastrophic backtracking, a possessive quantifier read as a
 * broken one, an escape that looked wrong and was not. Each of them says
 * "this is valid PCRE, do not report it".
 */
final class ValidatorAcceptsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideValidPatterns')]
    public function test_a_valid_pattern_is_reported_as_valid(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s was reported invalid: %s', $pattern, (string) $result->error));
        $this->assertNull($result->error);
    }

    /**
     * Rows that once sat in the provider above, on a pattern PHP refuses.
     */
    #[Test]
    #[DataProvider('provideFormerlyAcceptedPatternsPhpRefuses')]
    public function test_a_pattern_php_refuses_is_reported_as_invalid(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s compiles in no PCRE2 release but was reported valid.', $pattern));
        $this->assertNotNull($result->error);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideFormerlyAcceptedPatternsPhpRefuses(): iterable
    {
        // preg_match() on PCRE2 10.48 and 10.40: "PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u at offset 2".
        yield 'unicode four digit escape: /\\u0041/' => ['pattern' => '/\\u0041/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideValidPatterns(): iterable
    {
        yield 'validate valid: /foo{1,3}/ims' => ['pattern' => '/foo{1,3}/ims'];
        yield 'allows nested quantifiers: /(a+)*b/' => ['pattern' => '/(a+)*b/'];
        yield 'valid unicode property: /\\p{L}/' => ['pattern' => '/\\p{L}/'];
        yield 'valid java unicode properties: /\\p{javaLowerCase}/u' => ['pattern' => '/\\p{javaLowerCase}/u'];
        yield 'valid java unicode properties: /\\p{javaUpperCase}/u' => ['pattern' => '/\\p{javaUpperCase}/u'];
        yield 'valid java unicode properties: /\\p{javaWhitespace}/u' => ['pattern' => '/\\p{javaWhitespace}/u'];
        yield 'valid java unicode properties: /\\p{javaMirrored}/u' => ['pattern' => '/\\p{javaMirrored}/u'];
        yield 'valid unicode named character: /\\N{U+0041}/u' => ['pattern' => '/\\N{U+0041}/u'];
        yield 'allows non nested quantifiers: /(a*)(b*)/' => ['pattern' => '/(a*)(b*)/'];
        yield 'allows nested possessive quantifiers: /(a++)*+b/' => ['pattern' => '/(a++)*+b/'];
        yield 'allows nested possessive quantifiers: /([a-z]*+)++/' => ['pattern' => '/([a-z]*+)++/'];
        yield 'allows nested possessive quantifiers: /(a*+)+/' => ['pattern' => '/(a*+)+/'];
        yield 'allows symfony patterns with possessive quantifiers: /^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/' => ['pattern' => '/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+(?:\\\\[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+)++$/'];
        yield 'allows symfony patterns with possessive quantifiers: /^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/' => ['pattern' => '/^(?:[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*+\\\\)++$/'];
        yield 'allows symfony patterns with possessive quantifiers: /([^\\\\]++\\\\)++/' => ['pattern' => '/([^\\\\]++\\\\)++/'];
        yield 'allows symfony patterns with possessive quantifiers: /^(?:[-.\\w\\\\]*+:)*+\\w*+$/' => ['pattern' => '/^(?:[-.\\w\\\\]*+:)*+\\w*+$/'];
        yield 'validate valid char class: /[a-z\\d-]/' => ['pattern' => '/[a-z\\d-]/'];
        yield 'multi digit backref falls back to octal: /(a)\\11/' => ['pattern' => '/(a)\\11/'];
        yield 'multi digit backref falls back to octal: /(a)(b)\\10/' => ['pattern' => '/(a)(b)\\10/'];
        yield 'multi digit backref falls back to octal: /\\19/' => ['pattern' => '/\\19/'];
        yield 'validate valid subroutine: /(a)(?1)/' => ['pattern' => '/(a)(?1)/'];
        yield 'validate valid subroutine: /(a)(?-1)/' => ['pattern' => '/(a)(?-1)/'];
        yield 'validate valid subroutine: /(?<name>a)(?&name)/' => ['pattern' => '/(?<name>a)(?&name)/'];
        yield 'validate valid subroutine: /(?R)/' => ['pattern' => '/(?R)/'];
        yield 'allows octal zero escape in validator: /\\0/' => ['pattern' => '/\\0/'];
        yield 'validates named conditional: /(?<n>a)(?(n)b)/' => ['pattern' => '/(?<n>a)(?(n)b)/'];
        yield 'validator allows nested quantifiers: /(a+)+/' => ['pattern' => '/(a+)+/'];
        yield 'valid backreference with capturing group: /(a)\\1/' => ['pattern' => '/(a)\\1/'];
        yield 'accepts negated posix word class: /[[:^word:]]/' => ['pattern' => '/[[:^word:]]/'];
        yield 'validator posix class variations: /[[:word:]]/' => ['pattern' => '/[[:word:]]/'];
        yield 'validator posix class variations: /[[:ascii:]]/' => ['pattern' => '/[[:ascii:]]/'];
        yield 'validator posix class variations: /[[:xdigit:]]/' => ['pattern' => '/[[:xdigit:]]/'];
        yield 'validator unicode prop variations: /\\p{Ll}/' => ['pattern' => '/\\p{Ll}/'];
        yield 'validator unicode prop variations: /\\p{Lu}/' => ['pattern' => '/\\p{Lu}/'];
        yield 'validator unicode prop variations: /\\p{N}/' => ['pattern' => '/\\p{N}/'];
        yield 'validator unicode prop variations: /\\P{L}/' => ['pattern' => '/\\P{L}/'];
        yield 'validator backref edge cases: /(?<name>a)\\k<name>/' => ['pattern' => '/(?<name>a)\\k<name>/'];
        yield 'validator backref edge cases: /(a)\\g{-1}/' => ['pattern' => '/(a)\\g{-1}/'];
    }
}
