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
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

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
