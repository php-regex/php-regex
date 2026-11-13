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
 * What each tab of the answers does with what it shows. A range is a way
 * to the pattern, never a number to read and look for: every one the tabs
 * print selects its bytes in the field. A translation is checked where it
 * will run. What the eye cannot see in a sample is drawn. The tests read
 * the script and the stylesheet as the page ships them.
 */
#[Group('documentation')]
final class PlaygroundTabsTest extends TestCase
{
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    // --- The strip -----------------------------------------------------------

    /**
     * A first visit sees tabs, not a line of words: every tab carries the
     * icon of its tool, the closed ones stand on a fill of their own, and
     * the open one is lifted — the panel's color, its edges, an accent on
     * its top — joined to the content under it, as a folder's tab is.
     */
    #[Test]
    public function test_the_strip_reads_as_tabs_at_first_sight(): void
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><body>'.self::source(self::INCLUDE).'</body>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $tabs = $xpath->query('//*[@role="tab"]');
        $this->assertNotFalse($tabs);
        $this->assertSame(7, $tabs->length);
        foreach ($tabs as $tab) {
            \assert($tab instanceof \DOMElement);
            $icon = $xpath->query('./*[1][self::svg][contains(@class, "pg-tab-icon")][@aria-hidden="true"]', $tab);
            $this->assertNotFalse($icon);
            $this->assertSame(1, $icon->length, $tab->getAttribute('data-tool').' leads with its icon, hidden from a voice that reads its name.');
        }

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-tab \{([^}]*)\}/m', $stylesheet, $closed));
        $this->assertStringContainsString('background: var(--code-bg);', $closed[1], 'A closed tab stands on a fill: it reads as a tab before a hand finds it.');
        $this->assertSame(1, preg_match('/^\.pg-tab\[aria-selected="true"\] \{([^}]*)\}/m', $stylesheet, $open));
        $this->assertStringContainsString('background: var(--panel);', $open[1], 'The open tab wears the panel\'s color, joined to what it shows.');
        $this->assertStringContainsString('border-color: var(--line-soft);', $open[1]);
        $this->assertStringContainsString('inset 0 2px 0 var(--amber)', $open[1], 'An accent on its top edge.');
        $this->assertSame(1, preg_match('/^\.pg-tabs \{([^}]*)\}/m', $stylesheet, $strip));
        $this->assertStringContainsString('box-shadow: inset 0 -1px 0 var(--line-soft);', $strip[1], 'The strip\'s line is painted under the tabs, so the open one covers it: a border would be clipped by the scrolling strip.');
        $this->assertSame(1, preg_match('/^\.pg-tab-icon \{([^}]*)\}/m', $stylesheet, $icon));
        $this->assertStringContainsString('stroke: currentColor;', $icon[1], 'The icon takes the tab\'s own ink, open or closed.');
    }

    /**
     * A narrow panel — a laptop, or a column the visitor squeezed — keeps
     * every tab in sight: the closed ones say themselves by their icon,
     * their name in the tip and to a voice, and the open one keeps its
     * word. The panel's own width decides, not the window's: the splitter
     * moves it too.
     */
    #[Test]
    public function test_a_narrow_panel_keeps_every_tab_in_sight(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^\.pg-results \{([^}]*)\}/m', $stylesheet, $panel));
        $this->assertStringContainsString('container: pg-answers / inline-size;', $panel[1]);
        $this->assertSame(1, preg_match('/@container pg-answers \(max-width: 46rem\) \{\s*\.pg-tab:not\(\[aria-selected="true"\]\) \{([^}]*)\}/', $stylesheet, $compact));
        $this->assertStringContainsString('font-size: 0;', $compact[1], 'The word is folded for the eye, still the tab\'s name for a voice.');

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><body>'.self::source(self::INCLUDE).'</body>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $tabs = (new \DOMXPath($document))->query('//*[@role="tab"]');
        $this->assertNotFalse($tabs);
        foreach ($tabs as $tab) {
            \assert($tab instanceof \DOMElement);
            $this->assertSame(trim((string) $tab->firstElementChild?->nextSibling?->textContent), $tab->getAttribute('title'), 'A tab folded to its icon names itself in its tip.');
        }
    }

    // --- Tree ----------------------------------------------------------------

    /**
     * The row says a quantifier the way the pattern says it: \d{4} is
     * "{4}", not "{4,4}"; a star is a star.
     */
    #[Test]
    public function test_a_quantifier_reads_as_it_is_written(): void
    {
        $notation = self::between(self::source(self::SCRIPT), 'function quantifierNotation(node) {', "\n  }\n");

        foreach (["return '*';", "return '+';", "return '?';", "'{' + node.min + '}'", "'{' + node.min + ',}'", "'{' + node.min + ',' + node.max + '}'"] as $form) {
            $this->assertStringContainsString($form, $notation);
        }
        $describe = self::between(self::source(self::SCRIPT), 'function describeRow(node) {', "\n  }\n");
        $this->assertStringContainsString("case 'quantifier': detail = quantifierNotation(node) + ' ' + node.mode; break;", $describe);
    }

    /**
     * A tree folds, as every tree of an IDE does: a chevron on the rows
     * that have children, the keys of the tree pattern — right opens, then
     * enters; left closes, then climbs — and up and down skip what a fold
     * hides.
     */
    #[Test]
    public function test_the_tree_folds_by_the_chevron_and_by_the_keys(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString("var toggle = text('span', 'tree-toggle' + (children.length ? '' : ' is-leaf'));", $script, 'Every row keeps the chevron\'s room, so the names line up.');
        $this->assertStringContainsString("toggle.setAttribute('aria-hidden', 'true');", $script, 'The state is the item\'s aria-expanded; the chevron is for the eye.');
        $fold = self::between($script, 'function setExpanded(index, open) {', "\n  }\n");
        $this->assertStringContainsString("setAttribute('aria-expanded', open ? 'true' : 'false')", $fold);
        $keys = self::between($script, "el.treeNodes.addEventListener('keydown', function (event) {", "\n  });\n");
        $this->assertStringContainsString('isExpanded(index)', $keys);
        $this->assertStringContainsString('setExpanded(index, true)', $keys, 'Right opens a closed row.');
        $this->assertStringContainsString('setExpanded(index, false)', $keys, 'Left closes an open one.');
        $this->assertStringContainsString('visibleStep(index, 1)', $keys, 'Down skips what a fold hides.');
        $this->assertStringContainsString('visibleStep(index, -1)', $keys);
        $this->assertStringContainsString("event.target.closest('.tree-toggle')", $script, 'A click on the chevron folds.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.tree-item\[aria-expanded="false"\] > \.tree-children \{([^}]*)\}/m', $stylesheet, $folded));
        $this->assertStringContainsString('display: none;', $folded[1]);
        $this->assertSame(1, preg_match('/^\.tree-item\[aria-expanded="true"\] > \.tree-row \.tree-toggle::before \{([^}]*)\}/m', $stylesheet, $open));
        $this->assertStringContainsString('rotate(90deg)', $open[1], 'The chevron pivots on the open row, the dialect of every tree of this web.');
    }

    /**
     * The line that reads the node under the pointer stands at the foot of
     * the panel, not under the last row: a long tree no longer pushes what
     * the pointer asks for out of sight.
     */
    #[Test]
    public function test_the_node_detail_stays_in_sight_under_a_long_tree(): void
    {
        $this->assertSame(1, preg_match('/^\.tree-detail \{([^}]*)\}/m', self::source(self::STYLESHEET), $detail));
        $this->assertStringContainsString('position: sticky;', $detail[1]);
        $this->assertStringContainsString('bottom: 0;', $detail[1]);
        $this->assertStringContainsString('background: var(--panel);', $detail[1], 'Opaque: the rows scroll under it, never through it.');
    }

    /**
     * The node the pointer is on lights the field the way it lights the
     * tree's own pattern: a tint and a line under it. A box drawn round
     * each piece the field's colors cut it into read as a row of tiles.
     */
    #[Test]
    public function test_the_field_lights_a_node_as_the_tree_s_pattern_does(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^\.pg-mirror \.pg-mark-node \{([^}]*)\}/m', $stylesheet, $field));
        $this->assertStringContainsString('background: var(--amber-tint);', $field[1]);
        $this->assertStringContainsString('box-shadow: inset 0 -2px 0 var(--amber);', $field[1], 'A line under the node, as in the tree\'s pattern.');
        $this->assertStringNotContainsString('inset 0 0 0', $field[1], 'No box: the pieces would each draw their own.');
    }

    // --- Diagnostics ---------------------------------------------------------

    /**
     * A diagnostic's range is a way to it: a click selects its bytes in the
     * field, a pointer on the item lights them there first.
     */
    #[Test]
    public function test_a_diagnostic_range_leads_to_its_bytes_in_the_field(): void
    {
        $script = self::source(self::SCRIPT);

        $reveal = self::between($script, 'function revealRange(start, end) {', "\n  }\n");
        $this->assertStringContainsString('unitRange(current.payload, start, end)', $reveal, 'The bytes the engine counts, in the units the field counts.');
        $this->assertStringContainsString('el.pattern.setSelectionRange(', $reveal);
        $this->assertStringContainsString('el.pattern.focus();', $reveal);
        $goto = self::between($script, 'function rangeButton(label, start, end) {', "\n  }\n");
        $this->assertStringContainsString("'pg-goto'", $goto);
        $this->assertStringContainsString('revealRange(start, end)', $goto);
        $this->assertStringContainsString("'mouseenter'", $goto, 'Pointing lights the bytes before a click selects them.');

        $lint = self::between($script, 'function renderLint(findings) {', "\n  }\n");
        $this->assertStringContainsString('rangeButton(', $lint);
        $errors = self::between($script, 'function renderErrors(payload) {', "\n  }\n");
        $this->assertStringContainsString('rangeButton(', $errors);
        $mirror = self::between($script, 'function renderPatternMirror(payload, regex, lint) {', "\n  }\n");
        $this->assertStringContainsString('if (hoverRange) {', $mirror, 'The field lights the range the pointer is on.');
    }

    #[Test]
    public function test_the_diagnostics_count_by_severity_and_keep_the_badge_beside_the_message(): void
    {
        $lint = self::between(self::source(self::SCRIPT), 'function renderLint(findings) {', "\n  }\n");
        $this->assertStringContainsString('severityCounts(findings)', $lint, 'Two findings say which: "1 warning, 1 info".');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-diag-head \{([^}]*)\}/m', $stylesheet, $head));
        $this->assertStringContainsString('flex-wrap: nowrap;', $head[1], 'A long message wraps in its own column, never under its badge.');
        $this->assertSame(1, preg_match('/^\.pg-diag-badge \{([^}]*)\}/m', $stylesheet, $badge));
        $this->assertStringContainsString('flex: none;', $badge[1]);
        $this->assertSame(1, preg_match('/^\.pg-goto \{([^}]*)\}/m', $stylesheet, $goto));
        $this->assertStringContainsString('cursor: pointer;', $goto[1]);
    }

    // --- ReDoS ---------------------------------------------------------------

    #[Test]
    public function test_the_verdict_s_actions_say_what_they_do_beside_them(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-tool-row > \.pg-tool-line \{([^}]*)\}/m', $stylesheet, $line), 'A button and the words that say what it does share a line.');
        $this->assertStringContainsString('flex: 1 1 14rem;', $line[1]);
        $this->assertStringContainsString('margin: 0;', $line[1]);

        $redos = self::between(self::source(self::SCRIPT), 'function renderRedos(panel, payload) {', "\n  }\n");
        $this->assertStringContainsString('rangeButton(hotspot.pattern, hotspot.start, hotspot.end)', $redos, 'A hotspot leads to its bytes, as a diagnostic does.');
        $this->assertStringContainsString("'Suggested (verify behavior): '", $redos, 'A recommendation is split into what is wrong and what to try.');
        $this->assertStringContainsString("'pg-hint-fix'", $redos);
    }

    // --- Translate -----------------------------------------------------------

    /**
     * The translation is checked where it will run: the browser's own
     * JavaScript engine matches the text, in a worker that a pattern that
     * backtracks cannot freeze the page from, and the panel says whether
     * it finds what PHP found.
     */
    #[Test]
    public function test_a_javascript_translation_is_checked_in_this_browser(): void
    {
        $script = self::source(self::SCRIPT);

        $check = self::between($script, 'function checkInBrowser(panel, result) {', "\n  }\n");
        $this->assertStringContainsString("new Blob([PARITY_WORKER], { type: 'text/javascript' })", $check, 'A worker of its own: the page never runs the pattern on its main thread.');
        $this->assertStringContainsString('worker.terminate();', $check);
        $this->assertStringContainsString('PARITY_TIMEOUT_MS', $check, 'A pattern that backtracks in JavaScript too is stopped, and said.');
        $this->assertStringContainsString('current.payload.matches', $check, 'Compared with what PHP found on the same text.');
        $this->assertStringContainsString('URL.revokeObjectURL(', $check);
        $this->assertStringContainsString("subject.matchAll(", $script, 'Every match, as preg_match_all walks them.');

        $render = self::between($script, 'function renderTranspile(panel, payload, announceResult) {', "\n  }\n");
        $this->assertStringContainsString("toolSettings.transpile.target === 'js'", $render, 'Only JavaScript runs here; Python and the HTML attribute are not checked.');
        $this->assertStringContainsString('checkInBrowser(panel, result)', $render);
        $this->assertStringContainsString("codeRow(panel, 'Literal', result.literal", $render, 'The literal and the constructor each have a row and a copy of their own.');
        $this->assertStringContainsString("codeRow(panel, 'Constructor', result.constructor", $render);

        $stylesheet = self::source(self::STYLESHEET);
        foreach (['.pg-parity.is-same', '.pg-parity.is-different', '.pg-parity.is-stopped'] as $state) {
            $this->assertStringContainsString($state, $stylesheet);
        }
    }

    // --- Samples -------------------------------------------------------------

    #[Test]
    public function test_a_sample_draws_the_whitespace_the_eye_cannot_see(): void
    {
        $script = self::source(self::SCRIPT);
        $draw = self::between($script, 'function drawWhitespace(target, value) {', "\n  }\n");
        foreach (["' ': '\\u00B7'", "'\\t': '\\u2192'", "'\\n': '\\u21B5'"] as $glyph) {
            $this->assertStringContainsString($glyph, $draw);
        }
        $this->assertStringContainsString("'pg-ws'", $draw);
        $samples = self::between($script, 'function renderSamples(panel, answer, announceResult) {', "\n  }\n");
        $this->assertStringContainsString('drawWhitespace(chip, sample)', $samples);
        $this->assertStringContainsString("chip.setAttribute('aria-label'", $samples, 'A voice reads the sample, not the dots that draw its spaces.');

        $this->assertSame(1, preg_match('/^\.pg-ws \{([^}]*)\}/m', self::source(self::STYLESHEET), $ws));
        $this->assertStringContainsString('color: var(--ink-soft);', $ws[1]);
    }

    // --- Format --------------------------------------------------------------

    #[Test]
    public function test_a_laid_out_pattern_can_take_the_field_s_place(): void
    {
        $format = self::between(self::source(self::SCRIPT), 'function renderFormat(panel, payload, announceResult) {', "\n  }\n");

        $this->assertStringContainsString("toolButton('Use in the field'", $format);
        $this->assertStringContainsString('el.pattern.value = result.pattern;', $format);
        $this->assertStringContainsString("el.pattern.dispatchEvent(new Event('input', { bubbles: true }));", $format, 'An edit of the field like any other: the address and the counts follow.');
        $this->assertStringContainsString('if (result.changed) {', $format, 'Offered only when there is something to use.');
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
