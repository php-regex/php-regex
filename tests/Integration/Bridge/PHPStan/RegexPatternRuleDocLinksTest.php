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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every "Read more" link of a lint issue lands on a heading of the
 * reference: an anchor GitHub does not generate opens the top of the page.
 */
final class RegexPatternRuleDocLinksTest extends TestCase
{
    private const REFERENCE = __DIR__.'/../../../../docs/reference.md';

    #[Test]
    public function test_every_lint_doc_link_names_a_heading_of_the_reference(): void
    {
        $anchors = self::headingAnchors();

        foreach (self::lintDocLinks() as $issueId => $url) {
            $fragment = (string) parse_url($url, \PHP_URL_FRAGMENT);
            $this->assertContains($fragment, $anchors, \sprintf('%s links to #%s, which no heading of docs/reference.md generates.', $issueId, $fragment));
        }
    }

    #[Test]
    public function test_lazy_end_and_literal_metachar_have_a_doc_link(): void
    {
        $links = self::lintDocLinks();

        $this->assertArrayHasKey('regex.lint.quantifier.lazyEnd', $links);
        $this->assertArrayHasKey('regex.lint.charclass.literalMetachar', $links);
    }

    /**
     * The rules of error severity fail a lint run, so each one links to the
     * page that explains it.
     */
    #[Test]
    public function test_the_rules_that_fail_a_lint_run_have_a_doc_link(): void
    {
        $links = self::lintDocLinks();

        $this->assertStringEndsWith('#multibyte-character-in-a-class', $links['regex.lint.unicode.multibyteInClassWithoutU'] ?? '');
        $this->assertStringEndsWith('#quantifier-after-a-multibyte-character', $links['regex.lint.unicode.quantifiedMultibyteWithoutU'] ?? '');
        $this->assertStringEndsWith('#bytes-without-u', $links['regex.lint.unicode.propertyWithoutU'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    private static function lintDocLinks(): array
    {
        $links = (new \ReflectionClass(RegexPatternRule::class))->getConstant('LINT_DOC_LINKS');
        self::assertIsArray($links);

        $typed = [];
        foreach ($links as $issueId => $url) {
            self::assertIsString($issueId);
            self::assertIsString($url);
            $typed[$issueId] = $url;
        }

        return $typed;
    }

    /**
     * The anchors GitHub generates: lower case, punctuation dropped, spaces
     * turned into hyphens, a repeated heading suffixed "-1", "-2"...
     *
     * @return list<string>
     */
    private static function headingAnchors(): array
    {
        $markdown = file_get_contents(self::REFERENCE);
        self::assertIsString($markdown);
        // Headings inside fenced code blocks are no headings.
        $markdown = (string) preg_replace('/^```.*?^```/ms', '', $markdown);
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*$/m', $markdown, $matches);

        $anchors = [];
        $seen = [];
        foreach ($matches[1] as $heading) {
            $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(strip_tags($heading))));
            $anchor = isset($seen[$slug]) ? $slug.'-'.$seen[$slug] : $slug;
            $seen[$slug] = ($seen[$slug] ?? 0) + 1;
            $anchors[] = $anchor;
        }

        return $anchors;
    }
}
