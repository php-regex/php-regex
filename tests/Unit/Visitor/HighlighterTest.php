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

namespace PHPRegex\Tests\Unit\Visitor;

use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class HighlighterTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    public function test_highlight_cli_contains_ansi_codes(): void
    {
        $highlighted = $this->highlight('/a+/', 'cli');

        $this->assertStringContainsString('a', $highlighted);
        $this->assertStringContainsString("\033[", $highlighted); // ANSI escape code
        $this->assertStringContainsString("\033[0m", $highlighted); // Reset
    }

    public function test_highlight_html_contains_span_tags(): void
    {
        $highlighted = $this->highlight('/a+/', 'html');

        $this->assertStringContainsString('<span', $highlighted);
        $this->assertStringContainsString('</span>', $highlighted);
        $this->assertStringContainsString('regex-token', $highlighted);
        $this->assertStringContainsString('regex-literal', $highlighted);
        $this->assertStringContainsString('regex-quantifier', $highlighted);
    }

    public function test_highlight_html_escapes_special_chars(): void
    {
        $highlighted = $this->highlight('/<script>/', 'html');

        $this->assertStringContainsString('&lt;', $highlighted);
        $this->assertStringContainsString('&gt;', $highlighted);
        $this->assertStringNotContainsString('<script>', $highlighted);
    }

    public function test_highlight_cli_complex_pattern(): void
    {
        $highlighted = $this->highlight('/^[0-9]+(\w+)$/', 'cli');

        $this->assertStringContainsString("\033[38;2;86;156;214m", $highlighted); // Meta chars
        $this->assertStringContainsString("\033[38;2;215;186;125m", $highlighted); // Quantifiers
        $this->assertStringContainsString("\033[38;2;78;201;176m", $highlighted); // Escapes
    }

    public function test_highlight_html_complex_pattern(): void
    {
        $highlighted = $this->highlight('/^[0-9]+(\w+)$/', 'html');

        $this->assertStringContainsString('regex-meta', $highlighted);
        $this->assertStringContainsString('regex-quantifier', $highlighted);
        $this->assertStringContainsString('regex-type', $highlighted);
        $this->assertStringContainsString('regex-anchor', $highlighted);
    }

    public function test_highlight_with_unicode_and_special_chars(): void
    {
        // Test Unicode, control chars, assertions, backrefs, anchors, etc.
        $pattern = '/^\A\z\b\B\x00\cA\u{1F600}\1$/';
        $highlightedCli = $this->highlight($pattern, 'cli');
        $highlightedHtml = $this->highlight($pattern, 'html');

        $this->assertNotEmpty($highlightedCli);
        $this->assertNotEmpty($highlightedHtml);
        $this->assertStringContainsString('regex-anchor', $highlightedHtml);
        $this->assertStringContainsString('regex-type', $highlightedHtml);
    }

    public function test_highlight_with_character_classes(): void
    {
        // Test char classes, ranges, POSIX classes, Unicode props
        $pattern = '/[a-z0-9] [\p{L}\p{N}] [[:alpha:]]/';
        $highlightedCli = $this->highlight($pattern, 'cli');
        $highlightedHtml = $this->highlight($pattern, 'html');

        $this->assertNotEmpty($highlightedCli);
        $this->assertNotEmpty($highlightedHtml);
        $this->assertStringContainsString('regex-meta', $highlightedHtml); // [ and ]
    }

    public function test_highlight_with_quantifiers_and_modifiers(): void
    {
        // Test different quantifier types
        $pattern = '/a* a+ a? a{2,3} a{4,} a{5} a*? a+? a?? a{2,3}?/';
        $highlightedCli = $this->highlight($pattern, 'cli');
        $highlightedHtml = $this->highlight($pattern, 'html');

        $this->assertNotEmpty($highlightedCli);
        $this->assertNotEmpty($highlightedHtml);
        $this->assertStringContainsString('regex-quantifier', $highlightedHtml);
    }

    public function test_highlight_with_groups_and_conditionals(): void
    {
        // Test groups, conditionals, subroutines
        $pattern = '/(a)(?<name>b)(?1)(?&name)(?(1)yes|no)/';
        $highlightedCli = $this->highlight($pattern, 'cli');
        $highlightedHtml = $this->highlight($pattern, 'html');

        $this->assertNotEmpty($highlightedCli);
        $this->assertNotEmpty($highlightedHtml);
    }

    public function test_highlight_with_pcre_verbs_and_special(): void
    {
        // Test PCRE verbs, comments, defines, etc.
        $pattern = '/(*FAIL)(?#comment)(?(DEFINE)a)\K(*LIMIT_MATCH=5)/';
        $highlightedCli = $this->highlight($pattern, 'cli');
        $highlightedHtml = $this->highlight($pattern, 'html');

        $this->assertNotEmpty($highlightedCli);
        $this->assertNotEmpty($highlightedHtml);
        $this->assertStringContainsString('regex-meta', $highlightedHtml);
        $this->assertStringContainsString('regex-token', $highlightedHtml);
        $this->assertStringContainsString('regex-comment', $highlightedHtml);
    }

    public function test_highlight_with_class_operations(): void
    {
        // PHP has no class operations: "&&" is two members and "!--" a range.
        $pattern = '/[a&&b] [!--]/';
        $highlightedCli = $this->highlight($pattern, 'cli');
        $highlightedHtml = $this->highlight($pattern, 'html');

        $this->assertNotEmpty($highlightedCli);
        $this->assertNotEmpty($highlightedHtml);
    }

    private function highlight(string $pattern, string $format): string
    {
        $visitor = match ($format) {
            'cli' => new ConsoleHighlighter(),
            'html' => new HtmlHighlighter(),
            default => throw new \InvalidArgumentException("Invalid format: $format"),
        };

        return $this->regexService->parse($pattern)->accept($visitor);
    }
}
