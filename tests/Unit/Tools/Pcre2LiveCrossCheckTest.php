<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Tools;

use PhpRegex\Tests\TestUtils\Pcre2LiveCrossCheck;
use PhpRegex\Tests\TestUtils\Pcre2TestdataExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the live cross-check run at extraction time.
 *
 * Every expected verdict written to the suite fixture must equal what
 * preg_match does on the PHP that extracted it. Every call yields a
 * verdict: a "Compilation failed: ... at offset N" warning is a reject at
 * offset N; any other preg_match warning (delimiter, unknown modifier) is a
 * reject without an offset; no warning at all is an accept, including a false
 * return, because preg_match only fails at match time after the pattern
 * compiled (backtrack or depth limit, JIT failure). A disagreement is either
 * explained by a known difference between PHP's compile context and
 * pcre2test's, and becomes a phpOverride holding what preg_match observed, or
 * it is reported — no observation is ever silently passed over.
 *
 * Every live expectation below was observed on PHP 8.4 linked to PCRE2 10.48.
 * The compile offsets asserted here ("[abc" error 106 at 4, "a{2,1}" error
 * 104 at 5) are the same on PCRE2 10.40, the oldest engine a supported PHP
 * ships, so these tests hold on every supported engine.
 *
 * @phpstan-type Pcre2Case = array{id: string, pattern: string, delimiter: string, flags: string, verdict: string|null, offset: int|null, error: string|null, pcre2Code: int|null, phpOverride: array<string, mixed>|null, skipCategory: string|null, skipReason: string|null}
 */
final class Pcre2LiveCrossCheckTest extends TestCase
{
    private const LOOKAROUND_BSK_MESSAGE = '\K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK)';

    #[Test]
    public function test_observe_reads_compile_warning_into_verdict_and_offset(): void
    {
        // preg_match('/[abc/', '') warns "Compilation failed: missing
        // terminating ] for character class at offset 4".
        $this->assertSame(
            ['verdict' => 'reject', 'offset' => 4, 'message' => 'missing terminating ] for character class'],
            Pcre2LiveCrossCheck::observe('/[abc/'),
        );
    }

    #[Test]
    public function test_observe_reads_offset_past_a_quantifier(): void
    {
        // preg_match('/a{2,1}/', '') warns "... numbers out of order in {}
        // quantifier at offset 5": the offset is body-relative, like the
        // testoutput files.
        $this->assertSame(
            ['verdict' => 'reject', 'offset' => 5, 'message' => 'numbers out of order in {} quantifier'],
            Pcre2LiveCrossCheck::observe('/a{2,1}/'),
        );
    }

    #[Test]
    public function test_observe_reports_accept(): void
    {
        // Every supported PHP compiles it: preg_match returns 0.
        $this->assertSame(
            ['verdict' => 'accept', 'offset' => null, 'message' => null],
            Pcre2LiveCrossCheck::observe('/b\Kc(?=d)/'),
        );
    }

    #[Test]
    public function test_observe_reads_match_time_false_as_compiled(): void
    {
        // Compiles on every supported engine (10.40 and 10.48 pcre2test both
        // accept it), then fails at match time on the empty subject: on
        // 10.48 preg_match returns false with "Backtrack limit exhausted" and
        // no warning, with JIT on and off. Either way there is no compile
        // warning, so the observation is accept.
        $this->assertSame(
            ['verdict' => 'accept', 'offset' => null, 'message' => null],
            Pcre2LiveCrossCheck::observe('/(*NO_START_OPT)(*LIMIT_MATCH=1)(a|b|)*c/'),
        );
    }

    /**
     * Warnings from PHP's pattern-string layer, before PCRE2 compiles
     * anything: a reject with no PCRE2 offset.
     *
     * @return iterable<string, array{pattern: string, message: string}>
     */
    public static function providePatternLayerWarnings(): iterable
    {
        // preg_match('/abc', '') warns "preg_match(): No ending delimiter '/' found".
        yield 'missing closing delimiter' => ['pattern' => '/abc', 'message' => "No ending delimiter '/' found"];

        // preg_match('/abc/q', '') warns "preg_match(): Unknown modifier 'q'".
        yield 'unknown modifier' => ['pattern' => '/abc/q', 'message' => "Unknown modifier 'q'"];
    }

