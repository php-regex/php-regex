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

        $this->assertStringContainsString('{% include playground.html findings_repo=page.findings_repo pattern=page.finding_pattern card=page.finding_card %}', $page);
        $this->assertStringContainsString('{{ page.findings_php }}', $page, 'The PHP the project was judged for.');
        $this->assertStringContainsString('{{ page.findings_pcre }}', $page, 'And the PCRE2 that PHP bundles.');
        $this->assertStringContainsString('{{ page.findings_committed }}', $page, 'The code as of its commit.');
        $this->assertStringContainsString('{% if include.findings_repo %} data-findings-repo="{{ include.findings_repo | escape }}"{% endif %}', self::source(self::DOCS.'/_includes/playground.html'));

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
            $this->assertMatchesRegularExpression('/^finding_pattern: ".*"$/m', $front, 'The tool opens on the first finding.');
            $this->assertStringContainsString('finding_card: "<div class=\\"pg-finding-head\\"><a class=\\"pg-finding-avatar\\" href=\\"https://github.com/', $front, $slug.': the card of that finding stands on the page at the first paint.');
            $this->assertMatchesRegularExpression('/^finding_card: ".*<\/p>"$/m', $front, 'The card ends where the script\'s ends: with the outcome of the replay.');
        }
    }

    /**
     * The page of a repository lists its findings under the tool: where
     * each stands, its pattern, what the linter says and the page of each
     * rule, and the link that replays it in the tool. The list is what a
     * reader without the script reads, and what a search engine finds.
     */
    #[Test]
    public function test_a_repository_page_lists_its_findings_under_the_tool(): void
    {
        $rows = 0;
        foreach (glob(self::DOCS.'/_findings/*.md') ?: [] as $file) {
            $source = self::source($file);
            $front = self::between($source, "---\n", "\n---\n");
            $count = (int) preg_replace('/.*^findings_count: (\d+)$/ms', '$1', $front);
            $this->assertGreaterThan(0, $count, $file);
            $this->assertSame($count, substr_count($source, '<li class="finding-row '), $file.': every finding is on its page, once.');
            $this->assertStringContainsString('<h2 id="findings-list-title">'.$count.' finding'.(1 === $count ? '' : 's').'</h2>', $source, $file);
            $list = self::between($source, '<section class="findings-list"', '</section>');
            $this->assertSame($count, preg_match_all('~<a class="finding-row-replay" href="#f-[0-9a-f]{10}(?:-\d+)?">~', $list), $file.': each finding replays in the tool.');
            preg_match_all('~<a class="finding-row-rule" href="([^"]+)">~', $list, $links);
            foreach ($links[1] as $link) {
                $this->assertMatchesRegularExpression('~^/(?:reference/(?:rules|diagnostics)/|guides/)[a-z0-9/-]+/$~', $link, $file.': the page of a rule, or of a message, on this site.');
            }
            $this->assertSame(0, preg_match('~href="https?://php-regex\.com~', $list), $file.': a link of the page is a path, never the address of the site.');
            $this->assertSame(0, preg_match('~href="https?://php-regex\.com~', $list), $file.': a link of the page is a path, never the address of the site.');
            $this->assertSame(0, preg_match('~\{[%{]~', $list), $file.': nothing in the list opens a tag of the template engine.');
            $rows += $count;
            $this->assertSame($count > 40 ? 1 : 0, preg_match('~<details class="findings-more"><summary>\d+ more findings</summary>~', $source), $file.': only the end of a long list folds.');
        }

        $this->assertGreaterThan(0, $rows, 'The corpus has findings.');
    }

    /**
     * The tool of a repository's page opens on the first finding: its
     * field holds that pattern from the first paint. The height of the
     * field is the height of its text, read from the mirror under the
     * field: the script sets none before it runs, and the page does not
     * move when it does.
     */
    #[Test]
    public function test_the_field_of_the_tool_opens_on_the_first_finding_at_its_height(): void
    {
        $finding = self::source(self::DOCS.'/_includes/finding.html');
        $this->assertStringContainsString('{% include playground.html findings_repo=page.findings_repo pattern=page.finding_pattern card=page.finding_card %}', $finding);

        $include = self::source(self::DOCS.'/_includes/playground.html');
        $this->assertStringContainsString('<pre class="pg-mirror" aria-hidden="true">{% if include.pattern %}{{ include.pattern | escape }}{% endif %}</pre>', $include, 'The mirror under the field holds the pattern.');
        $this->assertStringContainsString('<textarea id="pg-pattern" class="pg-input" rows="1" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" aria-describedby="pg-pattern-count" placeholder="/pattern/flags">{% if include.pattern %}{{ include.pattern | escape }}{% endif %}</textarea>', $include, 'So does the field, escaped.');

        $stylesheet = self::source(self::DOCS.'/assets/css/main.scss');
        $this->assertSame(1, preg_match('/^\.pg-editor-pattern \{([^}]*)\}/m', $stylesheet, $editor), 'The pattern editor has a rule of its own.');
        $this->assertStringContainsString('display: grid;', $editor[1], 'The field and its mirror stand in one cell.');
        $this->assertSame(1, preg_match('/^\.pg-editor\.pg-editor-pattern > \.pg-mirror \{([^}]*)\}/m', $stylesheet, $mirror), 'The mirror of the pattern field is placed apart from the mirrors that overlay.');
        $this->assertStringContainsString('position: relative;', $mirror[1], 'It stands in the row, and gives the row the height of the text: a pattern that wraps gives the row two lines before any script measures the field.');
        $this->assertLessThan((int) strpos($stylesheet, '.pg-editor.pg-editor-pattern > .pg-mirror {'), (int) strpos($stylesheet, "\n.pg-editor .pg-mirror {\n  position: absolute;"), 'Written after the rule of the mirrors that overlay, and heavier: at equal weight the one written last won, and the mirror gave the row nothing.');
    }

    /**
     * The list stands right under the tool, and the engine answers the
     * finding the page opens on after the first paint. The room of the
     * answer is kept from the first paint — the results, the facts of the
     * engine, the note on the JIT — or the list jumps down under the
     * reader's thumb.
     */
    #[Test]
    public function test_the_tool_of_a_project_page_keeps_the_room_of_its_answer(): void
    {
        $stylesheet = self::source(self::DOCS.'/assets/css/main.scss');

        $this->assertSame(1, preg_match('/@media \(max-width: 1023px\) \{\s*\.hero-finding \.pg-body > \.pg-results \{([^}]*)\}/', $stylesheet, $results), 'On a project page the results have a rule of their own, where the layout gives the body no height.');
        $this->assertStringContainsString('height: 22rem;', $results[1], 'They keep one height, whatever the engine answers.');
        $this->assertStringContainsString('overflow: auto;', $results[1], 'What does not fit scrolls inside them.');

        $this->assertSame(1, preg_match('/^\.hero-finding \.pg-facts \{([^}]*)\}/m', $stylesheet, $facts), 'The facts of the engine keep one line.');
        $this->assertStringContainsString('flex-wrap: nowrap;', $facts[1]);
        $this->assertStringContainsString('overflow-x: auto;', $facts[1]);
        $this->assertSame(1, preg_match('/^\.hero-finding \.pg-facts:empty \{([^}]*)\}/m', $stylesheet, $room), 'Empty until the engine answers, the line still stands.');
        $this->assertStringContainsString('display: flex;', $room[1]);
        $this->assertStringContainsString('min-height: calc(', $room[1], 'The height of a chip, so the facts land in it without moving the page.');

        $this->assertSame(1, preg_match('/^\.hero-finding \.pg-caption \{([^}]*)\}/m', $stylesheet, $caption), 'The note on the JIT keeps its lines before it is written.');
        $this->assertStringContainsString('display: block;', $caption[1]);
        $this->assertStringContainsString('min-height: 4.56rem;', $caption[1], 'Four lines from the width of a phone on: a line is 1.14rem.');
        $this->assertMatchesRegularExpression('/@media \(max-width: 359px\) \{\s*\.hero-finding \.pg-caption \{\s*min-height: 5\.7rem;\s*\}\s*\}/', $stylesheet, 'Five lines on the narrowest phones.');
        $this->assertMatchesRegularExpression('/@media \(min-width: 1240px\) \{\s*\.hero-finding \.pg-caption \{\s*min-height: 1\.14rem;\s*\}\s*\}/', $stylesheet, 'One line where the tool is wide.');
    }

    /**
     * The card of the finding the page opens on stands on the page at the
     * first paint, drawn by the report, with the text field and the share
     * buttons hidden as the script hides them. The script then draws the
     * same card over it: the boxes and the words the report writes are
     * the script's own.
     */
    #[Test]
    public function test_the_card_of_the_page_stands_on_it_at_the_first_paint(): void
    {
        $finding = self::source(self::DOCS.'/_includes/finding.html');
        $this->assertStringContainsString('{% include playground.html findings_repo=page.findings_repo pattern=page.finding_pattern card=page.finding_card %}', $finding, 'The tool receives the card the report drew.');

        $include = self::source(self::DOCS.'/_includes/playground.html');
        $this->assertStringContainsString('<div class="pg-finding-card"{% unless include.card %} hidden{% endunless %}>', $include, 'The card is shown when the page brings one, hidden otherwise.');
        $drawn = self::between($include, '{%- if include.card -%}', '{%- else %}');
        $this->assertSame(['{%- if include.card -%}', '{{ include.card }}'], array_map(trim(...), explode("\n", trim($drawn))), 'The card of the report stands in the place of the empty boxes the script fills, and of them only: one set of boxes in the source.');
        $this->assertStringContainsString('<div class="pg-field pg-field-text"{% if include.card %} hidden{% endif %}>', $include, 'In a finding, the text field gives its place to the card.');
        $this->assertStringContainsString('<div class="pg-share-actions"{% if include.card %} hidden{% endif %}>', $include, 'So do the share buttons of the sandbox.');
        $this->assertStringContainsString('<button type="button" class="pg-share-native"{% unless include.card %} hidden{% endunless %}>Share</button>', $include, 'On the card of a page the button of the system\'s share stands from the first paint: the narrow screens are phones, whose browsers share, and the row of the actions would grow a line when it came.');

        $script = self::source(self::DOCS.'/assets/js/playground.js');
        $opening = self::between($script, 'function openRepoPage(name) {', 'var wanted = window.location.hash');
        $this->assertStringContainsString("el.shareNative.hidden = typeof navigator.share !== 'function';", $opening, 'A browser whose hand cannot share drops the button as the page opens, before the findings travel.');
        foreach ([
            "text('span', 'visually-hidden', 'Judged for ')",
            "text('span', 'pg-finding-engine', 'PHP ' + repo.target.php)",
            "'View on GitHub at ' + repo.sha.slice(0, 7)",
            "text('span', 'pg-snippet-line' + (line.focus ? ' is-focus' : ''))",
            "text('span', 'pg-snippet-number', String(line.number))",
            "text('span', 'pg-finding-level', issue.level === 'fail' ? 'FAIL' : 'WARN')",
            "text('span', 'pg-finding-hint', issue.hint)",
            "text('a', 'pg-finding-rule', issue.rule)",
            "text('span', 'pg-fix-del', '- ' + finding.pattern)",
            "text('span', 'pg-rewrite-change', change)",
            "text('span', 'pg-witness-label', 'Witness: ')",
            "text('code', 'pg-witness-pump', '\"' + finding.witness.pump + '\"')",
            "el.outcome.textContent = 'Replayed on PCRE2 ' + finding.witness.pcre + ' (JIT off, backtrack limit ' + finding.witness.limit + '): preg_match() gives up from length ' + finding.witness.length + '.'",
        ] as $drawn) {
            $this->assertStringContainsString($drawn, $script, 'The script draws the card the report writes.');
        }
    }

    /**
     * A finding named under the tool replays it in the tool: the address
     * keeps its id, and the tool comes back into view.
     */
    #[Test]
    public function test_a_finding_named_under_the_tool_replays_it_there(): void
    {
        $script = self::source(self::DOCS.'/assets/js/playground.js');

        $this->assertStringContainsString("link.getAttribute('href').slice(1)", $script, 'The link names its finding by the id of its address.');
        $this->assertStringContainsString("root.scrollIntoView({ block: 'start' });", $script, 'The tool comes back into view.');
        $replay = self::between($script, "document.querySelectorAll('.finding-row-replay').forEach(function (link) {", "\n  });\n");
        $this->assertStringContainsString('event.preventDefault();', $replay, 'The address of the page does not take the id: the tool would not scroll to a row of another id.');
        $this->assertStringContainsString('openFindingKey(', $replay);
    }

    /**
     * A repository's page says where it stands: home, the projects, the
     * repository.
     */
    #[Test]
    public function test_a_repository_page_says_where_it_stands(): void
    {
        $finding = self::source(self::DOCS.'/_includes/finding.html');

        $this->assertSame(1, preg_match('~<script type="application/ld\+json">\s*\{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":\[\{"@type":"ListItem","position":1,"name":"Home","item":\{\{ \'\/\' \| absolute_url \| jsonify \}\}\},\{"@type":"ListItem","position":2,"name":"Projects","item":\{\{ \'\/findings\/\' \| absolute_url \| jsonify \}\}\},\{"@type":"ListItem","position":3,"name":\{\{ page\.findings_repo \| jsonify \}\}\}\]\}\s*</script>~', $finding), 'One trail, three steps, the repository last.');
    }

    #[Test]
    public function test_finding_pages_name_their_card(): void
    {
        $head = self::source(self::DOCS.'/_includes/head.html');

        // A page may name its own card, where no card was drawn for it; the
        // site's stays the default.
        $this->assertSame(1, substr_count($head, "{% assign head_image = page.image | default: '/assets/img/social.png' %}"), 'A page may name its own card; the site\'s stays the default.');
        $this->assertSame(2, substr_count($head, '{{ head_image | absolute_url }}'), 'The Open Graph card and the Twitter card are the same.');
        $this->assertFileExists(self::DOCS.'/assets/img/findings-social.png', 'One card for every repository: nothing on it goes stale.');
        $this->assertFileExists(PlaygroundDriver::ROOT.'/art/findings-social.svg');
        $this->assertStringNotContainsString('confirmed', self::source(PlaygroundDriver::ROOT.'/art/findings-social.svg'), 'Most findings are the linter\'s advice: the card claims nothing an engine did not confirm.');
        $this->assertStringContainsString("{% assign head_image_alt = page.image_alt | default: 'PHPRegex — static analysis, linter and logic solver for PHP regular expressions.' %}", $head, 'The card\'s text alternative follows the card.');
        $this->assertSame(2, substr_count($head, '{{ head_image_alt | escape_once }}'), 'Both cards carry it, escaped.');
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
        $this->assertStringContainsString('page.projects_index', $scripts, 'The script list carries the row filter for this page alone.');

        $filter = self::source(self::DOCS.'/assets/js/projects.js');
        $this->assertStringContainsString("querySelector('.projects-index-name')", $filter, 'The directory narrows by the project\'s name, not by the whole row: a PHP version is not a filter.');
        $this->assertStringNotContainsString('row.textContent.toLowerCase()', $filter);
        $this->assertStringContainsString('<span class="page-count" role="status"></span>', $index, 'The count says itself to a screen reader as it changes.');
        $stylesheet = self::source(self::DOCS.'/assets/css/main.scss');
        $this->assertSame(1, preg_match('/\.projects-index-fail\.is-zero,\s*\.projects-index-warn\.is-zero \{([^}]*)\}/', $stylesheet, $zero), 'A zero count keeps a quieter voice.');
        $this->assertStringNotContainsString('opacity', $zero[1], 'Quieter, never dimmer: the contrast holds in both themes.');
        $this->assertSame(1, preg_match('/@media \(max-width: 479px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.projects-index-name \{([^}]*)\}/', $stylesheet, $narrow), 'On a phone, a name wraps instead of ellipsizing into its neighbours.');
        $this->assertStringContainsString('white-space: normal', $narrow[1]);
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
