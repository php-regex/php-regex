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

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A bare "(?i)" is a setting, not a group: PCRE applies it from where it
 * stands to the end of the group around it, or of the pattern, across the
 * later branches of an alternation too. "(?i:...)" is a scope, and an empty
 * one, "(?i:)", switches nothing for what follows.
 *
 * The tree holds a setting as an inline-flags group over an empty literal;
 * neither the setting nor any other empty literal is a NUL byte.
 */
final class ExplainInlineSettingTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideTextExplanations(): iterable
    {
        // preg_match('/(?i)a/', 'A') === 1
        yield 'a setting at the top holds to the end of the pattern' => [
            'pattern' => '/(?i)a/',
            'expected' => "Regex matches\n  Inline flags 'i' (from here to the end of the pattern)\n  'a'",
        ];

        // preg_match('/(a(?i)b)c/', 'aBc') === 1, preg_match('/(a(?i)b)c/', 'aBC') === 0
        yield 'a setting in a group holds to the end of the group' => [
            'pattern' => '/(a(?i)b)c/',
            'expected' => "Regex matches\n  Capturing group\n    'a'\n    Inline flags 'i' (from here to the end of the group)\n    'b'\n  End group\n  'c'",
        ];

        // preg_match('/(?i)a|B/', 'b') === 1: the later branch is reached
        yield 'a setting reaches the later branches' => [
            'pattern' => '/(?i)a|B/',
            'expected' => "Regex matches\n  EITHER\n    Inline flags 'i' (from here to the end of the pattern)\n    'a'\n  OR\n    'B'",
        ];

        // preg_match('/(?(?=a)a(?i)b|C)D/', 'cD') === 1, preg_match('/(?(?=a)a(?i)b|c)D/', 'aBd') === 0
        yield 'a setting in a conditional holds to the end of the conditional' => [
            'pattern' => '/(?(?=a)a(?i)b|C)D/',
            'expected' => "Inline flags 'i' (from here to the end of the group)",
        ];

        // preg_match('/(*sr:a(?i)b)c/', 'aBc') === 1, preg_match('/(*sr:a(?i)b)c/', 'aBC') === 0
        yield 'a setting in a script run holds to its end' => [
            'pattern' => '/(*sr:a(?i)b)c/',
            'expected' => "Inline flags 'i' (from here to the end of the group)",
        ];

        // preg_match('/(?(DEFINE)(?i))a/', 'A') === 0
        yield 'a setting in a DEFINE block holds to its end' => [
            'pattern' => '/(?(DEFINE)(?i))a/',
            'expected' => "Inline flags 'i' (from here to the end of the group)",
        ];

        // preg_match('/(?-i)a/i', 'A') === 0
        yield 'a setting that turns a flag off' => [
            'pattern' => '/(?-i)a/i',
            'expected' => "Regex matches (with flags: i)\n  Inline flags '-i' (from here to the end of the pattern)\n  'a'",
        ];

        // preg_match('/(?^)a/i', 'A') === 0
        yield 'a setting that resets the flags' => [
            'pattern' => '/(?^)a/i',
            'expected' => "Regex matches (with flags: i)\n  Inline flags '^' (from here to the end of the pattern)\n  'a'",
        ];

        // preg_match('/(?i:)a/', 'A') === 0: an empty scope switches nothing
        yield 'an empty scope stays a group' => [
            'pattern' => '/(?i:)a/',
            'expected' => "Regex matches\n  Inline flags 'i'\n    '' (empty string)\n  End group\n  'a'",
        ];

        yield 'a scope explains its body' => [
            'pattern' => '/(?i:a)/',
            'expected' => "Regex matches\n  Inline flags 'i'\n    'a'\n  End group",
        ];

        // preg_match('/a|/', '') === 1
        yield 'an empty branch is the empty string' => [
            'pattern' => '/a|/',
            'expected' => "Regex matches\n  EITHER\n    'a'\n  OR\n    '' (empty string)",
        ];

        yield 'an empty group holds the empty string' => [
            'pattern' => '/()/',
            'expected' => "Regex matches\n  Capturing group\n    '' (empty string)\n  End group",
        ];
    }

    #[Test]
    #[DataProvider('provideTextExplanations')]
    public function test_text_explanation_of_inline_settings_and_empty_literals(string $pattern, string $expected): void
    {
        $output = RegexParser::create()->parse($pattern)->accept(new TextExplainer());

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString('\x00', $output);
    }

    #[Test]
    public function test_html_explanation_of_a_setting(): void
    {
        $output = RegexParser::create()->parse('/(a(?i)b)c|(?-i)d/')->accept(new HtmlExplainer());

        $this->assertStringContainsString('Inline flags &#039;i&#039; (from here to the end of the group)', $output);
        $this->assertStringContainsString('Inline flags &#039;-i&#039; (from here to the end of the pattern)', $output);
        $this->assertStringNotContainsString('<strong>Inline flags &#039;i&#039;:</strong>', $output);
        $this->assertStringNotContainsString('\x00', $output);
    }

    /**
     * A conditional, a DEFINE block and a script run are groups a setting
     * ends with, as the text explanation says.
     */
    #[Test]
    #[DataProvider('provideHtmlGroupsOfOtherKinds')]
    public function test_html_explanation_of_a_setting_in_other_groups(string $pattern): void
    {
        $output = RegexParser::create()->parse($pattern)->accept(new HtmlExplainer());

        $this->assertStringContainsString('Inline flags &#039;i&#039; (from here to the end of the group)', $output);
        $this->assertStringNotContainsString('end of the pattern', $output);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideHtmlGroupsOfOtherKinds(): iterable
    {
        yield 'in the yes branch of a conditional' => ['pattern' => '/(?(?=a)a(?i)b)D/'];
        yield 'in the no branch of a conditional' => ['pattern' => '/(?(?=a)a|(?i)c)D/'];
        yield 'in a DEFINE block' => ['pattern' => '/(?(DEFINE)(?i))a/'];
        yield 'in a script run' => ['pattern' => '/(*sr:a(?i)b)c/'];
    }

    #[Test]
    public function test_html_explanation_of_an_empty_scope_and_an_empty_branch(): void
    {
        $output = RegexParser::create()->parse('/(?i:)a|/')->accept(new HtmlExplainer());

        $this->assertStringContainsString('<strong>Inline flags &#039;i&#039;:</strong>', $output);
        $this->assertStringContainsString('<strong>&#039;&#039; (empty string)</strong>', $output);
        $this->assertStringNotContainsString('\x00', $output);
    }
}
