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
 * The Step through tab is a debugger over a recorded match: a transport
 * that travels both ways, a timeline that is a slider, the execution point
 * shown in the pattern and in the text, and three panes — the steps, the
 * stops, the captures. The trace is PCRE2's own; these tests read what the
 * page builds around it, in the script and the stylesheet as they ship.
 */
#[Group('documentation')]
final class PlaygroundStepDebuggerTest extends TestCase
{
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    /**
     * Four buttons travel the trace, in the order a hand expects: back to
     * the previous stop, one step back, one step on, on to the next stop.
     * Each says its name to a voice, its key in a tooltip, and its key to
     * an assistive technology.
     */
    #[Test]
    public function test_the_transport_names_its_four_buttons_and_their_keys(): void
    {
        $section = self::debugger();

        $this->assertSame(4, preg_match_all("/\{ act: '(\w+)', label: '([^']+)', hint: '([^']+)', keys: '([^']+)'/", self::between($section, 'var TRACE_CONTROLS = [', '];'), $controls), 'Four buttons travel; Play stands on the timeline.');
        $this->assertSame(['rewind', 'back', 'step', 'resume'], $controls[1], 'The transport reads left to right: back in time, then on.');
        $this->assertSame(['Shift+ArrowLeft', 'ArrowLeft', 'ArrowRight', 'Shift+ArrowRight'], $controls[4], 'The arrows step; Shift travels to a stop.');

        $button = self::between($section, 'function traceButton(control) {', "\n  }\n");
        $this->assertStringContainsString("setAttribute('aria-label', control.label)", $button, 'An icon alone names nothing.');
        $this->assertStringContainsString("button.title = control.label + ' (' + control.hint + ')';", $button, 'The tooltip teaches the key.');
        $this->assertStringContainsString("setAttribute('aria-keyshortcuts', control.keys)", $button);
    }

    /**
     * A button that reached an end of the trace is dimmed, never disabled:
     * a disabled button drops the keyboard's focus to the body, and the
     * arrows that were stepping the trace would step nothing.
     */
    #[Test]
    public function test_an_end_of_the_trace_dims_a_button_and_keeps_its_focus(): void
    {
        $section = self::debugger();

        $this->assertStringContainsString("setAttribute('aria-disabled'", $section);
        $this->assertSame(0, preg_match('/\.disabled\s*=/', $section), 'No control of the debugger is ever disabled.');
    }

    /**
     * The icons are drawn in the site's dialect — Lucide's, hidden from a
     * voice — and no pictograph stands in for one: a glyph's shape
     * is the font's, and the fonts differ.
     */
    #[Test]
    public function test_the_icons_are_drawn_not_typed(): void
    {
        $section = self::debugger();

        $icon = self::between($section, 'function traceIcon(name, solid) {', "\n  }\n");
        $this->assertStringContainsString("'http://www.w3.org/2000/svg'", $section);
        $this->assertStringContainsString("setAttribute('viewBox', '0 0 24 24')", $icon, 'Lucide\'s own box: the icons are the vendored ones, as every icon of the site.');
        $this->assertStringContainsString("setAttribute('aria-hidden', 'true')", $icon);
        $this->assertStringContainsString("setAttribute('focusable', 'false')", $icon);
        $this->assertSame(0, preg_match('/[\x{23E9}-\x{23FA}\x{25B6}\x{25C0}\x{2B05}\x{27A1}\x{1F300}-\x{1FAFF}]/u', $section), 'No media pictograph, no emoji.');
    }

    /**
     * Play and Pause are two drawings, not two paths of one: the button
     * swaps its whole icon each way, so neither turn leaves it blank.
     */
    #[Test]
    public function test_play_and_pause_swap_their_whole_icon(): void
    {
        $section = self::debugger();

        $sync = self::between($section, 'function syncTracePlay(view, playing) {', "\n  }\n");
        $this->assertStringContainsString("traceIcon(playing ? 'pause' : 'play', TRACE_PLAY.solid)", $sync, 'Both turns draw the icon of the state the button now stands for.');
        $this->assertStringContainsString('.replaceWith(', $sync, 'The old drawing goes, the new one takes its place.');
        $this->assertStringNotContainsString("setAttribute('d'", $sync, 'An icon is more than one path: no path data is written over another.');
    }

