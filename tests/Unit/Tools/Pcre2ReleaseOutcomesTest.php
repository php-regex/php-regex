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

namespace PHPRegex\Tests\Unit\Tools;

use PHPRegex\Tests\TestUtils\Pcre2ReleaseOutcomes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the release outcomes tool that need no pcre2test: the line
 * each release compiles, the cases it applies to, the runs the outcomes are
 * stored as, and the build options a binary must show.
 */
final class Pcre2ReleaseOutcomesTest extends TestCase
{
    #[Test]
    public function test_a_line_compiles_as_php_compiles_on_each_release(): void
    {
        $this->assertSame('/a\K/caseless,utf,ucp', Pcre2ReleaseOutcomes::line('a\K', '/', 'iu', '10.37', false));
        $this->assertSame('/a\K/caseless,utf,ucp,allow_lookaround_bsk', Pcre2ReleaseOutcomes::line('a\K', '/', 'iu', '10.38', false));
        $this->assertSame('!a!bad_escape_is_literal', Pcre2ReleaseOutcomes::line('a', '!', '', '10.32', true));
        $this->assertSame('/a/', Pcre2ReleaseOutcomes::line('a', '/', 'X', '10.33', true));
        $this->assertSame('/a/caseless_restrict,allow_lookaround_bsk', Pcre2ReleaseOutcomes::line('a', '/', 'r', '10.43', false));
    }

    #[Test]
    public function test_a_flag_with_no_pcre2test_modifier_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        Pcre2ReleaseOutcomes::line('a', '/', 'S', '10.49', false);
    }

    #[Test]
    public function test_a_case_applies_where_php_can_express_it(): void
    {
        $this->assertTrue(Pcre2ReleaseOutcomes::applies(['pattern' => 'a', 'flags' => 'i'], '10.35', true));
        $this->assertFalse(Pcre2ReleaseOutcomes::applies(['pattern' => 'a', 'flags' => 'i'], '10.36', true));
        $this->assertFalse(Pcre2ReleaseOutcomes::applies(['pattern' => "a\0b", 'flags' => ''], '10.32', true));
        $this->assertTrue(Pcre2ReleaseOutcomes::applies(['pattern' => "a\0b", 'flags' => ''], '10.32', false));
        $this->assertFalse(Pcre2ReleaseOutcomes::applies(['pattern' => 'a', 'flags' => 'n'], '10.33', true));
        $this->assertFalse(Pcre2ReleaseOutcomes::applies(['pattern' => 'a', 'flags' => 'r'], '10.42', false));
        $this->assertTrue(Pcre2ReleaseOutcomes::applies(['pattern' => 'a', 'flags' => 'r'], '10.43', false));
    }

    #[Test]
    public function test_outcomes_are_stored_as_runs_and_read_back(): void
    {
        $outcomes = ['10.32' => [5, 160], '10.33' => [5, 160], '10.34' => null, '10.35' => null, '10.36' => [2, 101]];
        $runs = Pcre2ReleaseOutcomes::runs($outcomes);

        $this->assertSame(['10.32' => [5, 160], '10.34' => null, '10.36' => [2, 101]], $runs);
        $this->assertSame($outcomes, Pcre2ReleaseOutcomes::expand($runs, ['10.32', '10.33', '10.34', '10.35', '10.36']));
        $this->assertSame(['10.43' => null, '10.44' => null], Pcre2ReleaseOutcomes::expand(['10.43' => null], ['10.42', '10.43', '10.44']));
    }

    #[Test]
    public function test_an_observation_is_an_outcome_or_no_verdict(): void
    {
        $this->assertNull(Pcre2ReleaseOutcomes::outcome(['verdict' => 'accept']));
        $this->assertSame([3, 114], Pcre2ReleaseOutcomes::outcome(['verdict' => 'reject', 'offset' => 3, 'code' => 114]));

        $this->expectException(\RuntimeException::class);
        Pcre2ReleaseOutcomes::outcome(['verdict' => 'diagnostic', 'message' => '** Unknown modifier']);
    }

    #[Test]
    public function test_the_build_options_are_read_in_the_layout_of_each_release(): void
    {
        $old = "Compiled with\n  8-bit support\n  UTF and UCP support (Unicode version 11.0.0)\n  Newline sequence is LF\n  \\R matches all Unicode newlines\n  \\C is supported\n  Internal link size = 2\n  Parentheses nest limit = 250\n";
        $new = "Compiled with\n  8-bit support\n  UTF and UCP support (Unicode version 17.0.0)\n  Default newline sequence is LF\n  \\R matches all Unicode newlines\n  \\C is supported\n  Internal link size\n    Requested = 2\n    Effective = 2\n  Parentheses nest limit = 250\n";

        $this->assertTrue(Pcre2ReleaseOutcomes::acceptsConfiguration($old));
        $this->assertTrue(Pcre2ReleaseOutcomes::acceptsConfiguration($new));
        $this->assertFalse(Pcre2ReleaseOutcomes::acceptsConfiguration(str_replace('is LF', 'is CRLF', $new)));
        $this->assertFalse(Pcre2ReleaseOutcomes::acceptsConfiguration(str_replace('Requested = 2', 'Requested = 4', $new)));
        $this->assertFalse(Pcre2ReleaseOutcomes::acceptsConfiguration(str_replace('\\C is supported', '\\C is not supported', $old)));
    }

    #[Test]
    public function test_the_fixture_is_written_one_case_per_line(): void
    {
        $fixture = ['meta' => ['releases' => ['10.48', '10.49']], 'cases' => [
            ['id' => '10.49:testinput2:1', 'pattern' => 'a', 'delimiter' => '/', 'flags' => '', 'php' => ['10.48' => [0, 101], '10.49' => null]],
            ['id' => '10.49:testinput2:2', 'pattern' => 'é', 'delimiter' => '/', 'flags' => 'u', 'php' => ['10.48' => null, '10.49' => [1, 101]], 'php7' => false],
        ]];

        $json = Pcre2ReleaseOutcomes::encode($fixture);

        $this->assertStringContainsString("\n        {\"id\":\"10.49:testinput2:2\",\"pattern\":\"é\",\"delimiter\":\"/\",\"flags\":\"u\",\"php\":{\"10.48\":null,\"10.49\":[1,101]},\"php7\":false}\n", $json);
        $this->assertSame($fixture, json_decode($json, true));
    }
}
