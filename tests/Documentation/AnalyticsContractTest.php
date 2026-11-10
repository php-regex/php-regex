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
 * The site measures visits and a few gestures with the standard Google
 * Analytics tag: Google's cookies, and no consent asked. What a visitor
 * types stays in the browser all the same. The tag reports the address up
 * to the "#", where the playground keeps a pattern: read on the requests
 * it sends, for a page and for an event after the fragment moved, on
 * 2026-11-10. And the site hands the tag a gesture's name with the details
 * the privacy page lists, never an address, a pattern or a text.
 */
#[Group('documentation')]
final class AnalyticsContractTest extends TestCase
{
    private const HEAD = PlaygroundDriver::ROOT.'/docs/_includes/head.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';

    /**
     * What each gesture says of itself, as the privacy page lists it.
     */
    private const GESTURES = [
        'run' => ['trigger'],
        'example_loaded' => ['example_name'],
        'finding_opened' => ['finding_id'],
        'share_clicked' => ['finding_id', 'method'],
        'voice_toggled' => ['voice'],
        'version_changed' => ['php_version'],
    ];

    #[Test]
    public function test_the_standard_tag_is_installed_once_in_production(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        $production = strpos($head, "{% if jekyll.environment == 'production' %}");
        $config = strpos($head, "gtag('config', 'G-QSYBM5HXPS');");
        $loader = strpos($head, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-QSYBM5HXPS"></script>');
        $this->assertNotFalse($production);
        $this->assertNotFalse($config);
        $this->assertNotFalse($loader);
        $this->assertLessThan($config, $production, 'Nothing is measured outside the published site.');
        $this->assertLessThan($loader, $config);
        $this->assertSame(1, substr_count($head, "gtag('config'"));
        $this->assertSame(1, substr_count($head, 'googletagmanager.com/gtag/js'));
        $this->assertStringNotContainsString("gtag('consent'", $head, 'No consent is set, and the privacy page says so: one that set it would make the page wrong.');
    }

    #[Test]
    public function test_the_tag_is_handed_no_address(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        foreach (['page_location', 'page_path', 'location.href', 'location.hash'] as $address) {
            $this->assertStringNotContainsString($address, $head, 'The address the tag reports is its own, which stops before the "#".');
        }

        $script = (string) file_get_contents(self::SCRIPT);
        $this->assertSame(1, substr_count($script, 'gtag('), 'Every gesture goes through track().');
        $this->assertStringContainsString("window.gtag('event', name, params || {});", $script);
    }

    #[Test]
    public function test_a_gesture_says_its_name_and_the_details_the_privacy_page_lists(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        $this->assertGreaterThan(0, preg_match_all("/\\btrack\\('([a-z_]+)', \\{([^}]*)\\}\\);/", $script, $calls, \PREG_SET_ORDER));
        $this->assertCount(substr_count($script, 'track(') - 1, $calls, 'Every call of track() names its gesture and its details in place.');

        $said = [];
        foreach ($calls as [, $gesture, $details]) {
            $this->assertArrayHasKey($gesture, self::GESTURES);
            preg_match_all('/(?:^|,)\s*([a-z_]+):/', $details, $keys);
            $this->assertSame([], array_values(array_diff($keys[1], self::GESTURES[$gesture])), $gesture.' says more than the privacy page lists.');
            $said[$gesture] = array_values(array_unique([...($said[$gesture] ?? []), ...$keys[1]]));
        }

        ksort($said);
        $listed = self::GESTURES;
        ksort($listed);
        $this->assertSame(array_map(static function (array $details): array {
            sort($details);

            return $details;
        }, $listed), array_map(static function (array $details): array {
            sort($details);

            return $details;
        }, $said));
    }
}
