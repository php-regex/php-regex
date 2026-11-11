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

    private const HEADER = __DIR__.'/../../docs/_includes/header.html';

    private const STYLESHEET = __DIR__.'/../../docs/assets/css/main.scss';

    private const COOKBOOK = __DIR__.'/../../docs/cookbook.md';

    private const HOW_PCRE2_MATCHES = __DIR__.'/../../docs/concepts/how-pcre2-matches.md';

    private const ARTICLES = [
        'catastrophic-backtracking-in-php',
        're2-vs-pcre2',
        'which-pcre2-ships-with-which-php',
        'why-pcre2-is-a-separate-library',
    ];

    private const LLMS = __DIR__.'/../../docs/llms.txt';

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
        $this->assertSame(1, preg_match('/^version: (\S+)$/m', $product, $version), 'product.yml carries the version the docs print.');
        $this->assertSame(1, preg_match('/^stable: (true|false)$/m', $product, $stable));
        $this->assertSame(1, preg_match('/^version_label: (.+)$/m', $product, $label));
        if ('true' === $stable[1]) {
            $this->assertSame($label[1], $version[1], 'From the first stable release on, the docs print the released version, the one the installed package reports.');
        } else {
            $this->assertStringContainsString("public const VERSION = '".$version[1]."';", (string) file_get_contents(__DIR__.'/../../src/Toolkit/Regex.php'), 'Before the first stable release, the docs print the version the tree reports.');
        }
        foreach (['guides/cli.md', 'guides/lsp.md', '_articles/which-pcre2-ships-with-which-php.md', '_articles/why-pcre2-is-a-separate-library.md'] as $page) {
            $text = (string) file_get_contents(__DIR__.'/../../docs/'.$page);
            $this->assertStringContainsString('{{ site.data.product.version }}', $text, $page.': a sample output reads the version from product.yml.');
            $this->assertStringNotContainsString($version[1], $text, $page.': a sample output does not spell the version.');
        }
        $this->assertStringNotContainsString('composer config', $home);
        $this->assertStringNotContainsString('php-regex/php-regex:2.x-dev', $home);
        $this->assertStringNotContainsString('PCRE2 10.49', $home);
        $this->assertStringNotContainsString('2.0.0-DEV', $home);
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

    /**
     * The landing page has no drawer to carry its sections, and below
     * 1024px the header's own words went with every other page's: a phone
     * stood on the tool with no way to the docs, the tutorial or the
     * articles but the footer, far below. On the landing page the words
     * stay in the header at every width; on a phone the wordmark gives
     * way to its mark so the three links fit beside the controls.
     */
    #[Test]
    public function test_the_landing_header_keeps_its_sections_on_a_phone(): void
    {
        $header = (string) file_get_contents(self::HEADER);
        $this->assertStringContainsString("class=\"site-header{% if page.layout == 'landing' %} site-header--landing{% endif %}\"", $header, 'The landing header says it is the landing header.');

        $stylesheet = (string) file_get_contents(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.site-header--landing \.header-nav \{([^}]*)\}/m', $stylesheet, $nav), 'The landing nav rule exists.');
        $this->assertStringContainsString('display: flex;', $nav[1], 'The sections stand in the header at every width.');
        $phone = '/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.site-header--landing \.header-nav a \{([^}]*)\}/';
        $this->assertSame(1, preg_match($phone, $stylesheet, $links), 'A phone compacts the landing links.');
        $this->assertStringContainsString('font-size:', $links[1]);
        $this->assertSame(1, preg_match('/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.site-header--landing \.brand-name \{([^}]*)\}/', $stylesheet, $brand), 'A phone folds the wordmark into its mark.');
        $this->assertStringContainsString('display: none;', $brand[1]);
    }

    /**
     * The documentation and the playground point at each other: a reader
     * who just ran an example by hand is one link away from the tree, the
     * lint findings and the step-through of the same pattern. Every link
     * carries a pattern the engine accepts.
     */
    #[Test]
    public function test_runnable_examples_open_in_the_playground(): void
    {
        $pages = [
            self::QUICK_START => 2,
            self::COOKBOOK => 2,
            self::HOW_PCRE2_MATCHES => 1,
        ];
        foreach ($pages as $path => $least) {
            $page = (string) file_get_contents($path);
            preg_match_all('/\]\(\/(?:#|%23)pattern=([^)&\s]+)/', $page, $matches, \PREG_SET_ORDER);
            $this->assertGreaterThanOrEqual($least, \count($matches), $path.': at least '.$least.' example(s) open in the playground.');
            foreach ($matches as $match) {
                $pattern = rawurldecode($match[1]);
                $this->assertNotFalse(@preg_match($pattern, ''), $path.': the linked pattern '.\var_export($pattern, true).' is one the engine accepts.');
            }
        }
    }

    /**
     * The four launch articles stay drafts until a hand validates them:
     * the blog shows nothing of them and llms.txt indexes nothing of
     * them — an agent reading the site does not read what a reader
     * cannot. Publishing them is a flip of one flag each, plus the
     * index the runbook describes.
     */
    #[Test]
    public function test_the_four_blog_articles_wait_for_validation(): void
    {
        foreach (self::ARTICLES as $slug) {
            $article = (string) file_get_contents(__DIR__.'/../../docs/_articles/'.$slug.'.md');
            $this->assertSame(1, preg_match('/^published: false$/m', $article), $slug.' stays a draft until a hand validates it.');
        }
        $llms = (string) file_get_contents(self::LLMS);
        $this->assertStringNotContainsString('## Articles', $llms, 'No index of what is not published.');
        foreach (self::ARTICLES as $slug) {
            $this->assertStringNotContainsString('php-regex.com/blog/'.$slug, $llms, $slug.' is indexed nowhere.');
        }
    }
}
