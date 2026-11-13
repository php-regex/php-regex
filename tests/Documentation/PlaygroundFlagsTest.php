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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The flags beside the pattern: one toggle per modifier PHP reads, the
 * five a visitor reaches for in the row, the rest behind a door. A toggle
 * writes the letter after the pattern's closing delimiter, and the field
 * stays the one source of truth: a toggle is pressed because the letter
 * is there, whoever wrote it. The tests read the include, the script and
 * the stylesheet as the page ships them.
 */
#[Group('documentation')]
final class PlaygroundFlagsTest extends TestCase
{
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    /**
     * The row's five, in the order a reader of PHP code meets them.
     */
    private const PRIMARY = ['i', 'm', 's', 'x', 'u'];

    /**
     * The door's five. S and X are left out: PHP has made both no-ops
     * since PCRE2, and a toggle that changes nothing is a lie.
     */
    private const MORE = ['n', 'U', 'D', 'A', 'J'];

    #[Test]
    public function test_the_flags_stand_in_the_pattern_row_before_the_version(): void
    {
        $xpath = self::include();
        $controls = '//*'.self::has('pg-patternbar').'//*'.self::has('pg-controls');

        $this->assertSame(1, self::nodes($xpath, $controls.'/*'.self::has('pg-flags').'[@role="group"][@aria-label="Flags"]'), 'One group of flags, named for a voice, among the row\'s controls.');
        $this->assertSame(1, self::nodes($xpath, $controls.'/*'.self::has('pg-flags').'/following-sibling::select[@id="pg-version"]'), 'The flags are read before the engine they run on.');
    }

    /**
     * @param list<string> $letters
     */
    #[Test]
    #[DataProvider('provideFlagSets')]
    public function test_every_flag_is_a_toggle_named_by_its_letter_and_its_meaning(string $place, array $letters): void
    {
        $xpath = self::include();
        $buttons = $xpath->query($place.self::has('pg-flag'));
        $this->assertNotFalse($buttons);

        $seen = [];
        foreach ($buttons as $button) {
            $this->assertInstanceOf(\DOMElement::class, $button);
            $letter = $button->getAttribute('data-flag');
            $seen[] = $letter;
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertSame('false', $button->getAttribute('aria-pressed'), 'A toggle starts released; the script presses what the field says.');
            $this->assertStringStartsWith($letter.', ', $button->getAttribute('aria-label'), 'A voice hears the letter, then what it does.');
            $this->assertGreaterThan(\strlen($letter) + 2, \strlen($button->getAttribute('aria-label')));
        }
        $this->assertSame($letters, $seen);
    }

    /**
     * @return iterable<string, array{place: string, letters: list<string>}>
     */
    public static function provideFlagSets(): iterable
    {
        yield 'the row' => ['place' => '//*'.self::has('pg-flags').'/button', 'letters' => self::PRIMARY];
        yield 'the door' => ['place' => '//*'.self::has('pg-flags-menu').'//button', 'letters' => self::MORE];
    }

