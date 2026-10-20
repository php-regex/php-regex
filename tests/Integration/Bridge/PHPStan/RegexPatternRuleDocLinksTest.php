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

use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\PHPStan\PatternChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every "Read more" link of an issue lands on a page of php-regex.com the
 * docs tree builds, on a heading that page generates: an anchor the site
 * does not generate opens the top of the page, a page the restructure
 * removed opens a 404. The link of a lint issue is the one the rule
 * catalogue gives every report.
 */
final class RegexPatternRuleDocLinksTest extends TestCase
{
    private const SITE = 'https://php-regex.com/';

    private const DOCS_ROOT = __DIR__.'/../../../../docs/';

    #[Test]
    public function test_every_concept_link_lands_on_an_existing_page_and_heading(): void
    {
        $anchorsByPage = [];

        foreach (self::constantMap('DOC_LINKS') as $concept => $url) {
            $page = self::docsPage($concept, $url);
            $anchorsByPage[$page] ??= self::headingAnchors($page);

            $fragment = (string) parse_url($url, \PHP_URL_FRAGMENT);
            $this->assertContains($fragment, $anchorsByPage[$page], \sprintf('%s links to %s#%s, which no heading of docs/%s generates.', $concept, $page, $fragment, $page));
        }
    }

    /**
     * The lint issues PHPStan reports link where every other report links
     * them, to the docs site.
     */
    #[Test]
    public function test_the_lint_rules_link_to_the_docs_site(): void
    {
        $this->assertFalse((new \ReflectionClass(PatternChecker::class))->hasConstant('LINT_DOC_LINKS'));

        foreach ([
            'regex.lint.quantifier.lazyEnd' => 'https://php-regex.com/reference/rules/#lazy-quantifier-at-the-end',
            'regex.lint.charclass.literalMetachar' => 'https://php-regex.com/reference/rules/#literal-metacharacter-in-a-character-class',
            'regex.lint.unicode.multibyteInClassWithoutU' => 'https://php-regex.com/reference/rules/#multibyte-character-in-a-class',
            'regex.lint.unicode.quantifiedMultibyteWithoutU' => 'https://php-regex.com/reference/rules/#quantifier-after-a-multibyte-character',
            'regex.lint.unicode.propertyWithoutU' => 'https://php-regex.com/reference/rules/#unicode-property-without-u',
            'regex.lint.complexity' => 'https://php-regex.com/reference/rules/#complexity-threshold',
        ] as $issueId => $url) {
            $this->assertSame($url, RuleCatalog::docsUrl($issueId), $issueId);
        }
    }

    /**
     * The concepts a fix suggests link to the pages that teach them since
     * the reference stopped repeating the tutorial.
     */
    #[Test]
    public function test_the_advanced_concepts_link_to_their_tutorial_chapters(): void
    {
        $links = self::constantMap('DOC_LINKS');

        $this->assertSame(self::SITE.'tutorial/04-quantifiers/#possessive-quantifiers-performance', $links['possessive quantifiers']);
        $this->assertSame(self::SITE.'tutorial/08-performance-redos/#1-atomic-groups-', $links['atomic groups']);
        $this->assertSame(self::SITE.'reference/rules/#catastrophic-backtracking', $links['catastrophic backtracking']);
    }

    /**
     * The concept links are the ones a ReDoS tip cites, and no other: a
     * link no message reads is a link nobody keeps alive.
     */
    #[Test]
    public function test_the_concept_links_are_the_ones_a_tip_cites(): void
    {
        $concepts = array_keys(self::constantMap('DOC_LINKS'));
        sort($concepts);

        $this->assertSame(['atomic groups', 'catastrophic backtracking', 'possessive quantifiers'], $concepts);
    }

    /**
     * A map constant of PatternChecker, asserted into a map of strings.
     *
     * @return array<string, string>
     */
    private static function constantMap(string $name): array
    {
        $map = (new \ReflectionClass(PatternChecker::class))->getConstant($name);
        self::assertIsArray($map);

        $typed = [];
        foreach ($map as $key => $url) {
            self::assertIsString($key);
            self::assertIsString($url);
            $typed[$key] = $url;
        }

        return $typed;
    }

    /**
     * The docs page a URL of the site addresses, relative to docs/.
     */
    private static function docsPage(string $concept, string $url): string
    {
        self::assertSame(1, preg_match('~^'.preg_quote(self::SITE, '~').'((?:[a-z0-9-]+/)+)#~', $url, $parts), \sprintf('%s does not link to a page of the docs site.', $concept));

        return rtrim($parts[1], '/').'.md';
    }

    /**
     * The anchors the site generates: lower case, punctuation dropped,
     * spaces turned into hyphens, a repeated heading suffixed "-1", "-2"...
     *
     * @return list<string>
     */
    private static function headingAnchors(string $page): array
    {
        $path = self::DOCS_ROOT.$page;
        self::assertFileExists($path, \sprintf('The docs page %s does not exist.', $page));

        $markdown = (string) file_get_contents($path);
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
