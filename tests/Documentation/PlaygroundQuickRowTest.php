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
 * The row under the pattern says what each of its doors holds before a
 * hand opens it. An example shows the construct it is about, in the
 * code's own face, so the row reads as a map of PCRE rather than a list
 * of names; the corpus door shows the projects it holds and how much the
 * linter found in them, from the build, before any byte is fetched.
 */
#[Group('documentation')]
final class PlaygroundQuickRowTest extends TestCase
{
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    #[Test]
    public function test_every_example_shows_the_construct_it_is_about(): void
    {
        $xpath = self::include();
        $examples = $xpath->query('//*'.self::has('pg-examples').'/li/button'.self::has('pg-example'));
        $this->assertNotFalse($examples);
        $this->assertGreaterThanOrEqual(10, $examples->length);

        $glyphs = [];
        foreach ($examples as $example) {
            $this->assertInstanceOf(\DOMElement::class, $example);
            $glyph = $xpath->query('./*'.self::has('pg-example-glyph').'[@aria-hidden="true"]', $example);
            $this->assertNotFalse($glyph);
            $this->assertSame(1, $glyph->length, 'One glyph, first in the chip, hidden from a voice that reads the name and the pattern.');
            $first = $glyph->item(0);
            $this->assertInstanceOf(\DOMElement::class, $first);
            $text = trim($first->textContent);
            $this->assertNotSame('', $text);
            $this->assertStringContainsString($text, (string) $example->getAttribute('data-regex'), 'The glyph is a piece of the pattern the chip loads: the construct, not a decoration.');
            $this->assertSame($glyph->item(0), $example->firstElementChild, 'The construct leads; the name follows.');
            $glyphs[] = $text;
        }
        $this->assertSame($glyphs, array_values(array_unique($glyphs)), 'No two examples show the same construct.');

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-example-glyph \{([^}]*)\}/m', $stylesheet, $rule));
        $this->assertStringContainsString('font-family: var(--font-mono);', $rule[1], 'A construct is code, and reads in the code\'s face.');
    }

    #[Test]
    public function test_the_example_that_warns_says_so_before_it_is_run(): void
    {
        $xpath = self::include();
        $warning = $xpath->query('//button'.self::has('pg-example').self::has('is-danger'));
        $this->assertNotFalse($warning);
        $this->assertSame(1, $warning->length, 'One example is the ReDoS one, and it says so.');
        $button = $warning->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button);
        // The engine itself gives up on it, as the page's engine does
        // (JIT off, the default backtrack limit): the warning is earned.
        $jit = ini_set('pcre.jit', '0');

        try {
            $this->assertFalse(@preg_match($button->getAttribute('data-regex'), $button->getAttribute('data-subject')));
            $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }

        $stylesheet = self::source(self::STYLESHEET);
        $this->assertSame(1, preg_match('/^\.pg-example\.is-danger \.pg-example-glyph \{([^}]*)\}/m', $stylesheet, $rule));
        $this->assertStringContainsString('var(--danger)', $rule[1]);
    }

    #[Test]
    public function test_the_example_in_the_fields_reads_as_pressed(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^\.pg-example\[aria-pressed="true"\] \{([^}]*)\}/m', $stylesheet, $pressed), 'The script presses the example the fields hold; the sheet must show it.');
        $this->assertStringContainsString('border-color: var(--amber);', $pressed[1], 'A border, so a forced palette still tells it.');
        $this->assertStringContainsString('background: var(--amber-tint);', $pressed[1]);
    }

    /**
     * "Projects" said nothing about what waits behind it. The door now
     * shows the faces of the projects, how many issues the linter found
     * and in how many projects — counted by the build from the findings
     * collection, so the sentence is whole on the first paint.
     */
    #[Test]
    public function test_the_corpus_door_says_what_it_holds_before_it_opens(): void
    {
        $include = self::source(self::INCLUDE);
        $xpath = self::include();
        $summary = '//details'.self::has('pg-findings-browse').'/summary';

        $this->assertSame(1, self::nodes($xpath, $summary.'/*'.self::has('pg-findings-faces').'[@aria-hidden="true"]'), 'The faces are for the eye: the words carry the meaning.');
        $this->assertStringContainsString('{%- assign findings_ranked = site.findings | sort: "findings_rank" -%}', $include, 'The faces are the projects the linter ranks first.');
        $this->assertStringContainsString('for finding in findings_ranked limit: 4', $include, 'Four faces: enough to recognise the corpus, few enough to stay a glance.');
        $this->assertStringContainsString('src="/assets/findings/avatars/{{ finding.findings_repo | split: \'/\' | first | escape }}.png" alt=""', $include);
        $this->assertSame(1, self::nodes($xpath, $summary.'/*'.self::has('pg-findings-total')), 'How much was found.');
        $this->assertSame(1, self::nodes($xpath, $summary.'/*'.self::has('pg-findings-what')), 'What was found.');
        $this->assertSame(1, self::nodes($xpath, $summary.'/*'.self::has('pg-findings-count')), 'Where it was found.');
        $this->assertStringContainsString('findings_total = findings_total | plus: finding.findings_count', $include, 'The build sums the issues from the collection: no fetch says the number.');
        $this->assertStringContainsString('regex issues in', $include);
        $this->assertSame(1, preg_match('/<summary[^>]* title="[^"]*replay[^"]*"/', $include), 'A pointer that hesitates learns what opening it does.');

        $script = self::source(self::SCRIPT);
        $this->assertStringContainsString("pageRepo ? pageRepo : index.repos.length + ' projects'", $script, 'Loaded, the corpus keeps the sentence whole: a number of projects, or the page\'s own project.');
    }

    #[Test]
    public function test_the_corpus_panel_says_what_the_list_is_before_the_list(): void
    {
        $xpath = self::include();

        $this->assertSame(1, self::nodes($xpath, '//*'.self::has('pg-findings-body').'/*[1]'.self::has('pg-findings-intro')), 'The panel opens on one sentence that says what the tree is.');
        $intro = $xpath->evaluate('string(//*'.self::has('pg-findings-intro').')');
        $this->assertIsString($intro);
        $this->assertStringContainsString('open-source PHP', (string) $intro);
        $this->assertStringContainsString('own PHP version', (string) $intro, 'Each project is judged for the PHP it declares, and the sentence says so.');
    }

    #[Test]
    public function test_the_door_stands_apart_from_the_examples_and_keeps_its_place_on_a_phone(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        $this->assertSame(1, preg_match('/^\.pg-findings-faces img \{([^}]*)\}/m', $stylesheet, $face));
        $this->assertStringContainsString('border-radius: 50%;', $face[1]);
        $this->assertSame(1, preg_match('/^\.pg-findings-faces img \+ img \{([^}]*)\}/m', $stylesheet, $overlap), 'The faces overlap, a stack, not a row.');
        $this->assertStringContainsString('margin-left: -', $overlap[1]);
        $phone = '/@media \(max-width: 639px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*';
        $this->assertSame(1, preg_match($phone.'\.pg-findings-total,\s*\.pg-findings-what \{([^}]*)\}/', $stylesheet, $short), 'A phone keeps the faces and the projects, not the sentence.');
        $this->assertStringContainsString('display: none;', $short[1]);
    }

    /**
     * A name the build writes into an attribute is escaped there: a quote
     * or an angle bracket in a repository's name stays text and never ends
     * the attribute.
     */
    #[Test]
    public function test_every_value_the_build_writes_into_an_attribute_is_escaped(): void
    {
        $this->assertGreaterThan(0, preg_match_all('/[a-z-]+="[^"]*"/', self::source(self::INCLUDE), $attributes));
        $outputs = 0;
        foreach ($attributes[0] as $attribute) {
            $this->assertSame(1, preg_match('/^([a-z-]+)=/', $attribute, $name));
            $this->assertNotFalse(preg_match_all('/\{\{(.*?)\}\}/', $attribute, $values));
            foreach ($values[1] as $value) {
                $this->assertMatchesRegularExpression('/\|\s*escape\s*$/', $value, $name[1].' writes {{'.$value.'}} unescaped.');
                $outputs++;
            }
        }
        $this->assertGreaterThanOrEqual(4, $outputs, 'The door\'s tooltip and faces, the bundle\'s version and the page\'s project.');
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

    private static function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
