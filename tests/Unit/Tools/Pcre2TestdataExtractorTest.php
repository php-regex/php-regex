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

use PHPRegex\Tests\TestUtils\Pcre2CaseRunner;
use PHPRegex\Tests\TestUtils\Pcre2TestdataExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pcre2test input/output extractor.
 *
 * Every sample below is inline data shaped like the real pcre2test formats
 * (mirroring lines of the vendored tests/Fixtures/Pcre2/testdata files), so a
 * format regression is caught here without touching the big files. The
 * vendored files themselves are re-extracted and compared with the committed
 * fixture in tests/Integration/Pcre2ExtractorFidelityTest.php.
 *
 * @phpstan-type Pcre2Case = array{id: string, pattern: string, delimiter: string, flags: string, verdict: string|null, offset: int|null, error: string|null, pcre2Code: int|null, phpOverride: array<string, mixed>|null, floor: array{verdict: string, offset: int|null, pcre2Code: int|null}|null, skipCategory: string|null, skipReason: string|null}
 * @phpstan-type FloorObservation = array{verdict: string, offset: int|null, pcre2Code: int|null}
 */
final class Pcre2TestdataExtractorTest extends TestCase
{
    #[Test]
    public function test_extractor_parses_pattern_line_with_delimiter_and_modifiers(): void
    {
        $input = <<<'EOT'
            /abc/i,utf
                abc
            EOT;
        $output = <<<'EOT'
            /abc/i,utf
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertCount(1, $cases);
        $this->assertSame([
            'id' => 'sample:1',
            'pattern' => 'abc',
            'delimiter' => '/',
            'flags' => 'iu',
            'verdict' => 'accept',
            'offset' => null,
            'error' => null,
            'pcre2Code' => null,
            'phpOverride' => null,
            'floor' => null,
            'skipCategory' => null,
            'skipReason' => null,
        ], $cases[0]);
    }

    /**
     * The committed mapping table, one row per pcre2test modifier that has a
     * PHP pattern-modifier equivalent.
     *
     * @return iterable<string, array{modifier: string, flag: string}>
     */
    public static function provideModifierRows(): iterable
    {
        yield 'caseless maps to i' => ['modifier' => 'caseless', 'flag' => 'i'];
        yield 'multiline maps to m' => ['modifier' => 'multiline', 'flag' => 'm'];
        yield 'dotall maps to s' => ['modifier' => 'dotall', 'flag' => 's'];
        yield 'extended maps to x' => ['modifier' => 'extended', 'flag' => 'x'];
        yield 'anchored maps to A' => ['modifier' => 'anchored', 'flag' => 'A'];
        yield 'dollar_endonly maps to D' => ['modifier' => 'dollar_endonly', 'flag' => 'D'];
        yield 'dupnames maps to J' => ['modifier' => 'dupnames', 'flag' => 'J'];
        yield 'ungreedy maps to U' => ['modifier' => 'ungreedy', 'flag' => 'U'];
        yield 'utf maps to u' => ['modifier' => 'utf', 'flag' => 'u'];
        yield 'extra maps to X' => ['modifier' => 'extra', 'flag' => 'X'];
        yield 'no_auto_capture maps to n' => ['modifier' => 'no_auto_capture', 'flag' => 'n'];
    }

    #[Test]
    #[DataProvider('provideModifierRows')]
    public function test_extractor_maps_pcre2test_modifiers_to_php_flags(string $modifier, string $flag): void
    {
        $this->assertSame($flag, Pcre2TestdataExtractor::modifierMap()[$modifier]);
    }

    #[Test]
    public function test_extractor_applies_mapped_flag_to_case(): void
    {
        $input = <<<'EOT'
            /abc/caseless
                ABC
            EOT;
        $output = <<<'EOT'
            /abc/caseless
                ABC
             0: ABC
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertSame('i', $cases[0]['flags']);
        $this->assertNull($cases[0]['skipCategory']);
    }

    #[Test]
    public function test_extractor_drops_match_only_modifiers(): void
    {
        $input = <<<'EOT'
            /abc/no_jit,study,I
                abc
            EOT;
        $output = <<<'EOT'
            /abc/no_jit,study,I
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        // no_jit and study have no compile-visible effect; I only asks
        // pcre2test to print information about the compiled pattern.
        $this->assertSame('', $cases[0]['flags']);
        $this->assertNull($cases[0]['skipCategory']);
    }

    /**
     * @return iterable<string, array{modifier: string}>
     */
    public static function provideInexpressibleModifiers(): iterable
    {
        yield 'auto_callout' => ['modifier' => 'auto_callout'];
        yield 'alt_bsux' => ['modifier' => 'alt_bsux'];
        yield 'allow_empty_class' => ['modifier' => 'allow_empty_class'];
    }

