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
 * The playground's Findings mode: the column of examples gains a second
 * source, what the linter reports on real open-source projects, browsed
 * owner by owner, repository by repository, and filtered. In a finding,
 * the text field gives its place to a card that cannot be typed into: the
 * code, the linter's words, the optimizer's rewrite, the witness. Editing
 * is a copy, back in the sandbox. The tests read the include, the script
 * and the stylesheet as the page ships them.
 */
#[Group('documentation')]
final class FindingsLayoutTest extends TestCase
{
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    private const EVENTS = ['run', 'example_loaded', 'finding_opened', 'share_clicked', 'voice_toggled', 'version_changed'];

    #[Test]
    public function test_column_one_holds_a_segmented_examples_findings_control(): void
    {
        $xpath = self::include();

        $groups = $xpath->query(self::byClass('pg-examples').'/li[1]/*[@role="radiogroup"]');
        $this->assertNotFalse($groups);
        $this->assertCount(1, $groups, 'The column\'s head is its control: which list it shows.');
        $group = $groups->item(0);
        $this->assertInstanceOf(\DOMElement::class, $group);
        $this->assertStringContainsString('pg-source', (string) $group->getAttribute('class'));
        $this->assertSame('Source', $group->getAttribute('aria-label'));

        $options = $xpath->query('.//button[@role="radio"]', $group);
        $this->assertNotFalse($options);
        $this->assertCount(2, $options);
        $seen = [];
        foreach ($options as $index => $option) {
            $this->assertInstanceOf(\DOMElement::class, $option);
            $this->assertStringContainsString('pg-source-option', (string) $option->getAttribute('class'), 'The voice toggle\'s dialect: one look for one kind of choice.');
            $seen[$option->getAttribute('data-source')] = trim($option->textContent);
            $this->assertSame(0 === $index ? 'true' : 'false', $option->getAttribute('aria-checked'));
            $this->assertSame(0 === $index ? '' : '-1', $option->getAttribute('tabindex'), 'One stop in the tab order; the arrows move inside.');
        }
        $this->assertSame(['examples' => 'Examples', 'findings' => 'Projects'], $seen, 'The list is named for what it holds: projects, real ones.');
        $this->assertSame(1, preg_match('/function setSource\(source, persist\)/', self::source(self::SCRIPT)), 'One function moves the column between its two lists.');
    }

