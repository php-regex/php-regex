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
 * the privacy page lists, never an address, a pattern or a text — the
 * words a visitor searches in the documentation being the one thing typed
 * that leaves, and the privacy page naming it. An automated browser is not
 * a visitor: the tag is not configured for it.
 */
#[Group('documentation')]
final class AnalyticsContractTest extends TestCase
{
    private const HEAD = PlaygroundDriver::ROOT.'/docs/_includes/head.html';
    private const SCRIPTS = [
        'playground.js' => PlaygroundDriver::ROOT.'/docs/assets/js/playground.js',
        'copy.js' => PlaygroundDriver::ROOT.'/docs/assets/js/copy.js',
        'search.js' => PlaygroundDriver::ROOT.'/docs/assets/js/search.js',
        'gestures.js' => PlaygroundDriver::ROOT.'/docs/assets/js/gestures.js',
    ];

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
        'install_copied' => ['package'],
        'github_clicked' => ['kind'],
        'view_search_results' => ['search_term'],
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
        $end = strpos($head, '{% endif %}', $production);
        $this->assertNotFalse($end);
        // The script that loads the tag and the one that configures it, in
        // either order: the first is asynchronous, and the second queues
        // what it says until the tag reads it.
        foreach ([$config, $loader] as $at) {
            $this->assertGreaterThan($production, $at, 'Nothing is measured outside the published site.');
            $this->assertLessThan($end, $at, 'Nothing is measured outside the published site.');
        }
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

        foreach (self::SCRIPTS as $name => $path) {
            $this->assertStringNotContainsString('gtag(', (string) file_get_contents($path), $name.' hands no gesture to the tag itself: every gesture goes through the one helper.');
        }

        $helper = self::between($head, 'window.phpregexTrack = function (name, params) {', '};');
        $this->assertStringContainsString("if (typeof window.gtag === 'function') {", $helper);
        $this->assertStringContainsString("window.gtag('event', name, params || {});", $helper, 'The helper hands the tag the name and the details, and nothing else.');
    }

    /**
     * The tag is not configured for a browser a script drives or a crawler
     * runs: half of what the property counted came from machines, and a
     * visitor is what the figures are about.
     */
    #[Test]
    public function test_an_automated_browser_is_not_measured(): void
    {
        $head = (string) file_get_contents(self::HEAD);

        $production = strpos($head, "{% if jekyll.environment == 'production' %}");
        $this->assertNotFalse($production);
        $end = strpos($head, '{% endif %}', $production);
        $this->assertNotFalse($end);
        $block = substr($head, $production, $end - $production);

        $this->assertSame(1, preg_match('/if \(!navigator\.webdriver.*?\) \{\s*gtag\(\x27js\x27, new Date\(\)\);\s*gtag\(\x27config\x27, \x27G-QSYBM5HXPS\x27\);/s', $block, $guard), 'The configuration of the tag stands behind the guard of the automated browsers.');
        $this->assertMatchesRegularExpression('/navigator\.userAgent/', $guard[0], 'A crawler names itself in its user agent.');
        foreach (['bot', 'crawl', 'headless'] as $word) {
            $this->assertStringContainsString($word, $guard[0], 'The guard reads "'.$word.'" in a user agent.');
        }
    }

    #[Test]
    public function test_a_gesture_says_its_name_and_the_details_the_privacy_page_lists(): void
    {
        $calls = [];
        foreach (self::SCRIPTS as $name => $path) {
            $script = (string) file_get_contents($path);
            $this->assertGreaterThan(0, preg_match_all("/\\btrack\\('([a-z_]+)', \\{([^}]*)\\}\\);/", $script, $found, \PREG_SET_ORDER), $name.' measures its gestures through track().');
            $this->assertCount(substr_count($script, "track('"), $found, $name.': every call of track() names its gesture and its details in place.');
            array_push($calls, ...$found);
        }

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

    private static function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($text, $to, $start + \strlen($from));
        self::assertNotFalse($end, $to);

        return substr($text, $start, $end - $start + \strlen($to));
    }
}
