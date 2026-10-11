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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
