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

use PHPRegex\Tests\Support\FrontMatter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each recipe of the cookbook has a page of its own: one address a search
 * result ranks for one need. A page carries, in its front matter, the
 * pattern its row of the Quick Reference shows, and that row links to it.
 * The cookbook promises every pattern of a page safe, and the analyzer
 * proves it.
 *
 * What the page says the pattern matches and rejects is what the engine
 * matches and rejects: a line of a list is the mark, one space, the
 * subject, and a reason in parentheses after two spaces or more. Every
 * other pattern the page shows compiles. The one link the page sends to
 * the playground spells the pattern of the page, and every link of the
 * page lands.
 */
#[Group('documentation')]
final class CookbookPagesTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    private const INDEX = __DIR__.'/../../docs/cookbook.md';

    /**
     * The pages of the recipes, in the order the Quick Reference lists them.
     */
    private const SLUGS = ['email', 'url', 'uuid', 'ipv4', 'ipv6', 'date', 'time', 'datetime', 'slug', 'username', 'password', 'phone-number', 'credit-card', 'hex-color', 'semantic-version'];

    /**
     * A row of the Quick Reference: where the name links, and the pattern
     * the row shows. A row is HTML, and the site turns the file a Markdown
     * link names into an address without reading an HTML attribute: a row
     * names the file of its page through the link tag of the site, which
     * stops the build when the page is not there.
     */
    private const ROW = '/<li class="recipe-entry" data-copy="">.*?<a class="recipe-entry-name" href="(?P<href>[^"]+)">(?P<name>[^<]*)<\/a>.*?<code class="recipe-pattern">(?P<pattern>[^<]*)<\/code>/s';

    /**
     * A line of a list of examples: the mark, one space, then the subject.
     */
    private const EXAMPLE = '/^(?P<mark>[✓✗]) (?P<rest>\S.*)$/u';

    /**
     * The link the rule pages and the recipes send to the playground.
     */
    private const PLAYGROUND = '/\[Open `(?P<spelled>[^`]+)` in the playground\]\(\/#pattern=(?P<encoded>[^)&\s]+)&subject=(?P<subject>[^)\s]*)\)/';

    /**
     * @return iterable<string, array{slug: string}>
     */
    public static function providePages(): iterable
    {
        foreach (self::SLUGS as $slug) {
            yield $slug => ['slug' => $slug];
        }
    }

    #[Test]
    #[DataProvider('providePages')]
    public function test_a_page_carries_the_pattern_of_its_row_and_the_analyzer_proves_it_safe(string $slug): void
    {
        $pattern = $this->pattern($slug);

        $this->assertSame($pattern, $this->row($slug)['pattern'], $slug.' carries the pattern its row of the Quick Reference shows.');
        $this->assertSame('safe (proven)', Regex::create()->redos($pattern)->headline(), $slug.': the cookbook promises the pattern safe, and the analyzer proves it.');
    }

    #[Test]
    #[DataProvider('providePages')]
    public function test_what_a_page_matches_and_rejects_is_what_the_engine_matches_and_rejects(string $slug): void
    {
        $pattern = $this->pattern($slug);
        $fences = self::fences($this->text($slug));

        $lists = self::lists($fences);
        $matches = $lists['✓'] ?? null;
        $rejects = $lists['✗'] ?? null;
        if (null === $matches || null === $rejects) {
            self::fail($slug.' shows what its pattern '.(null === $matches ? 'matches' : 'rejects').'.');
        }

        // The lists of the first pattern are the first lists of the page:
        // no other pattern stands between that pattern and its examples.
        foreach (['✓' => $matches, '✗' => $rejects] as $mark => $list) {
            foreach (self::patterns($fences, $pattern) as $other) {
                $this->assertLessThan($other['line'], $list['line'], $slug.': the lists of its pattern come before the pattern '.\var_export($other['pattern'], true).'.');
            }

            foreach ($list['lines'] as $line => $text) {
                $at = $slug.' line '.$line.': ';

                $this->assertSame(1, preg_match(self::EXAMPLE, $text, $example), $at.'a line is the mark, one space, then the subject.');
                $parts = (array) preg_split('/ {2,}/', $example['rest'], 2);
                $subject = $parts[0];
                $reason = trim($parts[1] ?? '');
                $this->assertTrue('' === $reason || 1 === preg_match('/^\(.*\)$/u', $reason), $at.'a reason stands in parentheses.');

                $returned = preg_match($pattern, $subject);
                $this->assertSame('✓' === $mark ? 1 : 0, $returned, $at.\var_export($subject, true).' is '.('✓' === $mark ? 'accepted' : 'refused').' by the pattern of the page.');
            }
        }
    }

    #[Test]
    #[DataProvider('providePages')]
    public function test_every_pattern_a_page_shows_compiles_and_is_proven_safe(string $slug): void
    {
        $pattern = $this->pattern($slug);
        $shown = self::patterns(self::fences($this->text($slug)), null);

        $this->assertContains($pattern, array_column($shown, 'pattern'), $slug.' shows the pattern of its front matter.');

        foreach ($shown as $one) {
            $problem = self::compiled($one['pattern']);
            $this->assertNull($problem, $slug.' line '.$one['line'].': '.($problem ?? '').' '.\var_export($one['pattern'], true));
            $this->assertSame('safe (proven)', Regex::create()->redos($one['pattern'])->headline(), $slug.' line '.$one['line'].': the cookbook promises every pattern of a page safe, and the analyzer proves '.\var_export($one['pattern'], true));
        }
    }

    #[Test]
    #[DataProvider('providePages')]
    public function test_a_page_sends_its_own_pattern_to_the_playground_once(string $slug): void
    {
        $pattern = $this->pattern($slug);
        $text = $this->text($slug);

        $this->assertSame(1, preg_match_all('/\]\(\/(?:#|%23)pattern=[^)\s]+\)/', $text), $slug.' holds one link to the playground.');
        $this->assertSame(1, preg_match_all(self::PLAYGROUND, $text, $links, \PREG_SET_ORDER), $slug.' sends its pattern in the form the rule pages use.');

        $spelled = $links[0]['spelled'] ?? null;
        $encoded = $links[0]['encoded'] ?? null;
        $subject = $links[0]['subject'] ?? null;
        $this->assertIsString($spelled);
        $this->assertIsString($encoded);
        $this->assertIsString($subject);
        $this->assertSame($pattern, $spelled, $slug.': the text of the link spells the pattern of the page.');
        $this->assertSame($pattern, rawurldecode($encoded), $slug.': the link opens the pattern of the page.');
        $this->assertSame(1, preg_match($pattern, rawurldecode($subject)), $slug.': the link opens on a subject the pattern matches.');
        $this->assertLessThanOrEqual(2000, \strlen($encoded), $slug.': the pattern of the link stays under the limit of the address.');
    }

    #[Test]
    #[DataProvider('providePages')]
    public function test_a_page_hangs_under_the_cookbook_and_the_index_links_to_it(string $slug): void
    {
        $path = self::DOCS.'cookbook/'.$slug.'.md';
        $this->assertFileExists($path, 'The recipe '.$slug.' has a page.');

        $this->assertSame('/cookbook/', FrontMatter::of($path)['parent'] ?? null, $slug.' hangs under the cookbook.');
        $this->assertSame(self::href($slug), $this->row($slug)['href'], $slug.' is where the link of its row goes.');
        $this->assertStringContainsString('](../cookbook.md)', (string) file_get_contents($path), $slug.' links back to the cookbook.');
    }

    /**
     * A link of a page names the file it goes to, or an address: the site
     * resolves a link to a file into the address of its page. The source
     * of a recipe stands in a directory beside the cookbook, whose own
     * address is that directory: a link to the cookbook written with its
     * address would lead one directory off.
     */
    #[Test]
    #[DataProvider('providePages')]
    public function test_every_link_of_a_page_names_a_file_or_an_address(string $slug): void
    {
        preg_match_all('/\]\(([^)\s]+)\)/', self::prose($this->text($slug)), $links);

        $this->assertNotEmpty($links[1], $slug.' holds links.');

        foreach ($links[1] as $href) {
            $this->assertIsString($href);
            if (str_starts_with($href, '/') || 1 === preg_match('~^(?:https?://|mailto:|#)~', $href)) {
                continue;
            }

            $file = (string) preg_replace('/#.*$/', '', $href);
            $this->assertMatchesRegularExpression('~\.md$~', $file, $slug.': "'.$href.'" names a file the site resolves, not the address of a page.');
            $this->assertFileExists(self::DOCS.'cookbook/'.$file, $slug.': "'.$href.'" goes to a page that exists.');
        }
    }

    /**
     * The Quick Reference is an index: one row per page, each linking to
     * the page of its recipe, in the order of the pages; and no page
     * stands without a row.
     */
    #[Test]
    public function test_the_quick_reference_leads_to_the_pages_one_row_each(): void
    {
        preg_match_all(self::ROW, self::quickReference(), $rows, \PREG_SET_ORDER);
        $hrefs = [];
        foreach ($rows as $row) {
            $hrefs[] = (string) $row['href'];
        }

        $this->assertSame(array_map([self::class, 'href'], self::SLUGS), $hrefs, 'The rows lead to the pages of the recipes, one row each.');

        $pages = array_map(static fn (string $page): string => basename($page, '.md'), glob(self::DOCS.'cookbook/*.md') ?: []);
        sort($pages);
        $slugs = self::SLUGS;
        sort($slugs);
        $this->assertSame($slugs, $pages, 'The directory of the cookbook holds the pages of the recipes and nothing else.');
    }

    /**
     * How a row of the Quick Reference names the page of a recipe.
     */
    private static function href(string $slug): string
    {
        return '{% link cookbook/'.$slug.'.md %}';
    }

    /**
     * The row of the Quick Reference whose link goes to the page.
     *
     * @return array{href: string, name: string, pattern: string}
     */
    private function row(string $slug): array
    {
        $href = self::href($slug);
        preg_match_all(self::ROW, self::quickReference(), $rows, \PREG_SET_ORDER);
        foreach ($rows as $row) {
            if ($href === $row['href']) {
                return ['href' => (string) $row['href'], 'name' => (string) $row['name'], 'pattern' => html_entity_decode((string) $row['pattern'], \ENT_QUOTES | \ENT_HTML5, 'UTF-8')];
            }
        }

        self::fail('No row of the Quick Reference links to the page of '.$slug.'.');
    }

    private static function quickReference(): string
    {
        $cookbook = (string) file_get_contents(self::INDEX);
        $start = strpos($cookbook, '## Quick Reference');
        $end = strpos($cookbook, '## How to Use This Cookbook');

        if (false === $start || false === $end) {
            self::fail('The Quick Reference section is missing.');
        }

        return substr($cookbook, $start, $end - $start);
    }

    /**
     * The pattern a page names in its front matter.
     */
    private function pattern(string $slug): string
    {
        $path = self::DOCS.'cookbook/'.$slug.'.md';
        $this->assertFileExists($path, 'The recipe '.$slug.' has a page.');
        $pattern = FrontMatter::of($path)['pattern'] ?? null;
        $this->assertIsString($pattern, $slug.' names its pattern in its front matter.');

        return $pattern;
    }

    private function text(string $slug): string
    {
        $path = self::DOCS.'cookbook/'.$slug.'.md';
        $this->assertFileExists($path, 'The recipe '.$slug.' has a page.');

        return (string) file_get_contents($path);
    }

    /**
     * The fences of a page: their language (none for a bare fence), their
     * code, and the page line the code starts at.
     *
     * @return list<array{language: string, code: string, line: int}>
     */
    private static function fences(string $text): array
    {
        $fences = [];
        $language = null;
        $code = [];
        $first = 0;
        $fence = '';

        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            if (null === $language) {
                if (1 === preg_match('/^\s*(`{3,}|~{3,})\s*(\S*)/', $line, $open)) {
                    $language = strtolower($open[2]);
                    $fence = $open[1];
                    $code = [];
                    $first = $number + 2;
                }

                continue;
            }

            if (1 === preg_match('/^\s*'.preg_quote($fence[0], '/').'{'.\strlen($fence).',}\s*$/', $line)) {
                $fences[] = ['language' => $language, 'code' => implode("\n", $code), 'line' => $first];
                $language = null;

                continue;
            }

            $code[] = $line;
        }

        return $fences;
    }

    /**
     * The first list of each mark: a bare fence whose every line is an
     * example under that mark, with the page line of each.
     *
     * @param list<array{language: string, code: string, line: int}> $fences
     *
     * @return array{✓: array{line: int, lines: array<int, string>}|null, ✗: array{line: int, lines: array<int, string>}|null}
     */
    private static function lists(array $fences): array
    {
        $lists = ['✓' => null, '✗' => null];
        foreach ($fences as $fence) {
            if ('' !== $fence['language'] || !self::isListOfExamples($fence['code'])) {
                continue;
            }

            $mark = str_starts_with(trim($fence['code']), '✓') ? '✓' : '✗';
            if (null !== $lists[$mark]) {
                continue;
            }

            $lines = [];
            foreach (preg_split('/\R/', $fence['code']) ?: [] as $offset => $line) {
                if ('' !== trim($line)) {
                    $lines[$fence['line'] + $offset] = $line;
                }
            }

            $lists[$mark] = ['line' => $fence['line'], 'lines' => $lines];
        }

        return $lists;
    }

    /**
     * Whether every line of a fence is an example under one mark.
     */
    private static function isListOfExamples(string $code): bool
    {
        $mark = null;
        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (1 !== preg_match(self::EXAMPLE, $line, $example) || (null !== $mark && $mark !== $example['mark'])) {
                return false;
            }

            $mark ??= $example['mark'];
        }

        return null !== $mark;
    }

    /**
     * The bare fences that hold one pattern, as PHP writes it: a
     * delimiter, the body, the delimiter again, and modifiers alone after
     * it. What PHP refuses is a pattern here too: compiling it is the
     * caller's check. With a pattern given, the fences that hold another.
     *
     * @param list<array{language: string, code: string, line: int}> $fences
     *
     * @return list<array{pattern: string, line: int}>
     */
    private static function patterns(array $fences, ?string $beside): array
    {
        $patterns = [];
        foreach ($fences as $fence) {
            $code = trim($fence['code']);
            if ('' !== $fence['language'] || $code === $beside || !self::isOnePattern($code)) {
                continue;
            }

            $patterns[] = ['pattern' => $code, 'line' => $fence['line']];
        }

        return $patterns;
    }

    private static function isOnePattern(string $code): bool
    {
        if (str_contains($code, "\n") || str_starts_with($code, '✓') || str_starts_with($code, '✗')) {
            return false;
        }

        $opening = $code[0];
        if (1 === preg_match('/[\p{L}\p{N}_\s\\\\]/u', $opening)) {
            return false;
        }

        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$opening] ?? $opening;
        $escaped = false;
        for ($i = 1, $length = \strlen($code); $i < $length; ++$i) {
            if ($escaped) {
                $escaped = false;
            } elseif ('\\' === $code[$i]) {
                $escaped = true;
            } elseif (str_starts_with(substr($code, $i), $closing)) {
                return 1 === preg_match('/^[a-zA-Z]*$/', substr($code, $i + \strlen($closing)));
            }
        }

        return false;
    }

    /**
     * Why the engine does not take a pattern in silence, or null when it
     * compiles it without a word.
     */
    private static function compiled(string $pattern): ?string
    {
        $warning = '';
        set_error_handler(static function (int $number, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $returned = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if (false === $returned) {
            return '' === $warning ? 'the engine refuses it without a word.' : 'the engine refuses it: '.$warning;
        }

        return '' === $warning ? null : 'it prints a warning: '.$warning;
    }

    /**
     * The page without its code: a pattern spells brackets and
     * parentheses that read as the end and the start of a link.
     */
    private static function prose(string $text): string
    {
        $withoutFences = (string) preg_replace('/^\s*(`{3,}|~{3,}).*?^\s*\1\s*$/ms', '', $text);

        return (string) preg_replace('/`[^`\n]*`/', '`x`', $withoutFences);
    }
}