    #[Test]
    #[DataProvider('provideInexpressibleModifiers')]
    public function test_extractor_skips_php_inexpressible_compile_options(string $modifier): void
    {
        $input = \sprintf("/abc/%s\n    abc\n", $modifier);
        $output = \sprintf("/abc/%s\n    abc\n 0: abc\n", $modifier);

        $cases = self::extract($input, $output);

        $this->assertSame('modifier-inexpressible', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
        $this->assertStringContainsString($modifier, (string) ($cases[0]['skipReason'] ?? ''));
    }

    /**
     * pcre2test API-level operators on the pattern line: they exercise
     * machinery PHP patterns cannot express, so the case is skipped rather
     * than asserted. Display-only pattern modifiers and every subject-line
     * modifier are covered by the drop tests further down instead.
     *
     * @return iterable<string, array{input: string, output: string}>
     */
    public static function provideApiOperatorLines(): iterable
    {
        yield 'pattern modifier B with hex' => [
            'input' => "/61 62/B,hex\n    ab\n",
            'output' => "/61 62/B,hex\n    ab\n 0: ab\n",
        ];

        yield 'pattern modifier push' => [
            'input' => "/(a)b/push\n",
            'output' => "/(a)b/push\n",
        ];

        yield 'pattern modifier pushcopy' => [
            'input' => "/(a)b/pushcopy\n    ab\n",
            'output' => "/(a)b/pushcopy\n    ab\n 0: ab\n 1: a\n",
        ];

        yield 'pattern modifier find_limits' => [
            'input' => "/a+b/find_limits\n    aaaaaab\n",
            'output' => "/a+b/find_limits\n    aaaaaab\nMinimum heap limit = 0\nMinimum match limit = 2\nMinimum depth limit = 2\n 0: aaaaaab\n",
        ];
    }

    #[Test]
    #[DataProvider('provideApiOperatorLines')]
    public function test_extractor_skips_api_level_operators(string $input, string $output): void
    {
        $cases = self::extract($input, $output);

        $this->assertSame('pcre2test-api', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_honors_command_context(): void
    {
        $input = <<<'EOT'
            /fox/
            \= Expect no match
                FOX

            /dog/
                dog
            EOT;
        $output = <<<'EOT'
            /fox/
            \= Expect no match
                FOX
            No match

            /dog/
                dog
             0: dog
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        // The "\= Expect no match" line belongs to the first case's subject
        // context: it must not be mistaken for a pattern line, must not start
        // a case of its own, and must not break input/output pairing.
        $this->assertCount(2, $cases);
        $this->assertSame('sample:1', $cases[0]['id']);
        $this->assertSame('fox', $cases[0]['pattern']);
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame('sample:5', $cases[1]['id']);
        $this->assertSame('dog', $cases[1]['pattern']);
        $this->assertSame('accept', $cases[1]['verdict']);
    }

    #[Test]
    public function test_extractor_marks_newline_command_context_as_skip(): void
    {
        // An explicit per-pattern newline setting changes the compile in a way
        // a PHP pattern string cannot express.
        $input = <<<'EOT'
            /^abc$/newline=cr
                abc
            EOT;
        $output = <<<'EOT'
            /^abc$/newline=cr
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertSame('newline-command', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_marks_newline_command_lines_as_skip(): void
    {
        $input = <<<'EOT'
            #newline cr
            /^abc$/
                abc
            EOT;
        $output = <<<'EOT'
            #newline cr
            /^abc$/
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertSame('newline-command', $cases[0]['skipCategory']);
        $this->assertSame('sample:2', $cases[0]['id']);
    }

    #[Test]
    public function test_extractor_does_not_skip_on_newline_default_command(): void
    {
        // #newline_default only selects defaults for the library builds; the
        // header of the real testinput1 starts with it, and skipping on it
        // would drop the whole main corpus.
        $input = <<<'EOT'
            #newline_default lf any anycrlf

            /abc/
                abc
            EOT;
        $output = <<<'EOT'
            #newline_default lf any anycrlf

            /abc/
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_marks_testoutput_skipped_cases(): void
    {
        $input = "/abc/\n    abc\n";
        $output = "/abc/\n  ** Test skipped: this build has no Unicode property support\n";

        $cases = self::extract($input, $output);

        $this->assertSame('engine-skipped', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_pairs_rejection_with_offset(): void
    {
        // Real shape from testinput2:122 / testoutput2:122-124.
        $input = <<<'EOT'
            /ab\idef/
            EOT;
        $output = <<<'EOT'
            /ab\idef/
            Failed: error 103 at offset 4: unrecognized character follows \
                    here: ab\i |<--| def
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertSame('reject', $cases[0]['verdict']);
        $this->assertSame(4, $cases[0]['offset']);
        $this->assertSame('unrecognized character follows \\', $cases[0]['error']);
        $this->assertNull($cases[0]['skipCategory']);
    }

    #[Test]
    public function test_extractor_pairs_second_rejection_with_offset(): void
    {
        // Real shape from testinput2:399 / testoutput2:980.
        $input = <<<'EOT'
            /a{37,17}/
            EOT;
        $output = <<<'EOT'
            /a{37,17}/
            Failed: error 104 at offset 7: numbers out of order in {} quantifier
                    here: a{37,17 |<--| }
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertSame('reject', $cases[0]['verdict']);
        $this->assertSame(7, $cases[0]['offset']);
        $this->assertSame('numbers out of order in {} quantifier', $cases[0]['error']);
    }

    /**
     * @return iterable<string, array{input: string, output: string, expectedOffset: int|null, expectedSkip: string|null}>
     */
    public static function provideFailedOutputVariants(): iterable
    {
        yield 'message with parenthesized detail is parsed' => [
            'input' => "/[abc/\n",
            'output' => "/[abc/\nFailed: error 106 at offset 5: missing terminating ] for character class (detail)\n",
            'expectedOffset' => 5,
            'expectedSkip' => null,
        ];

        yield 'error line without an offset is ambiguous' => [
            'input' => "/abc/\n    abc\n",
            'output' => "/abc/\nError -80: PCRE2_ERROR_BADDATA (unknown error number)\n",
            'expectedOffset' => null,
            'expectedSkip' => 'ambiguous',
        ];
    }

    #[Test]
    #[DataProvider('provideFailedOutputVariants')]
    public function test_extractor_handles_failed_output_variants(string $input, string $output, ?int $expectedOffset, ?string $expectedSkip): void
    {
        $cases = self::extract($input, $output);

        $this->assertSame($expectedSkip, $cases[0]['skipCategory']);
        $this->assertSame($expectedOffset, $cases[0]['offset']);
    }

    #[Test]
    public function test_extractor_flags_overlength_patterns(): void
    {
        // Regex::DEFAULT_MAX_PATTERN_LENGTH (100 000) is enforced on the full
        // pattern string, so the boundary is the reconstructed
        // "/" . body . "/": 100 000 exactly passes, one more char is over.
        $atLimit = \str_repeat('a', 99_998);
        $overLimit = \str_repeat('a', 99_999);

        $atCases = self::extract(
            \sprintf("/%s/\n    a\n", $atLimit),
            \sprintf("/%s/\n    a\n 0: a\n", $atLimit),
        );
        $this->assertNull($atCases[0]['skipCategory'], 'a 100 000-char reconstruction is at the limit, not over it');
        $this->assertSame('accept', $atCases[0]['verdict']);

        $overCases = self::extract(
            \sprintf("/%s/\n    a\n", $overLimit),
            \sprintf("/%s/\n    a\n 0: a\n", $overLimit),
        );
        $this->assertSame('length-limit', $overCases[0]['skipCategory']);
        $this->assertNull($overCases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_case_ids_stable(): void
    {
        $input = <<<'EOT'
            /abc/
                abc

            /a{37,17}/
            EOT;
        $output = <<<'EOT'
            /abc/
                abc
             0: abc

            /a{37,17}/
            Failed: error 104 at offset 7: numbers out of order in {} quantifier
                    here: a{37,17 |<--| }
            EOT;

        $first = self::extract($input."\n", $output."\n");
        $second = self::extract($input."\n", $output."\n");

        $this->assertSame(['sample:1', 'sample:4'], array_column($first, 'id'));
        $this->assertSame(
            array_column($first, 'id'),
            array_column($second, 'id'),
            're-extracting the same pin must not move case ids',
        );
        $this->assertSame(json_encode($first), json_encode($second));
    }

    #[Test]
    public function test_extractor_marks_newer_than_floor(): void
    {
        $this->assertSame('10.40', Pcre2TestdataExtractor::PCRE2_FLOOR);

        $newer = Pcre2TestdataExtractor::newerThanFloorModifiers();
        $this->assertNotSame([], $newer, 'the newer-than-floor list must name the constructs added after the floor');

        $known = array_keys(Pcre2TestdataExtractor::modifierMap());
        $this->assertSame([], array_intersect($newer, $known), 'a modifier cannot be mapped and newer-than-floor at once');
        $this->assertSame([], array_intersect($newer, Pcre2TestdataExtractor::droppedModifiers()));
        $this->assertSame([], array_intersect($newer, Pcre2TestdataExtractor::inexpressibleModifiers()));
    }

    /**
     * pcre2test modifiers added after the floor. "r" and "caseless_restrict"
     * have a PHP spelling, but only from PHP 8.4 on (preg_match('/k/r', '')
     * compiles on 8.4; PHP 8.3 answers "Unknown modifier 'r'"), so they
     * date the case. "alt_extended_class" has no PHP flag at all.
     *
     * @return iterable<string, array{modifier: string, category: string}>
     */
    public static function provideModifiersNewerThanTheFloor(): iterable
    {
        yield 'r — PHP 8.4 flag' => ['modifier' => 'r', 'category' => 'newer-than-floor'];
        yield 'caseless_restrict — PHP 8.4 flag' => ['modifier' => 'caseless_restrict', 'category' => 'newer-than-floor'];
        yield 'alt_extended_class — no PHP flag' => ['modifier' => 'alt_extended_class', 'category' => 'modifier-inexpressible'];
    }

    #[Test]
    #[DataProvider('provideModifiersNewerThanTheFloor')]
    public function test_extractor_classifies_modifiers_newer_than_the_floor(string $modifier, string $category): void
    {
        $input = \sprintf("/abc/%s\n    abc\n", $modifier);
        $output = \sprintf("/abc/%s\n    abc\n 0: abc\n", $modifier);

        $cases = self::extract($input, $output);

        $this->assertSame($category, $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
        $this->assertStringContainsString($modifier, (string) $cases[0]['skipReason']);
        $this->assertSame(
            'newer-than-floor' === $category,
            \in_array($modifier, Pcre2TestdataExtractor::newerThanFloorModifiers(), true),
            'the modifier tables and the classification must agree',
        );
    }

    #[Test]
    public function test_extractor_classifies_turkish_casing_as_inexpressible(): void
    {
        // The pinned suite proves the modifier is compile-visible:
        // testinput5:2549 ("/i/i,turkish_casing") is a recorded rejection in
        // testoutput5:5740 — "Failed: error 204 at offset 0:
        // PCRE2_EXTRA_TURKISH_CASING require Unicode (UTF or UCP) mode".
        // A modifier that changes whether PCRE2 accepts the pattern belongs
        // with the PHP-inexpressible compile options, not with the
        // match-only drops.
        $this->assertContains('turkish_casing', Pcre2TestdataExtractor::inexpressibleModifiers());
        $this->assertNotContains('turkish_casing', Pcre2TestdataExtractor::droppedModifiers());

        $input = "/i/i,turkish_casing\n";
        $output = "/i/i,turkish_casing\nFailed: error 204 at offset 0: PCRE2_EXTRA_TURKISH_CASING require Unicode (UTF or UCP) mode\n";

        $cases = self::extract($input, $output);

        $this->assertSame('modifier-inexpressible', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_joins_multiline_pattern_lines(): void
    {
        // Real shape from testinput2:431-433: the closing delimiter is on a
        // later line, and the newline stays part of the pattern (PCRE2
        // reports "Contains explicit CR or LF match" for these).
        $input = "/word ((?:[a-z]+ )(\n(?:[a-z]+ )?)?)?other/I\n    word abc other\n";
        $output = "/word ((?:[a-z]+ )(\n(?:[a-z]+ )?)?)?other/I\n    word abc other\n 0: word abc other\n";

        $cases = self::extract($input, $output);

        $this->assertCount(1, $cases);
        $this->assertSame('sample:1', $cases[0]['id']);
        $this->assertSame("word ((?:[a-z]+ )(\n(?:[a-z]+ )?)?)?other", $cases[0]['pattern']);
        // I is a pcre2test display modifier: dropped, not mapped.
        $this->assertSame('', $cases[0]['flags']);
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_preserves_control_bytes_in_pattern_bodies(): void
    {
        $body = "a\x01b";
        $input = \sprintf("/%s/\n    subject\n", $body);
        $output = \sprintf("/%s/\n    subject\n 0: subject\n", $body);

        $cases = self::extract($input, $output);

        $this->assertSame($body, $cases[0]['pattern'], 'pattern bodies are byte strings, preserved verbatim');
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_supports_bracket_delimiters(): void
    {
        $input = <<<'EOT'
            (abc)i
                abc

            "abc"
                abc
            EOT;
        $output = <<<'EOT'
            (abc)i
                abc
             0: abc

            "abc"
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertCount(2, $cases);
        $this->assertSame('(', $cases[0]['delimiter']);
        $this->assertSame('abc', $cases[0]['pattern']);
        $this->assertSame('i', $cases[0]['flags']);
        $this->assertSame('"', $cases[1]['delimiter']);
        $this->assertSame('abc', $cases[1]['pattern']);
        $this->assertSame('', $cases[1]['flags']);
    }

    #[Test]
    public function test_extractor_assigns_distinct_ids_to_duplicate_pattern_lines(): void
    {
        $input = <<<'EOT'
            /abc/
                x

            /abc/
                y
            EOT;
        $output = <<<'EOT'
            /abc/
                x
             0: x

            /abc/
                y
             0: y
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertCount(2, $cases);
        $this->assertSame('sample:1', $cases[0]['id']);
        $this->assertSame('sample:4', $cases[1]['id']);
        $this->assertNotSame($cases[0]['id'], $cases[1]['id']);
    }

    #[Test]
    public function test_extractor_handles_modifier_combos(): void
    {
        $input = <<<'EOT'
            /abc/i,dupnames,ungreedy,utf,no_jit
                abc
            EOT;
        $output = <<<'EOT'
            /abc/i,dupnames,ungreedy,utf,no_jit
                abc
             0: abc
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        // Mapped letters, canonically sorted; the match-only one dropped.
        $this->assertSame('JUiu', $cases[0]['flags']);
        $this->assertNull($cases[0]['skipCategory']);
    }

    #[Test]
    public function test_extractor_preserves_utf8_pattern_bodies(): void
    {
        $body = 'àâçéèê+№';
        $input = \sprintf("/%s/utf\n    à\n", $body);
        $output = \sprintf("/%s/utf\n    à\n 0: à\n", $body);

        $cases = self::extract($input, $output);

        $this->assertSame($body, $cases[0]['pattern']);
        $this->assertSame('u', $cases[0]['flags']);
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    /**
     * Pattern modifiers that only change what pcre2test prints or what it
     * does after a successful compile: the compiled pattern is the same, so
     * the case keeps its compilation verdict.
     *
     * @return iterable<string, array{modifiers: string, flags: string}>
     */
    public static function provideDisplayOnlyModifiers(): iterable
    {
        yield 'B (bytecode dump)' => ['modifiers' => 'B', 'flags' => ''];
        yield 'bincode' => ['modifiers' => 'bincode', 'flags' => ''];
        yield 'fullbincode' => ['modifiers' => 'fullbincode', 'flags' => ''];
        yield 'debug' => ['modifiers' => 'debug', 'flags' => ''];
        yield 'replace with a value' => ['modifiers' => 'replace=xyz', 'flags' => ''];
        yield 'substitute_extended' => ['modifiers' => 'replace=x,substitute_extended', 'flags' => ''];
        yield 'substitute_literal' => ['modifiers' => 'replace=x,substitute_literal', 'flags' => ''];
        yield 'substitute_matched' => ['modifiers' => 'replace=x,substitute_matched', 'flags' => ''];
        yield 'substitute_overflow_length' => ['modifiers' => 'replace=x,substitute_overflow_length', 'flags' => ''];
        yield 'substitute_replacement_only' => ['modifiers' => 'replace=x,substitute_replacement_only', 'flags' => ''];
        yield 'substitute_unset_empty' => ['modifiers' => 'replace=x,substitute_unset_empty', 'flags' => ''];
        yield 'B inside an abbreviation run keeps the mapped letter' => ['modifiers' => 'iB', 'flags' => 'i'];
        yield 'B after a mapped long name' => ['modifiers' => 'caseless,B', 'flags' => 'i'];
    }

    #[Test]
    #[DataProvider('provideDisplayOnlyModifiers')]
    public function test_extractor_drops_display_only_modifiers(string $modifiers, string $flags): void
    {
        $input = \sprintf("/abc/%s\n    abc\n", $modifiers);
        $output = \sprintf("/abc/%s\n    abc\n 0: abc\n", $modifiers);

        $cases = self::extract($input, $output);

        $this->assertCount(1, $cases);
        $this->assertNull($cases[0]['skipCategory'], \sprintf('"%s" cannot change the compilation', $modifiers));
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame($flags, $cases[0]['flags']);
    }

    #[Test]
    public function test_extractor_ignores_bytecode_dump_lines(): void
    {
        // Dump shapes from testoutput2 (B) and pcre2test's fullbincode
        // layout: the dump sits between the pattern echo and the first
        // subject echo, exactly where rejection lines are read. A literal in
        // the dump can spell "Failed: error ..." and must not be taken for
        // a rejection; bracket-looking lines must not become cases.
        $input = <<<'EOT'
            /Failed: error 106 at offset 4: x/B
                Failed: error 106 at offset 4: x

            /[\B]/B

            /[ab]c/fullbincode
                ac
            EOT;
        $output = <<<'EOT'
            /Failed: error 106 at offset 4: x/B
            ------------------------------------------------------------------
                    Bra
                    Failed: error 106 at offset 4: x
                    Ket
                    End
            ------------------------------------------------------------------
                Failed: error 106 at offset 4: x
             0: Failed: error 106 at offset 4: x

            /[\B]/B
            Failed: error 107 at offset 3: escape sequence is invalid in character class
                    here: [\B |<--| ]

            /[ab]c/fullbincode
            ------------------------------------------------------------------
              0  11 Bra
              3     [ab]
             36     c
             38  11 Ket
             41     End
            ------------------------------------------------------------------
                ac
             0: ac
            EOT;

        $cases = self::extract($input."\n", $output."\n");

        $this->assertSame(['sample:1', 'sample:4', 'sample:6'], array_column($cases, 'id'));

        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertNull($cases[0]['offset']);

        // testinput2:68 — PHP agrees: preg_match('/[\B]/', '') warns
        // "escape sequence is invalid in character class at offset 3".
        $this->assertNull($cases[1]['skipCategory']);
        $this->assertSame('[\B]', $cases[1]['pattern']);
        $this->assertSame('reject', $cases[1]['verdict']);
        $this->assertSame(3, $cases[1]['offset']);
        $this->assertSame(107, $cases[1]['pcre2Code']);

        $this->assertNull($cases[2]['skipCategory']);
        $this->assertSame('[ab]c', $cases[2]['pattern']);
        $this->assertSame('accept', $cases[2]['verdict']);
    }

    /**
     * Subject-line modifiers act on one match call, never on the compiled
     * pattern, so none of them can take the compilation verdict away.
     *
     * @return iterable<string, array{input: string, output: string}>
     */
    public static function provideSubjectModifierLines(): iterable
    {
        $rows = [
            'callout_data' => 'abc\\=callout_data=1',
            'callout_capture' => 'abc\\=callout_capture',
            'callout_fail' => 'abc\\=callout_fail=1',
            'find_limits' => 'abc\\=find_limits',
            'dfa' => 'abc\\=dfa',
            'startoffset' => 'xabc\\=startoffset=1',
            'ovector' => 'abc\\=ovector=1',
            'getall and copy' => 'abc\\=getall,copy=0',
            'replace with substitute_extended' => 'abc\\=replace=x,substitute_extended',
            'null_context' => 'abc\\=null_context',
            'zero_terminate' => 'abc\\=zero_terminate',
        ];

        foreach ($rows as $label => $subject) {
            yield $label => [
                'input' => \sprintf("/abc/\n    %s\n", $subject),
                'output' => \sprintf("/abc/\n    %s\n 0: abc\n", $subject),
            ];
        }

        yield 'column-0 modifier line' => [
            'input' => "/abc/\n\\=callout_fail=1\n    abc\n",
            'output' => "/abc/\n\\=callout_fail=1\n    abc\n 0: abc\n",
        ];
    }

    #[Test]
    #[DataProvider('provideSubjectModifierLines')]
    public function test_extractor_ignores_subject_modifiers(string $input, string $output): void
    {
        $cases = self::extract($input, $output);

        $this->assertCount(1, $cases);
        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame('abc', $cases[0]['pattern']);
    }

    /**
     * Modifiers that change the pattern text itself or the compile limits,
     * or that need an API PHP does not expose: still skipped, and named in
     * the committed tables rather than falling through as unrecognized.
     *
     * @return iterable<string, array{line: string}>
     */
    public static function provideBodyChangingModifiers(): iterable
    {
        yield 'hex (testinput2 shape)' => ['line' => '/61 62/hex'];
        yield 'expand (testinput2:5440)' => ['line' => '/\[(a)]{60}/expand'];
        yield 'convert' => ['line' => '/a*b/convert=glob'];
        yield 'convert option' => ['line' => '/a*b/convert=glob,convert_length=11'];
        yield 'literal (testinput2:5748)' => ['line' => '/a\b(c/literal'];
        yield 'max_pattern_length (testinput2:5069)' => ['line' => '/abcd/max_pattern_length=3'];
        yield 'parens_nest_limit (testinput2:4207)' => ['line' => '/(((((a)))))/parens_nest_limit=2'];
    }

    #[Test]
    #[DataProvider('provideBodyChangingModifiers')]
    public function test_extractor_keeps_body_changing_modifiers_skipped(string $line): void
    {
        $cases = self::extract($line."\n    ab\n", $line."\n    ab\n 0: ab\n");

        $this->assertCount(1, $cases);
        $this->assertNotNull($cases[0]['skipCategory'], \sprintf('%s must stay skipped', $line));
        $this->assertNotSame('ambiguous', $cases[0]['skipCategory'], 'the modifier is named in the tables, not unrecognized');
        $this->assertNull($cases[0]['verdict']);
    }

    /**
     * Pattern conversion (pcre2_pattern_convert) rewrites the body before
     * compiling it, and PHP has no way to ask for it.
     *
     * @return iterable<string, array{line: string}>
     */
    public static function provideConvertModifiers(): iterable
    {
        yield 'convert' => ['line' => '/a*b/convert=glob'];
        yield 'convert option' => ['line' => '/a*b/convert=glob,convert_length=11'];
        yield 'convert glob separator' => ['line' => '/a*b/convert=glob,convert_glob_separator=/'];
    }

    #[Test]
    #[DataProvider('provideConvertModifiers')]
    public function test_extractor_skips_convert_modifiers_as_inexpressible(string $line): void
    {
        $cases = self::extract($line."\n    ab\n", $line."\n    ab\n 0: ab\n");

        $this->assertCount(1, $cases);
        $this->assertSame('modifier-inexpressible', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    /**
     * An escaped delimiter followed by another escape: the backslash after
     * "\/" belongs to the next escape, it is not the "delimiter followed by
     * a backslash" terminator form. preg_match('/\d\d\/\d\d/', '') compiles.
     *
     * @return iterable<string, array{line: string, body: string}>
     */
    public static function provideEscapedDelimiterBodies(): iterable
    {
        yield 'two escapes around an escaped slash' => ['line' => '/\d\d\/\d\d/', 'body' => '\d\d\/\d\d'];
        yield 'testinput1:1697' => ['line' => '/\d\d\/\d\d\/\d\d\d\d/', 'body' => '\d\d\/\d\d\/\d\d\d\d'];
    }

    #[Test]
    #[DataProvider('provideEscapedDelimiterBodies')]
    public function test_extractor_handles_escaped_delimiter_before_escape(string $line, string $body): void
    {
        $cases = self::extract($line."\n    12/34\n", $line."\n    12/34\n 0: 12/34\n");

        $this->assertCount(1, $cases);
        $this->assertSame($body, $cases[0]['pattern']);
        $this->assertSame('', $cases[0]['flags']);
        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    /**
     * After a pattern, every line up to the next blank line is subject data,
     * whatever byte it starts with (testinput2:2248 is a column-0 subject
     * line opening with "(").
     *
     * @return iterable<string, array{dataLine: string}>
     */
    public static function provideColumnZeroDataLines(): iterable
    {
        yield 'bracket-looking data line (testinput2:2248 shape)' => ['dataLine' => '(0(0(0)0)0)\=jitstack=1024'];
        yield 'slash-looking data line' => ['dataLine' => '/usr/bin/'];
        yield 'quote-looking data line' => ['dataLine' => '"quoted"'];
    }

    #[Test]
    #[DataProvider('provideColumnZeroDataLines')]
    public function test_extractor_treats_lines_before_blank_as_subject_data(string $dataLine): void
    {
        $input = "/\\( (?: [^()]* | (?R) )* \\)/x\n".$dataLine."\n\n/abc/\n    abc\n";
        $output = "/\\( (?: [^()]* | (?R) )* \\)/x\n".$dataLine."\nNo match\n\n/abc/\n    abc\n 0: abc\n";

        $cases = self::extract($input, $output);

        $this->assertSame(['sample:1', 'sample:4'], array_column($cases, 'id'), 'a data line is never a pattern');
        $this->assertSame('\( (?: [^()]* | (?R) )* \)', $cases[0]['pattern']);
        $this->assertSame('x', $cases[0]['flags']);
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame('abc', $cases[1]['pattern']);
    }

    #[Test]
    public function test_extractor_marks_star_star_diagnostic_ambiguous(): void
    {
        // testoutput2:19067: pcre2test refused to run the pattern and said
        // so with a "** ..." line; there is no compile verdict to read.
        $input = "/^\\w+/tables=3\n    abc\n";
        $output = "/^\\w+/tables=3\n** 'Tables = 3' is invalid: binary tables have not been loaded\n    abc\n";

        $cases = self::extract($input, $output);

        $this->assertSame('ambiguous', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_ignores_star_star_diagnostic_after_a_subject(): void
    {
        // testoutput2:15303: the diagnostic is about the subject line, after
        // the pattern compiled; the compile verdict stays readable.
        $input = "/(abc)*/\n    \\[abc]{0}\n";
        $output = "/(abc)*/\n    \\[abc]{0}\n** Zero or negative repeat not allowed\n";

        $cases = self::extract($input, $output);

        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    /**
     * pcre2test hands the backslash after the closing delimiter to the
     * pattern (testinput2:401 "/abc/\", :403 "/abc/\i"): the body ends with
     * an odd backslash, which no PHP pattern string can carry
     * (preg_match("/abc\\/", '') warns "No ending delimiter '/' found").
     *
     * @return iterable<string, array{input: string, output: string}>
     */
    public static function provideOddTrailingBackslashLines(): iterable
    {
        yield 'bare trailing backslash' => [
            'input' => "/abc/\\\n",
            'output' => "/abc/\\\nFailed: error 101 at offset 4: \\ at end of pattern\n        here: abc\\ |<--|\n",
        ];

        yield 'trailing backslash followed by modifiers' => [
            'input' => "/abc/\\i\n",
            'output' => "/abc/\\i\nFailed: error 101 at offset 4: \\ at end of pattern\n        here: abc\\ |<--|\n",
        ];
    }

    #[Test]
    #[DataProvider('provideOddTrailingBackslashLines')]
    public function test_extractor_skips_odd_trailing_backslash_as_php_inexpressible(string $input, string $output): void
    {
        $cases = self::extract($input, $output);

        $this->assertCount(1, $cases);
        $this->assertSame('php-inexpressible', $cases[0]['skipCategory']);
        $this->assertNull($cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_keeps_even_trailing_backslash_assertable(): void
    {
        // "abc\\" is an escaped backslash: an ordinary PHP pattern body.
        $cases = self::extract("/abc\\\\/\n    abc\\\n", "/abc\\\\/\n    abc\\\n 0: abc\\\n");

        $this->assertSame('abc\\\\', $cases[0]['pattern']);
        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
    }

    #[Test]
    public function test_extractor_records_pcre2_error_number(): void
    {
        // testoutput2 shape for "/[abcd/" (error 106); an accepted case has
        // no error number.
        $input = "/[abc/\n\n/abc/\n    abc\n";
        $output = "/[abc/\nFailed: error 106 at offset 4: missing terminating ] for character class\n        here: [abc |<--|\n\n/abc/\n    abc\n 0: abc\n";

        $cases = self::extract($input, $output);

        $this->assertSame('reject', $cases[0]['verdict']);
        $this->assertSame(4, $cases[0]['offset']);
        $this->assertSame(106, $cases[0]['pcre2Code']);
        $this->assertSame('accept', $cases[1]['verdict']);
        $this->assertNull($cases[1]['pcre2Code']);
    }

    #[Test]
    public function test_extractor_rows_carry_no_php_override(): void
    {
        // Overrides come from the live cross-check at extraction time, never
        // from the testdata files: the extractor always leaves the key null.
        $cases = self::extract("/(?=a\\Kb)ab/\n", "/(?=a\\Kb)ab/\nFailed: error 199 at offset 10: \\K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK)\n");

        $this->assertArrayHasKey('phpOverride', $cases[0]);
        $this->assertNull($cases[0]['phpOverride']);
        $this->assertSame('reject', $cases[0]['verdict']);
        $this->assertSame(10, $cases[0]['offset']);
        $this->assertSame(199, $cases[0]['pcre2Code']);
    }

    /**
     * PHP always compiles with PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, so the
     * modifier asks for nothing PHP does not already do (testinput2:6396,
     * :6411 — the second one with pcre2test's leading-comma form).
     *
     * @return iterable<string, array{line: string, subject: string, match: string}>
     */
    public static function provideAllowLookaroundBskLines(): iterable
    {
        yield 'lookahead (testinput2:6396)' => ['line' => '/(?=a\Kb)ab/allow_lookaround_bsk', 'subject' => '    ab ', 'match' => ' 0: b'];
        yield 'leading comma (testinput2:6411)' => ['line' => '/^abc(?<!b\Kq)d/,allow_lookaround_bsk', 'subject' => '    abcd', 'match' => ' 0: abcd'];
    }

    #[Test]
    #[DataProvider('provideAllowLookaroundBskLines')]
    public function test_extractor_allow_lookaround_bsk_is_noop(string $line, string $subject, string $match): void
    {
        $this->assertNotContains('allow_lookaround_bsk', Pcre2TestdataExtractor::inexpressibleModifiers());

        $cases = self::extract($line."\n".$subject."\n", $line."\n".$subject."\n".$match."\n");

        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame('', $cases[0]['flags']);
    }

    /**
     * Transcript of pcre2test 10.48 (pcre2test -q) on the input below: the
     * whitespace-only line ends the first data block, so "/[x/" is compiled
     * as the next pattern.
     */
    #[Test]
    public function test_extractor_ends_data_block_on_whitespace_only_line(): void
    {
        $input = "/abc/\n    abc\n   \t\n/[x/\n    x\n";
        $output = "/abc/\n    abc\n 0: abc\n   \t\n/[x/\nFailed: error 106 at offset 2: missing terminating ] for character class\n        here: [x |<--|\n    x\n";

        $cases = self::extract($input, $output);

        $this->assertSame(['sample:1', 'sample:4'], array_column($cases, 'id'));
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame('[x', $cases[1]['pattern']);
        $this->assertSame('reject', $cases[1]['verdict']);
        $this->assertSame(2, $cases[1]['offset']);
        $this->assertSame(106, $cases[1]['pcre2Code']);
    }

    /**
     * Transcript of pcre2test 10.48 (pcre2test -q): inside a data block a
     * "#" line is a subject, not a comment or command ("#abc" matches
     * " 0: abc", "#pattern caseless" answers "No match"), so the "#pattern"
     * default never reaches "/ghi/". pcre2test documents commands only "in
     * between sets of test data".
     */
    #[Test]
    public function test_extractor_reads_hash_lines_inside_a_data_block_as_data(): void
    {
        $input = "/abc/\n    abc\n#abc\n\n/def/\n    def\n#pattern caseless\n\n/ghi/\n    GHI\n";
        $output = "/abc/\n    abc\n 0: abc\n#abc\n 0: abc\n\n/def/\n    def\n 0: def\n#pattern caseless\nNo match\n\n/ghi/\n    GHI\nNo match\n";

        $cases = self::extract($input, $output);

        $this->assertSame(['sample:1', 'sample:5', 'sample:9'], array_column($cases, 'id'));
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertSame('ghi', $cases[2]['pattern']);
        $this->assertSame('', $cases[2]['flags'], 'a "#pattern" line inside a data block sets no default');
        $this->assertNull($cases[2]['skipCategory']);
    }

    #[Test]
    public function test_extractor_still_reads_hash_commands_between_data_blocks(): void
    {
        // Control for the test above: after the blank line the same command
        // applies to the next pattern.
        $input = "/def/\n    def\n\n#pattern caseless\n\n/ghi/\n    GHI\n";
        $output = "/def/\n    def\n 0: def\n\n#pattern caseless\n\n/ghi/\n    GHI\n 0: GHI\n";

        $cases = self::extract($input, $output);

        $this->assertSame('i', $cases[1]['flags']);
    }

    #[Test]
    public function test_extractor_skips_bodies_that_are_not_valid_utf8(): void
    {
        // testinput1:163 is "/^\x81/" with a raw 0x81 byte: a legal 8-bit
        // PCRE2 body that the JSON fixture cannot carry, so the case is
        // skipped and its body left out of the row.
        $cases = self::extract("/^\x81/\n    \x81\n", "/^\x81/\n    \x81\n 0: \\x81\n");

        $this->assertSame('ambiguous', $cases[0]['skipCategory']);
        $this->assertSame('', $cases[0]['pattern']);
        $this->assertNull($cases[0]['verdict']);
        $this->assertNotFalse(json_encode($cases[0]), 'the row must serialize');
    }

    #[Test]
    public function test_extractor_keeps_an_empty_pattern(): void
    {
        // pcre2test compiles "//" (" 0: " on any subject); so does PHP.
        $cases = self::extract("//\n    abc\n", "//\n    abc\n 0: \n");

        $this->assertCount(1, $cases);
        $this->assertSame('', $cases[0]['pattern']);
        $this->assertSame('accept', $cases[0]['verdict']);
        $this->assertNull($cases[0]['skipCategory']);
        $this->assertSame('//', (new Pcre2CaseRunner())->phpPattern($cases[0]));
    }

    /**
     * Every delimiter pcre2test accepts on a pattern line.
     *
     * @return iterable<string, array{delimiter: string}>
     */
    public static function providePcre2testDelimiters(): iterable
    {
        foreach (['/', '!', '"', "'", '`', '-', '=', '_', ':', ';', ',', '%', '&', '@', '~'] as $delimiter) {
            yield 'delimiter '.$delimiter => ['delimiter' => $delimiter];
        }
    }

    #[Test]
    #[DataProvider('providePcre2testDelimiters')]
    public function test_extractor_and_runner_round_trip_every_pcre2test_delimiter(string $delimiter): void
    {
        $line = $delimiter.'a[b]c'.$delimiter.'i';

        $cases = self::extract($line."\n    ABC\n", $line."\n    ABC\n 0: ABC\n");

        $this->assertSame($delimiter, $cases[0]['delimiter']);
        $this->assertSame('a[b]c', $cases[0]['pattern']);
        $this->assertSame('i', $cases[0]['flags']);

        $phpPattern = (new Pcre2CaseRunner())->phpPattern($cases[0]);

        $this->assertSame($line, $phpPattern);
        // PHP accepts each of these delimiters: preg_match returns 1.
        $this->assertSame(1, preg_match($phpPattern, 'ABC'));
    }

    /**
     * Each row pairs the pinned 10.48 record (pcre2test -q output after the
     * echo; empty when the pattern compiles) with what a real PCRE2 10.40
     * pcre2test reports for the same pattern under PHP's compile context
     * (the line Pcre2FloorOracle::pcre2testLine builds). Both engines were
     * run for every row; the key cites the two results.
     *
     * @return iterable<string, array{line: string, pinOutput: string, floor: FloorObservation, skip: string|null}>
     */
    public static function provideFloorObservations(): iterable
    {
        $accept = ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null];
        $reject = static fn (int $offset, int $code): array => ['verdict' => 'reject', 'offset' => $offset, 'pcre2Code' => $code];

        yield 'spaces inside \x{} — 10.40 error 167 at 3, 10.48 compiles' => [
            'line' => '/\x{ 41 }/', 'pinOutput' => '', 'floor' => $reject(3, 167), 'skip' => 'newer-than-floor',
        ];
        yield '(?a) option — 10.40 error 111 at 4, 10.48 compiles' => [
            'line' => '/\d(?a)\d/utf', 'pinOutput' => '', 'floor' => $reject(4, 111), 'skip' => 'newer-than-floor',
        ];
        yield 'subroutine call with arguments — 10.40 error 114 at 9, 10.48 compiles' => [
            'line' => '/()()()(?2(2,3,2,3,2))/', 'pinOutput' => '', 'floor' => $reject(9, 114), 'skip' => 'newer-than-floor',
        ];
        yield '{0} group inside a lookbehind — 10.40 error 125 at 0, 10.48 compiles' => [
            'line' => '/(?<=a(b?c){0}d)X/', 'pinOutput' => '', 'floor' => $reject(0, 125), 'skip' => 'newer-than-floor',
        ];
        yield 'bounded variable lookbehind — 10.40 error 125 at 0, 10.48 compiles' => [
            'line' => '/(?<=a{1,3})b/', 'pinOutput' => '', 'floor' => $reject(0, 125), 'skip' => 'newer-than-floor',
        ];
        yield '\R in a lookbehind — 10.40 error 125 at 0, 10.48 compiles' => [
            'line' => '/(?<=\R)X/', 'pinOutput' => '', 'floor' => $reject(0, 125), 'skip' => 'newer-than-floor',
        ];
        yield '{,3} after a quantifier — 10.40 compiles, 10.48 error 109 at 6' => [
            'line' => '/A+{,3}/',
            'pinOutput' => "Failed: error 109 at offset 6: quantifier does not follow a repeatable item\n        here: A+{,3} |<--|\n",
            'floor' => $accept,
            'skip' => 'stricter-than-floor',
        ];
        yield '\x without digits — 10.40 compiles, 10.48 error 178 at 2' => [
            'line' => '/\xthing/',
            'pinOutput' => "Failed: error 178 at offset 2: digits missing after \\x or in \\x{} or \\o{} or \\N{U+}\n        here: \\x |<--| thing\n",
            'floor' => $accept,
            'skip' => 'stricter-than-floor',
        ];
        yield 'unlimited lookbehind — both reject, error 125 at 0' => [
            'line' => '/(?<=a+)b/',
            'pinOutput' => "Failed: error 125 at offset 0: length of lookbehind assertion is not limited\n        here: |-->| (?<=a+)b\n",
            'floor' => $reject(0, 125),
            'skip' => null,
        ];
        yield 'range out of order — both reject, 10.48 at 4, 10.40 at 3' => [
            'line' => '/[z-a]/',
            'pinOutput' => "Failed: error 108 at offset 4: range out of order in character class\n        here: [z-a |<--| ]\n",
            'floor' => $reject(3, 108),
            'skip' => null,
        ];
        yield 'plain pattern — both compile' => [
            'line' => '/abc/', 'pinOutput' => '', 'floor' => $accept, 'skip' => null,
        ];
    }

    /**
     * @param FloorObservation $floor
     */
    #[Test]
    #[DataProvider('provideFloorObservations')]
    public function test_extractor_classifies_cases_by_the_floor_verdict(string $line, string $pinOutput, array $floor, ?string $skip): void
    {
        $rows = self::extract($line."\n", $line."\n".$pinOutput);
        $pinVerdict = $rows[0]['verdict'];
        $pinOffset = $rows[0]['offset'];

        [$classified, $observed] = self::withFloor($rows, ['sample:1' => $floor]);

        $this->assertSame(['sample:1'], $observed, 'the floor runs once per assertable case');
        $this->assertSame($floor, $classified[0]['floor'], 'the floor observation is recorded on the row');
        $this->assertSame($skip, $classified[0]['skipCategory']);

        if (null === $skip) {
            $this->assertSame($pinVerdict, $classified[0]['verdict']);
            $this->assertSame($pinOffset, $classified[0]['offset'], 'the pinned offset stays the expected offset');
        } else {
            $this->assertNull($classified[0]['verdict']);
            $this->assertStringContainsString(Pcre2TestdataExtractor::PCRE2_FLOOR, (string) $classified[0]['skipReason']);
        }
    }

    #[Test]
    public function test_extractor_compares_the_floor_with_the_php_verdict_of_an_override(): void
    {
        // testinput2:6394: the suite rejects with error 199, PHP compiles it
        // (phpOverride), and 10.40 compiles it under allow_lookaround_bsk. PHP
        // and the floor agree, so the case stays assertable.
        $rows = self::extract(
            "/(?=a\\Kb)ab/\n",
            "/(?=a\\Kb)ab/\nFailed: error 199 at offset 10: \\K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK)\n",
        );
        $rows[0]['phpOverride'] = ['reason' => 'allow-lookaround-bsk', 'verdict' => 'accept', 'offset' => null, 'pcre2Code' => null];

        [$classified] = self::withFloor($rows, ['sample:1' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null]]);

        $this->assertNull($classified[0]['skipCategory']);
        $this->assertSame('reject', $classified[0]['verdict'], 'the suite record stays; the override sits beside it');
        $this->assertSame(['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null], $classified[0]['floor']);
    }

    #[Test]
    public function test_extractor_runs_no_floor_for_skipped_cases(): void
    {
        $input = "/61 62/hex\n\n/abc/\\\n\n/^abc$/newline=cr\n\n/abc/\n";
        $output = "/61 62/hex\n\n/abc/\\\nFailed: error 101 at offset 4: \\ at end of pattern\n\n/^abc$/newline=cr\n\n/abc/\n";

        $rows = self::extract($input, $output);

        $this->assertSame(['pcre2test-api', 'php-inexpressible', 'newline-command', null], array_column($rows, 'skipCategory'));

        [$classified, $observed] = self::withFloor($rows, ['sample:7' => ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null]]);

        $this->assertSame(['sample:7'], $observed);
        $this->assertSame([null, null, null], array_column(\array_slice($classified, 0, 3), 'floor'));
        $this->assertSame(['pcre2test-api', 'php-inexpressible', 'newline-command', null], array_column($classified, 'skipCategory'));
    }

    #[Test]
    public function test_extractor_hands_the_row_to_the_floor_observer(): void
    {
        $rows = self::extract("!a/b!i\n", "!a/b!i\n");
        $seen = [];

        Pcre2TestdataExtractor::applyFloor($rows, static function (array $row) use (&$seen): array {
            $seen[] = [$row['id'] ?? null, $row['pattern'] ?? null, $row['delimiter'] ?? null, $row['flags'] ?? null];

            return ['verdict' => 'accept', 'offset' => null, 'pcre2Code' => null];
        });

        $this->assertSame([['sample:1', 'a/b', '!', 'i']], $seen);
    }

    /**
     * Applies a fake floor observer that returns a recorded 10.40 result per
     * case id and remembers which cases it was asked about.
     *
     * @param list<array<string, mixed>>      $rows
     * @param array<string, FloorObservation> $floorById
     *
     * @return array{0: list<Pcre2Case>, 1: list<string>}
     */
    private static function withFloor(array $rows, array $floorById): array
    {
        $observed = [];

        /** @var list<Pcre2Case> $classified */
        $classified = Pcre2TestdataExtractor::applyFloor($rows, static function (array $row) use ($floorById, &$observed): array {
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
            $observed[] = $id;

            if (!isset($floorById[$id])) {
                throw new \LogicException(\sprintf('No recorded floor result for %s.', $id));
            }

            return $floorById[$id];
        });

        return [$classified, $observed];
    }

    /**
     * The extractor documents every case with exactly these keys, so the
     * shape is narrowed here once instead of in every assertion.
     *
     * @return list<Pcre2Case>
     */
    private static function extract(string $input, string $output): array
    {
        /** @var list<Pcre2Case> $cases */
        $cases = (new Pcre2TestdataExtractor())->extract($input, $output, 'sample');

        return $cases;
    }
}
