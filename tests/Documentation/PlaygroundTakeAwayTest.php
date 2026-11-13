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
 * What a visitor takes away from the sandbox: its address, and the code
 * that runs what is on screen in their own PHP. The code is a script, not
 * a sketch: a literal PHP reads back into the very bytes of the fields,
 * and a failed match says why instead of returning false into the void.
 */
#[Group('documentation')]
final class PlaygroundTakeAwayTest extends TestCase
{
    private const INCLUDE = PlaygroundDriver::ROOT.'/docs/_includes/playground.html';
    private const SCRIPT = PlaygroundDriver::ROOT.'/docs/assets/js/playground.js';
    private const STYLESHEET = PlaygroundDriver::ROOT.'/docs/assets/css/main.scss';

    #[Test]
    public function test_the_sandbox_offers_its_link_and_its_php_side_by_side(): void
    {
        $xpath = new \DOMXPath(self::document());
        $cluster = '//*[contains(concat(" ", normalize-space(@class), " "), " pg-share-actions ")]';

        $this->assertSame(1, self::matching($xpath, $cluster.'/button[contains(@class, "pg-link-copy")]'));
        $this->assertSame(1, self::matching($xpath, $cluster.'/button[contains(@class, "pg-php-copy")][normalize-space(.)="Copy as PHP"]'));
        $this->assertSame(1, self::matching($xpath, $cluster.'/button[contains(@class, "pg-php-copy")]/svg[@aria-hidden="true"]'), 'The chip carries its icon, as its sibling does.');
    }

    #[Test]
    public function test_the_php_runs_what_is_on_screen_and_says_why_it_failed(): void
    {
        $script = self::source(self::SCRIPT);
        $snippet = self::between($script, 'function phpSnippet() {', "\n  }\n");

        $this->assertStringContainsString('phpString(el.subject.value)', $snippet, 'The text is the one in the field.');
        $this->assertStringContainsString('phpString(el.pattern.value)', $snippet, 'The pattern is the one in the field.');
        $this->assertStringContainsString('preg_match_all(', $snippet, 'Every match, as the field underlines them.');
        $this->assertStringContainsString('preg_last_error_msg()', $snippet, 'A failed match says why.');
        $this->assertStringContainsString("copyText(phpSnippet(), 'the PHP code', el.textField);", $script);
    }

    /**
     * The literal's rules, held to PHP itself: a single-quoted string
     * reads `\'` as a quote and `\\` as one backslash, and every other
     * backslash as itself. Doubling only the backslashes those two rules
     * would eat keeps the pattern as its author wrote it.
     */
    #[Test]
    #[DataProvider('provideLiterals')]
    public function test_the_literal_reads_back_into_the_same_bytes(string $value): void
    {
        $this->assertStringContainsString("if (character === \"'\") {", self::between(self::source(self::SCRIPT), 'function phpString(value) {', "\n  }\n"));

        $literal = self::literal($value);
        $this->assertSame($value, eval('return '.$literal.';'), $literal);
    }

    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideLiterals(): iterable
    {
        yield 'plain' => ['value' => '/^(?<date>\d{4})-(\d{2})$/m'];
        yield 'a quote' => ['value' => "/it's/"];
        yield 'an escaped quote' => ['value' => "/\\'/"];
        yield 'an escaped backslash' => ['value' => '/a\\\\d/'];
        yield 'three backslashes' => ['value' => '/\\\\\\d/'];
        yield 'a trailing backslash' => ['value' => 'text\\'];
        yield 'a backslash before the closer' => ['value' => '/a\\\\/'];
        yield 'a newline' => ['value' => "line one\nline two"];
        yield 'unicode' => ['value' => '/\p{Lu}\p{Ll}+/u Ünïcode'];
        yield 'empty' => ['value' => ''];
    }

    #[Test]
    public function test_the_text_editor_fill_outranks_the_narrow_cap(): void
    {
        $stylesheet = self::source(self::STYLESHEET);

        // The narrow layouts cap the input at 30rem with a rule written
        // after the wide one: at equal weight the later rule won, and the
        // mirror, which has no cap, painted a slab under the field.
        $this->assertSame(1, preg_match('/@media \(min-width: 1024px\) \{(?:[^{}]*\{[^{}]*\})*?[^{}]*\.pg-field-text \.pg-editor-subject \.pg-input \{([^}]*)\}/', $stylesheet, $fill));
        $this->assertStringContainsString('max-height: none;', $fill[1]);
    }

    /**
     * The script's rules, said in PHP, so the table above holds both.
     */
    private static function literal(string $value): string
    {
        $out = '';
        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];
            if ("'" === $character) {
                $out .= "\\'";
            } elseif ('\\' === $character) {
                $next = $value[$i + 1] ?? '';
                $out .= ('' === $next || '\\' === $next || "'" === $next) ? '\\\\' : '\\';
            } else {
                $out .= $character;
            }
        }

        return "'".$out."'";
    }

    private static function document(): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><body>'.self::source(self::INCLUDE).'</body>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private static function matching(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes, $query);

        return $nodes->length;
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
