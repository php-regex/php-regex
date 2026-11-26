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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $page = $this->read(self::PAGES.$slug.'.md');
        $this->assertContains($id, $page['ids'], $slug.' declares the identifiers it documents.');
        $this->assertStringContainsString('`'.$id.'`', $page['body'], $slug.' names '.$id.' in its text.');
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

    /**
     * @return iterable<string, array{slug: string}>
     */
    public static function provideRulePages(): iterable
    {
        $pages = glob(self::PAGES.'*.md') ?: [];
        \assert([] !== $pages, 'The rule reference has a page of its own for its rules.');

        foreach ($pages as $page) {
            $slug = basename($page, '.md');
            yield $slug => ['slug' => $slug];
        }
    }

    #[Test]
    #[DataProvider('provideRulePages')]
    public function test_a_rule_page_carries_what_the_index_and_a_search_result_read(string $slug): void
    {
        $page = $this->read(self::PAGES.$slug.'.md');

        $this->assertSame('/reference/rules/', $page['parent'], $slug.' hangs under the rule reference.');
        $this->assertContains(self::anchor($page['category']), self::indexSections(), $slug.' names a section of the index.');
        $this->assertNotEmpty($page['ids'], $slug.' documents at least one identifier.');
        foreach ($page['ids'] as $id) {
            $this->assertSame($slug, self::slugOf(RuleCatalog::docsUrls()[$id] ?? ''), $slug.' is the page the catalogue links '.$id.' to.');
        }

        $this->assertMatchesRegularExpression('/^.{70,160}$/s', $page['description'], $slug.': a description a search result keeps whole.');
        $this->assertLessThanOrEqual(52, \strlen($page['title']), $slug.': the title tag stays under sixty characters with the site name.');
        $this->assertDoesNotMatchRegularExpression('/[`]/', $page['title'], $slug.': a title tag holds no markup.');

        $this->assertStringStartsWith('# ', $page['body'], $slug.' opens on its heading.');
        $this->assertStringContainsString('**Identifier:**', $page['body'], $slug.' names the identifier in its text.');
    }

    #[Test]
    public function test_no_two_rule_pages_share_a_title_a_description_or_a_place(): void
    {
        $titles = $descriptions = $places = [];
        foreach (glob(self::PAGES.'*.md') ?: [] as $file) {
            $page = $this->read($file);
            $slug = basename($file, '.md');

            $this->assertArrayNotHasKey($page['title'], $titles, $slug.' shares its title with '.($titles[$page['title']] ?? '').'.');
            $titles[$page['title']] = $slug;
            $this->assertArrayNotHasKey($page['description'], $descriptions, $slug.' shares its description with '.($descriptions[$page['description']] ?? '').'.');
            $descriptions[$page['description']] = $slug;

            $place = $page['category'].':'.$page['order'];
            $this->assertArrayNotHasKey($place, $places, $slug.' stands where '.($places[$place] ?? '').' stands.');
            $places[$place] = $slug;
        }

        $this->assertNotEmpty($titles, 'The rule reference has pages.');
    }

    #[Test]
    #[DataProvider('provideRulePages')]
    public function test_a_pattern_a_page_sends_to_the_playground_is_the_one_its_link_spells(string $slug): void
    {
        $body = $this->read(self::PAGES.$slug.'.md')['body'];

        $matched = preg_match_all('/\[Open `([^`]+)` in the playground\]\(\/#pattern=([^)&\s]+)&subject=([^)\s]*)\)/', $body, $links, \PREG_SET_ORDER);
        $this->assertNotFalse($matched);
        $this->assertLessThanOrEqual(1, \count($links), $slug.' sends one pattern to the playground at most.');

        foreach ($links as [, $spelled, $pattern, $subject]) {
            $this->assertSame($spelled, rawurldecode($pattern), $slug.': the link opens the pattern its text spells.');
            $this->assertTrue(mb_check_encoding((string) rawurldecode($subject), 'UTF-8'), $slug.': the subject decodes as text.');
        }
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
        $page = $this->read(self::PAGES.$slug.'.md');
        if (1 !== preg_match('/\[Open `([^`]+)` in the playground\]\([^)]*\): the finding is in the Diagnostics tab\./', $page['body'], $link)) {
            return;
        }

        $spoken = array_map(static fn (RuleViolation $violation): string => $violation->id, Regex::create()->lint($link[1]));
        $this->assertNotEmpty(array_intersect($page['ids'], $spoken), $slug.': its rule speaks on the pattern it sends to the playground.');
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
     * The reference is an index: it lists the pages by section, it keeps
     * no section of a rule (the page holds it), and each entry carries the
     * anchor of the heading the catalogue linked to before the rules had
     * pages. A link printed then, and a rule the playground names by its
     * identifier, open the page of the rule.
     */
    #[Test]
    public function test_the_reference_lists_the_pages_and_sends_an_old_link_to_the_page_of_its_rule(): void
    {
        $index = (string) file_get_contents(self::INDEX);

        $this->assertStringNotContainsString('**Identifier:**', $index, 'The section of a rule is on the page of the rule.');
        $sections = [];
        foreach (glob(self::PAGES.'*.md') ?: [] as $file) {
            $sections[$this->read($file)['category']] = true;
        }
        foreach (array_keys($sections) as $section) {
            $this->assertIsString($section);
            $this->assertStringContainsString('{%- assign rule_pages = site.pages | where: "rule_category", "'.$section.'" | sort: "rule_order" -%}', $index, 'The index lists the pages of '.$section.'.');
        }
        $this->assertStringContainsString('<ul class="rule-index">', $index, 'The entries are a list.');
        $this->assertStringContainsString('{%- for p in rule_pages -%}', $index);
        $this->assertStringContainsString('<a href="{{ p.url }}">{{ p.title }}</a>', $index, 'An entry links to its page.');
        $this->assertStringContainsString('<code>{{ id }}</code>', $index, 'An entry names the identifiers the rule is reported under.');
        $this->assertStringContainsString("<li id=\"{{ p.path | split: '/' | last | remove: '.md' }}\">", $index, 'An entry carries the anchor of its heading.');
        $this->assertStringContainsString('window.location.replace(link.href)', $index, 'An old link goes on to the page of the rule.');

        $script = (string) file_get_contents(self::DOCS.'assets/js/filter.js');
        $this->assertStringContainsString('input.dataset.items', $script, 'The filter narrows the entries a page names, as it narrows heading blocks.');

        $playground = (string) file_get_contents(self::DOCS.'assets/js/playground.js');
        $this->assertStringContainsString("link.href = '/reference/rules/#' + finding.id;", $playground, 'A finding of the playground names its rule to the reference, which opens the page of the rule.');
    }

    /**
     * The page of a rule, as the catalogue's link names it: its own
     * address, or the anchor of the page it shared before the rules had
     * pages.
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
     * A rule page: what its front matter names, under the types the site
     * reads it with, and its text.
     *
     * @return array{description: string, title: string, parent: string, category: string, order: int, ids: list<string>, body: string}
     */
    private function read(string $file): array
    {
        $text = (string) file_get_contents($file);
        $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $parts), $file.' opens with a front matter.');

        // A quoted value is JSON, a bare one is written as it reads.
        $scalar = static function (string $value): mixed {
            if ('"' === $value[0]) {
                return json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
            }

            return \is_numeric($value) ? (int) $value : $value;
        };

        $front = [];
        $ids = [];
        $inIds = false;
        foreach (explode("\n", $parts[1]) as $line) {
            if (1 === preg_match('/^(\w+): (.+)$/', $line, $field)) {
                $inIds = false;
                $front[$field[1]] = $scalar($field[2]);
            } elseif (1 === preg_match('/^(\w+):$/', $line, $field)) {
                $inIds = 'rule_ids' === $field[1];
            } elseif ($inIds && 1 === preg_match('/^  - (.+)$/', $line, $item)) {
                $ids[] = $item[1];
            }
        }

        $description = $front['description'] ?? null;
        $this->assertIsString($description, $file.' names a description.');
        $title = $front['head_title'] ?? null;
        $this->assertIsString($title, $file.' names a title.');
        $parent = $front['parent'] ?? null;
        $this->assertIsString($parent, $file.' names the page it hangs under.');
        $category = $front['rule_category'] ?? null;
        $this->assertIsString($category, $file.' names its section.');
        $order = $front['rule_order'] ?? null;
        $this->assertIsInt($order, $file.' names its place in its section.');
        $this->assertNotEmpty($ids, $file.' lists its identifiers.');

        return [
            'description' => $description,
            'title' => $title,
            'parent' => $parent,
            'category' => $category,
            'order' => $order,
            'ids' => $ids,
            'body' => $parts[2],
        ];
    }
}