    #[Test]
    public function test_the_findings_list_is_a_filter_and_a_tree_folded_below_the_widest_screens(): void
    {
        $xpath = self::include();

        $panels = $xpath->query(self::byClass('pg-examples').'/li'.self::has('pg-findings-panel'));
        $this->assertNotFalse($panels);
        $this->assertCount(1, $panels);
        $panel = $panels->item(0);
        $this->assertInstanceOf(\DOMElement::class, $panel);
        $this->assertTrue($panel->hasAttribute('hidden'), 'The examples are the default list.');

        $this->assertSame(1, self::nodes($xpath, './details'.self::has('pg-findings-browse').'/summary[contains(., "Browse")]/*'.self::has('pg-findings-count'), $panel), 'Below 1280px, a folded block says how many projects it holds.');
        $this->assertSame(1, self::nodes($xpath, './/input[@type="search"]'.self::has('pg-findings-filter').'[@aria-label="Filter by owner, repository or rule"]', $panel));
        $this->assertSame(1, self::nodes($xpath, './/ul'.self::has('pg-findings-tree').'[@role="tree"][@aria-label="Projects"]', $panel));
        $this->assertSame(1, self::nodes($xpath, './/a[@href="/findings/"]', $panel), 'The index is one link away, without the header.');
        $this->assertSame(1, self::nodes($xpath, './/a[@href="https://github.com/php-regex/php-regex/issues/new?template=propose-project.yml"]', $panel));

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("fetch('/assets/findings/index.json')", $script, 'The list is fetched from the site itself when it opens, never embedded in every page.');
        $this->assertStringContainsString("fetch('/assets/findings/repos/' + slug + '.json')", $script, 'A repository\'s findings, when it opens.');
        $this->assertStringContainsString("window.matchMedia('(min-width: 1280px)')", $script, 'The widest screens keep the block open.');
        $this->assertStringContainsString("item.setAttribute('role', 'treeitem');", $script);
        $this->assertStringContainsString("item.setAttribute('aria-expanded'", $script);
        $keys = self::between($script, 'function onTreeKey(event) {', "\n  }\n");
        foreach (['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft', 'Home', 'End', 'Enter'] as $key) {
            $this->assertStringContainsString("'".$key."'", $keys, 'The tree walks as the syntax tree does.');
        }
        $filter = self::between($script, 'function filterTree() {', "\n  }\n");
        $this->assertStringContainsString('keepTabStop();', $filter, 'Filtering never hides the tree\'s one tab stop.');
        $this->assertStringContainsString("el.examplesList.setAttribute('aria-label', findings ? 'Projects' : 'Examples');", $script, 'The list is named for what it shows.');
        $this->assertStringContainsString('repo.repo', $filter);
        $this->assertStringContainsString('repo.rules', $filter, 'A rule filters without opening every repository.');
        $row = self::between($script, 'function repoRow(repo) {', "\n  }\n");
        $this->assertStringContainsString("item.title = 'PHP ' + repo.target.php + ' · PCRE2 ' + repo.target.pcre", $row, 'Each project says the engine it was judged for, on its row, without a second line of chrome.');
        $this->assertStringNotContainsString('pg-tree-target', $row, 'A tree of hundreds of rows is one line per row.');
        $findingRow = self::between($script, 'function findingRow(repo, finding) {', "\n  }\n");
        $this->assertStringNotContainsString('pg-tree-message', $findingRow, 'The rule\'s message rides the row\'s tooltip and the card, not a second line.');
        $this->assertStringContainsString("item.setAttribute('aria-description', item.title);", $findingRow, 'A tooltip shows to a pointer only: the full path and the message are read out on focus as well — the description replaces the title for a screen reader, so it says all the title does.');
        $this->assertLessThan(strpos($findingRow, "item.setAttribute('aria-description', item.title);"), strpos($findingRow, 'item.title = '), 'The title is written first.');
        $this->assertStringContainsString("item.setAttribute('aria-description', 'PHP ' + repo.target.php + ' · PCRE2 ' + repo.target.pcre);", $row, 'And the engine a project was judged for.');
    }

    /**
     * Below 1280px the list sits in a fold that a finding's link leaves
     * closed: a row in a closed fold has no geometry to scroll to, so
     * opening the fold brings the shown row into the tree's window.
     */
    #[Test]
    public function test_opening_the_fold_brings_the_shown_row_into_view(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("el.findingsBrowse.addEventListener('toggle', function () {", $script);
        $toggle = self::between($script, "el.findingsBrowse.addEventListener('toggle', function () {", "\n  });\n");
        $this->assertStringContainsString('el.findingsBrowse.open', $toggle, 'Only when it opens.');
        $this->assertStringContainsString('[aria-selected="true"]', $toggle);
        $this->assertStringContainsString('revealRow(', $toggle);
    }

