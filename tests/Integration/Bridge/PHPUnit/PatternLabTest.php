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

namespace PHPRegex\Tests\Integration\Bridge\PHPUnit;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\PHPUnit\PatternLab;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The machinery behind the assertions: one parser for the process, so the
 * trees and the verdicts it keeps serve every assertion that reads the same
 * pattern again.
 */
final class PatternLabTest extends TestCase
{
    #[Test]
    public function test_the_parser_is_created_once_and_held_for_the_process(): void
    {
        $this->assertSame(PatternLab::parser(), PatternLab::parser());
    }

    /**
     * The assertions judge for the PHP running them, as the engine they
     * run each attempt on does.
     */
    #[Test]
    public function test_the_parser_judges_for_the_running_php(): void
    {
        $this->assertEquals(PcreTarget::runtime(), PatternLab::parser()->target());
    }

    /**
     * A pattern that does not parse fails the attempt with the library's
     * message, then the line at fault with a caret under it.
     */
    #[Test]
    public function test_a_pattern_that_does_not_parse_fails_with_its_message_and_snippet(): void
    {
        $attempt = PatternLab::run('/a(/', 'a', []);

        $this->assertNull($attempt->matched);
        $this->assertSame("Expected ) at end of input (found eof).\nLine 1: a(\n".str_repeat(' ', 10).'^', $attempt->failure);
    }

    #[Test]
    public function test_export_preserves_ordinary_values(): void
    {
        $this->assertSame("[true, false, null, 42, 1.5, 'a\\\\b\\'c']", PatternLab::export([true, false, null, 42, 1.5, "a\\b'c"]));
        $this->assertSame("['name' => ['value', 2]]", PatternLab::export(['name' => ['value', 2]]));
        $this->assertSame('[…]', PatternLab::export(['deep'], 8));
    }

    #[Test]
    public function test_export_bounds_shared_children_and_resets_its_budget(): void
    {
        $value = ['leaf'];
        for ($depth = 0; $depth < 5; $depth++) {
            $value = array_fill(0, 10, $value);
        }

        $rendered = PatternLab::export($value);

        $this->assertLessThanOrEqual(8192, \strlen($rendered));
        $this->assertStringContainsString('…', $rendered);
        $this->assertLessThanOrEqual(256, substr_count($rendered, 'leaf'));
        $this->assertSame("['leaf']", PatternLab::export(['leaf']));
    }

    /**
     * The budget is 256 values, the array itself counted: 255 items fit,
     * the 256th is cut.
     */
    #[Test]
    public function test_export_spends_a_budget_of_256_values(): void
    {
        $this->assertSame('['.implode(', ', range(1, 255)).']', PatternLab::export(range(1, 255)));
        $this->assertStringEndsWith(', 255, …', PatternLab::export(range(1, 256)));
    }

    /**
     * Arrays nest to depth 8: the ninth is shown as "[…]".
     */
    #[Test]
    public function test_export_shows_the_ninth_nested_array_as_an_ellipsis(): void
    {
        $nested = [1];
        for ($level = 0; $level < 8; $level++) {
            $nested = [$nested];
        }

        $this->assertSame('[[[[[[[[[…]]]]]]]]]', PatternLab::export($nested));
    }

    /**
     * 8,189 bytes fit whole, three left for the ellipsis; one more is cut,
     * and a part that finds no room left is cut to nothing.
     */
    #[Test]
    public function test_export_fills_the_ceiling_exactly(): void
    {
        $this->assertSame("'".str_repeat('x', 8187)."'", PatternLab::export(str_repeat('x', 8187)));
        $this->assertSame("'".str_repeat('x', 8188).'…', PatternLab::export(str_repeat('x', 8188)));
        $this->assertSame("['".str_repeat('x', 8186)."'…", PatternLab::export([str_repeat('x', 8186)]));
    }

    /**
     * Once cut, nothing more is written: the "]" that would fit in the bytes
     * a split character leaves is not appended.
     */
    #[Test]
    public function test_export_writes_nothing_after_the_cut(): void
    {
        $export = PatternLab::export([str_repeat('€', 4000), 1]);

        $this->assertTrue(mb_check_encoding($export, 'UTF-8'));
        $this->assertStringEndsWith('€…', $export);
    }

    /**
     * The cut falls on a character boundary: "€" takes three bytes, and the
     * message keeps whole ones before the ellipsis.
     */
    #[Test]
    public function test_export_cuts_a_long_string_between_characters(): void
    {
        $export = PatternLab::export(str_repeat('€', 10000));

        $this->assertTrue(mb_check_encoding($export, 'UTF-8'));
        $this->assertStringStartsWith("'€€€", $export);
        $this->assertStringEndsWith('€…', $export);
        $this->assertLessThanOrEqual(8192, \strlen($export));
    }

    #[Test]
    public function test_export_bounds_large_strings_and_keys_before_escaping(): void
    {
        $value = str_repeat("'\\", 100_000);
        foreach ([$value, [$value => $value], array_fill(0, 1000, $value)] as $input) {
            $rendered = PatternLab::export($input);
            $this->assertLessThanOrEqual(8192, \strlen($rendered));
            $this->assertStringContainsString('…', $rendered);
        }
    }
}