    /**
     * The timeline is the browser's own slider, laid unseen over the chart
     * of the match: the keys, the drag and the screen reader are the
     * platform's. The playhead and the chart share the travel of the
     * slider's thumb, so the line under the pointer is the step it sets.
     */
    #[Test]
    public function test_the_timeline_is_a_native_slider_over_the_chart(): void
    {
        $section = self::debugger();
        $this->assertStringContainsString("slider.type = 'range';", $section);
        $this->assertStringContainsString("slider.setAttribute('aria-label', 'Step');", $section);
        $this->assertStringContainsString("setAttribute('aria-valuetext'", $section, 'A voice hears “Step 6 of 23”, not a bare number.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-trace-track \{([^}]*)\}/m', $stylesheet, $track));
        $this->assertStringContainsString('--thumb:', $track[1], 'One length says the thumb\'s width.');
        $this->assertSame(1, preg_match('/^\.pg-trace-track input\[type="range"\] \{([^}]*)\}/m', $stylesheet, $slider));
        $this->assertStringContainsString('opacity: 0;', $slider[1], 'The slider is felt, not seen: the chart is its face.');
        $this->assertSame(1, preg_match('/^\.pg-trace-playhead \{([^}]*)\}/m', $stylesheet, $playhead));
        $this->assertStringContainsString('var(--thumb)', $playhead[1]);
        $this->assertSame(1, preg_match('/^\.pg-trace-chart \{([^}]*)\}/m', $stylesheet, $chart));
        $this->assertStringContainsString('var(--thumb)', $chart[1]);
        $this->assertStringContainsString('.pg-trace-track:has(input:focus-visible)', $stylesheet, 'The unseen slider still shows where the keyboard is.');
    }

    /**
     * A step moves the list under the current row, and nothing else: the
     * page and the answers column stay where the visitor left them.
     */
    #[Test]
    public function test_a_step_never_scrolls_the_page(): void
    {
        $section = self::debugger();

        $this->assertStringNotContainsString('scrollIntoView(', $section, 'scrollIntoView moves every scrolling ancestor, the page included.');
        $reveal = self::between($section, 'function traceReveal(box, row, head) {', "\n  }\n");
        $this->assertStringContainsString('box.scrollTop =', $reveal, 'The box that holds the row scrolls, alone.');
    }

    /**
     * Playback is a timer: it ends when the panel is drawn again, when the
     * tab is left, and at the last step. While it runs the status line is
     * not live — a voice would read every step of a run it cannot follow —
     * and it speaks again where the run stopped.
     */
    #[Test]
    public function test_playback_stops_with_the_panel_and_stays_quiet(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString('function traceStopPlay() {', $script);
        $this->assertStringContainsString('traceStopPlay();', self::between($script, 'function renderToolPanel(tool, announceResult) {', "\n  }\n"), 'A redrawn panel holds no running timer.');
        $this->assertStringContainsString('traceStopPlay();', self::between($script, 'function activateTool(tool, focus) {', "\n  }\n"), 'A tab that is left stops playing.');

        $section = self::debugger();
        $this->assertStringContainsString("setAttribute('aria-live', 'off')", $section);
        $this->assertStringContainsString("setAttribute('aria-live', 'polite')", $section);
    }

    /**
     * The keys are read in one place, on the panel, so they answer wherever
     * the focus stands in it. A modified key stays the browser's; the
     * slider and the checkboxes keep their own.
     */
    #[Test]
    public function test_the_keys_are_read_once_on_the_panel(): void
    {
        $script = self::source(self::SCRIPT);
        $this->assertSame(1, substr_count($script, "toolPanel('trace').addEventListener('keydown', onTraceKey);"));

        $keys = self::between($script, 'function onTraceKey(event) {', "\n  }\n");
        $this->assertStringContainsString('event.altKey || event.ctrlKey || event.metaKey', $keys);
        $this->assertStringContainsString("tagName === 'INPUT'", $keys, 'The slider steps by its own arrows; a checkbox ticks by its own Space.');
        foreach (["'ArrowRight'", "'ArrowLeft'", "'Home'", "'End'", "' '"] as $key) {
            $this->assertStringContainsString('case '.$key.':', $keys);
        }
    }

    /**
     * Resume and Rewind travel between stops: a backtrack, the start of a
     * match attempt, a capture that moved. The visitor chooses which ones
     * count, each shown with how many the trace holds.
     */
    #[Test]
    public function test_resume_and_rewind_travel_between_the_chosen_stops(): void
    {
        $section = self::debugger();

        $this->assertStringContainsString('var traceStops = { backtrack: true, attempt: true, capture: false };', $section, 'The two events PCRE2 flags are stops from the start.');
        $this->assertSame(3, preg_match_all("/\{ kind: '(\w+)', label: '([^']+)'/", self::between($section, 'var TRACE_STOPS = [', '];'), $stops));
        $this->assertSame(['backtrack', 'attempt', 'capture'], $stops[1]);
        $this->assertSame(3, preg_match_all('/hint: .*(flag|attempt|capture)/', self::between($section, 'var TRACE_STOPS = [', '];'), $hints), 'Every stop says what it is in its tooltip, for the count beside a backtrack is of flagged steps, never of backtracks.');
        $this->assertStringContainsString('function traceSeek(answer, direction) {', $section);
        $this->assertStringContainsString('function traceIsStop(answer, index) {', $section);
        $this->assertStringContainsString("input.type = 'checkbox';", $section, 'A stop is a checkbox: the platform\'s own.');

        // Every stop wears its mark twice, in the list and on the timeline,
        // and unticking it fades both. The capture stop is no exception:
        // its rows carry the stop's bar, its changes tick the chart's top.
        $this->assertStringContainsString("(answer.marks.moved[index] ? ' is-capture' : '')", $section, 'A row the capture stop pauses on says so in its class.');
        $this->assertStringContainsString("text('span', 'visually-hidden', 'Capture changed. '", $section, 'And in words, for a voice.');
        $this->assertStringContainsString("['is-capture', strokes(answer.marks.capture", $section, 'The chart draws the capture changes along its top.');
        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-trace-step\.is-capture \{([^}]*)\}/m', $stylesheet, $mark));
        $this->assertStringContainsString('box-shadow: inset -2px 0 0 var(--amber);', $mark[1], 'The stop\'s swatch is a bar: the row wears it at its far end.');
        foreach (['.pg-trace.no-capture .pg-trace-step.is-capture', '.pg-trace.no-capture .pg-trace-chart .is-capture'] as $dimmed) {
            $this->assertStringContainsString($dimmed, $stylesheet, 'An unticked stop fades everywhere it wears its mark.');
        }
    }

    /**
     * The execution point is shown where the hand is: the pattern with the
     * item PCRE2 is about to try, the text with what the attempt consumed.
     * Both are for the eye — the status line above them says the same in
     * words, and it is the one a voice hears, the step's number first.
     */
    #[Test]
    public function test_the_execution_point_shows_the_pattern_and_the_text(): void
    {
        $section = self::debugger();

        $this->assertStringContainsString("text('pre', 'pg-trace-pattern')", $section);
        $this->assertStringContainsString("text('pre', 'pg-trace-subject')", $section);
        $this->assertSame(2, substr_count(self::between($section, 'function renderTrace(panel, answer, announceResult) {', "\n  }\n"), "setAttribute('aria-hidden', 'true')"), 'The two lines are for the eye.');
        $this->assertStringContainsString("text('p', 'pg-trace-status')", $section);
        $this->assertStringContainsString("status.setAttribute('aria-atomic', 'true');", $section);
        $this->assertStringContainsString("text('span', 'visually-hidden', 'Step '", $section, 'A voice hears which step it is before what the step does.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-trace-item \{([^}]*)\}/m', $stylesheet, $item));
        $this->assertStringContainsString('background: var(--mark-match-current);', $item[1], 'The item wears the mark it wears in the pattern field.');
    }

    /**
     * A trace holds up to twenty thousand steps, and a step must cost the
     * same on the last as on the first. A row is one fixed line, numbered
     * by the stylesheet; the rows stand in chunks the browser skips when
     * they are out of sight, each chunk saying how tall its rows make it —
     * a list of twenty thousand siblings is walked whole at every layout,
     * a list of chunks is not. And a step reads the layout once, after it
     * has written everything.
     */
    #[Test]
    public function test_a_step_costs_the_same_on_a_long_trace(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^\.pg-trace-steps \{([^}]*)\}/m', $stylesheet, $list));
        $this->assertSame(1, preg_match('/^\s*--row: [\d.]+rem;$/m', $list[1]), 'One length says a row\'s height.');
        $this->assertSame(1, preg_match('/^\.pg-trace-step \{([^}]*)\}/m', $stylesheet, $row));
        $this->assertStringContainsString('height: var(--row);', $row[1], 'A fixed height: the list can place a row it has not drawn.');
        $this->assertSame(1, preg_match('/^\.pg-trace-step::before \{([^}]*)\}/m', $stylesheet, $number));
        $this->assertStringContainsString('content: attr(data-n);', $number[1], 'The number costs no element.');
        $this->assertSame(1, preg_match('/^\.pg-trace-chunk \{([^}]*)\}/m', $stylesheet, $chunk));
        $this->assertStringContainsString('content-visibility: auto;', $chunk[1], 'A chunk out of sight is skipped.');
        $this->assertStringContainsString('contain-intrinsic-block-size: auto calc(var(--rows, 1) * var(--row));', $chunk[1], 'And still stands as tall as its rows.');

        $section = self::debugger();
        $this->assertSame(1, preg_match('/var TRACE_CHUNK_ROWS = (\d+);/', $section, $rows));
        $this->assertLessThanOrEqual(200, (int) $rows[1], 'A chunk is a screen or two of rows, not the trace.');
        $this->assertStringContainsString("style.setProperty('--rows'", $section, 'Every chunk is told how many rows it holds.');

        // Every write of a step comes before its first read of the layout:
        // one layout a step, not one per box brought into view.
        $step = self::between($section, 'function setTraceStep(answer, index) {', "\n  }\n");
        $firstReveal = strpos($step, 'traceReveal(');
        $this->assertNotFalse($firstReveal);
        foreach (['clear(view.pattern);', 'clear(view.subject);', 'clear(view.captures);', 'fillTraceStatus('] as $write) {
            $at = strpos($step, $write);
            $this->assertNotFalse($at, $write);
            $this->assertLessThan($firstReveal, $at, $write.' is written before the layout is read.');
        }
    }

    /**
     * The debugger answers its own width, not the window's: the answers
     * column is squeezed and widened by hand. Wide, the panes stand side by
     * side; and where the column has a height of its own, the debugger
     * fills it and the panes scroll inside, as a debugger's do.
     */
    #[Test]
    public function test_the_debugger_answers_its_own_width(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^#pg-tool-trace \{([^}]*)\}/m', $stylesheet, $panel));
        $this->assertStringContainsString('container: pg-trace / inline-size;', $panel[1]);
        $this->assertSame(1, preg_match('/^@container pg-trace \(min-width: [\d.]+rem\) \{/m', $stylesheet), 'The panes stand side by side where the panel is wide.');
        $this->assertSame(1, preg_match('/^@media \(min-width: 1024px\) \{\n  @container pg-trace \(min-width: [\d.]+rem\) \{(.*?)\n  \}\n\}/ms', $stylesheet, $fill), 'The fill waits for a column that has a height.');
        $this->assertSame(1, preg_match('/\.pg-trace \{[^}]*height: 100%;/', $fill[1]));
        $this->assertStringContainsString('contain: size;', $fill[1], 'A list that fills its pane asks for no height of its own.');
    }

    /**
     * The debugger's part of the script: from its banner to the tab
     * strip's handlers.
     */
    private static function debugger(): string
    {
        return self::between(self::source(self::SCRIPT), '// --- step through ---', "// The tab strip's hands");
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
