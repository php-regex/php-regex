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
use PHPRegex\Linter\Rule\RuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPRegex\Toolkit\Regex;

/**
 * Each issue the rule reference documents has a page of its own: one
 * address a search result can rank for what the rule reports, and one a
 * report can open at the rule itself. A page carries what the index lists
 * it by (its section, its place there, the identifiers it documents), and
 * what a search result shows (a title and a description of its own, as
 * long as a result page keeps whole). A pattern a page sends to the
 * playground is the one its link spells.
 */
#[Group('documentation')]
final class RulePagesTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    private const PAGES = __DIR__.'/../../docs/reference/rules/';

    private const INDEX = __DIR__.'/../../docs/reference/rules.md';

    private const SITE_PAGE = 'https://php-regex.com/reference/rules/';

    #[Test]
    #[DataProvider('provideIssuesOfTheRuleReference')]
    public function test_an_issue_of_the_rule_reference_has_a_page_that_names_it(string $id, string $slug): void
    {
        $this->assertFileExists(self::PAGES.$slug.'.md', \sprintf('%s links to the rule page %s, which no file of docs/reference/rules builds.', $id, $slug));

        [$front, $body] = self::read(self::PAGES.$slug.'.md');
        $this->assertContains($id, $front['rule_ids'] ?? [], $slug.' declares the identifiers it documents.');
        $this->assertStringContainsString('`'.$id.'`', $body, $slug.' names '.$id.' in its text.');
    }

    /**
     * @return iterable<string, array{id: string, slug: string}>
     */
    public static function provideIssuesOfTheRuleReference(): iterable
    {
        foreach (RuleCatalog::docsUrls() as $id => $url) {
            if (str_starts_with($url, self::SITE_PAGE)) {
                yield $id => ['id' => $id, 'slug' => self::slugOf($url)];
            }
        }
    }

    #[Test]
    public function test_the_rule_reference_has_pages(): void
    {
        $this->assertNotEmpty(glob(self::PAGES.'*.md') ?: [], 'The rule reference has a page of its own for its rules.');
    }

    #[Test]
    #[DataProvider('provideRulePages')]
    public function test_a_rule_page_carries_what_the_index_and_a_search_result_read(string $slug): void
    {
        [$front, $body] = self::read(self::PAGES.$slug.'.md');

        $this->assertSame('/reference/rules/', $front['parent'] ?? null, $slug.' hangs under the rule reference.');
        $this->assertContains(self::anchor((string) ($front['rule_category'] ?? '')), self::indexSections(), $slug.' names a section of the index.');
        $this->assertIsInt($front['rule_order'] ?? null, $slug.' has its place in its section.');
        $this->assertNotEmpty($front['rule_ids'] ?? [], $slug.' documents at least one identifier.');
        foreach ($front['rule_ids'] ?? [] as $id) {
            $this->assertSame($slug, self::slugOf(RuleCatalog::docsUrls()[$id] ?? ''), $slug.' is the page the catalogue links '.$id.' to.');
        }

        $description = $front['description'] ?? null;
        $this->assertIsString($description, $slug.' has a description.');
        $this->assertMatchesRegularExpression('/^.{70,160}$/s', $description, $slug.': a description a search result keeps whole.');

        $title = $front['head_title'] ?? null;
        $this->assertIsString($title, $slug.' names the title search engines read.');
        $this->assertLessThanOrEqual(52, \strlen($title), $slug.': the title tag stays under sixty characters with the site name.');
        $this->assertDoesNotMatchRegularExpression('/[`]/', $title, $slug.': a title tag holds no markup.');

        $this->assertStringStartsWith('# ', $body, $slug.' opens on its heading.');
        $this->assertStringContainsString('**Identifier:**', $body, $slug.' names the identifier in its text.');
    }

    /**
     * @return iterable<string, array{slug: string}>
     */
    public static function provideRulePages(): iterable
    {
        foreach (glob(self::PAGES.'*.md') ?: [] as $page) {
            $slug = basename($page, '.md');
            yield $slug => ['slug' => $slug];
        }
    }

    #[Test]
    public function test_no_two_rule_pages_share_a_title_or_a_description(): void
    {
        $titles = $descriptions = $places = [];
        foreach (glob(self::PAGES.'*.md') ?: [] as $page) {
            [$front] = self::read($page);
            $slug = basename($page, '.md');

            $title = $front['head_title'] ?? null;
            $description = $front['description'] ?? null;
            $this->assertIsString($title, $slug.' names one title.');
            $this->assertIsString($description, $slug.' names one description.');
            $this->assertArrayNotHasKey($title, $titles, $slug.' shares its title with '.($titles[$title] ?? '').'.');
            $titles[$title] = $slug;
            $this->assertArrayNotHasKey($description, $descriptions, $slug.' shares its description with '.($descriptions[$description] ?? '').'.');
            $descriptions[$description] = $slug;

            $place = ($front['rule_category'] ?? '').':'.($front['rule_order'] ?? '');
            $this->assertArrayNotHasKey($place, $places, $slug.' stands where '.($places[$place] ?? '').' stands.');
            $places[$place] = $slug;
        }

        $this->assertNotEmpty($titles, 'The rule reference has pages.');
    }

    #[Test]
    #[DataProvider('provideRulePages')]
    public function test_a_pattern_a_page_sends_to_the_playground_is_the_one_its_link_spells(string $slug): void
    {
        [, $body] = self::read(self::PAGES.$slug.'.md');

        $matched = preg_match_all('/\[Open `([^`]+)` in the playground\]\(\/#pattern=([^)&\s]+)&subject=([^)\s]*)\)/', $body, $links, \PREG_SET_ORDER);
        $this->assertNotFalse($matched);
        $this->assertLessThanOrEqual(1, \count($links), $slug.' sends one pattern to the playground at most.');

        foreach ($links as [, $spelled, $pattern, $subject]) {
            $this->assertSame($spelled, rawurldecode($pattern), $slug.': the link opens the pattern its text spells.');
            $this->assertTrue(mb_check_encoding((string) rawurldecode($subject), 'UTF-8'), $slug.': the subject decodes as text.');
            $this->assertStringContainsString('`'.$spelled.'`', $body, $slug.' shows the pattern it opens.');
        }
    }

    #[Test]
    public function test_a_rule_page_offers_the_rules_around_it_in_its_section(): void
    {
        $layout = (string) file_get_contents(self::DOCS.'_layouts/default.html');
        $this->assertMatchesRegularExpression('/\{\{ content \}\}\s*\{% include rule-pager\.html %\}\s*\{% include pager\.html %\}/', $layout, 'The rules around the page come before the page meta.');

        $pager = (string) file_get_contents(self::DOCS.'_includes/rule-pager.html');
        $this->assertStringContainsString("{% assign rule_siblings = site.pages | where: 'rule_category', page.rule_category | sort: 'rule_order' %}", $pager, 'The pager reads the section and the place each page names.');
        $this->assertStringContainsString('rel="prev"', $pager);
        $this->assertStringContainsString('rel="next"', $pager);
    }

    /**
     * A page that promises the finding shows it: the rule of the page,
     * on by default, speaks on the pattern the link opens. A pattern the
     * rule stays silent on would open on a tab with nothing to see.
     */
    #[Test]
    #[DataProvider('provideRulePages')]
    public function test_a_pattern_a_page_promises_the_finding_of_makes_its_rule_speak(string $slug): void
    {
        [$front, $body] = $this->read(self::PAGES.$slug.'.md');
        if (1 !== preg_match('/\[Open `([^`]+)` in the playground\]\([^)]*\): the finding is in the Diagnostics tab\./', $body, $link)) {
            return;
        }

        $spoken = array_map(static fn (RuleViolation $violation): string => $violation->id, Regex::create()->lint($link[1]));
        $this->assertNotEmpty(array_intersect($front['rule_ids'], $spoken), $slug.': its rule speaks on the pattern it sends to the playground.');
    }

    /**
     * The page of a rule, as the catalogue's link names it: the anchor of
     * the page it shares today, or the page of its own.
     */
    private static function slugOf(string $url): string
    {
        $fragment = (string) parse_url($url, \PHP_URL_FRAGMENT);
        if ('' !== $fragment) {
            return $fragment;
        }

        $segments = explode('/', rtrim((string) parse_url($url, \PHP_URL_PATH), '/'));

        return (string) end($segments);
    }

    /**
     * The sections the index lists the pages under, as the site anchors
     * their headings: lower case, punctuation dropped, spaces to hyphens.
     *
     * @return list<string>
     */
    private static function indexSections(): array
    {
        $markdown = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents(self::INDEX));
        preg_match_all('/^## (.+)$/m', $markdown, $headings);

        return array_map(self::anchor(...), $headings[1]);
    }

    /**
     * The anchor the site gives a heading: lower case, punctuation
     * dropped, spaces turned into hyphens.
     */
    private static function anchor(string $heading): string
    {
        return str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower($heading)));
    }

    /**
     * A page's front matter, read as the few shapes these pages write, and
     * its text.
     *
     * @return array{array<string, mixed>, string}
     */
    private function read(string $page): array
    {
        $text = (string) file_get_contents($page);
        $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $parts), $page.' opens with a front matter.');

        $front = [];
        $list = null;
        foreach ( explode("\n", $parts[1]) as $line) {
            if (1 === preg_match('/^(\w+): (.*)$/', $line, $field)) {
                $list = null;
                $front[$field[1]] = match (true) {
                    '"' === $field[2][0], '[' === $field[2][0] => json_decode($field[2], true),
                    \is_numeric($field[2]) => (int) $field[2],
                    default => $field[2],
                };
                continue;
            }
            if (1 === preg_match('/^(\w+):$/', $line, $field)) {
                $list = $field[1];
                $front[$list] = [];

                continue;
            }
            if (null !== $list && 1 === preg_match('/^  - (.+)$/', $line, $item)) {
                $front[$list][] = trim($item[1], '"\\');
            }
        }

        return [$front, $parts[2]];
    }
}
