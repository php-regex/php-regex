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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Tests\TestUtils\Pcre2CaseRunner;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the per-case conformance runner.
 *
 * The runner feeds a suite case to Regex::validate() on the product path
 * (default options, runtime PCRE validation off) and compares the result with
 * the expected outcome as it comes back, the library's offsets being counted
 * from the pattern body as PCRE2's are: the verdict must always match, and
 * when both sides reject the offsets must be equal, whatever the two error messages
 * say (PCRE2's text and the library's text are never compared). A case
 * carrying a phpOverride is expected to behave as PHP compiled it, not as
 * pcre2test did.
 *
 * PCRE2-side values in these tests are the engine's own: the messages and
 * offsets were read from the vendored testoutput files and cross-checked with
 * preg_match on PCRE2 10.48. Library-side values were measured against the
 * library as shipped, not assumed.
 *
 * @phpstan-type Pcre2Override = array{reason: string, verdict: string, offset: int|null, pcre2Code: int|null}
 * @phpstan-type Pcre2Case = array{id: string, pattern: string, delimiter: string, flags: string, verdict: string|null, offset: int|null, error: string|null, pcre2Code: int|null, phpOverride: Pcre2Override|null, floor: Pcre2Floor|null, skipCategory: string|null, skipReason: string|null}
 * @phpstan-type Pcre2Floor = array{verdict: string, offset: int|null, pcre2Code: int|null}
 */
final class Pcre2CaseRunnerTest extends TestCase
{
    /**
     * PCRE2 10.48 text for error 106 ("Failed: error 106 at offset 4: ..."
     * in testoutput2; preg_match('/[abc/', '') warns "Compilation failed:
     * missing terminating ] for character class at offset 4").
     */
    private const PCRE2_MISSING_BRACKET = 'missing terminating ] for character class';

    /**
     * Shared rejections whose offset differs between PCRE2 10.40 (the floor)
     * and 10.48 (the pin). Both engines were run: pcre2test 10.48 and a 10.40
     * pcre2test built from the release tarball, under PHP's compile context.
     * The library's offset was measured for each. A library offset equal to
     * either engine's passes; any other offset is a defect on both engines.
     *
     * @return iterable<string, array{case: Pcre2Case, libraryOffset: int, outcome: string}>
     */
    public static function provideVersionDependentOffsets(): iterable
    {
        // PCRE2 10.47 reports these past the character at fault, the releases
        // before on it; the library reports them where the running release
        // does.
        $pastTheFault = version_compare(explode(' ', \PCRE_VERSION)[0], '10.47', '>=');

        // PHP: "range out of order in character class".
        yield 'range out of order — 10.48 at 4, 10.40 at 3, library as the running release' => [
            'case' => self::case('[z-a]', 'reject', 4, 'range out of order in character class', pcre2Code: 108, floor: ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 108]),
            'libraryOffset' => $pastTheFault ? 4 : 3,
            'outcome' => 'pass-either-offset',
        ];