    #[Test]
    #[DataProvider('providePatternLayerWarnings')]
    public function test_observe_reads_other_warnings_as_reject_without_offset(string $pattern, string $message): void
    {
        $observed = Pcre2LiveCrossCheck::observe($pattern);

        $this->assertSame(['verdict', 'offset', 'message'], array_keys($observed));
        $this->assertSame('reject', $observed['verdict']);
        $this->assertNull($observed['offset']);
        $this->assertIsString($observed['message']);
        $this->assertStringContainsString($message, (string) $observed['message']);
    }

    #[Test]
    public function test_observe_reads_jit_compilation_failure_as_compiled(): void
    {
        // preg_match('/^(?(?C25)(?=abc)abcd|xyz)/', 'abcd') warns "JIT
        // compilation failed: feature is not supported by the JIT compiler"
        // and still returns 1: ext/pcre only tries JIT after pcre2_compile
        // succeeded. ext/pcre caches compiled patterns per process and warns
        // on the first compile only, so each run uses a pattern string no
        // other test has compiled (a unique comment group), with JIT on.
        $previousJit = ini_set('pcre.jit', '1');

        try {
            $observed = Pcre2LiveCrossCheck::observe(self::uniqueJitUnsupportedPattern());

            // Whether the JIT refuses this pattern depends on the engine
            // version and on how PHP was built (PCRE_JIT_SUPPORT is read at
            // run time rather than folded by static analysis). The warning
            // was verified on the pinned engine with JIT, so the proof that
            // the warning path is taken is bounded to that engine; the
            // accept verdict below is asserted everywhere.
            $pinnedEngine = Pcre2LiveCrossCheck::engineMatchesPin(\PCRE_VERSION, Pcre2TestdataExtractor::PCRE2_PIN);

            if ($pinnedEngine && true === \constant('PCRE_JIT_SUPPORT')) {
                $warnings = [];
                set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                    $warnings[] = $message;

                    return true;
                });

                try {
                    preg_match(self::uniqueJitUnsupportedPattern(), '');
                } finally {
                    restore_error_handler();
                }

                $this->assertCount(1, $warnings);
                $this->assertStringContainsString('JIT compilation failed', $warnings[0]);
            }
        } finally {
            ini_set('pcre.jit', false === $previousJit ? '1' : $previousJit);
        }

        $this->assertSame(['verdict' => 'accept', 'offset' => null, 'message' => null], $observed);
    }

    #[Test]
    public function test_observe_leaves_no_error_behind(): void
    {
        error_clear_last();

        Pcre2LiveCrossCheck::observe('/[abc/');

        $this->assertNull(error_get_last(), 'the compile warning is consumed, not left for the caller');
    }

    #[Test]
    public function test_context_differences_table_lists_allow_lookaround_bsk(): void
    {
        // php-src sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK by default since
        // 8.1.0; pcre2test reports its absence as error 199.
        $this->assertSame(['allow-lookaround-bsk' => 199], Pcre2LiveCrossCheck::contextDifferences());
    }

    #[Test]
    public function test_check_accepts_agreeing_rows(): void
    {
        $result = Pcre2LiveCrossCheck::check([
            self::row('sample:1', '[abc', 'reject', 4, 'missing terminating ] for character class', 106),
            self::row('sample:3', 'abc', 'accept'),
        ]);

        $this->assertSame(['overrides' => [], 'disagreements' => []], $result);
    }

    #[Test]
    public function test_check_turns_allow_lookaround_bsk_disagreement_into_override(): void
    {
        // testinput2:6404 — testoutput2 rejects with error 199 at offset 14;
        // up to PHP 8.4, preg_match('/^abc(?<=b\Kc)d/', '') returns 0. The
        // override records the live observation, not a blind flip.
        $result = Pcre2LiveCrossCheck::check([
            self::row('testinput2:6404', '^abc(?<=b\Kc)d', 'reject', 14, self::LOOKAROUND_BSK_MESSAGE, 199),
        ]);

        $this->assertSame([], $result['disagreements']);

        // PHP 8.5 compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK: it
        // refuses the pattern as the suite does, and nothing is overridden.
        $expected = \PHP_VERSION_ID >= 80500 ? [] : [
            'testinput2:6404' => ['reason' => 'allow-lookaround-bsk', 'verdict' => 'accept', 'offset' => null, 'pcre2Code' => null],
        ];
        $this->assertSame($expected, $result['overrides']);
    }

    /**
     * @return iterable<string, array{row: Pcre2Case}>
     */
    public static function provideUnexplainedDisagreements(): iterable
    {
        yield 'suite rejects, PHP accepts, no known context difference' => [
            'row' => self::row('sample:7', 'abc', 'reject', 4, 'missing terminating ] for character class', 106),
        ];

        yield 'suite accepts, PHP rejects' => [
            'row' => self::row('sample:7', '[abc', 'accept'),
        ];

        yield 'both reject at different offsets' => [
            'row' => self::row('sample:7', '[abc', 'reject', 5, 'missing terminating ] for character class', 106),
        ];

        yield 'suite rejects, PHP compiles and only fails at match time' => [
            'row' => self::row('sample:7', '(*NO_START_OPT)(*LIMIT_MATCH=1)(a|b|)*c', 'reject', 0, 'invented rejection', 115),
        ];
    }

    /**
     * @param Pcre2Case $row
     */
    #[Test]
    #[DataProvider('provideUnexplainedDisagreements')]
    public function test_check_reports_disagreement_without_override(array $row): void
    {
        $result = Pcre2LiveCrossCheck::check([$row]);

        $this->assertSame([], $result['overrides']);
        $this->assertCount(1, $result['disagreements']);
        $this->assertStringContainsString('sample:7', $result['disagreements'][0]);
    }

    #[Test]
    public function test_check_ignores_skipped_rows(): void
    {
        // A skipped row has no expected verdict; the live engine rejecting
        // its body proves nothing about the fixture.
        $result = Pcre2LiveCrossCheck::check([
            self::row('sample:1', '[abc', null, skipCategory: 'pcre2test-api'),
        ]);

        $this->assertSame(['overrides' => [], 'disagreements' => []], $result);
    }

    #[Test]
    public function test_check_agrees_when_a_compiled_pattern_fails_at_match_time(): void
    {
        $result = Pcre2LiveCrossCheck::check([
            self::row('sample:1', '(*NO_START_OPT)(*LIMIT_MATCH=1)(a|b|)*c', 'accept'),
        ]);

        $this->assertSame(['overrides' => [], 'disagreements' => []], $result);
    }

    /**
     * @return iterable<string, array{pcreVersion: string, pin: string, expected: bool}>
     */
    public static function providePcreVersions(): iterable
    {
        yield 'PCRE_VERSION of the pinned release' => ['pcreVersion' => '10.48 2026-08-31', 'pin' => '10.48', 'expected' => true];
        yield 'bare version string' => ['pcreVersion' => '10.48', 'pin' => '10.48', 'expected' => true];
        yield 'older release' => ['pcreVersion' => '10.47 2025-10-21', 'pin' => '10.48', 'expected' => false];
        yield 'longer version sharing the pin as a prefix' => ['pcreVersion' => '10.481 2030-01-01', 'pin' => '10.48', 'expected' => false];
        yield 'floor release' => ['pcreVersion' => '10.40 2022-04-14', 'pin' => '10.48', 'expected' => false];
    }

    #[Test]
    #[DataProvider('providePcreVersions')]
    public function test_engine_matches_pin_compares_the_leading_version(string $pcreVersion, string $pin, bool $expected): void
    {
        $this->assertSame($expected, Pcre2LiveCrossCheck::engineMatchesPin($pcreVersion, $pin));
    }

    /**
     * A pattern PCRE2 compiles but its JIT compiler refuses (a callout as a
     * condition), made unique so ext/pcre's per-process cache has never seen
     * it and the JIT attempt, with its warning, happens again.
     */
    private static function uniqueJitUnsupportedPattern(): string
    {
        return \sprintf('/^(?(?C25)(?=abc)abcd|xyz)(?#%s)/', bin2hex(random_bytes(8)));
    }

    /**
     * Builds an extracted case row.
     *
     * @return Pcre2Case
     */
    private static function row(
        string $id,
        string $pattern,
        ?string $verdict,
        ?int $offset = null,
        ?string $error = null,
        ?int $pcre2Code = null,
        ?string $skipCategory = null,
    ): array {
        return [
            'id' => $id,
            'pattern' => $pattern,
            'delimiter' => '/',
            'flags' => '',
            'verdict' => $verdict,
            'offset' => $offset,
            'error' => $error,
            'pcre2Code' => $pcre2Code,
            'phpOverride' => null,
            'skipCategory' => $skipCategory,
            'skipReason' => null === $skipCategory ? null : 'skipped for the test',
        ];
    }
}
