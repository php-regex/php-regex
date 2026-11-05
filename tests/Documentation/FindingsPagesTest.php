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
 * Each repository with findings has a page of its own,
 * /findings/<vendor>/<repo>/, which opens the playground on its findings;
 * /findings/ lists them all. The pages are rewritten every day from the
 * corpus run, so they promise nothing a run cannot keep: no feed, no
 * status, no report on the projects' trackers.
 */
#[Group('documentation')]
final class FindingsPagesTest extends TestCase
{
    private const DOCS = PlaygroundDriver::ROOT.'/docs';
    private const GITHUB = PlaygroundDriver::ROOT.'/.github';

    #[Test]
    public function test_the_findings_are_a_collection_without_a_feed(): void
    {
        $config = self::source(self::DOCS.'/_config.yml');

        $this->assertSame(1, preg_match('/^  findings:\n    output: true$/m', $config), 'One page per repository.');
        $this->assertSame(1, preg_match('/^feed:\n  collections:\n    - articles\n\n/m', $config), 'A feed of pages rewritten every day would only be noise.');
        $this->assertStringNotContainsString('findings.xml', self::source(self::DOCS.'/_includes/head.html'));
        $this->assertStringNotContainsString('findings.xml', self::source(self::DOCS.'/_includes/footer.html'));
    }

    #[Test]
    public function test_a_repository_page_opens_the_playground_on_its_findings(): void
    {
        $page = self::source(self::DOCS.'/_includes/finding.html');

        $this->assertStringContainsString('{% include playground.html findings_repo=page.findings_repo %}', $page);
        $this->assertStringContainsString('{{ page.findings_php }}', $page, 'The PHP the project was judged for.');
        $this->assertStringContainsString('{{ page.findings_pcre }}', $page, 'And the PCRE2 that PHP bundles.');
        $this->assertStringContainsString('{{ page.findings_committed }}', $page, 'The code as of its commit.');
        $this->assertStringContainsString('{% if include.findings_repo %} data-findings-repo="{{ include.findings_repo }}"{% endif %}', self::source(self::DOCS.'/_includes/playground.html'));

        foreach (glob(self::DOCS.'/_findings/*.md') ?: [] as $file) {
            $front = self::between(self::source($file), "---\n", "\n---\n");
            $slug = basename($file, '.md');
            [$vendor, $name] = explode('__', str_replace('~', '.', $slug), 2);
            $this->assertStringContainsString("layout: landing\n", $front);
            $this->assertStringContainsString("permalink: /findings/{$vendor}/{$name}/\n", $front);
            $this->assertStringContainsString("findings_repo: {$vendor}/{$name}\n", $front);
            $this->assertMatchesRegularExpression('~^image: /assets/img/findings-social\.png$~m', $front);
            $this->assertMatchesRegularExpression('/^title: ".+: \d+ regex findings?"$/m', $front);
            $this->assertMatchesRegularExpression('/^findings_php: "[\d.]+"$/m', $front);
            $this->assertMatchesRegularExpression('/^findings_pcre: "10\.\d+"$/m', $front);
            $this->assertMatchesRegularExpression('/^findings_rank: \d+$/m', $front, 'The index lists the repositories in the order the playground does.');
            $this->assertMatchesRegularExpression('/^image_alt: ".+"$/m', $front);
        }
    }

    #[Test]
    public function test_finding_pages_name_their_card(): void
    {
        $head = self::source(self::DOCS.'/_includes/head.html');

        $this->assertSame(2, substr_count($head, "{{ page.image | default: '/assets/img/social.png' | absolute_url }}"), 'A page may name its own card; the site\'s stays the default.');
        $this->assertFileExists(self::DOCS.'/assets/img/findings-social.png', 'One card for every repository: nothing on it goes stale.');
        $this->assertFileExists(PlaygroundDriver::ROOT.'/art/findings-social.svg');
        $this->assertStringNotContainsString('confirmed', self::source(PlaygroundDriver::ROOT.'/art/findings-social.svg'), 'Most findings are the linter\'s advice: the card claims nothing an engine did not confirm.');
        $this->assertStringContainsString("{{ page.image_alt | default: 'PHPRegex — static analysis, linter and logic solver for PHP regular expressions.' | escape }}", $head, 'The card\'s text alternative follows the card, escaped as the title is.');
    }

    #[Test]
    public function test_the_index_lists_the_repositories_and_promises_no_more_than_a_run(): void
    {
        $index = self::source(self::DOCS.'/findings.md');

        $this->assertStringContainsString("permalink: /findings/\n", $index);
        $this->assertStringContainsString('site.findings | sort: "findings_rank"', $index);
        $this->assertStringContainsString('finding.findings_php', $index, 'Each project with the PHP and PCRE2 it was judged for.');
        $this->assertStringContainsString('/assets/findings/avatars/{{ owner }}.png', $index, 'Each row carries the project\'s owner, pictured as the tree pictures it.');
        $this->assertStringContainsString('<a href="{{ finding.url }}">', $index, 'The row is the link.');
        $this->assertStringNotContainsString('| Project |', $index, 'A wall of pipes is not a directory: rows, avatars and chips.');
        $this->assertStringNotContainsString('|---', $index);
        $this->assertStringContainsString('projects_index: true', $index, 'The page asks for its row filter.');
        $this->assertStringContainsString('class="page-filter"', $index, 'Two hundred rows are narrowed in place, the deep pages\' filter dialect.');
        $this->assertStringContainsString('class="page-empty"', $index);
        $this->assertStringNotContainsStringIgnoringCase('require.php floor', $index, 'The lead says what the page holds, not how the pipeline reads a manifest.');
        $scripts = self::source(self::DOCS.'/_includes/scripts.html');
        $this->assertStringContainsString("page.projects_index", $scripts, 'The script list carries the row filter for this page alone.');
        $this->assertStringContainsString('bin/corpus-lint', self::source(self::DOCS.'/maintainers.md'), 'The daily run is written down for the maintainer.');
        foreach (['90 days', 'disclos', 'fixed', 'maintainers hear', 'privately'] as $promise) {
            $this->assertStringNotContainsStringIgnoringCase($promise, $index, 'The list is what the linter reports today, nothing more.');
        }

        $footer = self::source(self::DOCS.'/_includes/footer.html');
        $this->assertStringContainsString('<li><a href="/findings/">Projects</a></li>', $footer);
        $this->assertStringNotContainsString('/findings/', self::source(self::DOCS.'/_includes/header.html'), 'Not in the header.');
    }

    #[Test]
    public function test_proposing_a_project_is_the_one_form_left(): void
    {
        $propose = self::source(self::GITHUB.'/ISSUE_TEMPLATE/propose-project.yml');
        $this->assertStringContainsString('id: repository', $propose);
        $this->assertStringContainsString('id: motivation', $propose);
        $this->assertStringContainsString('best effort', $propose);
        $this->assertStringContainsString('public', $propose);
        $this->assertStringNotContainsStringIgnoringCase('maintainers hear', $propose);

        $this->assertFileDoesNotExist(self::GITHUB.'/ISSUE_TEMPLATE/finding-report.yml');
        $this->assertFileDoesNotExist(self::GITHUB.'/workflows/findings-status.yml');
        $this->assertFileDoesNotExist(self::DOCS.'/_includes/finding-tombstone.html');
        $this->assertStringNotContainsString('findings', self::source(PlaygroundDriver::ROOT.'/bin/playground-check'));
    }

    private static function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($haystack, $to, $start + \strlen($from));

        return substr($haystack, $start, false === $end ? null : $end - $start);
    }

    private static function source(string $path): string
    {
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
