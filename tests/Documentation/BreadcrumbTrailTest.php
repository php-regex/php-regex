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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A page the navigation does not list — the page of one rule, of one
 * error message — hangs under the page it names in `parent`. Its
 * breadcrumb is that page's trail, the parent linked before the page
 * itself, and its structured data counts one step more.
 */
#[Group('documentation')]
final class BreadcrumbTrailTest extends TestCase
{
    private const BREADCRUMB = __DIR__.'/../../docs/_includes/breadcrumb.html';

    private const LAYOUT = __DIR__.'/../../docs/_layouts/default.html';

    #[Test]
    public function test_a_page_names_the_page_it_hangs_under_when_the_navigation_does_not_list_it(): void
    {
        $breadcrumb = (string) file_get_contents(self::BREADCRUMB);

        $this->assertStringContainsString('{% assign trail = page.url %}', $breadcrumb, 'A listed page is its own trail.');
        $this->assertStringContainsString('{% if idx < 0 and page.parent %}{% assign trail = page.parent %}{% endif %}', $breadcrumb, 'An unlisted page takes the trail of the page it names.');
        $this->assertStringContainsString("{% assign parent_page = site.pages | where: 'url', trail | first %}", $breadcrumb, 'The parent is read from the pages of the site.');
    }

    #[Test]
    public function test_the_parent_is_linked_before_the_page_itself(): void
    {
        $breadcrumb = (string) file_get_contents(self::BREADCRUMB);

        $this->assertMatchesRegularExpression(
            '/\{% if parent_page %}<li><a href="\{\{ trail \}\}">\{\{ parent_page\.title \}\}<\/a><\/li>\{% endif %}\s*<li aria-current="page">\{\{ page\.title \}\}<\/li>/',
            $breadcrumb,
            'Only the page itself is the current one.',
        );
        $this->assertMatchesRegularExpression(
            '/"position":\{% if parent_page %\}4{% else %}3{% endif %\},"name":\{\{ page\.title \| strip_html \| jsonify \}\}/',
            $breadcrumb,
            'The structured data counts the parent as a step.',
        );
        $this->assertStringContainsString('"name":{{ parent_page.title | strip_html | jsonify }},"item":{{ trail | absolute_url | jsonify }}', $breadcrumb);
    }

    #[Test]
    public function test_the_default_layout_carries_the_breadcrumb(): void
    {
        $this->assertStringContainsString('{% include breadcrumb.html %}', (string) file_get_contents(self::LAYOUT));
    }
}
