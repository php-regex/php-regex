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

use PHPRegex\PHPStan\PatternChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every "Read more" link of an issue lands on a page of the docs that
 * exists, on a heading that page generates: an anchor GitHub does not
 * generate opens the top of the page, a page the restructure removed
 * opens a 404.
 */
final class RegexPatternRuleDocLinksTest extends TestCase
{
    private const REPO_URL_PREFIX = 'https://github.com/php-regex/php-regex/blob/2.x/';

    private const DOCS_ROOT = __DIR__.'/../../../../docs/';

    #[Test]
    public function test_every_doc_link_lands_on_an_existing_page_and_heading(): void
    {
        $anchorsByPage = [];

        foreach (self::allDocLinks() as $issueId => $url) {
            $page = self::docsPage($issueId, $url);
            $anchorsByPage[$page] ??= self::headingAnchors($page);

            $fragment = (string) parse_url($url, \PHP_URL_FRAGMENT);
            $this->assertContains($fragment, $anchorsByPage[$page], \sprintf('%s links to %s#%s, which no heading of docs/%s generates.', $issueId, $page, $fragment, $page));
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
     * The rules taken from SonarPHP's regex checks each link to their
     * section of the reference.
     */
    #[Test]
    public function test_the_sonar_parity_rules_have_a_doc_link(): void
    {
        $links = self::lintDocLinks();

        foreach ([
            'regex.lint.quantifier.emptyRepeat',
            'regex.lint.anchor.alternationPrecedence',
            'regex.lint.quantifier.possessiveImpossible',
            'regex.lint.anchor.impossible.boundary',
            'regex.lint.lookaround.impossible',
            'regex.lint.group.empty',
            'regex.lint.charclass.single',
            'regex.lint.literal.multipleSpaces',
            'regex.lint.quantifier.lazyToClass',
        ] as $issueId) {
            $this->assertArrayHasKey($issueId, $links, \sprintf('%s has no "Read more" link.', $issueId));
        }
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
     * The concepts a fix suggests link to the pages that teach them since
     * the reference stopped repeating the tutorial.
     */
    #[Test]
    public function test_the_advanced_concepts_link_to_their_tutorial_chapters(): void
    {
        $links = self::constantMap('DOC_LINKS');

        $this->assertSame(self::REPO_URL_PREFIX.'docs/tutorial/04-quantifiers.md#possessive-quantifiers-performance', $links['possessive quantifiers']);
        $this->assertSame(self::REPO_URL_PREFIX.'docs/tutorial/08-performance-redos.md#1-atomic-groups-', $links['atomic groups']);
        $this->assertSame(self::REPO_URL_PREFIX.'docs/tutorial/06-lookarounds.md#types-of-lookarounds', $links['lookahead']);
        $this->assertSame(self::REPO_URL_PREFIX.'docs/tutorial/06-lookarounds.md#types-of-lookarounds', $links['lookbehind']);
    }

    /**
     * @return array<string, string>
     */
    private static function allDocLinks(): array
    {
        return array_merge(
            self::constantMap('DOC_LINKS'),
            self::lintDocLinks(),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function lintDocLinks(): array
    {
        return self::constantMap('LINT_DOC_LINKS');
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

        return self::typedStringMap($map);
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, string>
     */
    private static function typedStringMap(array $map): array
    {
        $typed = [];
        foreach ($map as $key => $url) {
            self::assertIsString($key);
            self::assertIsString($url);
            $typed[$key] = $url;
        }

        return $typed;
    }

    /**
     * The docs page a URL addresses, relative to docs/.
     */
    private static function docsPage(string $issueId, string $url): string
    {
        self::assertStringStartsWith(self::REPO_URL_PREFIX.'docs/', $url, \sprintf('%s does not link into the docs tree.', $issueId));

        return substr($url, \strlen(self::REPO_URL_PREFIX.'docs/'), (int) (strpos($url, '#') ?: \strlen($url)) - \strlen(self::REPO_URL_PREFIX.'docs/'));
    }

    /**
     * The anchors GitHub generates: lower case, punctuation dropped, spaces
     * turned into hyphens, a repeated heading suffixed "-1", "-2"...
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
