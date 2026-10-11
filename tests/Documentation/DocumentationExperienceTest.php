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

final class DocumentationExperienceTest extends TestCase
{
    private const HOME = __DIR__.'/../../docs/index.md';

    private const NAVIGATION = __DIR__.'/../../docs/_data/nav.yml';

    private const PRODUCT = __DIR__.'/../../docs/_data/product.yml';

    private const QUICK_START = __DIR__.'/../../docs/quick-start.md';

    #[Test]
    public function test_the_landing_page_keeps_its_first_visit_short(): void
    {
        $home = (string) file_get_contents(self::HOME);

        $this->assertLessThanOrEqual(4, substr_count($home, '<section'), 'The landing page has at most four narrative sections.');
        $this->assertLessThanOrEqual(11, substr_count($home, '<article'), 'The landing page shows at most eleven cards.');
        $this->assertStringNotContainsString('Sixteen components', $home);
        $this->assertStringNotContainsString('every preg_* pattern, every route constraint', $home);
    }

    #[Test]
    public function test_product_facts_come_from_one_data_file(): void
    {
        $this->assertFileExists(self::PRODUCT);

        $product = (string) file_get_contents(self::PRODUCT);
        $home = (string) file_get_contents(self::HOME);

        $this->assertStringContainsString("php_floor: '8.2'", $product);
        $this->assertStringContainsString('site.data.product.php_floor', $home);
        $this->assertStringContainsString('site.data.product.release', $home);
        $this->assertStringNotContainsString('php-regex/php-regex:2.x-dev', $home);
        $this->assertStringNotContainsString('PCRE2 10.49', $home);
        $this->assertStringNotContainsString('2.0.0-DEV', $home);
    }

    #[Test]
    public function test_the_landing_demo_shows_the_ast(): void
    {
        $home = (string) file_get_contents(self::HOME);

        $this->assertStringContainsString('site.data.hero_ast.command', $home);
        $this->assertStringContainsString('site.data.hero_ast.output', $home);
        $this->assertStringNotContainsString('vendor/bin/regex analyze', $home);
    }

    #[Test]
    public function test_the_landing_terminal_fits_without_horizontal_scrolling(): void
    {
        $this->assertSame(1, preg_match(
            '/command: \|-\n(.*?)\noutput: \|-\n(.*?)\z/s',
            (string) file_get_contents(__DIR__.'/../../docs/_data/hero_ast.yml'),
            $matches,
        ));

        $terminal = implode("\n", array_map(
            static fn (string $line): string => str_starts_with($line, '  ') ? substr($line, 2) : $line,
            explode("\n", $matches[1]."\n".$matches[2]),
        ));
        $longestLine = max(array_map(strlen(...), explode("\n", $terminal)));

        $this->assertLessThanOrEqual(58, $longestLine, 'Terminal lines longer than 58 characters force horizontal scrolling.');
    }

    #[Test]
    public function test_reference_navigation_is_grouped_by_task(): void
    {
        $navigation = (string) file_get_contents(self::NAVIGATION);
        $start = strpos($navigation, '- title: Reference');
        $end = strpos($navigation, '- title: Internals');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $section = substr($navigation, $start, $end - $start);
        $this->assertSame(5, preg_match_all('/^    - title:/m', $section), 'The sidebar shows five grouped reference entry points, not a flat catalogue.');
        $this->assertGreaterThanOrEqual(8, preg_match_all('/^        - title:/m', $section), 'Reference pages are nested under task groups.');
    }

    #[Test]
    public function test_navigation_sections_target_real_pages(): void
    {
        $navigation = (string) file_get_contents(self::NAVIGATION);

        preg_match_all('/^  url: (\/[^\s#]+)$/m', $navigation, $matches);
        $this->assertNotSame([], $matches[1]);

        foreach ($matches[1] as $url) {
            $path = __DIR__.'/../../docs'.rtrim($url, '/');
            $targets = is_dir($path)
                ? [$path.'/index.md', $path.'/README.md']
                : [$path.'.md', $path.'.html'];

            $this->assertContains(true, array_map(file_exists(...), $targets), $url);
        }
    }

    #[Test]
    public function test_quick_start_is_a_five_minute_path(): void
    {
        $this->assertFileExists(self::QUICK_START);

        $quickStart = (string) file_get_contents(self::QUICK_START);

        $this->assertLessThanOrEqual(190, substr_count($quickStart, "\n"), 'Quick Start stays a five-minute path; deeper material lives in guides.');
        $this->assertStringContainsString('## Install', $quickStart);
        $this->assertStringContainsString('## Run your first analysis', $quickStart);
        $this->assertStringContainsString('## Pick your integration', $quickStart);
    }
}
