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

use PHPRegex\Explain\TextExplainer;
use PHPRegex\Tests\Support\PlaygroundDriver;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The playground's window: a column of examples, then what the visitor
 * writes (the pattern, the text), then what the engine answers, behind
 * one strip of tabs. The tree is the first tab, the one the home's title
 * promises; the explanation is the tree's other voice. The tests read the
 * include, the script and the stylesheet as the page ships them.
 */
#[Group('documentation')]
final class PlaygroundLayoutTest extends TestCase
{
    private const HOME = PlaygroundDriver::ROOT.'/docs/index.md';
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    private const TABS = [
        'tree' => 'Tree',
        'diagnostics' => 'Diagnostics',
        'matches' => 'Matches',
        'redos' => 'ReDoS',
        'transpile' => 'Translate',
        'samples' => 'Samples',
        'format' => 'Format',
        'trace' => 'Step through',
    ];

    #[Test]
    public function test_the_results_panel_holds_the_one_tablist(): void
    {
        $xpath = self::include();

        $tablists = $xpath->query('//*[@role="tablist"]');
        $this->assertNotFalse($tablists);
        $this->assertCount(1, $tablists, 'One strip of tabs: no tool inside a tool.');
        $tablist = $tablists->item(0);
        $this->assertInstanceOf(\DOMElement::class, $tablist);
        $this->assertSame('Results', $tablist->getAttribute('aria-label'));
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-results').'//*[@role="tablist"]'), 'The strip heads the results panel.');
        $this->assertSame(0, self::nodes($xpath, self::byClass('pg-main').'//*[@role="tablist"]'));
        $this->assertSame(0, self::nodes($xpath, self::byClass('pg-main').'//'.self::self('pg-results')), 'The results are not in the column the visitor writes in.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-body').'/'.self::self('pg-results')), 'The results panel is a column of the window body.');
        $this->assertStringNotContainsString('pg-tools-title', self::source(self::INCLUDE));
    }

    #[Test]
    public function test_the_eight_tabs_come_in_order(): void
    {
        $xpath = self::include();
        $tabs = $xpath->query('//*[@role="tab"]');
        $this->assertNotFalse($tabs);

        $seen = [];
        foreach ($tabs as $i => $tab) {
            $this->assertInstanceOf(\DOMElement::class, $tab);
            $tool = $tab->getAttribute('data-tool');
            $label = $xpath->evaluate('string(./text()[normalize-space()][1])', $tab);
            $this->assertIsString($label);
            $seen[$tool] = trim($label);
            $this->assertSame('pg-tab-'.$tool, $tab->getAttribute('id'));
            $this->assertSame('pg-tool-'.$tool, $tab->getAttribute('aria-controls'));
            if (0 === $i) {
                $this->assertSame('true', $tab->getAttribute('aria-selected'), 'The tree is the tab the page opens on.');
                $this->assertFalse($tab->hasAttribute('tabindex'));
            } else {
                $this->assertSame('false', $tab->getAttribute('aria-selected'));
                $this->assertSame('-1', $tab->getAttribute('tabindex'), 'One tab in the tab order, the arrows walk the rest.');
            }

            $panel = $xpath->query('//*[@id="pg-tool-'.$tool.'"]');
            $this->assertNotFalse($panel);
            $this->assertCount(1, $panel);
            $element = $panel->item(0);
            $this->assertInstanceOf(\DOMElement::class, $element);
            $this->assertSame('tabpanel', $element->getAttribute('role'));
            $this->assertSame('pg-tab-'.$tool, $element->getAttribute('aria-labelledby'));
            $this->assertSame('0', $element->getAttribute('tabindex'));
            $this->assertSame(0 !== $i, $element->hasAttribute('hidden'), 'Every panel but the open one is hidden.');
        }

        $this->assertSame(self::TABS, $seen);
    }

    #[Test]
    public function test_the_left_column_holds_no_result_panel(): void
    {
        $xpath = self::include();
        $main = self::byClass('pg-main');

        $this->assertSame(0, self::nodes($xpath, $main.'//'.self::self('pg-panel')));
        $this->assertSame(0, self::nodes($xpath, $main.'//h2 | '.$main.'//h3 | '.$main.'//h4'));
        $this->assertSame(0, self::nodes($xpath, $main.'//*[@role="tab" or @role="tabpanel"]'));
        $this->assertSame(0, self::nodes($xpath, $main.'//'.self::self('pg-match-list').' | '.$main.'//'.self::self('pg-explain').' | '.$main.'//'.self::self('pg-diagnostics')));
        $this->assertStringNotContainsString('pg-tools-note', self::source(self::INCLUDE), 'A note nothing writes.');

        // What stays in the writing column: the two fields. The examples
        // stand in a column of their own, before it.
        $this->assertSame(0, self::nodes($xpath, $main.'//'.self::self('pg-examples')), 'The examples are a column of the window, not a row of the fields.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-body').'/'.self::self('pg-examples')), 'The examples are the body\'s first column.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-body').'/'.self::self('pg-examples').'/following-sibling::*'.self::has('pg-main')), 'The fields follow the examples.');
        $this->assertSame(1, self::nodes($xpath, $main.'//textarea[@id="pg-pattern"]'));
        $this->assertSame(1, self::nodes($xpath, $main.'//textarea[@id="pg-subject"]'));
    }

    #[Test]
    public function test_the_headings_are_the_h1_then_the_tabs(): void
    {
        $xpath = self::include();

        $this->assertSame(0, self::nodes($xpath, '//h1 | //h2 | //h3 | //h4 | //h5 | //h6'), 'The tabs name the panels; the home holds the one title.');
        $this->assertStringContainsString('<h1 class="visually-hidden">See a PHP regex as a tree</h1>', self::source(self::HOME));
    }

    #[Test]
    public function test_the_state_pill_names_the_sample(): void
    {
        $xpath = self::include();
        $state = $xpath->query(self::byClass('pg-state'));
        $this->assertNotFalse($state);
        $this->assertCount(1, $state);
        $element = $state->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);

        $this->assertSame('Sample · press Run', trim($element->textContent), 'Nothing ran yet: the pill says so.');
        $this->assertSame('status', $element->getAttribute('role'));
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("'Sample · press Run'", $script);
        $this->assertStringNotContainsString('Sample rendered ahead of time', $script, 'The pill already says it.');
        $this->assertStringNotContainsString('Press Run: the engine answers this tab', $script, 'Every tab answers from the sample.');
    }

    /**
     * The tint says a pattern is wrong to the eye; the field says it to a
     * screen reader too, with the error it is described by.
     */
    #[Test]
    public function test_an_invalid_pattern_marks_its_field(): void
    {
        $xpath = self::include();
        $this->assertSame(1, self::nodes($xpath, '//*[@id="pg-pattern-error"]'.self::has('visually-hidden')), 'One hidden error line, beside the field.');
        $this->assertSame('pg-pattern-count', $xpath->evaluate('string(//*[@id="pg-pattern"]/@aria-describedby)'), 'Described by the error only while there is one.');

        $errors = self::between(self::source(self::SCRIPT), 'function renderErrors(payload) {', "\n  }\n");
        $this->assertStringContainsString("el.pattern.setAttribute('aria-invalid', 'true');", $errors);
        $this->assertStringContainsString("el.pattern.removeAttribute('aria-invalid');", $errors);
        $this->assertStringContainsString("el.pattern.setAttribute('aria-describedby', invalid ? 'pg-pattern-count pg-pattern-error' : 'pg-pattern-count');", $errors);
        $this->assertStringContainsString('el.patternError.textContent = ', $errors);
    }

    #[Test]
    public function test_the_privacy_chip_lives_in_the_footer(): void
    {
        $xpath = self::include();
        $sentence = 'Nothing you type leaves your browser';

        $chip = $xpath->query(self::byClass('pg-statusbar').'//'.self::self('pg-privacy'));
        $this->assertNotFalse($chip);
        $this->assertCount(1, $chip);
        $element = $chip->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);
        $this->assertStringContainsString($sentence, $element->textContent);
        $this->assertSame(0, self::nodes($xpath, self::byClass('pg-credits').'[contains(., "'.$sentence.'")]'), 'Said once.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertStringNotContainsString(':has(.pg-facts:empty)', $stylesheet, 'The footer frames the window from the first paint.');
        $this->assertStringContainsString('.pg-facts:empty', $stylesheet, 'An empty list of facts takes no room beside the chip.');
    }

    #[Test]
    public function test_the_voice_control_is_a_radiogroup(): void
    {
        $xpath = self::include();
        $tree = '//*[@id="pg-tool-tree"]';

        $this->assertSame(1, self::nodes($xpath, $tree.'//*[@role="radiogroup"]'));
        $radios = $xpath->query($tree.'//*[@role="radiogroup"]/*[@role="radio"]');
        $this->assertNotFalse($radios);
        $this->assertCount(2, $radios);
        $first = $radios->item(0);
        $second = $radios->item(1);
        $this->assertInstanceOf(\DOMElement::class, $first);
        $this->assertInstanceOf(\DOMElement::class, $second);
        $this->assertSame(['Tree', 'Plain English'], [trim($first->textContent), trim($second->textContent)]);
        $this->assertSame(['true', 'false'], [$first->getAttribute('aria-checked'), $second->getAttribute('aria-checked')], 'The tree is the voice a first visit hears.');
        $this->assertSame('-1', $second->getAttribute('tabindex'));

        // Both voices, and what both share: the pattern piece by piece and
        // the line that reads the node under the pointer.
        $this->assertSame(1, self::nodes($xpath, $tree.'//pre'.self::has('tree-pattern')));
        $this->assertSame(1, self::nodes($xpath, $tree.'//ul[@role="tree"]'));
        $this->assertSame(1, self::nodes($xpath, $tree.'//'.self::self('pg-explain')));
        $this->assertSame(1, self::nodes($xpath, $tree.'//p'.self::has('tree-detail').'[@aria-live="polite"][@aria-atomic="true"]'));
        $this->assertStringContainsString("'playground-voice'", self::source(self::SCRIPT));
    }

    #[Test]
    public function test_the_tabs_carry_their_badges(): void
    {
        $xpath = self::include();
        $badged = $xpath->query('//*[@role="tab"][.//*'.self::has('pg-tab-badge').']');
        $this->assertNotFalse($badged);

        $tools = [];
        foreach ($badged as $tab) {
            $this->assertInstanceOf(\DOMElement::class, $tab);
            $tools[] = $tab->getAttribute('data-tool');
        }

        $this->assertSame(['diagnostics', 'matches', 'redos'], $tools, 'The state of the product reads on the closed tabs.');

        // On a phone the counts go before the labels: a badge with a count
        // and no dot of its own shows a dot in its color instead.
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/@media \(max-width: 639px\) \{(?:(?!\n\}).)*\.pg-tab-badge\.is-count::before,\s*\.pg-tab-badge\.is-lint::before \{([^}]*)\}/s', $stylesheet, $dot));
        $this->assertStringContainsString('background: currentColor;', $dot[1]);
    }

    #[Test]
    public function test_the_stored_tab_falls_back_to_tree(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString("var TABS = ['tree', 'diagnostics', 'matches', 'redos', 'transpile', 'samples', 'format', 'trace'];", $script);
        $this->assertStringContainsString("var activeTool = 'tree';", $script, 'A first visit opens on the tree.');
        $this->assertStringContainsString("'playground-tool'", $script, 'The chosen tab is remembered under the key it always had.');
    }

    #[Test]
    public function test_state_tokens_are_declared_in_both_themes(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        foreach (['tokens-light', 'tokens-dark'] as $mixin) {
            $this->assertSame(1, preg_match('/@mixin '.$mixin.' \{(.*?)\n\}/s', $stylesheet, $block), $mixin);
            $this->assertSame(1, preg_match_all('/^\s*--ok:\s*#[0-9A-F]{6};$/m', $block[1]), $mixin.' declares --ok once.');
            $this->assertSame(1, preg_match_all('/^\s*--danger:\s*#[0-9A-F]{6};$/m', $block[1]), $mixin.' declares --danger once.');
        }

        // The measured contrast of each, written where the palette says
        // its own: a state color is read on the page's paper and panels.
        $this->assertSame(1, preg_match('/\/\*[^*]*?State colors[^*]*?\*\//s', $stylesheet, $comment), 'The state tokens say their contrast.');
        foreach (['ok', 'danger'] as $token) {
            $this->assertSame(1, preg_match('/'.$token.'[^.\n]*?\d+\.\d\d/', $comment[0]), $token.' carries a measured ratio.');
        }

        // The playground draws with the tokens, never a raw color of its own.
        $section = substr($stylesheet, (int) strpos($stylesheet, '.hero-playground {'));
        $this->assertSame(0, preg_match('/#[0-9A-Fa-f]{3,8}\b|rgba?\(/', $section), 'The playground section holds no raw color.');
        $this->assertSame(1, substr_count($section, 'var(--tk-str)'), 'A code color is not a state color: the string token colors a token only.');
        $this->assertSame(1, preg_match('/\.pg-tok-anchor \{\s*color: var\(--tk-str\);/', $section));

        // The title bar keeps its navy in both themes: the error pill it
        // holds is read on that navy, not on the page's paper.
        $this->assertSame(1, preg_match('/\.terminal,\n\.pg-titlebar \{([^}]*)\}/', $stylesheet, $bar));
        $this->assertSame(1, preg_match('/--code-bg: (#[0-9A-F]{6});/', $bar[1], $navy));
        $this->assertSame(1, preg_match('/--danger: (#[0-9A-F]{6});/', $bar[1], $danger), 'The title bar says its own danger.');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($danger[1], $navy[1]), 'The Stopped pill is text: 4.5:1 on the navy.');
    }

    #[Test]
    public function test_the_strip_is_pinned_inside_the_panel(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/\.playground \{[^}]*overflow: clip;/', $stylesheet), 'Clip, not hidden: hidden would break the sticky column.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1024px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-body > \.pg-results \{([^}]*)\}/', $stylesheet, $column));
        $this->assertStringContainsString('position: sticky;', $column[1]);
        $this->assertStringContainsString('max-height:', $column[1]);
        $this->assertStringContainsString('overflow: auto;', $column[1]);
        $this->assertStringContainsString('scroll-padding-top: var(--pg-strip-h, 3rem);', $column[1], 'A row brought into view stops under the strip, one row of tabs or two.');
        $this->assertStringContainsString("el.results.style.setProperty('--pg-strip-h', el.tabStrip.offsetHeight + 'px');", self::source(self::SCRIPT), 'The strip measures itself.');
        $this->assertStringContainsString('new ResizeObserver(measureStrip).observe(el.tabStrip);', self::source(self::SCRIPT), 'A badge or a late font wraps the strip without a window resize.');
        $this->assertSame(1, preg_match('/\.pg-results > \.pg-tabs \{([^}]*)\}/', $stylesheet, $strip));
        $this->assertStringContainsString('position: sticky;', $strip[1]);
        $this->assertStringContainsString('top: 0;', $strip[1]);
        $this->assertStringContainsString('background: var(--panel);', $strip[1], 'The answers scroll under the strip, never through it.');
        $this->assertStringContainsString('.pg-tabs.is-scrolled', $stylesheet);
        $this->assertStringNotContainsString('.pg-body > .tree', $stylesheet);
    }

    #[Test]
    public function test_no_order_property_sets_the_mobile_stack(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $section = substr($stylesheet, (int) strpos($stylesheet, '.hero-playground {'));

        $this->assertSame(0, preg_match('/^\s*order:/m', $section), 'One column reads in the order of the markup: the examples, the fields, the answers.');

        $xpath = self::include();
        $columns = $xpath->query(self::byClass('pg-body').'/*');
        $this->assertNotFalse($columns);
        $classes = [];
        foreach ($columns as $column) {
            $this->assertInstanceOf(\DOMElement::class, $column);
            $classes[] = explode(' ', $column->getAttribute('class'))[0];
        }
        $this->assertSame(['pg-examples', 'pg-main', 'pg-results', 'pg-loader'], $classes);
    }

    /**
     * A column of examples beside the fields: one click loads a pattern
     * and its text. The pattern rides under the name — spelled in the
     * mono voice, hidden where the column folds back into a row — and the
     * window holds its three columns only where they fit; below that the
     * list wraps above the fields (a phone scrolls it as one row).
     */
    #[Test]
    public function test_the_examples_stand_in_their_own_column(): void
    {
        $xpath = self::include();

        $examples = $xpath->query(self::byClass('pg-examples').'/li/'.self::self('pg-example'));
        $this->assertNotFalse($examples);
        $this->assertGreaterThanOrEqual(10, $examples->length, 'A column is worth a list: the examples are many.');
        foreach ($examples as $example) {
            $this->assertInstanceOf(\DOMElement::class, $example);
            $name = $xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " pg-example-name ")])', $example);
            $this->assertNotSame('', $name, 'Every example is named.');
            $pattern = $xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " pg-example-pattern ")])', $example);
            $this->assertSame($example->getAttribute('data-regex'), $pattern, 'The line under the name is the pattern the click loads.');
            $this->assertNotSame('', $example->getAttribute('data-regex'));
            $this->assertNotSame('', $example->getAttribute('data-subject'), 'An example carries its own text to match against.');
            $this->assertSame($example->getAttribute('data-regex'), $example->getAttribute('title'), 'The tooltip says the whole pattern.');
        }

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/\.pg-example-pattern \{\s*display: none;/', $stylesheet), 'The pattern line waits for the column to have room.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-body \{([^}]*)\}/', $stylesheet, $wide), 'The widest screens give the body its three columns.');
        $this->assertSame(1, preg_match('/grid-template-columns:\s*minmax\([^;]*\)\s+minmax\(0, 2fr\)\s+minmax\(0, 3fr\);/', $wide[1]), 'Examples, fields, answers — three tracks.');
        $this->assertSame(1, preg_match('/@media \(max-width: 1023px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-examples \{([^}]*)\}/', $stylesheet, $narrow), 'A phone keeps one row: the list scrolls, the fields stay near.');
        $this->assertStringContainsString('overflow-x: auto;', $narrow[1]);
    }

    #[Test]
    public function test_the_deep_link_still_runs_on_boot_and_nothing_downloads_before_a_gesture(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertSame(1, preg_match("/if \\(fromHash\\) \\{[^}]*setState\\('Loading…', 'busy'\\);[^}]*analyzeNow\\(\\);\\n  \\}/", $script), 'A link with a pattern runs it at once, and the pill says the engine is coming.');
        $this->assertSame(2, substr_count($script, 'new Worker('), 'Two places create a worker: the engine and the step trace.');
        $this->assertSame(1, preg_match('/function traceWorkerCreate\(\) \{\s*try \{\s*var created = new Worker\(/', $script));
        $this->assertSame(1, preg_match_all('/(?<!function )traceWorkerCreate\(\)/', $script), 'The trace worker is asked for by the first trace job only.');
        $this->assertSame(1, preg_match('/function traceSend\(job\) \{(?:(?!\n  function ).)*traceWorkerCreate\(\)/s', $script));
        $this->assertSame(1, preg_match('/function createWorker\(\) \{\s*try \{\s*var created = new Worker\(/', $script));
        $this->assertSame(1, preg_match_all('/(?<!function )createWorker\(\)/', $script), 'Asked for by the first request only.');
        $this->assertSame(1, preg_match('/function send\(request\) \{(?:(?!\n  function ).)*createWorker\(\)/s', $script));
    }

    #[Test]
    public function test_the_redos_verdict_is_asked_eagerly(): void
    {
        $drain = self::between(self::source(self::SCRIPT), 'function drain() {', "\n  function ");

        $verdict = strpos($drain, 'verdictRequest()');
        $tool = strpos($drain, 'nextToolRequest()');
        $this->assertNotFalse($verdict, 'The verdict is asked whatever tab is open: its badge says it on the closed tab.');
        $this->assertNotFalse($tool);
        $this->assertLessThan($tool, $verdict);
    }

    /**
     * The library's sentence, reflowed for the eye: the colon before a
     * token and the bracketed repetition at the end give way to a space
     * and a clause. Run here with PCRE on the script's own rewrites.
     */
    #[Test]
    public function test_the_explanation_reflow_reads_one_sentence(): void
    {
        $rewrites = self::reflow();

        $this->assertSame('A digit [0-9], exactly 4 times', self::apply($rewrites, 'A digit: [0-9] (exactly 4 times)'));
        $this->assertSame('A digit [0-9], one or more times (as few as possible)', self::apply($rewrites, 'A digit: [0-9] (one or more times (as few as possible))'));
        $this->assertSame("'b', one or more times (and do not backtrack)", self::apply($rewrites, "'b' (one or more times (and do not backtrack))"));
        $this->assertSame("any character in [ Range: from 'a' to 'c' ], exactly 3 times", self::apply($rewrites, "any character in [ Range: from 'a' to 'c' ] (exactly 3 times)"));
        $this->assertSame('any character in [ POSIX Class [[:alpha:]] ], at least 1 but not more than 3 times', self::apply($rewrites, 'any character in [ POSIX Class: [[:alpha:]] ] (at least 1 but not more than 3 times)'));
        $this->assertSame('Quantified group, zero or more times', self::apply($rewrites, 'Quantified group (zero or more times)'));
        $this->assertSame("'c', once or not at all", self::apply($rewrites, "'c' (once or not at all)"));
        $this->assertSame('at least 2 times', self::apply($rewrites, 'at least 2 times'));

        // What is not a repetition, nor a colon before a token, stays.
        foreach ([
            'Any linebreak sequence (\r\n|[\n\x0B\f\r\x85])',
            'An uppercase letter (category)',
            'the start of a line: the start of the subject, or just after a newline (\n) that does not end it',
            'A whitespace character: a Unicode separator (\p{Z}), or a horizontal or vertical whitespace character (\h, \v)',
            'whatever the capturing group "1" matched',
            "any character in [ ':', ' ' (space), ''' ]",
            '\'note: "q"\'',
        ] as $unchanged) {
            $this->assertSame($unchanged, self::apply($rewrites, $unchanged));
        }

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('REFLOW_KEEP.indexOf(labeled[1]) === -1 ? reflow(labeled[2]) : labeled[2]', $script, 'A labelled line reads reflowed, unless it carries the visitor\'s own text.');
        $this->assertStringContainsString('reflow(content)', $script, 'A line of plain prose too.');
    }

    /**
     * A comment, a verb's name, a callout's argument: the visitor's own
     * words, which the library quotes and the page must not rewrite. The
     * kinds the library gives those lines are the kinds the script keeps.
     */
    #[Test]
    public function test_the_explanation_keeps_the_visitor_text(): void
    {
        $this->assertSame(1, preg_match('/var REFLOW_KEEP = \\[([^\\]]*)\\];/', self::source(self::SCRIPT), $declared));
        preg_match_all("/'([^']+)'/", $declared[1], $kept);
        $library = Regex::create();

        foreach ([
            "/a(?#see: [RFC 3339] or: 'x')b/" => 'Comment',
            "/a #c: [x]\n/x" => 'Comment',
            '/(*MARK:a: [b])x/' => 'PCRE Verb',
            '/(*MARK:x (one or more times)a/' => 'PCRE Verb',
            '/(?C"t: [x]")a/' => 'Callout',
        ] as $pattern => $kind) {
            $explained = $library->parse($pattern)->accept(new TextExplainer($library->target()));
            $this->assertSame(1, preg_match('/^\s*('.preg_quote($kind, '/').'): /m', $explained), $pattern.' is explained as a '.$kind.' line.');
            $this->assertContains($kind, $kept[1], $kind.' lines carry the visitor\'s text: the script leaves them as written.');
        }
    }

    /**
     * The verdict is asked on every run, whatever tab is open: when it
     * fails while its tab is closed, it gives up quietly, without a second
     * try and without stopping the window, and the line names the tab that
     * failed, never another.
     */
    #[Test]
    public function test_a_failed_verdict_on_a_closed_tab_stays_quiet(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("var request = { type: 'redos', regex: current.regex, version: current.version, mode: 'theoretical', eager: true };", $script);

        $fail = self::between($script, 'function fail(request, reason) {', "\n  function ");
        $quiet = self::between($fail, "if (request.eager && activeTool !== 'redos') {", "\n    }\n");
        $this->assertStringContainsString('failures[key] = 2;', $quiet, 'One failure is the last: the verdict is not asked again for this input.');
        $this->assertStringNotContainsString('send(', $quiet);
        $this->assertStringNotContainsString("'Stopped'", $quiet);
        $this->assertLessThan(strpos($fail, 'send(request);'), strpos($fail, "if (request.eager && activeTool !== 'redos') {"), 'Before the replay.');
        $this->assertStringContainsString("' The engine could not finish the ' + TAB_LABELS[owner] + ' tab; press Run to try again.'", $fail, 'A closed tab is named by its own label.');
        $this->assertStringContainsString("var owner = tab === 'probe' || tab === 'selfcheck' ? 'trace' : tab;", $fail, 'The trace\'s probe and self-check speak for the Step through tab.');
        $this->assertStringContainsString('activeTool === owner ?', $fail);

        // The ReDoS tab, opened, asks again: the quiet failure is dropped
        // with its count, and the tab's own request goes out.
        $activate = self::between($script, 'function activateTool(tool, focus) {', "\n  }\n");
        $retry = self::between($activate, "if (tool === 'redos' && toolAnswers.redos && toolAnswers.redos.failed && toolAnswers.redos.request.eager) {", "\n    }\n");
        $this->assertStringContainsString('delete failures[toolAnswers.redos.key];', $retry);
        $this->assertStringContainsString('delete toolAnswers.redos;', $retry);
        $this->assertLessThan(strpos($activate, 'drain();'), strpos($activate, "if (tool === 'redos' && toolAnswers.redos"), 'Dropped before the queue drains.');
    }

    /**
     * A setting moved on the sample: the panel stops showing the stored
     * answer for the old setting at once, before the engine boots.
     */
    #[Test]
    public function test_a_setting_moved_on_the_sample_drops_the_old_answer_first(): void
    {
        $moved = self::between(self::source(self::SCRIPT), 'function settingMoved(tool) {', "\n  }\n");

        $this->assertLessThan(strpos($moved, 'analyzeNow();'), strpos($moved, 'renderTool(tool, true);'));
    }

    /**
     * The window answers what the engine says; a test to paste into a test
     * class is the console's business, not a tab's. The driver keeps the op
     * (the bundle and its own tests answer it); the page never asks for it,
     * and a stored choice of the removed tab falls back to the tree.
     */
    #[Test]
    public function test_the_page_offers_no_export_tool(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringNotContainsString("'export'", $script, 'No tool, no label, no seeded answer of the export op on the page.');
        $this->assertStringNotContainsString('exportSubjects', $script);
        $this->assertStringContainsString('if (storedTool && TABS.indexOf(storedTool) !== -1) {', $script, 'A stored tab the strip no longer holds opens on the tree.');
    }

    /**
     * The WCAG contrast ratio of two #RRGGBB colors.
     */
    private static function contrast(string $one, string $two): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $lighter = max($luminance($one), $luminance($two));
        $darker = min($luminance($one), $luminance($two));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return list<array{pattern: string, replacement: string, limit: int}>
     */
    private static function reflow(): array
    {
        $script = self::source(self::SCRIPT);
        $block = self::between($script, 'var EXPLAIN_REFLOW = [', '];');
        preg_match_all("~^\\s*\\[/(.+)/(g?), '([^']*)'\\],?$~m", $block, $rules, \PREG_SET_ORDER);
        self::assertCount(2, $rules, 'Two rewrites, each a regex literal and its replacement.');

        return array_map(static fn (array $rule): array => [
            'pattern' => '/'.$rule[1].'/',
            'replacement' => $rule[3],
            'limit' => 'g' === $rule[2] ? -1 : 1,
        ], $rules);
    }

    /**
     * @param list<array{pattern: string, replacement: string, limit: int}> $rewrites
     */
    private static function apply(array $rewrites, string $text): string
    {
        foreach ($rewrites as $rewrite) {
            $text = (string) preg_replace($rewrite['pattern'], $rewrite['replacement'], $text, $rewrite['limit']);
        }

        return $text;
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

    private static function nodes(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
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