    /**
     * The list is a window of its own, not the page's longest column: at
     * every width the tree scrolls inside a frame tall as the answers
     * panel beside it — a project with three hundred findings opens as a
     * scroll, never as a page twenty screens deep.
     */
    #[Test]
    public function test_the_tree_scrolls_inside_its_column_at_every_width(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/\.pg-findings-tree \{([^}]*)\}/', $stylesheet, $base), 'The base tree rule exists.');
        $this->assertStringContainsString('max-height:', $base[1], 'Below the widest screens the tree scrolls.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-findings-tree \{([^}]*)\}/', $stylesheet, $wide), 'The widest screens keep the tree a window.');
        $this->assertStringNotContainsString('max-height: none', $wide[1], 'No width lets the list stretch the page.');
        $this->assertStringContainsString('clamp(', $wide[1], 'The window\'s height answers the viewport, as the answers panel does.');
    }

    /**
     * The engine a finding was judged for is two mono chips and a quiet
     * date — not a sentence wrapped over two lines; and the row the window
     * shows carries a rail, visible among three hundred siblings.
     */
    #[Test]
    public function test_the_card_names_the_engine_in_chips_and_the_shown_row_carries_a_rail(): void
    {
        $script = self::source(self::SCRIPT);
        $card = self::between($script, 'function renderFindingCard(repo, finding) {', "\n  }\n");
        $this->assertStringContainsString("'pg-finding-engine', 'PHP ' + repo.target.php", $card);
        $this->assertStringContainsString("'pg-finding-engine', 'PCRE2 ' + repo.target.pcre", $card);
        $this->assertStringContainsString('code as of \' + repo.committed', $card);
        $this->assertStringNotContainsString("'Judged for PHP ' + repo.target.php + ' · PCRE2 '", $card, 'No prose line: chips, then the date.');

        $markLast = self::between($script, 'function markLast() {', "\n  }\n");
        $this->assertStringContainsString('revealRow(item);', $markLast, 'The shown row is brought into the tree\'s window.');
        $this->assertStringNotContainsString('scrollIntoView', $markLast, 'scrollIntoView scrolls every ancestor: on a narrow screen it would pull the page from the card up to the tree.');
        $reveal = self::between($script, 'function revealRow(item) {', "\n  }\n");
        $this->assertStringContainsString('var tree = el.findingsTree;', $reveal);
        $this->assertStringContainsString('tree.scrollTop = ', $reveal, 'The tree scrolls, the page stays where it is.');
        $this->assertStringNotContainsString('scrollIntoView', $reveal);

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/\.pg-tree-item\[aria-selected="true"\] > \.pg-tree-row \{([^}]*)\}/', $stylesheet, $rail));
        $this->assertStringContainsString('inset 2px 0 0 var(--amber)', $rail[1], 'The shown row carries a rail on its edge.');
        $this->assertSame(1, preg_match('/\.hero-playground \.hero-inner \{([^}]*)\}/', $stylesheet, $wide), 'The playground home is wider than the docs column.');
        $this->assertStringContainsString('padding-inline: max(1.25rem, calc(50vw - 45rem))', $wide[1], 'A tool page: 90rem centered, the docs keep their 75rem.');
        // On its own tint over --code-bg, --ok reads at 4.13:1 in the light
        // theme, under the 4.5:1 small text needs; a fifth of --ink brings
        // it to 5.06:1 there and 9.10:1 in the dark theme.
        $this->assertSame(1, preg_match('/\.pg-fix-add \{([^}]*)\}/', $stylesheet, $added));
        $this->assertStringContainsString('color: color-mix(in srgb, var(--ok) 80%, var(--ink));', $added[1], 'An added line keeps 4.5:1 in both themes.');
    }

    /**
     * One group stands open at a time, at each level: opening a project
     * closes the others, opening a repository closes its siblings — the
     * list stays a window, never a wall. The keyboard opens as the
     * pointer does; a link or the filter still opens what they name,
     * outside the accordion.
     */
    #[Test]
    public function test_one_group_stands_open_at_a_time(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('function collapseSiblings(item) {', $script, 'The accordion exists.');
        $siblings = self::between($script, 'function collapseSiblings(item) {', "\n  }\n");
        $this->assertStringContainsString("getAttribute('aria-expanded') === 'true'", $siblings);
        $this->assertStringContainsString('expandItem(sibling, false);', $siblings);
        $this->assertStringContainsString('function openGroup(item) {', $script);
        $open = self::between($script, 'function openGroup(item) {', "\n  }\n");
        $this->assertStringContainsString('collapseSiblings(item);', $open, 'Opening a group closes its level first.');
        $this->assertStringContainsString('expandItem(item, true);', $open);
        $activate = self::between($script, 'function activateItem(item) {', "\n  }\n");
        $this->assertStringContainsString('openGroup(item);', $activate, 'A click that opens runs the accordion.');
        $keys = self::between($script, 'function onTreeKey(event) {', "\n  }\n");
        $this->assertStringContainsString('openGroup(item);', $keys, 'The arrow key opens as the pointer does.');
        $this->assertStringContainsString('return expandItem(item, true);', $open, 'The rows of a repository arrive with its data: the caller waits for them.');
    }

    /**
     * Back to the finding, after the accordion closed its group, opens
     * the finding's project and repository again: the row it shows is in
     * view, not inside a closed group. A row the visitor clicks is in an
     * open group already, and the groups the filter opened stay open.
     */
    #[Test]
    public function test_restoring_a_finding_reopens_its_group(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('function revealRepo(repo) {', $script);
        $reveal = self::between($script, 'function revealRepo(repo) {', "\n  }\n");
        $this->assertStringContainsString('[data-owner="', $reveal);
        $this->assertStringContainsString('[data-slug="', $reveal);
        $this->assertStringContainsString("getAttribute('aria-expanded') === 'false'", $reveal, 'Only a closed group is opened: an open one, and its siblings, are left as they are.');
        $this->assertSame(2, substr_count($reveal, 'openGroup('), 'The project, then the repository, through the accordion.');

        $restore = self::between($script, 'function openFindingKey(key) {', "\n  }\n");
        $this->assertStringContainsString('revealRepo(repo)', $restore, 'Back to the finding reopens its group.');
        $this->assertStringContainsString('revealRepo(repo)', self::between($script, 'function openRepoPage(name) {', "\n  }\n"), 'A repository\'s page opens its group the same way.');
    }

    #[Test]
    public function test_the_card_is_read_only_and_takes_the_text_fields_place(): void
    {
        $xpath = self::include();

        $cards = $xpath->query(self::byClass('pg-main').'/'.self::self('pg-field-text').'/following-sibling::*[1]'.self::has('pg-finding-card'));
        $this->assertNotFalse($cards);
        $this->assertCount(1, $cards, 'The card stands where the text field stands.');
        $card = $cards->item(0);
        $this->assertInstanceOf(\DOMElement::class, $card);
        $this->assertTrue($card->hasAttribute('hidden'), 'The sandbox is the default.');
        $this->assertSame(0, self::nodes($xpath, './/textarea|.//input|.//*[@contenteditable]', $card), 'Nothing in the card takes typing.');

        foreach (['pg-finding-head', 'pg-finding-target', 'pg-snippet', 'pg-snippet-permalink', 'pg-finding-issues', 'pg-rewrite', 'pg-witness', 'pg-finding-outcome', 'pg-finding-copy', 'pg-share-copy', 'pg-share-native'] as $part) {
            $this->assertSame(1, self::nodes($xpath, './/*'.self::has($part), $card), $part);
        }
        foreach (['pg-rewrite', 'pg-witness', 'pg-finding-outcome', 'pg-share-native'] as $part) {
            $this->assertSame(1, self::nodes($xpath, './/*'.self::has($part).'[@hidden]', $card), $part.' shows only where it has something to say.');
        }
        $this->assertSame(1, self::nodes($xpath, './/button'.self::has('pg-finding-copy').'[normalize-space()="Edit a copy"]', $card));

        $script = self::source(self::SCRIPT);
        $this->assertSame(1, preg_match('/function enterFinding\(repo, finding, run\) \{[\s\S]*?el\.textField\.hidden = true;[\s\S]*?el\.findingCard\.hidden = false;/', $script));
        $issues = self::between($script, 'function renderIssues(finding) {', "\n  }\n");
        $this->assertStringContainsString('issue.message', $issues);
        $this->assertStringContainsString('issue.hint', $issues);
        $this->assertStringContainsString('link.href = issue.docs;', $issues, 'Each issue links to its rule\'s page on this site.');
        $this->assertStringContainsString("issue.level === 'fail' ? 'FAIL' : 'WARN'", $issues, 'The words of the linter\'s own log.');
        $this->assertSame(1, self::nodes($xpath, './/*'.self::has('pg-rewrite-title').'[normalize-space()="Suggested rewrite, by the optimizer"]', $card), 'A rewrite is labelled as the optimizer\'s suggestion, nothing more claimed.');
    }

    #[Test]
    public function test_nothing_invites_an_issue_on_a_projects_tracker(): void
    {
        $this->assertStringNotContainsString('issues/new', self::source(self::SCRIPT), 'A list of automatic findings never prefills an issue on someone\'s tracker.');
        $this->assertSame(0, self::nodes(self::include(), '//*[contains(@class, "pg-finding-issue ") or @class="pg-finding-issue" or contains(@class, "pg-fix")]'));
    }

    #[Test]
    public function test_the_witness_line_marks_the_pump_and_states_the_replay(): void
    {
        $body = self::between(self::source(self::SCRIPT), 'function renderWitness(finding) {', "\n  }\n");

        $this->assertStringContainsString('finding.witness.prefix', $body);
        $this->assertStringContainsString("'pg-witness-pump'", $body, 'The pump is the repeated part, marked as the snippet marks the hotspot.');
        $this->assertStringContainsString("' × ' + finding.witness.n", $body);
        $this->assertStringContainsString('finding.witness.suffix', $body);
        $this->assertStringContainsString("'Replayed on PCRE2 ' + finding.witness.pcre + ' (JIT off, backtrack limit ' + finding.witness.limit + '): preg_match() gives up from length ' + finding.witness.length + '.'", $body, 'The log\'s own sentence.');
    }

    #[Test]
    public function test_edit_a_copy_copies_the_raw_witness_and_returns_to_sandbox(): void
    {
        $body = self::between(self::source(self::SCRIPT), 'function editCopy() {', "\n  }\n");

        $this->assertStringContainsString('el.subject.value = activeFinding.subject;', $body, 'The raw string, not the decomposition the card shows.');
        $this->assertStringNotContainsString('witness.pump', $body);
        $this->assertStringContainsString("leaveFinding('copy')", $body);
        $this->assertStringContainsString('el.subject.focus()', $body);
    }

    #[Test]
    public function test_the_first_pattern_edit_leaves_finding_mode(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertSame(1, preg_match("/if \\(area === el\\.pattern && activeFinding\\) \\{\\s*leaveFinding\\('edit'\\);/", $script), 'Editing leaves the exhibit: what follows is a copy.');
        $leave = self::between($script, 'function leaveFinding(reason) {', "\n  }\n");
        $this->assertStringContainsString("'Edited copy, no longer the finding.'", $leave);
        $this->assertStringContainsString("window.history.replaceState(null, '', '/');", $leave, 'Off a finding, the address is the home\'s, the finding\'s id gone with it.');
        $this->assertStringContainsString('lastFinding = activeFinding.key', $leave, 'The finding stays one click away.');
    }

    #[Test]
    public function test_a_finding_address_never_carries_a_pattern(): void
    {
        $script = self::source(self::SCRIPT);

        $write = self::between($script, 'function writeHash(request) {', "\n  }\n");
        $this->assertStringContainsString("if (activeFinding || window.location.pathname.indexOf('/findings/') === 0) {\n      return;\n    }", $write, 'A link that shows one pattern under another\'s card cannot be built, not even before the finding arrives.');
        $open = self::between($script, 'function openRepoPage(name) {', "\n  }\n");
        $this->assertLessThan(strpos($open, 'loadIndex()'), strpos($open, "var wanted = window.location.hash.replace(/^#/, '');"), 'The finding the link names is read before anything can rewrite the address.');
        $this->assertStringContainsString('if (pageTouched) {', $open, 'What the visitor typed while the findings loaded is never overwritten.');
        $this->assertStringContainsString("window.history.replaceState(null, '', '/');", self::between($open, 'if (pageTouched) {', "\n        }\n"), 'A visitor who acted first is in the sandbox: the address is the home\'s, where a pattern may be written.');
        foreach (['function activateItem(item) {', "Array.prototype.forEach.call(el.sources, function (option) {\n    option.addEventListener('click', function () {"] as $gesture) {
            $this->assertStringContainsString('pageTouched = true;', self::between($script, $gesture, "\n    });\n"), $gesture.': a gesture made while the findings load wins over the page\'s own finding.');
        }
        $this->assertStringContainsString("' is not in the latest run; '", $open, 'A link to a finding gone says so.');
        $this->assertSame(1, preg_match("/if \\(pageRepo && readHash\\(\\)\\) \\{\\s*window\\.location\\.replace\\('\\/' \\+ window\\.location\\.hash\\);/", $script), 'A findings page reached with a pattern hash sends it home.');
        $this->assertSame(1, preg_match("/function findingUrl\\(repo, finding\\) \\{\\s*return window\\.location\\.origin \\+ '\\/findings\\/' \\+ repo\\.repo \\+ '\\/#' \\+ finding\\.id;/", $script), 'A finding shares as its repository\'s page and its id.');
    }

    #[Test]
    public function test_storage_keys_are_named_and_guarded(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString("var SOURCE_STORAGE_KEY = 'playground-source';", $script);
        $this->assertStringContainsString("var FINDING_STORAGE_KEY = 'playground-finding';", $script);
        $this->assertStringContainsString("var STORAGE_KEY = 'php-version';", $script, 'The existing keys stay as they are: no migration, no reset.');
        $this->assertStringContainsString("var TOOL_STORAGE_KEY = 'playground-tool';", $script);
        $this->assertStringContainsString("var VOICE_STORAGE_KEY = 'playground-voice';", $script);

        preg_match_all('/^.*localStorage\.\w+\((?:SOURCE|FINDING)_STORAGE_KEY.*$/m', $script, $uses);
        $this->assertNotSame([], $uses[0]);
        foreach ($uses[0] as $use) {
            $this->assertMatchesRegularExpression('/^\s*(?:try \{ )?(?:var \w+ = |\w+ = )?localStorage/', $use);
        }
        $this->assertSame(\count($uses[0]), substr_count(self::between($script, '// --- the source ---', '// --- the URL hash'), 'catch (error)'), 'Every access is in a try: a private window keeps the defaults.');
    }

    #[Test]
    public function test_the_home_restores_the_source_not_the_finding(): void
    {
        $script = self::source(self::SCRIPT);
        $paint = self::between($script, '// --- first paint ---', '})();');

        $this->assertSame(1, preg_match("/var pageRepo = root\\.getAttribute\\('data-findings-repo'\\) \\|\\| '';/", $script));
        $this->assertSame(1, preg_match('/\} else if \(pageRepo\) \{\s*openRepoPage\(pageRepo\);/', $paint), 'A repository\'s page opens its finding: the link was the gesture.');
        $this->assertStringContainsString('setSource(storedSource, false);', $paint, 'The home comes back to the list the visitor chose…');
        $this->assertStringNotContainsString('enterFinding(', $paint, '…and never opens a finding unasked.');
        $this->assertStringContainsString('lastFinding = storedFinding;', $script, 'The last one is lit, one click away.');
    }

    #[Test]
    public function test_a_finding_runs_on_the_engine_it_was_judged_for_when_the_page_has_it(): void
    {
        $enter = self::between(self::source(self::SCRIPT), 'function enterFinding(repo, finding, run) {', "\n  }\n");

        $this->assertStringContainsString('VERSIONS.indexOf(judged) !== -1', $enter, 'The project\'s PHP when the playground offers it; the visitor\'s otherwise.');
        $this->assertStringNotContainsString('localStorage', $enter, 'The visitor\'s own choice of PHP is left as it was.');
        $this->assertStringContainsString("track('finding_opened', { finding_id: finding.id })", $enter);
        $this->assertStringContainsString('analyzeNow();', $enter, 'Opening a finding is a gesture: the engine answers it.');
    }

    #[Test]
    public function test_share_cluster_has_copy_and_native_share_and_no_third_party_endpoint(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString('navigator.share', $script);
        $this->assertStringContainsString('navigator.clipboard', $script);
        $this->assertStringContainsString("track('share_clicked', { finding_id: activeFinding.id", $script);
        foreach (['api.github.com', 'avatars.githubusercontent.com', 'twitter.com', 'x.com/intent'] as $host) {
            $this->assertStringNotContainsString($host, $script, 'Everything a finding shows was frozen when it was generated.');
        }
        $this->assertStringNotContainsString('innerHTML', $script, 'Every finding string goes through textContent.');
    }

    #[Test]
    public function test_every_gtag_event_is_a_named_gesture_without_content(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertSame(1, preg_match('/function track\(name, params\) \{\s*if \(typeof window\.gtag === \'function\'\) \{\s*window\.gtag\(\'event\', name, params \|\| \{\}\);/', $script), 'One way to the analytics, silent when they are absent.');
        $this->assertSame(1, substr_count($script, 'window.gtag('), 'No other call reaches the analytics.');
        preg_match_all("/track\\('([a-z_]+)'(.*)\\);/", $script, $calls, \PREG_SET_ORDER);
        $names = array_values(array_unique(array_column($calls, 1)));
        sort($names);
        $events = self::EVENTS;
        sort($events);
        $this->assertSame($events, $names, 'The gestures, named.');
        foreach ($calls as [$call, , $params]) {
            foreach (['el.pattern', 'el.subject', '.value', '.regex', '.subject', 'request', 'pattern'] as $content) {
                $this->assertStringNotContainsString($content, $params, $call.': a gesture and a finding id, never what was typed.');
            }
        }
    }

    #[Test]
    public function test_findings_speak_the_house_tokens_and_fonts(): void
    {
        $section = self::between(self::source(self::STYLESHEET), '/* --- Findings', '/* --- end of Findings */');

        $this->assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $section), 'No new color: the tokens say it all.');
        foreach (['.pg-source-option {', '.pg-findings-filter {', '.pg-finding-actions button {', '.pg-finding-back {'] as $control) {
            $this->assertSame(1, preg_match('/'.preg_quote($control, '/').'[^}]*font-family: var\(--font-/', $section), $control.': a control left to the browser speaks its own font.');
        }
        $this->assertStringContainsString('.pg-field-text[hidden]', $section, 'A displayed field would show through the hidden attribute.');
        $this->assertStringContainsString('.pg-snippet-line.is-focus', $section);
        $this->assertStringContainsString('.pg-snippet-hot', $section);
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{[^}]*\.pg-findings-browse > summary \{\s*display: none;/', $section), 'The widest screens show the list without its fold.');
    }

    private static function include(): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><body>'.self::source(self::INCLUDE).'</body>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private static function nodes(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): int
    {
        $nodes = $xpath->query($query, $context);
        self::assertNotFalse($nodes, $query);

        return $nodes->length;
    }

    private static function has(string $class): string
    {
        return '[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]';
    }

    private static function self(string $class): string
    {
        return '*'.self::has($class);
    }

    private static function byClass(string $class): string
    {
        return '//'.self::self($class);
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
        return (string) file_get_contents($path);
    }
}
