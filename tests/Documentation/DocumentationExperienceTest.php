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
final class DocumentationExperienceTest extends TestCase
{
    private const HOME = __DIR__.'/../../docs/index.md';

    private const NAVIGATION = __DIR__.'/../../docs/_data/nav.yml';

    private const PRODUCT = __DIR__.'/../../docs/_data/product.yml';

    private const QUICK_START = __DIR__.'/../../docs/quick-start.md';

    private const EXTENDING = __DIR__.'/../../docs/extending.md';

    /**
     * Lint rules have no public registry in 2.0 (backward-compatibility.md):
     * the extending guide must not offer one as an extension point.
     */
    #[Test]
    public function test_the_extending_guide_offers_no_custom_lint_rule(): void
    {
        $extending = (string) file_get_contents(self::EXTENDING);

        $this->assertDoesNotMatchRegularExpression('/custom lint rule/i', $extending);
        $this->assertStringContainsString('reference/backward-compatibility.md', $extending);
    }

    /**
     * tokenize() promises tokens in order that cover the pattern body, not
     * one token per character: the API reference must not teach a
     * per-character split, and the compatibility policy must say a minor
     * release may group characters differently.
     */
    #[Test]
    public function test_the_token_granularity_is_not_promised(): void
    {
        $api = (string) file_get_contents(__DIR__.'/../../docs/reference/api.md');
        $policy = (string) file_get_contents(__DIR__.'/../../docs/reference/backward-compatibility.md');

        $this->assertDoesNotMatchRegularExpression("/the same for 'o', '\\|'/", $api);
        $this->assertMatchesRegularExpression('/tokenize\(\)[^\n]*(one token|group)/', $api);
        $this->assertMatchesRegularExpression('/\*\*How a pattern splits into tokens\*\*/', $policy);
    }

    /**
     * The packages are the contract and the Regex facade a convenience layer
     * over seven of them: the policy says so, and the README neither claims
     * the facade covers every package nor sells the solver without naming
     * where it lives.
     */
    #[Test]
    public function test_the_facade_is_described_as_a_convenience_layer(): void
    {
        $readme = (string) file_get_contents(__DIR__.'/../../README.md');
        $policy = (string) file_get_contents(__DIR__.'/../../docs/reference/backward-compatibility.md');

        $this->assertStringContainsString('The packages are the contract', $policy);
        $this->assertStringNotContainsString('every library below in one call', $readme);
        $this->assertMatchesRegularExpression('/\*\*Logic Solver:\*\*[^\n]*LanguageSolver/', $readme);
    }

    #[Test]
    public function test_the_landing_page_keeps_its_first_visit_short(): void
    {
        $home = (string) file_get_contents(self::HOME);

        $this->assertLessThanOrEqual(5, substr_count($home, '<section'), 'The landing page routes through at most five sections.');
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

        $this->assertStringContainsString("php_floor: '8.1'", $product);
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
