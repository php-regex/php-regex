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
 * The condition of "(?(1)...)" tests whether a group has matched, and the
 * one of "(?(R)...)" whether a recursion is in progress: neither matches
 * anything, as the backreference or the call it looks like would.
 */
final class ExplainConditionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideConditions')]
    public function test_a_condition_is_explained_as_the_test_it_makes(string $pattern, string $condition, string $subject, int $matches): void
    {
        // The engine: the subject tells the test described from a match.
        $this->assertSame($matches, preg_match($pattern, $subject), 'engine');

        $tree = RegexParser::create()->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer());
        $html = html_entity_decode((string) $tree->accept(new HtmlExplainer()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $this->assertMatchesRegularExpression('/^ *IF \('.preg_quote($condition, '/').'\) THEN$/m', $text, 'text');
        $this->assertStringContainsString('IF ('.$condition.')', strip_tags($html), 'html');
        $this->assertStringNotContainsString('Backreference', $text.$html);
        $this->assertStringNotContainsString('IF (Subroutine', $text.strip_tags($html));
    }

    /**
     * @return iterable<string, array{pattern: string, condition: string, subject: string, matches: int}>
     */
    public static function provideConditions(): iterable
    {
        // "c" alone: group 1 has not matched, so the else branch is taken.
        yield 'a numbered group' => ['pattern' => '/^(a)?(?(1)b|c)$/', 'condition' => 'the capturing group "1" has matched', 'subject' => 'ac', 'matches' => 0];
        yield 'a numbered group not matched' => ['pattern' => '/^(a)?(?(1)b|c)$/', 'condition' => 'the capturing group "1" has matched', 'subject' => 'c', 'matches' => 1];
        yield 'a named group in angle brackets' => ['pattern' => '/^(?<n>a)?(?(<n>)b|c)$/', 'condition' => 'the capturing group "n" has matched', 'subject' => 'ab', 'matches' => 1];
        yield 'a named group in quotes' => ['pattern' => "/^(?<n>a)?(?('n')b|c)$/", 'condition' => 'the capturing group "n" has matched', 'subject' => 'ac', 'matches' => 0];
        yield 'a bare name' => ['pattern' => '/^(?<n>a)?(?(n)b|c)$/', 'condition' => 'the capturing group "n" has matched', 'subject' => 'c', 'matches' => 1];
        yield 'a group counted back' => ['pattern' => '/^(a)?(?(-1)b|c)$/', 'condition' => 'the capturing group 1 back from here has matched', 'subject' => 'ab', 'matches' => 1];
        yield 'a group counted ahead' => ['pattern' => '/^(?(+1)b|c)(a)?$/', 'condition' => 'the capturing group 1 ahead of here has matched', 'subject' => 'c', 'matches' => 1];
        yield 'any recursion' => ['pattern' => '/(?(R)a|b)/', 'condition' => 'a recursion is in progress, into the whole pattern or any group', 'subject' => 'a', 'matches' => 0];
        yield 'a recursion into a numbered group' => ['pattern' => '/^(x(?1)?|(?(R1)y|z))$/', 'condition' => 'the most recent recursion is into the capturing group "1"', 'subject' => 'xy', 'matches' => 1];
        yield 'a recursion into a named group' => ['pattern' => '/^(?<n>x(?&n)|(?(R&n)y|z))$/', 'condition' => 'the most recent recursion is into the capturing group "n"', 'subject' => 'xz', 'matches' => 0];
    }

    #[Test]
    public function test_the_condition_is_shown_without_the_indentation_of_its_line(): void
    {
        $text = (string) RegexParser::create()->parse('/(a)(?(1)b|c)/')->accept(new TextExplainer());

        $this->assertStringContainsString("\n  IF (the capturing group \"1\" has matched) THEN\n    'b'\n  ELSE\n    'c'", $text);
    }
}
