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

#[Group('documentation')]
final class SidebarNavigationTest extends TestCase
{
    private const SIDEBAR = __DIR__.'/../../docs/_includes/sidebar.html';

    #[Test]
    public function test_child_navigation_groups_are_collapsible(): void
    {
        $sidebar = (string) file_get_contents(self::SIDEBAR);

        $this->assertStringContainsString('<details class="nav-group"', $sidebar);
        $this->assertStringContainsString('<summary class="nav-group-toggle"', $sidebar);
        $this->assertStringContainsString("aria-label='Toggle {{ item.title }} submenu'", $sidebar);
    }

    #[Test]
    public function test_the_group_containing_the_current_page_opens(): void
    {
        $sidebar = (string) file_get_contents(self::SIDEBAR);

        $this->assertStringContainsString('{% assign open_group = false %}', $sidebar);
        $this->assertStringContainsString('{% if child.url == page.url %}{% assign open_group = true %}{% endif %}', $sidebar);
        $this->assertStringContainsString('{% if open_group %} open{% endif %}', $sidebar);
    }

    /**
     * The page of one rule or of one error message is not listed: on it,
     * the group of the page it hangs under opens, as it does on a listed
     * child. The link keeps its place in the group: only the page itself
     * would be the current one, and it is not in the list.
     */
    #[Test]
    public function test_the_group_of_the_page_an_unlisted_page_hangs_under_opens(): void
    {
        $sidebar = (string) file_get_contents(self::SIDEBAR);

        $this->assertStringContainsString('{% if page.parent and page.parent == item.url %}{% assign open_group = true %}{% endif %}', $sidebar, 'The page hangs under the item of a group.');
        $this->assertStringContainsString('{% if page.parent and page.parent == child.url %}{% assign open_group = true %}{% endif %}', $sidebar, 'The page hangs under a child of a group.');
        $opens = (string) preg_replace('/\{% if page\.parent and page\.parent == (?:item|child)\.url %\}\{% assign open_group = true %\}\{% endif %\}/', '', $sidebar);
        $this->assertStringNotContainsString('page.parent', $opens, 'The parent opens the group, and nothing else in the list reads it: no link stands as the page.');
    }

    /**
     * A guide the sidebar does not list is a guide a reader never finds:
     * every page of docs/guides stands in the navigation, under the address
     * the site gives it.
     */
    #[Test]
    public function test_every_guide_is_in_the_navigation(): void
    {
        $navigation = (string) file_get_contents(__DIR__.'/../../docs/_data/nav.yml');
        preg_match_all('~^\s*-?\s*url: (/guides/[^\s]+/)$~m', $navigation, $listed);
        $this->assertNotEmpty($listed[1], 'The navigation lists the guides.');

        foreach (glob(__DIR__.'/../../docs/guides/*.md') ?: [] as $guide) {
            $name = basename($guide, '.md');
            if ('index' === $name) {
                continue;
            }

            $this->assertContains('/guides/'.$name.'/', $listed[1], $guide.' is listed in the sidebar.');
        }
    }
}
