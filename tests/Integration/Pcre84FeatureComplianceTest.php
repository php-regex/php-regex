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

namespace PHPRegex\Tests\Integration;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pcre84FeatureComplianceTest extends TestCase
{
    private Regex $regex;

    private Validator $validator;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->validator = new Validator();
    }

    #[DataProvider('provideOpenLowerQuantifiers')]
    public function test_open_lower_quantifiers_are_parsed_and_validated(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public static function provideOpenLowerQuantifiers(): iterable
    {
        yield '{,n} no spaces' => ['/a{,3}/'];
        yield '{,n} with spaces' => ['/b{ , 5 }/'];
        yield '{n,} with spaces' => ['/c{ 2 , }/'];
    }

    #[DataProvider('provideNewlineConventionVerbs')]
    public function test_newline_convention_verbs_are_supported(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public static function provideNewlineConventionVerbs(): iterable
    {
        yield 'CR' => ['/(*CR)foo/'];
        yield 'LF' => ['/(*LF)bar/'];
        yield 'CRLF' => ['/(*CRLF)baz/'];
    }

    #[DataProvider('provideControlVerbs')]
    public function test_control_verbs_are_supported(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public static function provideControlVerbs(): iterable
    {
        yield 'MARK' => ['/(*MARK:here)A/'];
        yield 'PRUNE' => ['/(*PRUNE)B/'];
        yield 'SKIP' => ['/(*SKIP)C/'];
        yield 'THEN' => ['/(*THEN)D/'];
    }

    #[DataProvider('provideEncodingVerbs')]
    public function test_encoding_verbs_are_supported(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public static function provideEncodingVerbs(): iterable
    {
        yield 'UTF8' => ['/(*UTF8)ä/'];
        yield 'UCP' => ['/(*UCP)\\w+/'];
    }

    #[DataProvider('provideMatchControlVerbs')]
    public function test_match_control_verbs_are_supported(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public static function provideMatchControlVerbs(): iterable
    {
        yield 'NOTEMPTY' => ['/(*NOTEMPTY)a?/'];
        yield 'NOTEMPTY_ATSTART' => ['/(*NOTEMPTY_ATSTART)^foo/'];
    }

    public function test_backslash_r_is_treated_as_char_type_not_backreference(): void
    {
        $ast = $this->regex->parse('/\R/');
        $ast->accept($this->validator);

        $dump = $ast->accept(new NodeDumper());
        $this->assertStringContainsString("CharType('\\R')", $dump);
        $this->assertStringNotContainsString('Backref', $dump);
    }

    public function test_possessive_quantifier_after_char_class_is_supported(): void
    {
        $ast = $this->regex->parse('/[ab]++c/');
        $ast->accept($this->validator);

        $dump = $ast->accept(new NodeDumper());
        $this->assertStringContainsString('type: possessive', $dump);
    }

    #[DataProvider('provideUnicodeProperties')]
    public function test_extended_unicode_properties_are_validated(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);
        $this->assertInstanceOf(RegexNode::class, $ast);
    }

    public static function provideUnicodeProperties(): iterable
    {
        yield 'general category' => ['/\\p{L}+/'];
        yield 'script property' => ['/\\p{Script=Greek}+$/'];
        yield 'block property' => ['/\\p{Block=Basic_Latin}/'];
    }

    #[DataProvider('provideCallouts')]
    public function test_callout_syntax_is_parsed(string $pattern): void
    {
        $ast = $this->regex->parse($pattern);
        $ast->accept($this->validator);

        $dump = $ast->accept(new NodeDumper());
        $this->assertStringContainsString('Callout', $dump);
    }

    public static function provideCallouts(): iterable
    {
        yield 'bare callout' => ['/(?C)foo/'];
        yield 'numeric callout' => ['/(?C42)bar/'];
        yield 'string callout' => ['/(?C"handler")baz/'];
    }

    /**
     * Rows that once sat in provideCallouts(), on a pattern PHP refuses.
     */
    #[DataProvider('provideCalloutsPhpRefuses')]
    public function test_callout_php_refuses_is_reported_invalid(string $pattern): void
    {
        $this->assertFalse($this->regex->validate($pattern)->isValid);
    }

    public static function provideCalloutsPhpRefuses(): iterable
    {
        // preg_match() on PCRE2 10.48: "unrecognized string delimiter follows (?C at offset 4".
        yield 'named callout' => ['/(?CmyHandler)qux/'];
    }
}
