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
    public function test_the_seven_tabs_come_in_order(): void
    {
        $xpath = self::include();
        $tabs = $xpath->query('//*[@role="tab"]');
        $this->assertNotFalse($tabs);
        $panels = $xpath->query('//*[@role="tabpanel"]');
        $this->assertNotFalse($panels);
        $this->assertCount(\count($tabs), $panels, 'Every panel is named by a tab; no answer without its strip.');

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
     * The window's name stands in the middle of its bar whatever the state
     * beside Run says: the two outer tracks share what the name leaves, so
     * "Ready" and "Sample · press Run" never slide it sideways.
     */
    #[Test]
    public function test_the_title_stays_centred_whatever_the_state_says(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/(?<!,\n)^\.pg-titlebar \{([^}]*)\}/m', $stylesheet, $bar), 'The bar\'s own rule exists, not the one it shares with the terminal.');
        $this->assertStringContainsString('grid-template-columns: 1fr minmax(0, max-content) 1fr;', $bar[1], 'Two outer tracks of one share, the name between them.');
        $this->assertSame(1, preg_match('/^\.pg-controls \{([^}]*)\}/m', $stylesheet, $controls));
        $this->assertStringContainsString('justify-self: end;', $controls[1], 'The controls keep to the bar\'s far edge.');
    }

    /**
     * The state is a light and a word, not a pill: an outlined pill beside
     * Run reads as a second button. The dot is drawn by a border, so a
     * forced palette keeps it; it pulses while the engine works, unless
     * less motion was asked for.
     */
    #[Test]
    public function test_the_state_is_a_light_not_a_button(): void
    {
        $xpath = self::include();
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-state').'/'.self::self('pg-state-word')), 'The word has its own box, which a phone can hide.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-state').'[@title="Sample · press Run"]'), 'The light names itself from the first paint, before any script speaks.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-state \{([^}]*)\}/m', $stylesheet, $state));
        $this->assertStringNotContainsString('border:', $state[1], 'No outline: the state is not a control.');
        $this->assertSame(1, preg_match('/^\.pg-state::before \{([^}]*)\}/m', $stylesheet, $dot), 'The light is drawn before the word.');
        $this->assertStringContainsString('border:', $dot[1], 'A border, so forced colors keep the dot.');
        foreach (['is-busy', 'is-ok', 'is-error'] as $tone) {
            $this->assertStringContainsString('.pg-state.'.$tone.'::before', $stylesheet, 'The light takes the '.$tone.' tone.');
        }
        $this->assertSame(1, preg_match('/@media \(prefers-reduced-motion: no-preference\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-state\.is-busy::before \{([^}]*)\}/', $stylesheet, $pulse), 'The pulse waits for a visitor who did not ask for less motion.');
        $this->assertStringContainsString('animation:', $pulse[1]);

        $script = self::source(self::SCRIPT);
        $set = self::between($script, 'function setState(word, tone) {', "\n  }\n");
        $this->assertStringContainsString('el.stateWord.textContent = word;', $set);
        $this->assertStringContainsString('el.state.title = word;', $set, 'Where the word is hidden, the light still names itself.');
        $this->assertStringNotContainsString("setState('Ready');", $script, 'Ready lights green, every time.');
        $this->assertStringContainsString("setState('Ready', 'ok');", $script);
    }

    /**
     * Where the controls outgrow half the bar, the desktop's window lights
     * go and the name keeps to the left, where no state can move it; a
     * phone keeps the bar to one row, the state said by its light, its
     * word still read by the live region.
     */
    #[Test]
    public function test_the_title_bar_keeps_one_row_on_a_phone(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $narrow = '/@media \(max-width: 899px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*';
        $phone = '/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*';

        $this->assertSame(1, preg_match($narrow.'\.pg-lights \{([^}]*)\}/', $stylesheet, $lights));
        $this->assertStringContainsString('display: none;', $lights[1], 'The desktop metaphor gives its room to the controls.');
        $this->assertSame(1, preg_match($phone.'\.pg-state-word \{([^}]*)\}/', $stylesheet, $word));
        $this->assertStringContainsString('clip-path: inset(50%);', $word[1], 'Hidden from the eye, not from the live region.');
        $this->assertStringNotContainsString('display: none', $word[1]);
        $this->assertSame(0, preg_match($phone.'\.pg-controls \{[^}]*grid-column: 1 \/ -1;/', $stylesheet), 'No second row for the controls.');
        // The header above already says the brand: a phone's bar says
        // "Playground" whole rather than the full name cut short.
        $xpath = self::include();
        $title = $xpath->evaluate('string('.self::byClass('pg-title').')');
        $this->assertIsString($title);
        $this->assertSame('PHPRegex playground', trim($title), 'A wide bar says the whole name.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-title').'/'.self::self('pg-title-brand')));
        $this->assertSame(1, preg_match($phone.'\.pg-title-brand \{\s*display: none;/', $stylesheet), 'The brand word gives way on a phone.');
        $this->assertSame(1, preg_match($phone.'\.pg-title::first-letter \{\s*text-transform: uppercase;/', $stylesheet), 'And the name left starts as a name.');

        $this->assertSame(1, preg_match($narrow.'\.pg-titlebar \{([^}]*)\}/', $stylesheet, $bar));
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto;', $bar[1], 'The name, then the controls.');
    }

    /**
     * Run says its shortcut where there is room for it, in the platform's
     * own words; a screen reader hears the shortcut from the attribute,
     * not twice from the label.
     */
    #[Test]
    public function test_run_shows_its_shortcut(): void
    {
        $xpath = self::include();
        $run = $xpath->query(self::byClass('pg-run'));
        $this->assertNotFalse($run);
        $button = $run->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button);
        $this->assertSame('Control+Enter Meta+Enter', $button->getAttribute('aria-keyshortcuts'));
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-run').'/kbd'.self::has('pg-run-key').'[@aria-hidden="true"]'), 'The key is for the eye.');

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("el.runKey.textContent = onApple ? '⌘↵' : 'Ctrl ↵';", $script, 'The platform\'s own modifier.');
        $this->assertSame(1, preg_match('/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-run-key \{\s*display: none;/', self::source(self::STYLESHEET)), 'A phone has no keyboard shortcut to show.');
    }

    /**
     * Every control of the window shows the keyboard where it stands with
     * the same two-pixel amber ring: a border that changes color is not a
     * focus indicator.
     */
    #[Test]
    public function test_the_window_controls_show_a_focus_ring(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        foreach (['.pg-titlebar .pg-run:focus-visible', '.pg-example:focus-visible'] as $selector) {
            $this->assertSame(1, preg_match('/(?<!,\n)^'.preg_quote($selector, '/').' \{([^}]*)\}/m', $stylesheet, $rule), $selector.' has a rule of its own.');
            $this->assertStringContainsString('outline: 2px solid var(--amber);', $rule[1], $selector.' draws the ring.');
        }
    }

    /**
     * Under the column, the examples are a row: a name never folds over
     * two lines inside its chip, and a row that scrolls fades on the edge
     * where more examples wait, as the strip of tabs does.
     */
    #[Test]
    public function test_an_example_chip_keeps_its_name_on_one_line(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-example \{([^}]*)\}/m', $stylesheet, $chip));
        $this->assertStringContainsString('white-space: nowrap;', $chip[1], 'A name never wraps at its space.');
        $this->assertSame(1, preg_match('/^\.pg-examples li \{([^}]*)\}/m', $stylesheet, $item));
        $this->assertStringContainsString('flex: none;', $item[1], 'A chip never shrinks below its name.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-examples \.pg-example-name \{([^}]*)\}/', $stylesheet, $name), 'In the column, a long name is cut, never pushed past the box.');
        $this->assertStringContainsString('text-overflow: ellipsis;', $name[1]);

        $this->assertStringContainsString('.pg-examples.has-more-right', $stylesheet);
        $this->assertStringContainsString('.pg-examples.has-more-left', $stylesheet);
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('function syncExampleFades() {', $script);
        $this->assertStringContainsString("el.examplesList.addEventListener('scroll', syncExampleFades, { passive: true });", $script);
    }

    /**
     * The engine's first boot is said once to the eye: the loader's card
     * names the stage, so the status line under the fields steps aside
     * while it stands — still read by its live region.
     */
    #[Test]
    public function test_the_loader_says_the_stage_once(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("root.classList.add('is-booting');", self::between($script, 'function showLoader(stage) {', "\n  }\n"));
        $this->assertStringContainsString("root.classList.remove('is-booting');", self::between($script, 'function hideLoader() {', "\n  }\n"));
        $this->assertSame(1, preg_match('/^\.playground\.is-booting \.pg-status \{([^}]*)\}/m', self::source(self::STYLESHEET), $status));
        $this->assertStringContainsString('clip-path: inset(50%);', $status[1], 'Out of sight, never out of the accessibility tree.');
    }

    /**
     * Before any run the status bar already says what judged the sample,
     * so the bar is never a blank strip beside the privacy chip.
     */
    #[Test]
    public function test_the_status_bar_names_the_sample_engine(): void
    {
        $caption = self::between(self::source(self::SCRIPT), 'function renderCaption(payload, prerendered) {', "\n  }\n");
        $sample = self::between($caption, 'if (prerendered) {', 'return;');
        $this->assertStringContainsString("chip('sample judged for', 'PHP ' + payload.target.php + ' · PCRE2 ' + payload.target.pcre)", $sample);
    }

    /**
     * The privacy chip carries a lock, the sign every browser gives the
     * same promise; and the projects filter's hint fits its field.
     */
    #[Test]
    public function test_the_small_print_reads_whole(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-privacy::before \{([^}]*)\}/m', $stylesheet, $lock));
        $this->assertStringContainsString('mask:', $lock[1], 'A drawn lock in the chip\'s own ink.');

        $xpath = self::include();
        $this->assertSame('owner, repo or rule', $xpath->evaluate('string('.self::byClass('pg-findings-filter').'/@placeholder)'), 'A hint the narrow column shows whole.');
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

        $this->assertSame(['diagnostics', 'redos'], $tools, 'The state of the product reads on the closed tabs; the matches are said by the text field itself.');

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

        $this->assertStringContainsString("var TABS = ['tree', 'diagnostics', 'redos', 'transpile', 'samples', 'format', 'trace'];", $script);
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

    /**
     * The strip of tabs is one line, always: when the answers panel is
     * narrow — a phone, or a column the visitor squeezed — the tabs scroll
     * under fades that appear only where more of them wait, and the open
     * tab, or the one the arrows land on, is carried to the strip's
     * middle. No second row at any width: a wrapped strip never said
     * which tab is open.
     */
    #[Test]
    public function test_the_strip_stays_one_line_and_carries_the_open_tab(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-tabs \{([^}]*)\}/m', $stylesheet, $strip), 'The base strip rule exists.');
        $this->assertStringContainsString('flex-wrap: nowrap;', $strip[1], 'One line, at every width.');
        $this->assertStringContainsString('overflow-x: auto;', $strip[1], 'The tabs scroll, never stack.');
        $this->assertStringContainsString('.pg-tabs.has-more-right', $stylesheet, 'A fade on the right edge says more tabs wait.');
        $this->assertStringContainsString('.pg-tabs.has-more-left', $stylesheet, 'And on the left, when the strip has travelled.');
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('function scrollTabIntoView(tab) {', $script, 'The open tab is carried to the middle of the strip.');
        $this->assertStringContainsString('function syncTabFades() {', $script, 'The fades say themselves, only where there is more.');
    }

    /**
     * The strip scrolls sideways, and a scroll container's automatic
     * minimum height is zero: in the panel's `auto 1fr` rows, a tree
     * taller than a short screen's panel crushed the strip to its
     * padding and the tabs were gone. The strip asks for its content's
     * height outright.
     */
    #[Test]
    public function test_a_full_panel_never_crushes_the_strip(): void
    {
        $this->assertSame(1, preg_match('/^\.pg-tabs \{([^}]*)\}/m', self::source(self::STYLESHEET), $strip));
        $this->assertStringContainsString('overflow-x: auto;', $strip[1], 'The strip is a scroll container…');
        $this->assertStringContainsString('min-height: max-content;', $strip[1], '…so its height is asked for, never left to the automatic minimum.');
    }

    /**
     * A tab never shrinks into two lines of text: "Step through" wrapped at
     * its space would make the one-line strip two lines tall again. The
     * whole tab scrolls instead.
     */
    #[Test]
    public function test_a_tab_keeps_its_label_on_one_line(): void
    {
        $this->assertSame(1, preg_match('/^\.pg-tab \{([^}]*)\}/m', self::source(self::STYLESHEET), $tab), 'The base tab rule exists.');
        $this->assertStringContainsString('flex: none;', $tab[1], 'A tab never shrinks below its label.');
        $this->assertStringContainsString('white-space: nowrap;', $tab[1], 'Its label never wraps at a space.');
    }

    /**
     * A badge, or a font that arrives late, widens a tab without changing
     * the strip's own box: the tabs themselves are observed, so the fades
     * follow the content and not only the strip's size.
     */
    #[Test]
    public function test_the_fades_follow_a_tab_that_changes_width(): void
    {
        $script = self::source(self::SCRIPT);
        $observer = self::between($script, 'if (window.ResizeObserver) {', 'measureStrip();');
        $this->assertStringContainsString('stripObserver.observe(el.tabStrip);', $observer, 'The strip is observed.');
        $this->assertStringContainsString('stripObserver.observe(tab);', $observer, 'And every tab in it, for a badge or a late font.');
    }

    /**
     * A visitor who asked for less motion sees the strip jump to the open
     * tab, never glide to it.
     */
    #[Test]
    public function test_the_strip_jumps_under_reduced_motion(): void
    {
        $scroll = self::between(self::source(self::SCRIPT), 'function scrollTabIntoView(tab) {', "\n  }\n");
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $scroll, 'The preference is read on every carry.');
        $this->assertStringContainsString("? 'auto' : 'smooth'", $scroll, 'Reduced motion jumps; the rest glides.');
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
        $this->assertStringContainsString('var stripObserver = new ResizeObserver(measureStrip);', self::source(self::SCRIPT), 'A badge or a late font changes the strip without a window resize.');
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
        $this->assertSame(['pg-listing-tab', 'pg-listing-rail', 'pg-examples', 'pg-main', 'pg-results', 'pg-loader'], $classes, 'The fold tab and its rail of avatars ride the body\'s edge; the columns read in the order of the markup: the examples, the fields, the answers.');
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
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-example \{([^}]*)\}/', $stylesheet, $box), 'One column, one size of box.');
        $this->assertStringContainsString('width: 100%;', $box[1], 'A box fills its item: no example shrinks to its name.');
        $this->assertSame(1, preg_match('/@media \(max-width: 1023px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-examples \{([^}]*)\}/', $stylesheet, $narrow), 'Below the side-by-side layout, phones and tablets keep one row: the list scrolls, the fields stay near.');
        $this->assertStringContainsString('overflow-x: auto;', $narrow[1]);
    }

    /**
     * The text field is where the matches are read: it stands as tall as
     * the answers beside it on a wide screen, and scrolls inside for a
     * longer text — the stylesheet owns its height there, the script's
     * auto-size keeps to the narrower layouts.
     */
    #[Test]
    public function test_the_text_field_stands_as_tall_as_the_answers(): void
    {
        $xpath = self::include();
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-main').'//'.self::self('pg-field-text')), 'The text field carries its own class.');
        $this->assertStringNotContainsString('pg-tool-matches', self::source(self::INCLUDE), 'The matches live in the text field; no tab holds them anymore.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-main \{([^}]*)\}/', $stylesheet, $main), 'The writing column stretches on a wide screen.');
        $this->assertStringContainsString('align-self: stretch;', $main[1]);
        $this->assertStringContainsString('grid-template-rows: auto minmax(0, 1fr) auto;', $main[1], 'The text field takes whatever height the answers leave.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-editor-subject \.pg-input \{([^}]*)\}/', $stylesheet, $input), 'The text input fills its editor on a wide screen.');
        $this->assertStringContainsString('height: 100%;', $input[1]);
        $this->assertStringContainsString('max-height: none;', $input[1], 'No cap cuts the fill short.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1280px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-editor-subject \.pg-mirror \{([^}]*)\}/', $stylesheet, $mirror), 'The mirror drops its floor with the input: an opened match list shrinks both.');
        $this->assertStringContainsString('min-height: 0;', $mirror[1]);

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("matchMedia('(min-width: 1280px)').matches", $script, 'The auto-size yields to the stylesheet exactly where the fill begins.');
    }

    /**
     * A mark alone says neither an empty match (it underlines nothing) nor
     * a capped list (the marks stop at the engine's cap): the line right
     * after the field's label counts the matches in sight, and says how
     * many of them the marks show when the sets were capped. Under the
     * field, a folded disclosure holds every match one by one — its text,
     * where it sits, and each group's name or number, value and position —
     * in sight once opened, and in reach of a screen reader the aria-hidden
     * mirror cannot serve. Neither is live: the live line keeps to the
     * count.
     */
    #[Test]
    public function test_the_matches_are_counted_and_detailed_beside_the_text_field(): void
    {
        $xpath = self::include();
        $field = self::byClass('pg-field-text');

        $this->assertSame(1, self::nodes($xpath, $field.'/'.self::self('pg-match-summary').'[@id="pg-match-summary"][not(@aria-live)]'), 'The count sits in the field, and is not live.');
        $this->assertSame(0, self::nodes($xpath, $field.'/'.self::self('pg-match-summary').self::has('visually-hidden')), 'The count is in sight.');
        $this->assertSame(1, self::nodes($xpath, $field.'/label/following-sibling::*[1]'.self::has('pg-match-summary')), 'The count reads right after the label.');
        $this->assertSame(1, self::nodes($xpath, $field.'/details'.self::has('pg-match-details').'[@hidden][not(@open)]/summary/following-sibling::ol'.self::has('pg-subject-matches').'[@aria-labelledby="pg-match-summary"][not(@aria-live)]'), 'The list sits in a folded disclosure under the field, named by the count.');
        $this->assertSame(0, self::nodes($xpath, $field.'//'.self::self('pg-subject-matches').self::has('visually-hidden')), 'Opened, the list is in sight.');

        $script = self::source(self::SCRIPT);
        $marks = self::between($script, 'function renderSubjectMarks(payload, subject) {', "\n  }\n");
        $this->assertStringContainsString('el.matchSummary.textContent = ', $marks);
        // PHP's warning is a refusal at compile time or a match it could not
        // finish ("/(?=a\\K)/" on "a", PHP 8.4): the words fit both.
        $this->assertSame(1, preg_match("/!matches && payload\\.engineError \\? 'PHP could not complete the match'/", $marks), 'A warning from PHP says beside the field that no match was completed; the engine\'s words stay in the diagnostics.');
        $this->assertStringContainsString("announce('PHP could not complete the match: ' + payload.engineError);", $marks, 'The live line says the same, with the engine\'s words.');
        $this->assertStringNotContainsString('refused the pattern', $marks, 'A match-time failure is no refusal of the pattern.');
        $this->assertStringContainsString('el.subjectMatches.appendChild(', $marks, 'One item per match set.');
        $this->assertStringContainsString("announce(matches.count === 1 ? '1 match' : matches.count + ' matches');", $marks, 'The live line keeps to the count.');

        $summary = self::between($script, 'function matchSummary(matches) {', "\n  }\n");
        $this->assertStringContainsString('matches.shown < matches.count', $summary, 'A capped list says how many the marks show.');
        $this->assertStringContainsString("[0] === ''", $summary, 'An empty match is counted in words.');
        $this->assertStringContainsString("' of them empty'", $summary, 'Capped, the empty ones are counted among the underlined only, and say so.');

        $item = self::between($script, 'function matchItem(set, i, subject, names) {', "\n  }\n");
        $this->assertStringContainsString('Array.from(subject.slice(0, whole[2])).length', $item, 'The driver gives UTF-16 offsets; the item counts characters, so "😀a" puts the a at 1.');
        $this->assertStringContainsString('Array.from(whole[0]).length', $item, 'Each item says where the match ends, in characters too.');
        $this->assertStringContainsString("names[key] ? names[key] + ' (#' + key + ')' : '#' + key", $item, 'A group reads by its name and its number, or by its number alone.');
        $this->assertStringContainsString('Array.from(subject.slice(0, capture[2])).length', $item, 'A group says where it sits, in characters.');
        $this->assertStringContainsString("text('dl', 'pg-groups')", $item, 'The groups of a match are listed under it.');
        $this->assertStringContainsString('el.subjectMatches.appendChild(matchItem(set, i, subject, names));', $marks);
        $this->assertStringContainsString('el.matchDetails.hidden = ', $marks, 'No match, no disclosure.');

        // A keystroke in either field makes the answer stale: the count, the
        // list and the marks go before the debounce, and come back with the
        // answer to the new input.
        $typed = self::between($script, "area.addEventListener('input', function () {", "\n    });\n");
        $this->assertSame(1, preg_match('/refreshFields\(\);\s*dropSubjectMarks\(\);\s*schedule\(\);/', $typed), 'The stale answer goes before the next one is asked for.');
        // An example replaces both fields without an input event: it drops
        // them the same way, before it asks.
        $example = self::between($script, "Array.prototype.forEach.call(el.examples, function (chip) {\n    chip.addEventListener('click', function () {", "\n    });\n");
        $this->assertSame(1, preg_match('/refreshFields\(\);\s*dropSubjectMarks\(\);\s*analyzeNow\(\);/', $example), 'An example drops the old marks too.');
        // A match answer that lands after a keystroke belongs to the old
        // text: it is kept, not drawn, so the dropped marks stay dropped.
        $answered = self::between($script, "} else if (request.type === 'match') {", "} else if (request.type === 'lint') {");
        $this->assertSame(1, preg_match('/if \(fieldsRead\(request\)\) \{\s*renderSubjectMarks\(current\.payload, request\.subject\);\s*\}/', $answered), 'A late answer does not draw the marks back over a newer text, nor over another PHP version.');
        // An analysis carries match sets of its own: the same guard holds.
        $rendered = self::between($script, 'function render(payload, request, prerendered) {', "\n  }\n");
        $this->assertSame(1, preg_match('/if \(fieldsRead\(request\)\) \{\s*renderSubjectMarks\(payload, request\.subject\);\s*\}/', $rendered), 'A late analysis does not draw the marks back either.');
        $read = self::between($script, 'function fieldsRead(request) {', "\n  }\n");
        $this->assertStringContainsString('return el.pattern.value === request.regex && el.subject.value === request.subject && request.version === chosen;', $read, 'The fields, and the PHP version, still say what was asked.');
        // Another PHP version is another answer: the marks go at once.
        $version = self::between($script, "el.version.addEventListener('change', function () {", "\n  });\n");
        $this->assertSame(1, preg_match('/dropSubjectMarks\(\);\s*analyzeNow\(\);/', $version), 'A version change drops the old marks before it asks.');
        $drop = self::between($script, 'function dropSubjectMarks() {', "\n  }\n");
        $this->assertStringContainsString("el.matchSummary.textContent = '';", $drop);
        $this->assertStringContainsString('clear(el.subjectMatches);', $drop);
        $this->assertStringContainsString('el.matchDetails.hidden = true;', $drop);
        $this->assertStringContainsString('el.subjectMirror.appendChild(markRanges(el.subject.value, []));', $drop, 'The mirror keeps the text, without a mark.');

        $this->assertSame(1, preg_match('/\n\.pg-field-text \{([^}]*)\}/', self::source(self::STYLESHEET), $columns), 'The text field reads its label, its count and its bytes on one line.');
        $this->assertStringContainsString('grid-template-columns: auto minmax(0, 1fr) auto;', $columns[1]);
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