    #[Test]
    public function test_the_row_flags_say_their_meaning_on_hover_and_the_door_flags_in_words(): void
    {
        $xpath = self::include();

        $this->assertSame(\count(self::PRIMARY), self::nodes($xpath, '//*'.self::has('pg-flags').'/button'.self::has('pg-flag').'[@data-tip]'), 'A letter alone is a riddle: the row\'s toggles carry a tip.');
        $this->assertSame(\count(self::MORE), self::nodes($xpath, '//*'.self::has('pg-flags-menu').'//button'.self::has('pg-flag').'/*'.self::has('pg-flag-name')), 'In the door there is room for the words.');
        $this->assertSame(1, self::nodes($xpath, '//*'.self::has('pg-flags').'/details'.self::has('pg-flags-more').'/summary[@aria-label="More flags"]'), 'The rest is behind a door named for a voice.');
        $this->assertSame(1, self::nodes($xpath, '//*'.self::has('pg-flags-menu').'//button[@data-flag="n"]//*'.self::has('pg-flag-note')), 'The one flag an older PHP does not read says since which.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-flag\[data-tip\]::after \{([^}]*)\}/m', $stylesheet, $tip), 'The tip is drawn by the stylesheet, from the attribute.');
        $this->assertStringContainsString('content: attr(data-tip);', $tip[1]);
        $this->assertStringContainsString('pointer-events: none;', $tip[1], 'A tip never takes the click meant for what is under it.');
        $this->assertStringContainsString('.pg-flag[data-tip]:hover::after', $stylesheet);
        $this->assertStringContainsString('.pg-flag[data-tip]:focus-visible::after', $stylesheet, 'The keyboard reads the tip the pointer reads.');
    }

    #[Test]
    public function test_a_pressed_flag_reads_as_pressed_in_both_themes_and_a_forced_palette(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^\.pg-flag\[aria-pressed="true"\] \{([^}]*)\}/m', $stylesheet, $pressed));
        $this->assertStringContainsString('var(--amber', $pressed[1], 'Pressed wears the accent, the tokens of both themes.');
        $this->assertStringContainsString('border-color:', $pressed[1], 'Pressed is a border too, not a fill alone: a forced palette drops fills.');
        $this->assertSame(1, preg_match('/^\.pg-flag:disabled \{([^}]*)\}/m', $stylesheet, $disabled));
        $this->assertStringContainsString('cursor: not-allowed;', $disabled[1]);
    }

    #[Test]
    public function test_a_phone_gives_the_flags_a_row_of_their_own(): void
    {
        $stylesheet = self::source(self::STYLESHEET);
        $phone = '/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*';

        $this->assertSame(1, preg_match($phone.'\.pg-patternbar \.pg-controls \{([^}]*)\}/', $stylesheet, $controls));
        $this->assertStringContainsString('flex-wrap: wrap;', $controls[1], 'Six toggles, a select and Run do not share 375 pixels.');
        $this->assertSame(1, preg_match($phone.'\.pg-flags \{([^}]*)\}/', $stylesheet, $flags));
        $this->assertStringContainsString('flex: 1 0 100%;', $flags[1], 'The flags take the first row whole; the version and Run share the next one.');
    }

    /**
     * The field is the truth: the toggles read it on every change, a
     * keystroke, an example, a link, a finding, and write it back through
     * the same input event a keystroke fires, so the address, the counts
     * and the finding's exit follow without a second path.
     */
    #[Test]
    public function test_the_toggles_read_the_field_and_write_through_its_input_event(): void
    {
        $script = self::source(self::SCRIPT);

        $refresh = self::between($script, 'function refreshFields() {', "\n  }\n");
        $this->assertStringContainsString('syncFlags();', $refresh, 'Whatever filled the field, the toggles follow it.');

        $toggle = self::between($script, 'function toggleFlag(letter) {', "\n  }\n");
        $this->assertStringContainsString("el.pattern.dispatchEvent(new Event('input', { bubbles: true }));", $toggle, 'A toggle is an edit of the field, nothing else.');
        $this->assertStringContainsString('analyzeNow();', $toggle, 'A toggle is a run gesture, like a chip.');

        $version = self::between($script, "el.version.addEventListener('change', function () {", "\n  });\n");
        $this->assertStringContainsString('syncFlags();', $version, 'A version that does not read a flag disables its toggle.');
    }

    /**
     * PHP's own reading: the first non-space character opens; a bracket
     * closes with its mirror, anything else with itself; the letters
     * after the last closer are the flags, spaces between them ignored.
     * A field that is not a delimited pattern has no flags to toggle.
     */
    #[Test]
    public function test_the_flags_are_read_after_the_closing_delimiter_as_php_reads_them(): void
    {
        $split = self::between(self::source(self::SCRIPT), 'function splitFlags(value) {', "\n  }\n");

        $this->assertStringContainsString("var FLAG_CLOSERS = { '(': ')', '[': ']', '{': '}', '<': '>' };", self::source(self::SCRIPT), 'Brackets close with their mirror.');
        $this->assertStringContainsString('FLAG_CLOSERS[open] || open', $split, 'Any other delimiter closes with itself.');
        $this->assertStringContainsString('lastIndexOf(close)', $split, 'The last closer is the one: the flags are letters, never a delimiter.');
        $this->assertStringContainsString('/[A-Za-z0-9\\\\]/.test(open)', $split, 'A letter, a digit or a backslash never opens a pattern.');

        // The reading the script encodes agrees with the engine on every row.
        foreach (self::delimited() as $pattern => $flags) {
            $this->assertNotFalse(@preg_match($pattern, ''), $pattern.' is a pattern PHP compiles.');
            $this->assertSame($flags, self::flagsOf($pattern), $pattern);
        }
    }

    #[Test]
    public function test_the_n_flag_waits_for_the_php_that_reads_it(): void
    {
        $script = self::source(self::SCRIPT);

        $this->assertStringContainsString("var FLAG_SINCE = { n: '8.2' };", $script);
        $sync = self::between($script, 'function syncFlags() {', "\n  }\n");
        $this->assertStringContainsString('VERSIONS.indexOf(chosen) < VERSIONS.indexOf(since)', $sync);
        $this->assertStringContainsString('!pressed', $sync, 'A flag already written can always be taken out.');
    }

    /**
     * IntelliJ's find bar speaks the same: Alt and the letter, from the
     * pattern field, for the row's five.
     */
    #[Test]
    public function test_alt_and_the_letter_toggle_the_row_flags_from_the_pattern_field(): void
    {
        $script = self::source(self::SCRIPT);

        $keys = self::between($script, "el.pattern.addEventListener('keydown', function (event) {", "\n  });\n");
        $this->assertStringContainsString('event.altKey', $keys);
        $this->assertStringContainsString('event.code', $keys, 'The key, not the character: Alt+I types a circumflex on a Mac.');
        $this->assertStringContainsString('toggleFlag(', $keys);
    }

    /**
     * @return array<string, string>
     */
    private static function delimited(): array
    {
        return [
            '/abc/' => '',
            '/abc/im' => 'im',
            '#a/b#x' => 'x',
            '~a~u' => 'u',
            '(a(b)c)i' => 'i',
            '{a{1,2}}s' => 's',
            '[a]m' => 'm',
            '<a>U' => 'U',
            '/a\/b/i' => 'i',
            "\x20/a/i" => 'i', // A leading space, before the delimiter.
            "/a/i\n" => 'i',
        ];
    }

    /**
     * The script's reading, said in PHP: the oracle row and the script's
     * rules are held to the same table.
     */
    private static function flagsOf(string $value): ?string
    {
        if (1 !== preg_match('/\S/', $value, $first, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $first[0][1];
        $open = $value[$start];
        if (1 === preg_match('/[A-Za-z0-9\\\\]/', $open)) {
            return null;
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($value, $close);
        if (false === $end || $end <= $start) {
            return null;
        }
        $flags = substr($value, $end + 1);

        return 1 === preg_match('/^[A-Za-z]*\s*$/', $flags) ? rtrim($flags) : null;
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
