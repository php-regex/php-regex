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

use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The site measures visits and a few gestures, without a cookie: every
 * consent is denied before the analytics script loads, and the address it
 * reports stops at the path, so the fragment that carries a pattern never
 * leaves the browser.
 */
#[Group('documentation')]
final class AnalyticsContractTest extends TestCase
{
    private const HEAD = PlaygroundDriver::ROOT.'/docs/_includes/head.html';

    #[Test]
    public function test_consent_defaults_all_four_denied_before_gtag_js_loads(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        $consent = strpos($head, "gtag('consent', 'default', {");
        $loader = strpos($head, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-QSYBM5HXPS"></script>');
        $this->assertNotFalse($consent);
        $this->assertNotFalse($loader);
        $this->assertLessThan($loader, $consent, 'The default is set before the script that reads it arrives.');

        $block = substr($head, $consent, (int) strpos($head, '});', $consent) - $consent);
        foreach (['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization'] as $type) {
            $this->assertStringContainsString($type.": 'denied'", $block);
        }
        $this->assertStringNotContainsString("'granted'", $head, 'Nothing ever grants: no banner, no cookie.');
    }

    #[Test]
    public function test_config_forces_page_location_without_fragment(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        $this->assertSame(1, preg_match("/gtag\\('config', 'G-QSYBM5HXPS', \\{\\s*page_location: window\\.location\\.origin \\+ window\\.location\\.pathname,?\\s*\\}\\);/", $head), 'The reported address stops at the path: the fragment holds what was typed.');
        $this->assertSame(1, substr_count($head, "gtag('config'"));
    }
}
