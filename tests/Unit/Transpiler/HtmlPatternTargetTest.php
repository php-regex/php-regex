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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The HTML pattern attribute matches the whole value under the v flag:
 * "^(?:" . pattern . ")$" (WHATWG HTML, the pattern attribute). Each value
 * below was checked with Node 24, new RegExp("^(?:" + value + ")$", "v"),
 * on the subjects listed: it accepts exactly those preg_match() matches.
 */
final class HtmlPatternTargetTest extends TestCase
{
    /**
     * @param array<int|string, bool> $verdicts subject => whether preg_match() and the attribute accept it
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_attribute_accepts_what_preg_match_matches(string $pattern, string $attribute, array $verdicts): void
    {
        $result = Regex::create(['cache' => null])->transpile($pattern, 'html-pattern');

        $this->assertSame($attribute, $result->pattern);
        $this->assertSame($attribute, $result->literal);
        $this->assertSame('v', $result->flags);
        foreach ($verdicts as $subject => $accepted) {
            $this->assertSame($accepted ? 1 : 0, preg_match($pattern, (string) $subject), $pattern.' on '.$subject);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, attribute: string, verdicts: array<int|string, bool>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a search' => ['pattern' => '/\d+/', 'attribute' => '[\s\S]*(?:\d+)[\s\S]*', 'verdicts' => ['a1b' => true, 'ab' => false]];
        yield 'anchored both ends' => ['pattern' => '/^\d+$/', 'attribute' => '^\d+$', 'verdicts' => ['12' => true, '1a' => false]];
        yield 'subject anchors' => ['pattern' => '/\A[a-z]+\z/u', 'attribute' => '^[a-z]+$', 'verdicts' => ['abc' => true, 'ab1' => false]];
        yield 'anchored at the start only' => ['pattern' => '/^ab/', 'attribute' => '(?:^ab)[\s\S]*', 'verdicts' => ['abc' => true, 'cab' => false]];
        yield 'parentheses in a class' => ['pattern' => '/^[(a)]+$/', 'attribute' => '^[\(a\)]+$', 'verdicts' => ['(a)' => true, 'b' => false]];
        yield 'a hyphen and a dot in a class' => ['pattern' => '/^[\w.-]+@[\w-]+\.com$/', 'attribute' => '^[\w\.\-]+@[\w\-]+\.com$', 'verdicts' => ['a.b-c@d-e.com' => true, 'a@b.org' => false]];
        yield 'an alternation anchored on one side each' => ['pattern' => '/^a|b$/', 'attribute' => '[\s\S]*(?:^a|b$)[\s\S]*', 'verdicts' => ['ax' => true, 'xb' => true, 'xa' => false]];
        yield 'brackets in a class' => ['pattern' => '/^[\w\[\]]+$/', 'attribute' => '^[\w\[\]]+$', 'verdicts' => ['a[b]' => true, 'a(b)' => false]];
        yield 'an opening bracket alone in a class' => ['pattern' => '/^[^[]+$/', 'attribute' => '^[^\[]+$', 'verdicts' => ['ab' => true, 'a[b' => false]];
        yield 'a property' => ['pattern' => '/^\p{L}+$/u', 'attribute' => '^\p{L}+$', 'verdicts' => ['été' => true, 'é1' => false]];
    }

    #[Test]
    public function test_the_caseless_flag_cannot_be_carried(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('The HTML pattern attribute takes no flags');

        Regex::create(['cache' => null])->transpile('/abc/i', 'html-pattern');
    }

    #[Test]
    public function test_the_line_flags_change_nothing_in_a_field_value(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^a.b$/sm', 'html-pattern');

        $this->assertSame('^a.b$', $result->pattern);
        $this->assertContains('A field value holds no line break: /s and /m change nothing there.', $result->notes);
    }

    #[Test]
    public function test_extended_mode_is_applied_and_noted(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^ a b $/x', 'html-pattern');

        $this->assertSame('^ab$', $result->pattern);
        $this->assertContains('Applied /x (extended mode): whitespace and comments were removed during compilation.', $result->notes);
    }

    #[Test]
    public function test_the_constructor_is_the_regexp_the_browser_builds(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^\d+$/', 'html');

        $this->assertSame('html-pattern', $result->target);
        $this->assertSame('new RegExp("^(?:^\\\\d+$)$", "v")', $result->constructor);
    }
}