        // PHP: "unrecognized character after (? or (?-".
        yield 'unknown (? construct — 10.48 at 4, 10.40 at 3, library as the running release' => [
            'case' => self::case('a(?{)b', 'reject', 4, 'unrecognized character after (? or (?-', pcre2Code: 111, floor: ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 111]),
            'libraryOffset' => $pastTheFault ? 4 : 3,
            'outcome' => 'pass-either-offset',
        ];

        // PHP: "unrecognized character after (? or (?-": 10.48 takes "a" as
        // an option and stops past the "Z", 10.40 stops on the "a". PCRE2
        // 10.43 to 10.46 take the "a" and stop on the "Z", an offset neither
        // pinned release reports.
        $readsAsciiOptions = version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');
        yield 'option letter — 10.48 at 4, 10.40 at 2, library as the running release' => [
            'case' => self::case('(?aZ)', 'reject', 4, 'unrecognized character after (? or (?-', pcre2Code: 111, floor: ['verdict' => 'reject', 'offset' => 2, 'pcre2Code' => 111]),
            'libraryOffset' => $pastTheFault ? 4 : ($readsAsciiOptions ? 3 : 2),
            'outcome' => $pastTheFault || !$readsAsciiOptions ? 'pass-either-offset' : 'offset-defect',
        ];

        // testinput2:347.
        yield 'unmatched closing parenthesis — 10.48 at 4, 10.40 at 3, library as the running release' => [
            'case' => self::case('abc)', 'reject', 4, 'unmatched closing parenthesis', pcre2Code: 122, floor: ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 122]),
            'libraryOffset' => $pastTheFault ? 4 : 3,
            'outcome' => 'pass-either-offset',
        ];

        // testinput2:4797. The library reports the offset of the PCRE2 PHP
        // runs: 13 from 10.45, 8 before.
        yield 'subpattern number too big — 10.48 at 13, 10.40 at 8, library as the running PCRE2' => [
            'case' => self::case('(?(8000000000', 'reject', 13, 'subpattern number is too big', pcre2Code: 161, floor: ['verdict' => 'reject', 'offset' => 8, 'pcre2Code' => 161]),
            'libraryOffset' => PhpErrorOffset::of('/(?(8000000000/') ?? 13,
            'outcome' => 'pass-either-offset',
        ];
    }

    /**
     * @param Pcre2Case $case
     */
    #[Test]
    #[DataProvider('provideVersionDependentOffsets')]
    public function test_runner_accepts_either_engine_offset_when_they_differ(array $case, int $libraryOffset, string $outcome): void
    {
        $result = (new Pcre2CaseRunner())->run($case);

        $this->assertSame('reject', $result['verdict']);
        $this->assertSame($libraryOffset, $result['offset']);
        $this->assertSame($outcome, $result['outcome']);
    }

    #[Test]
    public function test_runner_scores_the_offset_when_both_versions_agree_on_it(): void
    {
        // "(a(?<=(?3)))(b(?<=(c(?2))))": error 125 at offset 2, recorded
        // alike for both versions (PCRE2 10.49: "length of lookbehind
        // assertion is not limited at offset 2"). The library still reports
        // it at 14, so this is an offset defect; swap the case once that one
        // is fixed.
        $result = (new Pcre2CaseRunner())->run(self::case(
            '(a(?<=(?3)))(b(?<=(c(?2))))',
            'reject',
            2,
            'length of lookbehind assertion is not limited',
            pcre2Code: 125,
            floor: ['verdict' => 'reject', 'offset' => 2, 'pcre2Code' => 125],
        ));

        $this->assertSame(14, $result['offset']);
        $this->assertSame('offset-defect', $result['outcome']);

        // Same agreement on "[abc" (106 at 4 on both): the library agrees too.
        $agreeing = (new Pcre2CaseRunner())->run(self::case(
            '[abc',
            'reject',
            4,
            self::PCRE2_MISSING_BRACKET,
            pcre2Code: 106,
            floor: ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 106],
        ));

        $this->assertSame('pass', $agreeing['outcome']);
    }

    #[Test]
    public function test_runner_still_reports_a_false_accept_when_the_offset_depends_on_the_version(): void
    {
        // A runner-level fake: the case says both engines reject "abc", at
        // different offsets, and the library accepts it. No real pattern is
        // needed to exercise the runner, so the sample cannot go stale when
        // the library stops wrongly accepting one.
        $result = (new Pcre2CaseRunner())->run(self::case(
            'abc',
            'reject',
            5,
            'range out of order in character class',
            pcre2Code: 108,
            floor: ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 108],
        ));

        $this->assertSame('accept', $result['verdict']);
        $this->assertSame('false-accept', $result['outcome']);

        // "[^^--]": 10.48 error 108 at 5, 10.40 at 4. This was the sample false
        // accept until the library stopped reading "--" as a class
        // subtraction; it now rejects it where the range ends, at 10.48's
        // offset (PHP: "range out of order in character class at offset 5").
        $result = (new Pcre2CaseRunner())->run(self::case(
            '[^^--]',
            'reject',
            5,
            'range out of order in character class',
            pcre2Code: 108,
            floor: ['verdict' => 'reject', 'offset' => 4, 'pcre2Code' => 108],
        ));

        $this->assertSame('reject', $result['verdict']);
        $this->assertSame('pass-either-offset', $result['outcome']);

        // "(?Cab)xx" (testinput2:1066): 10.48 error 182 at 4, 10.40 at 3. This
        // was the sample false accept until the library refused it; it now
        // reports 10.48's offset.
        $result = (new Pcre2CaseRunner())->run(self::case(
            '(?Cab)xx',
            'reject',
            4,
            'unrecognized string delimiter follows (?C',
            pcre2Code: 182,
            floor: ['verdict' => 'reject', 'offset' => 3, 'pcre2Code' => 182],
        ));

        $this->assertSame('pass-either-offset', $result['outcome']);

        // "[\B]": 10.48 error 107 at 3, 10.40 at 2. This was the sample false
        // accept until the library rejected it; it now reports 10.48's offset.
        $result = (new Pcre2CaseRunner())->run(self::case(
            '[\B]',
            'reject',
            3,
            'escape sequence is invalid in character class',
            pcre2Code: 107,
            floor: ['verdict' => 'reject', 'offset' => 2, 'pcre2Code' => 107],
        ));

        $this->assertSame('pass-either-offset', $result['outcome']);
    }

    #[Test]
    public function test_runner_verdict_accept_matches(): void
    {
        $result = (new Pcre2CaseRunner())->run(self::case('abc', 'accept'));

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('accept', $result['verdict']);
        $this->assertNull($result['offset']);
    }

    #[Test]
    public function test_runner_verdict_reject_matches(): void
    {
        // PCRE2 rejects '[abc' with error 106 at body offset 4; the library
        // rejects it at the same body offset with its own wording.
        $result = (new Pcre2CaseRunner())->run(self::case(
            '[abc',
            'reject',
            4,
            self::PCRE2_MISSING_BRACKET,
            pcre2Code: 106,
        ));

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame('reject', $result['verdict']);
        $this->assertSame(4, $result['offset']);
    }

    #[Test]
    public function test_runner_both_reject_same_offset_passes(): void
    {
        $case = self::case('[abc', 'reject', 4, self::PCRE2_MISSING_BRACKET, pcre2Code: 106);

        $result = (new Pcre2CaseRunner())->run($case);

        // The two messages differ (the library says 'Unclosed character
        // class "]" at end of input.'); only the offsets are compared.
        $this->assertNotSame($case['error'], $result['error']);
        $this->assertSame('pass', $result['outcome']);
        $this->assertSame(4, $result['offset']);
    }

    /**
     * @return iterable<string, array{case: Pcre2Case, libraryOffset: int}>
     */
    public static function provideDifferentOffsetRejections(): iterable
    {
        // preg_match('/(a(?<=(?3)))(b(?<=(c(?2))))/', '') warns "length of
        // lookbehind assertion is not limited at offset 2" (PCRE2 10.49); the
        // library still reports it at 14. Swap the case once that is fixed.
        yield 'real suite error reported at a different position' => [
            'case' => self::case('(a(?<=(?3)))(b(?<=(c(?2))))', 'reject', 2, 'length of lookbehind assertion is not limited', pcre2Code: 125),
            'libraryOffset' => 14,
        ];

        // Same pattern, same PCRE2 error text, a recorded offset the library
        // does not produce: a plain offset defect.
        yield 'same error text with a different offset' => [
            'case' => self::case('[abc', 'reject', 99, self::PCRE2_MISSING_BRACKET, pcre2Code: 106),
            'libraryOffset' => 4,
        ];
    }

    /**
     * @param Pcre2Case $case
     */
    #[Test]
    #[DataProvider('provideDifferentOffsetRejections')]
    public function test_runner_both_reject_different_offset_is_offset_defect(array $case, int $libraryOffset): void
    {
        $result = (new Pcre2CaseRunner())->run($case);

        $this->assertSame('reject', $result['verdict']);
        $this->assertSame('offset-defect', $result['outcome']);
        $this->assertSame($libraryOffset, $result['offset']);
    }

    #[Test]
    public function test_runner_flags_false_reject(): void
    {
        // The pinned suite accepted this pattern; the library rejects it.
        $result = (new Pcre2CaseRunner())->run(self::case('[abc', 'accept'));

        $this->assertSame('false-reject', $result['outcome']);
        $this->assertSame('reject', $result['verdict']);
    }

    #[Test]
    public function test_runner_flags_false_accept(): void
    {
        // The pinned suite rejected this pattern; the library accepts it.
        $result = (new Pcre2CaseRunner())->run(self::case(
            'abc',
            'reject',
            4,
            self::PCRE2_MISSING_BRACKET,
            pcre2Code: 106,
        ));

        $this->assertSame('false-accept', $result['outcome']);
        $this->assertSame('accept', $result['verdict']);
    }

    #[Test]
    public function test_runner_php_override_replaces_expected_verdict(): void
    {
        // PHP compiles with PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK (php-src sets it
        // since 8.1.0), so preg_match('/^abc(?<=b\Kc)d/', '') returns 0 where
        // pcre2test reports error 199 at offset 14 (testoutput2). The override
        // holds what preg_match observed; the runner must expect that.
        $override = ['reason' => 'allow-lookaround-bsk', 'verdict' => 'accept', 'offset' => null, 'pcre2Code' => null];
        $runner = new Pcre2CaseRunner();

        // Library accepts (measured on 'abc'): with the override the expected
        // verdict is accept, so this passes instead of being a false-accept.
        $accepted = $runner->run(self::case(
            'abc',
            'reject',
            14,
            '\K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK)',
            pcre2Code: 199,
            phpOverride: $override,
        ));

        $this->assertSame('accept', $accepted['verdict']);
        $this->assertSame('pass', $accepted['outcome']);

        // When the library rejects a pattern the override says PHP accepts,
        // that is a false-reject, not a pass on a shared rejection. The body
        // only has to be one the library rejects ("[abc" is unclosed).
        $rejected = $runner->run(self::case(
            '[abc',
            'reject',
            14,
            '\K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK)',
            pcre2Code: 199,
            phpOverride: $override,
        ));

        $this->assertSame('reject', $rejected['verdict']);
        $this->assertSame('false-reject', $rejected['outcome']);
    }

    #[Test]
    public function test_runner_php_override_supplies_expected_offset(): void
    {
        // An override that records a PHP-side rejection carries its own
        // offset, which replaces the suite's for the offset comparison. The
        // runner reads verdict and offset only; the reason is documentation.
        $runner = new Pcre2CaseRunner();
        $reject = static fn (int $offset): array => [
            'reason' => 'allow-lookaround-bsk',
            'verdict' => 'reject',
            'offset' => $offset,
            'pcre2Code' => null,
        ];

        $sameOffset = $runner->run(self::case('[abc', 'accept', phpOverride: $reject(4)));
        $this->assertSame('pass', $sameOffset['outcome']);

        $otherOffset = $runner->run(self::case('[abc', 'accept', phpOverride: $reject(2)));
        $this->assertSame('offset-defect', $otherOffset['outcome']);
    }

    #[Test]
    public function test_runner_reports_a_modifier_error_from_the_body(): void
    {
        $runner = new Pcre2CaseRunner();

        // The library counts a modifier error from the body, as PCRE2 does:
        // for '/abc/q' the "q" is at 4, past the body and its delimiter.
        $slash = $runner->run(self::case(
            'abc',
            'reject',
            4,
            'Unknown regex flag(s) found: "q"',
            '/',
            'q',
        ));

        $this->assertSame('reject', $slash['verdict']);
        $this->assertSame(4, $slash['offset'], 'the modifier is counted from the body');

        // Same shape with a bracket delimiter pair: '(abc)q' puts the "q" at
        // the same body coordinate.
        $bracket = $runner->run(self::case(
            'abc',
            'reject',
            4,
            'Unknown regex flag(s) found: "q"',
            '(',
            'q',
        ));

        $this->assertSame('reject', $bracket['verdict']);
        $this->assertSame(4, $bracket['offset'], 'a bracket opening delimiter is still one character of prefix');
    }

    /**
     * @return iterable<string, array{case: Pcre2Case, expected: string}>
     */
    public static function providePhpPatternRows(): iterable
    {
        yield 'slash delimiter wraps the body' => [
            'case' => self::case('abc', 'accept', null, null, '/', ''),
            'expected' => '/abc/',
        ];

        yield 'body containing the delimiter switches delimiter instead of escaping' => [
            // Escaping ("\/") would insert a byte and shift every later
            // offset; the harness compares byte offsets, so the delimiter is
            // swapped for one absent from the body.
            'case' => self::case('a/b', 'accept', null, null, '/', ''),
            'expected' => '#a/b#',
        ];

        yield 'non-slash single-char delimiter is preserved when safe' => [
            'case' => self::case('abc', 'accept', null, null, '"', ''),
            'expected' => '"abc"',
        ];

        yield 'bracket delimiter pair is preserved' => [
            'case' => self::case('abc', 'accept', null, null, '(', 'i'),
            'expected' => '(abc)i',
        ];

        yield 'escaped delimiter in the body keeps the original delimiter' => [
            // PHP's delimiter scan steps over "\/" and hands the body to
            // PCRE2 unchanged, exactly as pcre2test does: preg_match('/a\/b/',
            // 'a/b') returns 1.
            'case' => self::case('a\\/b', 'accept', null, null, '/', ''),
            'expected' => '/a\\/b/',
        ];

        yield 'unescaped delimiter elsewhere still forces a switch' => [
            'case' => self::case('a\\/b/c', 'accept', null, null, '/', ''),
            'expected' => '#a\\/b/c#',
        ];

        yield 'an escaped trailing backslash does not escape the closer' => [
            'case' => self::case('a\\\\', 'accept', null, null, '/', ''),
            'expected' => '/a\\\\/',
        ];
    }

    #[Test]
    public function test_runner_rebuilds_a_body_that_escapes_every_candidate_delimiter(): void
    {
        // testinput2:965 escapes the "/" delimiter and most punctuation, and
        // leaves some candidate delimiters unescaped ("_"), so no candidate
        // is absent from the body. The original delimiter still encloses it,
        // because every "/" in it is escaped.
        $body = self::vendoredTestinput2Body(965);
        $case = self::case($body, 'accept');

        $pattern = (new Pcre2CaseRunner())->phpPattern($case);

        $this->assertSame('/'.$body.'/', $pattern);
        $this->assertSame(0, @preg_match($pattern, ''), 'PHP reads the rebuilt pattern and compiles it');
    }

    #[Test]
    public function test_runner_compares_utf8_offsets_in_bytes(): void
    {
        // preg_match('/\u{e9}[abc/u', '') warns "missing terminating ] for
        // character class at offset 6": "\u{e9}" is two bytes. The library
        // also reports byte offset 6.
        $result = (new Pcre2CaseRunner())->run(self::case(
            "\u{e9}[abc",
            'reject',
            6,
            self::PCRE2_MISSING_BRACKET,
            flags: 'u',
            pcre2Code: 106,
        ));

        $this->assertSame(6, $result['offset']);
        $this->assertSame('pass', $result['outcome']);
    }

    /**
     * @param Pcre2Case $case
     */
    #[Test]
    #[DataProvider('providePhpPatternRows')]
    public function test_runner_reconstructs_php_pattern(array $case, string $expected): void
    {
        $this->assertSame($expected, (new Pcre2CaseRunner())->phpPattern($case));
    }

    #[Test]
    public function test_runner_classifies_principled_reject(): void
    {
        $result = (new Pcre2CaseRunner())->run(self::case(
            '[abc',
            'reject',
            4,
            self::PCRE2_MISSING_BRACKET,
            pcre2Code: 106,
        ));

        $this->assertSame('principled', $result['errorClass']);
        $this->assertNotSame('crash', $result['outcome']);
    }

    /**
     * @return iterable<string, array{throwable: \Throwable, expected: string}>
     */
    public static function provideThrowables(): iterable
    {
        yield 'parser exception is principled' => [
            'throwable' => new ParserException('unbalanced parenthesis', ErrorCode::GroupUnclosed, 3, 'a(b'),
            'expected' => 'principled',
        ];

        yield 'lexer exception is principled' => [
            'throwable' => new LexerException('unrecognized character', ErrorCode::EscapeUnrecognized, 2, 'a\\q'),
            'expected' => 'principled',
        ];

        yield 'plain runtime error is a crash' => [
            'throwable' => new \RuntimeException('unexpected library state'),
            'expected' => 'crash',
        ];

        yield 'engine type error is a crash' => [
            'throwable' => new \TypeError('unsupported offset type'),
            'expected' => 'crash',
        ];
    }

    #[Test]
    #[DataProvider('provideThrowables')]
    public function test_runner_classifies_principled_reject_vs_crash(\Throwable $throwable, string $expected): void
    {
        $this->assertSame($expected, Pcre2CaseRunner::classifyThrowable($throwable));
    }

    /**
     * The body of a one-line "/.../" pattern of the vendored testinput2.
     */
    private static function vendoredTestinput2Body(int $lineNumber): string
    {
        $lines = file(__DIR__.'/../../Fixtures/Pcre2/testdata/testinput2', \FILE_IGNORE_NEW_LINES);

        if (false === $lines || !isset($lines[$lineNumber - 1])) {
            throw new \RuntimeException(\sprintf('testinput2:%d is missing from the vendored testdata.', $lineNumber));
        }

        $line = $lines[$lineNumber - 1];
        $end = strrpos($line, '/');

        if (!str_starts_with($line, '/') || false === $end || 0 === $end) {
            throw new \RuntimeException(\sprintf('testinput2:%d is not a one-line slash-delimited pattern.', $lineNumber));
        }

        return substr($line, 1, $end - 1);
    }

    /**
     * Builds a suite case in the extractor's canonical shape.
     *
     * @param Pcre2Override|null $phpOverride
     * @param Pcre2Floor|null    $floor
     *
     * @return Pcre2Case
     */
    private static function case(
        string $pattern,
        string $verdict,
        ?int $offset = null,
        ?string $error = null,
        string $delimiter = '/',
        string $flags = '',
        ?int $pcre2Code = null,
        ?array $phpOverride = null,
        ?array $floor = null,
    ): array {
        return [
            'id' => 'sample:1',
            'pattern' => $pattern,
            'delimiter' => $delimiter,
            'flags' => $flags,
            'verdict' => $verdict,
            'offset' => $offset,
            'error' => $error,
            'pcre2Code' => $pcre2Code,
            'phpOverride' => $phpOverride,
            'floor' => $floor,
            'skipCategory' => null,
            'skipReason' => null,
        ];
    }
}
