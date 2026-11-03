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
 * Before a visitor asks for a run, the home shows a result rendered ahead
 * of time: the bundled driver's answer for the default pattern and subject,
 * judged for the default browser engine (PHP 8.4 / PCRE2 10.44), stored as
 * data and rendered by the same script as a live result. The data is
 * generated, so what the tests hold is that it is the driver's current
 * answer, that it is safe inside a script element, and that the home reads
 * it.
 */
#[Group('documentation')]
final class PlaygroundDefaultTest extends TestCase
{
    private const HOME = PlaygroundDriver::ROOT.'/docs/index.md';
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPTS = PlaygroundDriver::ROOT.'/docs/_includes/scripts.html';

    #[Test]
    public function test_the_default_result_is_fresh(): void
    {
        $data = self::data();
        $bundle = PlaygroundDriver::extractBundle();
        $target = ['php' => '8.4.1', 'pcre' => '10.44'];

        $live = PlaygroundDriver::analyze($bundle['driver'], $bundle['root'], $data['default']['regex'], $data['default']['subject'], $target);
        $stored = $live;
        unset($stored['engine'], $stored['timing']);
        $this->assertSame($stored, $data['analyze'], 'The stored result is not what the bundled driver answers: regenerate the bundle.');

        $lint = PlaygroundDriver::lint($bundle['driver'], $bundle['root'], $data['default']['regex'], $target);
        $storedLint = $lint;
        unset($storedLint['timing']);
        $this->assertSame($storedLint, $data['lint']);

        $this->assertSame([], $live['errors'], 'The sample is a pattern that parses.');
        $this->assertNotNull($live['matches']);
        $this->assertGreaterThan(0, $live['matches']['count'], 'The sample matches something.');
        $this->assertNotSame([], $live['matches']['names'], 'The sample shows a named group.');
    }

    #[Test]
    public function test_the_default_is_safe_inside_a_script_tag(): void
    {
        $raw = (string) file_get_contents(PlaygroundDriver::DEFAULT_DATA);

        $this->assertStringNotContainsString('<', $raw, 'A "<" could close the script element that embeds the data.');
        $this->assertStringContainsString(
            "{{ site.data.playground | jsonify | replace: '<', '\\u003C' }}",
            (string) file_get_contents(self::INCLUDE),
            'Liquid re-encodes the data and writes "<" again: the include escapes it back.',
        );
    }

    #[Test]
    public function test_the_home_embeds_the_default_and_the_playground(): void
    {
        $home = (string) file_get_contents(self::HOME);
        $include = (string) file_get_contents(self::INCLUDE);
        $scripts = (string) file_get_contents(self::SCRIPTS);

        $this->assertStringContainsString('{% include playground.html %}', $home);
        $this->assertStringNotContainsString('hero_ast', $home);
        $this->assertFileDoesNotExist(PlaygroundDriver::ROOT.'/docs/_data/hero_ast.yml');

        $this->assertStringContainsString('site.data.playground | jsonify', $include);
        $this->assertStringContainsString('type="application/json"', $include);
        $this->assertStringNotContainsString('<section', $include, 'The home counts its sections; the playground is not one.');
        $this->assertStringNotContainsString('<article', $include, 'The home counts its cards; the playground is not one.');
        $this->assertStringNotContainsString('innerHTML', (string) file_get_contents(PlaygroundDriver::ROOT.'/docs/assets/js/playground.js'), 'Every user-derived string goes through textContent.');

        $this->assertSame(1, preg_match('/\{% if page\.layout == \'landing\' %\}\s*<script defer src="\/assets\/js\/playground\.js\?v=/', $scripts), 'playground.js loads on the landing layout only.');
    }

    #[Test]
    public function test_the_first_paint_gestures_are_wired_and_bow_to_reduced_motion(): void
    {
        $include = (string) file_get_contents(self::INCLUDE);
        $script = (string) file_get_contents(PlaygroundDriver::ROOT.'/docs/assets/js/playground.js');
        $stylesheet = (string) file_get_contents(PlaygroundDriver::ROOT.'/docs/assets/css/main.scss');

        // The engine's loader: in the window, hidden until a boot, out of
        // the live regions the status line and the state word already are.
        $this->assertSame(1, preg_match('/<div class="pg-loader" hidden aria-hidden="true">/', $include));
        $this->assertStringContainsString('pg-loader-stage', $include);
        $this->assertStringContainsString('el.loader.hidden = false', $script);

        // The caret that says the pattern is editable: shown while the
        // field is untouched, gone at the first focus or keystroke.
        $this->assertStringContainsString('pg-editor pg-editor-pattern is-untouched', $include);
        $this->assertStringContainsString('.pg-editor-pattern.is-untouched .pg-caret', $stylesheet);
        $this->assertStringContainsString("classList.remove('is-untouched')", $script);

        // The walk through the tree: a JavaScript motion the stylesheet's
        // reduced-motion rule cannot stop, so the script asks itself.
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $script);
        $this->assertStringContainsString('is-visiting', $stylesheet);
    }

    /**
     * @return array{default: array{regex: string, subject: string}, analyze: array<string, mixed>, lint: array<string, mixed>}
     */
    private static function data(): array
    {
        /** @var array{default: array{regex: string, subject: string}, analyze: array<string, mixed>, lint: array<string, mixed>} $data */
        $data = json_decode((string) file_get_contents(PlaygroundDriver::DEFAULT_DATA), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
