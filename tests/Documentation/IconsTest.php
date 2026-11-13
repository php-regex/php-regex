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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The site's icons are Lucide's, vendored: the few the pages draw are
 * copied, with their license, under docs/assets/icons/lucide/, and every
 * place that draws one — the markup, the scripts, the stylesheet's masks —
 * draws it exactly as the vendored file does. No request leaves for an
 * icon, and no icon is redrawn by hand next to Lucide's.
 */
#[Group('documentation')]
final class IconsTest extends TestCase
{
    private const DOCS = PlaygroundDriver::ROOT.'/docs';
    private const VENDOR = self::DOCS.'/assets/icons/lucide';

    #[Test]
    public function test_the_icons_come_with_their_license(): void
    {
        $license = self::source(self::VENDOR.'/LICENSE');

        $this->assertStringContainsString('ISC License', $license);
        $this->assertStringContainsString('Lucide Icons and Contributors', $license);
        $this->assertStringContainsString('Cole Bemis', $license, 'The icons derived from Feather keep their MIT notice.');
    }

    /**
     * Every icon of the includes names its Lucide source and draws it to
     * the byte; the brand's mark and GitHub's are the only other drawings.
     */
    #[Test]
    public function test_every_icon_of_the_pages_is_the_vendored_one(): void
    {
        $seen = 0;
        foreach (glob(self::DOCS.'/_includes/*.html') ?: [] as $file) {
            $html = self::source($file);
            preg_match_all('/<svg\b([^>]*)>(.*?)<\/svg>/s', $html, $svgs, \PREG_SET_ORDER);
            foreach ($svgs as [$whole, $attributes, $inner]) {
                if (str_contains($attributes, 'class="mark"') || str_contains($whole, 'M8 0C3.58 0 0 3.58 0 8')) {
                    continue;
                }
                $this->assertSame(1, preg_match('/data-icon="([a-z0-9-]+)"/', $attributes, $name), basename($file).': an icon names its Lucide source: '.substr($whole, 0, 120));
                $this->assertStringContainsString('viewBox="0 0 24 24"', $attributes);
                $this->assertStringContainsString('aria-hidden="true"', $attributes, 'An icon is for the eye; the words carry the meaning.');
                $this->assertSame(self::inner($name[1]), $inner, basename($file).': '.$name[1].' is drawn as Lucide draws it.');
                $seen++;
            }
        }
        $this->assertGreaterThanOrEqual(17, $seen);
    }

    /**
     * @return iterable<string, array{script: string, key: string, icon: string}>
     */
    public static function provideScriptIcons(): iterable
    {
        foreach (['rewind' => 'skip-back', 'back' => 'step-back', 'step' => 'step-forward', 'resume' => 'skip-forward', 'play' => 'play', 'pause' => 'pause'] as $key => $icon) {
            yield 'the debugger\'s '.$key => ['script' => 'playground.js', 'key' => $key, 'icon' => $icon];
        }
        yield 'back to top' => ['script' => 'reading.js', 'key' => 'top', 'icon' => 'arrow-up'];
    }

    #[Test]
    #[DataProvider('provideScriptIcons')]
    public function test_the_scripts_draw_the_vendored_icons(string $script, string $key, string $icon): void
    {
        $source = self::source(self::DOCS.'/assets/js/'.$script);

        $this->assertStringContainsString($key.": '".self::inner($icon)."'", $source, $key.' draws Lucide\'s '.$icon.'.');
        $this->assertStringContainsString("'0 0 24 24'", $source, 'In Lucide\'s own box.');
    }

    /**
     * @return iterable<string, array{selector: string, icon: string}>
     */
    public static function provideMasks(): iterable
    {
        yield 'the theme toggle, light' => ['selector' => '.theme-toggle::before {', 'icon' => 'moon'];
        yield 'the theme toggle, dark' => ['selector' => "[data-theme='dark'] .theme-toggle::before {", 'icon' => 'sun'];
        yield 'the page filter' => ['selector' => '.page-filter::before {', 'icon' => 'search'];
        yield 'the privacy chip' => ['selector' => '.pg-privacy::before {', 'icon' => 'lock'];
        yield 'a backtrack' => ['selector' => '.pg-trace-stop-mark.is-backtrack {', 'icon' => 'undo-2'];
    }

    /**
     * An icon drawn as a mask takes the element's own color: it is the
     * same Lucide file, written as a data address.
     */
    #[Test]
    #[DataProvider('provideMasks')]
    public function test_the_masks_draw_the_vendored_icons(string $selector, string $icon): void
    {
        $stylesheet = self::source(self::DOCS.'/assets/css/main.scss');
        $start = strpos($stylesheet, $selector);
        $this->assertNotFalse($start, $selector);
        $rule = substr($stylesheet, $start, (int) strpos($stylesheet, "\n}", $start) - $start);

        $this->assertStringContainsString('mask: url("'.self::dataAddress($icon).'")', $rule, $selector.' masks Lucide\'s '.$icon.'.');
    }

    #[Test]
    public function test_the_icon_rule_speaks_lucide_s_geometry(): void
    {
        $this->assertSame(1, preg_match('/^\.icon \{([^}]*)\}/m', self::source(self::DOCS.'/assets/css/main.scss'), $icon));
        foreach (['stroke: currentColor;', 'stroke-width: 2;', 'stroke-linecap: round;', 'stroke-linejoin: round;'] as $line) {
            $this->assertStringContainsString($line, $icon[1]);
        }
    }

    /**
     * The vendored file's drawing: what stands between its svg tags, one
     * line, the whitespace between the elements gone.
     */
    private static function inner(string $icon): string
    {
        $svg = self::source(self::VENDOR.'/'.$icon.'.svg');
        self::assertSame(1, preg_match('/<svg\b[^>]*>(.*)<\/svg>/s', $svg, $match), $icon);

        return trim((string) preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], trim($match[1])));
    }

    private static function dataAddress(string $icon): string
    {
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>".str_replace('"', "'", self::inner($icon)).'</svg>';

        return 'data:image/svg+xml,'.strtr($svg, ['<' => '%3C', '>' => '%3E', '#' => '%23']);
    }

    private static function source(string $path): string
    {
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
