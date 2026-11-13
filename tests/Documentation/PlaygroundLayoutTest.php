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

        // What stays in the writing pane: the text field — the pattern
        // has a row of its own above the panes, the quick patterns
        // another. The examples stand in that row, never in the pane.
        $this->assertSame(0, self::nodes($xpath, $main.'//'.self::self('pg-examples')), 'The quick patterns are not a row of the text pane.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-quickbar').'/ul'.self::has('pg-examples')), 'They are the quick row\'s list, above the panes.');
        $this->assertSame(1, self::nodes($xpath, self::byClass('pg-quickbar').'/following-sibling::*'.self::has('pg-body').'/'.self::self('pg-main')), 'The panes follow the quick row.');
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
     * No window, no title bar: the pattern is the tool's first row, one
     * line of it — a field as wide as the tool, its controls standing at
     * the row's far end on the field's own middle. The delimiters are the
     * field's label: "Pattern" is said to a voice, never shown to the
     * eye, and the byte count reads inside the field's right corner,
     * under a veil, the way an editor shows its corner facts. The
     * desktop's lights are gone with the metaphor that drew them, and
     * the tool answers a wide screen: to 1 720 pixels, where the reading
     * pages keep their column.
     */
    #[Test]
    public function test_the_pattern_row_holds_the_field_and_its_controls(): void
    {
        $xpath = self::include();

        $this->assertSame(0, self::nodes($xpath, self::byClass('pg-lights').'|'.self::byClass('pg-titlebar').'|'.self::byClass('pg-title').'|'.self::byClass('pg-statusbar')), 'The window and its furniture are gone.');

        $row = self::byClass('pg-patternbar');
        $this->assertSame(1, self::nodes($xpath, $row), 'One row above the panes.');
        $this->assertSame(1, self::nodes($xpath, $row.'//textarea[@id="pg-pattern"]'), 'The row opens on the pattern itself.');
        $this->assertSame(1, self::nodes($xpath, $row.'//select[@id="pg-version"]'));
        $this->assertSame(1, self::nodes($xpath, $row.'//'.self::self('pg-run')));
        $this->assertSame(1, self::nodes($xpath, $row.'//'.self::self('pg-state')), 'The state sits beside Run, where it is read.');

        // The field's name is spoken, never drawn: one line above the
        // panes, the delimiters its label.
        $labels = $xpath->query('//label[@for="pg-pattern"]');
        $this->assertNotFalse($labels);
        $label = $labels->item(0);
        $this->assertInstanceOf(\DOMElement::class, $label);
        $this->assertStringContainsString('visually-hidden', (string) $label->getAttribute('class'), 'A voice hears "Pattern"; the eye reads the delimiters.');
        $count = $xpath->query('//*[@id="pg-pattern-count"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " pg-editor-pattern ")]');
        $this->assertNotFalse($count);
        $this->assertGreaterThan(0, $count->length, 'The count lives inside the field, an editor\'s corner fact.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-patternbar \{([^}]*)\}/m', $stylesheet, $bar), 'The pattern row\'s own rule exists.');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto;', $bar[1], 'The field takes the row; the controls keep to its end.');
        $this->assertStringContainsString('align-items: center;', $bar[1], 'One line: the controls ride the field\'s middle, not its floor.');
        $this->assertSame(1, preg_match('/^\.pg-controls \{([^}]*)\}/m', $stylesheet, $controls));
        $this->assertStringContainsString('justify-self: end;', $controls[1], 'The controls keep to the row\'s far edge.');
        $corner = '/\.pg-editor-pattern \.pg-field-count \{([^}]*)\}/';
        $this->assertSame(1, preg_match($corner, $stylesheet, $fact), 'The corner fact\'s rule exists.');
        $this->assertStringContainsString('pointer-events: none;', $fact[1], 'It says, it never takes a click.');
        // A long pattern scrolls inside its field, it never grows the row:
        // the field is an editor, not a page.
        $this->assertSame(1, preg_match('/^.pg-editor.pg-editor-pattern \.pg-input,\s*^.pg-editor.pg-editor-pattern \.pg-mirror \{([^}]*)\}/m', $stylesheet, $cap), 'The pattern field is capped.');
        $this->assertStringContainsString('max-height:', $cap[1], 'A twenty-line pattern meets a field a few lines tall, scrolling inside.');
        $this->assertStringContainsString('linear-gradient(', $fact[1], 'A veil fades the field\'s text under it — in the count\'s own rule, not wherever a gradient may live.');
        $this->assertSame(1, preg_match('/^\.hero-playground \.hero-inner \{([^}]*)\}/m', $stylesheet, $inner));
        $this->assertStringContainsString('padding-inline: max(1.25rem, calc(50vw - 53.75rem));', $inner[1], 'A tool page to 1 720 pixels; the prose keeps its column.');
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
     * On a phone the pattern row gives the field a line of its own: the
     * controls drop under it rather than squeeze the field that holds
     * the work, and the state says itself by its light alone, its word
     * still read by the live region.
     */
    #[Test]
    public function test_the_pattern_row_keeps_one_row_on_a_phone(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $phone = '/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*';

        $this->assertSame(1, preg_match($phone.'\.pg-patternbar \{([^}]*)\}/', $stylesheet, $bar));
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr);', $bar[1], 'The field holds the row; the controls take the next.');
        $this->assertSame(1, preg_match($phone.'\.pg-controls \{([^}]*)\}/', $stylesheet, $controls));
        $this->assertStringContainsString('flex-wrap: wrap;', $controls[1], 'And wrap among themselves when one row of them is too wide.');

        $this->assertSame(1, preg_match($phone.'\.pg-state-word \{([^}]*)\}/', $stylesheet, $word));
        $this->assertStringContainsString('clip-path: inset(50%);', $word[1], 'Hidden from the eye, not from the live region.');
        $this->assertStringNotContainsString('display: none', $word[1]);
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
        foreach (['.pg-patternbar .pg-run:focus-visible', '.pg-example:focus-visible'] as $selector) {
            $this->assertSame(1, preg_match('/(?<!,\n)^'.preg_quote($selector, '/').' \{([^}]*)\}/m', $stylesheet, $rule), $selector.' has a rule of its own.');
            $this->assertStringContainsString('outline: 2px solid var(--amber);', $rule[1], $selector.' draws the ring.');
        }
    }

    /**
     * Under the quick row a chip keeps its name on one line, and a row
     * that scrolls fades on the edge where more patterns wait, as the
     * strip of tabs does.
     */
    #[Test]
    public function test_an_example_chip_keeps_its_name_on_one_line(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-example \{([^}]*)\}/m', $stylesheet, $chip));
        $this->assertStringContainsString('white-space: nowrap;', $chip[1], 'A name never wraps at its space.');
        $this->assertSame(1, preg_match('/^\.pg-examples li \{([^}]*)\}/m', $stylesheet, $item));
        $this->assertStringContainsString('flex: none;', $item[1], 'A chip never shrinks below its name.');
        $this->assertStringContainsString('margin: 0;', $item[1], 'Nor carries the list item\'s margin.');

        $this->assertStringContainsString('.pg-examples.has-more-right', $stylesheet);
        $this->assertStringContainsString('.pg-examples.has-more-left', $stylesheet);
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('function syncExampleFades() {', $script);
        $this->assertStringContainsString("el.examplesList.addEventListener('scroll', syncExampleFades, { passive: true });", $script);
    }

    /**
     * The engine's first boot is said once to the eye: the loader's card
     * names the stage, so the status line steps aside while it says that
     * stage — still read by its live region. A warning the boot meets — a
     * subject cut out of a link, a failed boot starting again — is the
     * line's own: no stage overwrites it, and no clip hides it, until the
     * engine answers.
     */
    #[Test]
    public function test_the_loader_says_the_stage_once(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("root.classList.add('is-booting');", self::between($script, 'function showLoader(stage) {', "\n  }\n"));
        $hide = self::between($script, 'function hideLoader() {', "\n  }\n");
        $this->assertStringContainsString("root.classList.remove('is-booting');", $hide);
        $this->assertStringContainsString("bootNotice = '';", $hide, 'A notice lasts as long as the boot it was met in.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.playground\.is-booting \.pg-status\.is-stage \{([^}]*)\}/m', $stylesheet, $status), 'The stage the card repeats, and nothing else.');
        $this->assertStringContainsString('clip-path: inset(50%);', $status[1], 'Out of sight, never out of the accessibility tree.');
        $this->assertSame(0, preg_match('/^\.playground\.is-booting \.pg-status(?:\.is-busy)? \{/m', $stylesheet), 'A busy or a warning line is never hidden behind the loader.');

        $progress = self::between($script, "if (message.type === 'progress') {", "\n    }\n");
        $this->assertStringContainsString('var notice = bootNotice || linkWarning();', $progress);
        $this->assertStringContainsString("setStatus(notice || stage, notice ? 'warn' : 'stage');", $progress, 'A stage never overwrites a warning.');
        $retry = self::between($script, 'if (failures[key] < 2) {', "\n    }\n");
        $this->assertStringContainsString("bootNotice = reason + ' Starting the engine again…';", $retry);
        $this->assertStringContainsString("setStatus(bootNotice, 'warn');", $retry);

        // A subject cut out of a link warns for as long as the cut text is
        // the one in the field — through the boot, after the answer, until
        // the visitor's own hand or an example changes it.
        $this->assertStringContainsString("linkNotice = { text: 'The subject in this link was cut at ' + SUBJECT_CAP + ' bytes.', subject: link.subject };", self::between($script, 'function noteCutLink(link) {', "\n  }\n"));
        $this->assertStringContainsString('noteCutLink(fromHash);', $script, 'The link read at boot is the one noted.');
        $this->assertStringContainsString('return linkNotice && el.subject.value === linkNotice.subject ? linkNotice.text : \'\';', self::between($script, 'function linkWarning() {', "\n  }\n"));
        $set = self::between($script, 'function setStatus(message, tone) {', "\n  }\n");
        $this->assertStringContainsString('if (!message && linkWarning()) {', $set, 'An empty line falls back to the link\'s warning.');
    }

    /**
     * Before any run the meta line under the columns already says what
     * judged the sample, so the line is never a blank strip beside the
     * privacy chip.
     */
    #[Test]
    public function test_the_meta_line_names_the_sample_engine(): void
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
    public function test_the_privacy_chip_lives_in_the_meta_line(): void
    {
        $xpath = self::include();
        $sentence = 'Nothing you type leaves your browser';

        $chip = $xpath->query(self::byClass('pg-meta').'//'.self::self('pg-privacy'));
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

        // The pattern row sits on the page: the state's tones are the
        // state tokens of the theme it stands on, nothing of its own.
        $this->assertSame(1, preg_match('/^\.terminal \{([^}]*)\}/m', $stylesheet), 'The navy panel is the terminal\'s alone.');
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
     * height outright — and so does its row: under a panel taller than a
     * squeezed column the floor alone did not hold, the row fell back to
     * the strip's padding and the panel rose over the tabs.
     */
    #[Test]
    public function test_a_full_panel_never_crushes_the_strip(): void
    {
        $this->assertSame(1, preg_match('/^\.pg-tabs \{([^}]*)\}/m', self::source(self::STYLESHEET), $strip));
        $this->assertStringContainsString('overflow-x: auto;', $strip[1], 'The strip is a scroll container…');
        $this->assertStringContainsString('min-height: max-content;', $strip[1], '…so its height is asked for, never left to the automatic minimum.');
        $this->assertSame(1, preg_match('/^\.pg-results \{([^}]*)\}/m', self::source(self::STYLESHEET), $panel));
        $this->assertStringContainsString('grid-template-rows: max-content 1fr;', $panel[1], 'The strip\'s row is as tall as the strip, whatever stands under it.');
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
        $this->assertSame(['pg-main', 'pg-results', 'pg-loader'], $classes, 'The body holds two panes and the loader, in the order they read: the text, the answers.');
    }

    /**
     * The quick patterns are a row under the pattern, not a column beside
     * the work: one click loads a pattern and its text. A chip never
     * folds its name over two lines, and a row that scrolls fades on the
     * edge where more patterns wait, as the strip of tabs does. The
     * pattern under the name stays in the chip's words, read as its own
     * by a voice, out of the eye's way.
     */
    #[Test]
    public function test_the_quick_patterns_stand_in_a_row_of_their_own(): void
    {
        $xpath = self::include();

        $examples = $xpath->query(self::byClass('pg-quickbar').'/ul'.self::has('pg-examples').'/li/'.self::self('pg-example'));
        $this->assertNotFalse($examples);
        $this->assertGreaterThanOrEqual(10, $examples->length, 'A row is worth a list: the patterns are many.');
        foreach ($examples as $example) {
            $this->assertInstanceOf(\DOMElement::class, $example);
            $name = $xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " pg-example-name ")])', $example);
            $this->assertIsString($name);
            $this->assertNotSame('', $name, 'Every pattern is named.');
            $pattern = $xpath->evaluate('string(.//*[contains(concat(" ", normalize-space(@class), " "), " pg-example-pattern ")])', $example);
            $this->assertSame($example->getAttribute('data-regex'), $pattern, 'The line under the name is the pattern the click loads.');
            $this->assertNotSame('', $example->getAttribute('data-regex'));
            $this->assertNotSame('', $example->getAttribute('data-subject'), 'A pattern carries its own text to match against.');
            $this->assertSame($example->getAttribute('data-regex'), $example->getAttribute('title'), 'The tooltip says the whole pattern.');
        }

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-examples \{([^}]*)\}/m', $stylesheet, $row), 'The row\'s own rule exists.');
        $this->assertStringContainsString('flex-wrap: nowrap;', $row[1], 'One line of chips, at every width.');
        $this->assertStringContainsString('overflow-x: auto;', $row[1], 'The row scrolls, never stacks.');
        $this->assertSame(1, preg_match('/^\.pg-example \{([^}]*)\}/m', $stylesheet, $chip));
        $this->assertStringContainsString('white-space: nowrap;', $chip[1], 'A name never wraps at its space.');
        $this->assertStringContainsString('flex: none;', $chip[1], 'A chip never shrinks below its name.');
        $this->assertSame(1, preg_match('/^\.pg-examples \.pg-example-pattern \{([^}]*)\}/m', $stylesheet, $pattern), 'The pattern line waits for a place of its own.');
        $this->assertStringContainsString('clip-path: inset(50%);', $pattern[1], 'Hidden from the eye, still read as the chip\'s own words.');

        $this->assertStringContainsString('.pg-examples.has-more-right', $stylesheet);
        $this->assertStringContainsString('.pg-examples.has-more-left', $stylesheet);
        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString('function syncExampleFades() {', $script);
        $this->assertStringContainsString("el.examplesList.addEventListener('scroll', syncExampleFades, { passive: true });", $script);
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
        $this->assertSame(1, preg_match('/@media \(min-width: 1024px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-main \{([^}]*)\}/', $stylesheet, $main), 'The writing pane stretches wherever the panes stand side by side.');
        $this->assertStringContainsString('align-self: stretch;', $main[1]);
        $this->assertStringContainsString('grid-template-rows: minmax(0, 1fr) auto;', $main[1], 'The text field takes whatever height the answers leave; the pattern has a row of its own above the columns.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1024px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-editor-subject \.pg-input \{([^}]*)\}/', $stylesheet, $input), 'The text input fills its editor on a wide screen.');
        $this->assertStringContainsString('height: 100%;', $input[1]);
        $this->assertStringContainsString('max-height: none;', $input[1], 'No cap cuts the fill short.');
        $this->assertSame(1, preg_match('/@media \(min-width: 1024px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-editor-subject \.pg-mirror \{([^}]*)\}/', $stylesheet, $mirror), 'The mirror drops its floor with the input: an opened match list shrinks both.');
        $this->assertStringContainsString('min-height: 0;', $mirror[1]);

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("matchMedia('(min-width: 1024px)').matches", $script, 'The auto-size yields to the stylesheet exactly where the fill begins, at the width the panes stand side by side.');
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
        $this->assertSame(3, substr_count($script, 'new Worker('), 'Three places create a worker: the engine, the step trace, and the check of a translation in this browser.');
        $this->assertSame(1, preg_match('/var url = URL\.createObjectURL\(new Blob\(\[PARITY_WORKER\][^;]*;\s*var worker;\s*try \{\s*worker = new Worker\(url\);/', $script), 'The third is built from the page\'s own text: it downloads nothing.');
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
     * The page shows each sentence of the explanation as the library wrote
     * it: a branch keyword, a labelled kind or plain prose, never rewritten
     * on the way.
     */
    #[Test]
    public function test_the_explanation_is_shown_as_the_library_wrote_it(): void
    {
        $voices = self::between(self::source(self::SCRIPT), 'function explainVoices(content, head) {', "\n  }\n");

        $this->assertStringContainsString("text('span', 'pg-explain-text', keyword[2])", $voices, 'What follows a branch keyword.');
        $this->assertStringContainsString("text('span', 'pg-explain-text', labeled[2])", $voices, 'What follows a labelled kind.');
        $this->assertStringContainsString("text('span', 'pg-explain-text', content)", $voices, 'A line of plain prose.');
        $this->assertStringNotContainsString('.replace(', $voices);
    }

    /**
     * The library writes the sentence the page shows: no colon between a
     * word and a bracketed or escaped token, and a repetition as a clause
     * at the end of the line, "A digit [0-9], exactly 4 times".
     */
    #[Test]
    public function test_the_library_writes_the_sentence_the_page_shows(): void
    {
        $library = Regex::create();

        $this->assertStringContainsString('A digit [0-9], exactly 4 times', $library->explain('/\d{4}/'));
        $this->assertStringContainsString('A word character [a-zA-Z_0-9], zero or more times (and do not backtrack)', $library->explain('/\w*+\h{2,5}/'));
        $this->assertStringContainsString("any character in [ Range: from 'a' to 'c' ], zero or more times", $library->explain('/(?(1)\D{2}|[a-c]*)/'));
        $this->assertStringContainsString('Quantified group, one or more times (as few as possible)', $library->explain('/(?:[[:alpha:]\s]c)+?/u'));

        foreach (['/\d{4}/', '/(?:[[:alpha:]\s]c)+?/u', '/\w*+\h{2,5}/', '/(?(1)\D{2}|[a-c]*)/'] as $pattern) {
            foreach (explode("\n", $library->explain($pattern)) as $line) {
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z]: (?=[\[\\\\])/', $line, $pattern);
                $this->assertDoesNotMatchRegularExpression('/ \((?:(?:zero or more|one or more) times|once or not at all|exactly \d+ times|at least \d+(?: but not more than \d+)? times)(?: \((?:as few as possible|and do not backtrack)\))?\)$/', $line, $pattern);
            }
        }
    }

    /**
     * A comment, a verb's name, a callout's argument: the visitor's own
     * words, which the library quotes on a line of their kind and the page
     * shows as written.
     */
    #[Test]
    public function test_the_explanation_keeps_the_visitor_text(): void
    {
        $library = Regex::create();

        foreach ([
            "/a(?#see: [RFC 3339] or: 'x')b/" => ['Comment', "see: [RFC 3339] or: 'x'"],
            "/a #c: [x]\n/x" => ['Comment', 'c: [x]'],
            '/(*MARK:a: [b])x/' => ['PCRE Verb', 'a: [b]'],
            '/(*MARK:x (one or more times)a/' => ['PCRE Verb', 'x (one or more times'],
            '/(?C"t: [x]")a/' => ['Callout', 't: [x]'],
        ] as $pattern => [$kind, $words]) {
            $explained = $library->parse($pattern)->accept(new TextExplainer($library->target()));
            $this->assertSame(1, preg_match('/^\s*('.preg_quote($kind, '/').'): (.+)$/m', $explained, $line), $pattern.' is explained as a '.$kind.' line.');
            $this->assertStringContainsString($words, $line[2], $pattern.' keeps the visitor\'s words.');
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
