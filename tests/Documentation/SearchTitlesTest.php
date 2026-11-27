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

use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\Support\FrontMatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a search result shows of a page of the site: its title tag and its
 * description. A title tag carries the page's heading, or the title the
 * page names in `head_title` when the heading is a label ("Psalm",
 * "Chapter 7: ..."), and the site's name after it. Both stay inside the
 * room a result page gives them — a title is cut past sixty characters, a
 * description past a hundred and sixty — and long enough to say
 * something. No two pages say the same thing twice there.
 *
 * The error page has no result of its own, the articles are drafts until
 * a hand validates them, and the pages of the projects are written by the
 * corpus run.
 */
#[Group('documentation')]
final class SearchTitlesTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    private const SITE_NAME = ' — PHPRegex';

    private const OUTSIDE = [
        '404.html',
        'search-index.html',
    ];

    #[Test]
    #[DataProvider('providePages')]
    public function test_a_page_titles_itself_for_a_search_result(string $page, string $title): void
    {
        $shown = $this->headTitle($page) ?? $title;

        $tag = $shown.self::SITE_NAME;
        $this->assertGreaterThanOrEqual(30, mb_strlen($tag), $page.': the title tag says more than a label.');
        $this->assertLessThanOrEqual(60, mb_strlen($tag), $page.': the title tag is not cut by the result page.');
        $this->assertDoesNotMatchRegularExpression('/[`<>]/', $shown, $page.': a title tag holds no markup.');
    }

    #[Test]
    #[DataProvider('providePages')]
    public function test_a_page_describes_itself_for_a_search_result(string $page, string $title): void
    {
        $description = self::frontMatter($page)['description'] ?? null;

        $this->assertIsString($description, $page.' has a description.');
        $this->assertMatchesRegularExpression('/^.{70,160}$/su', $description, $page.': a description a search result keeps whole.');
    }

    #[Test]
    public function test_no_two_pages_say_the_same_thing_to_a_search_result(): void
    {
        $titles = $descriptions = [];
        foreach (array_keys(self::titles()) as $page) {
            $title = $this->headTitle($page) ?? self::titles()[$page];
            $this->assertArrayNotHasKey($title, $titles, $page.' shares its title with '.($titles[$title] ?? '').'.');
            $titles[$title] = $page;
            $description = self::frontMatter($page)['description'] ?? null;
            $this->assertIsString($description, $page.' has a description.');
            $this->assertArrayNotHasKey($description, $descriptions, $page.' shares its description with '.($descriptions[$description] ?? '').'.');
            $descriptions[$description] = $page;
        }
    }

    /**
     * Every page of the site but those the class tells of.
     *
     * @return iterable<string, array{page: string, title: string}>
     */
    public static function providePages(): iterable
    {
        foreach (self::titles() as $page => $title) {
            \assert(\is_string($title));

            yield $page => ['page' => $page, 'title' => $title];
        }
    }

    /**
     * The title of each page as the site reads it from its source: the
     * `title` of its front matter, or its first heading.
     *
     * @return array<string, string>
     */
    private static function titles(): array
    {
        $titles = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DOCS, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'md' !== $file->getExtension()) {
                continue;
            }
            $page = Path::fromHost(substr($file->getPathname(), \strlen(self::DOCS)));
            if (\in_array($page, self::OUTSIDE, true) || str_starts_with($page, '_') || 'README.md' === $page) {
                continue;
            }

            $text = (string) file_get_contents($file->getPathname());
            $title = self::frontMatter($page)['title'] ?? self::firstHeading($text);
            \assert(\is_string($title) || null === $title, $page.' names its title as one line.');
            $titles[$page] = $title ?? '';
        }

        \assert([] !== $titles, 'The site has pages.');

        return $titles;
    }

    private static function firstHeading(string $text): ?string
    {
        // Headings inside fenced code blocks are no headings.
        $body = (string) preg_replace('/^```.*?^```/ms', '', (string) preg_replace('/\A---\n.*?\n---\n/s', '', $text));

        return 1 === preg_match('/^# (.+)$/m', $body, $heading) ? $heading[1] : null;
    }

    private static function headTitle(string $page): ?string
    {
        $title = self::frontMatter($page)['head_title'] ?? null;
        \assert(null === $title || \is_string($title), $page.' names its title as one line.');

        return $title;
    }

    /**
     * @return array<string, mixed>
     */
    private static function frontMatter(string $page): array
    {
        static $cache = [];

        return $cache[$page] ??= FrontMatter::of(self::DOCS.$page);
    }
}
