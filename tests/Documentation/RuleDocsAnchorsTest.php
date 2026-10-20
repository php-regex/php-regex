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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Linter\Rule\RuleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The link each issue carries lands on a page of php-regex.com that the
 * docs tree builds, on a heading that page generates, and that heading's
 * section names the issue: an anchor the site does not generate opens the
 * top of the page, a page the docs no longer hold opens a 404.
 */
final class RuleDocsAnchorsTest extends TestCase
{
    private const SITE = 'https://php-regex.com/';

    private const DOCS_ROOT = __DIR__.'/../../docs/';

    #[Test]
    #[DataProvider('provideDocsUrls')]
    public function test_the_link_of_an_issue_lands_on_a_heading_whose_section_names_it(string $id, string $url): void
    {
        $this->assertStringStartsWith(self::SITE, $url);
        $this->assertSame(1, preg_match('~^'.preg_quote(self::SITE, '~').'((?:[a-z0-9-]+/)+)#([a-z0-9-]+)$~', $url, $parts), $url.' is no page of the site with an anchor.');

        $page = self::DOCS_ROOT.rtrim($parts[1], '/').'.md';
        $this->assertFileExists($page, \sprintf('%s links to %s, which no page of docs/ builds.', $id, $url));

        $sections = self::sections((string) file_get_contents($page));
        $this->assertArrayHasKey($parts[2], $sections, \sprintf('%s links to #%s, which no heading of %s generates.', $id, $parts[2], $page));
        $this->assertStringContainsString('`'.$id.'`', $sections[$parts[2]], \sprintf('The section #%s does not name %s.', $parts[2], $id));
    }

    /**
     * @return iterable<string, array{id: string, url: string}>
     */
    public static function provideDocsUrls(): iterable
    {
        foreach (RuleCatalog::docsUrls() as $id => $url) {
            yield $id => ['id' => $id, 'url' => $url];
        }
    }

    /**
     * The text under each heading, up to the next heading of its level or
     * above, by the anchor the site generates for it: lower case,
     * punctuation dropped, spaces turned into hyphens, a repeated heading
     * suffixed "-1", "-2"...
     *
     * @return array<string, string>
     */
    private static function sections(string $markdown): array
    {
        // Headings inside fenced code blocks are no headings.
        $markdown = (string) preg_replace_callback('/^```.*?^```/ms', static fn (array $block): string => str_replace('#', ' ', $block[0]), $markdown);
        preg_match_all('/^(#{1,6})\s+(.+?)\s*#*$/m', $markdown, $headings, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        $sections = [];
        $seen = [];
        foreach ($headings as $i => $heading) {
            $level = \strlen($heading[1][0]);
            $end = \strlen($markdown);
            foreach (\array_slice($headings, $i + 1) as $next) {
                if (\strlen($next[1][0]) <= $level) {
                    $end = $next[0][1];

                    break;
                }
            }

            $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(strip_tags($heading[2][0]))));
            $anchor = isset($seen[$slug]) ? $slug.'-'.$seen[$slug] : $slug;
            $seen[$slug] = ($seen[$slug] ?? 0) + 1;
            $sections[$anchor] = substr($markdown, $heading[0][1], $end - $heading[0][1]);
        }

        return $sections;
    }
}
